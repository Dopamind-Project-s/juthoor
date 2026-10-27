/*
 * Juthoor unified tree renderer (UX-P0.3). Data: every person and father edge the viewer may see, loaded once in
 * batches (the server applies privacy and publication). Layout: tree-layout.js (compact single-child chains, sibling
 * grids, founder cards). Rendering: SVG, only what intersects the viewport, with a lighter level of detail when zoomed
 * out. Actions come from server URL templates that exist only for permitted actions; every route checks again.
 */
(() => {
    const app = document.querySelector('[data-tree-app]');
    if (!app || !window.JuthoorTreeLayout) return;
    const L = window.JuthoorTreeLayout;
    const $ = selector => app.querySelector(selector);
    const svg = $('[data-tree-svg]'), viewport = $('[data-tree-viewport]'), stage = $('[data-tree-stage]');
    const loading = $('[data-tree-loading]'), empty = $('[data-tree-empty]'), error = $('[data-tree-error]');
    const panel = $('[data-person-panel]'), popover = $('[data-founder-popover]'), progress = $('[data-tree-progress]');
    const branchSelect = $('[data-branch-select]'), modeSelect = $('[data-focus-mode]');
    const displaySelect = $('[data-display-mode]'), ancestrySelect = $('[data-ancestry-layout]');
    let displayMode = ['full', 'main_branches', 'sub_branches'].includes(new URLSearchParams(location.search).get('display')) ? new URLSearchParams(location.search).get('display') : 'full';
    displaySelect.value = displayMode;
    if (new URLSearchParams(location.search).get('ancestry') === 'compact') ancestrySelect.value = 'compact';
    const branchExpanded = new Set(), searchRevealed = new Set();
    let renderedIds = new Set(), scopedIds = new Set(), ancestryWidth = 0, previousStageWidth = stage.clientWidth;
    const ns = 'http://www.w3.org/2000/svg';
    const VERIFICATION = {verified: 'موثق ومراجع', sourced: 'مسند إلى مصدر', pending_review: 'قيد المراجعة', disputed: 'مختلف عليه', unresolved: 'غير محسوم'};
    // Arabic counted noun: 3–10 take the plural, 11+ the singular accusative.
    const generations = n => n >= 3 && n <= 10 ? `${n} أجيال` : `${n} جيلًا`;
    const edgeClass = v => v === 'verified' ? 'is-verified' : v === 'sourced' ? 'is-sourced' : 'is-pending';

    let data = {nodes: [], edges: [], meta: {}}, idx = L.index([], []), attributes = new Map(), templates = {}, placeholder = '';
    let collapsed = new Set(), expanded = new Set(), selected = null, view = {mode: 'full', focus: null};
    let units = [], layout = {items: [], links: [], rects: new Map(), width: 0, height: 0}, visibleState = new Map();
    let scale = 1, x = 0, y = 0, mounted = new Map(), lod = 'near', drawQueued = false, moved = false;
    const pointers = new Map();
    const branchPath = JSON.parse(app.dataset.branchPath || '[]');
    const initialBranch = app.dataset.branch || '';

    const element = (name, attrs = {}, text) => {
        const node = document.createElementNS(ns, name);
        Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const url = (key, id) => templates[key] ? templates[key].split(placeholder).join(String(id)) : null;
    const nodeOf = id => idx.byId.get(id);
    const state = active => [loading, empty, error].forEach(el => { el.hidden = el !== active; });

    // ---- data ---------------------------------------------------------------------------------------------------
    async function loadAll() {
        state(loading);
        progress.textContent = 'جارٍ تحميل جميع السجلات المسموح بعرضها…';
        const nodes = [], edges = [];
        let cursor = 0, payload;
        do {
            // Keep API requests on the page origin, including behind Hostinger TLS termination.
            const configured = new URL(app.dataset.graphUrl, location.href);
            const endpoint = new URL(configured.pathname + configured.search, location.origin);
            endpoint.searchParams.set('all', 1); endpoint.searchParams.set('batch', 200); endpoint.searchParams.set('cursor', cursor);
            const response = await fetch(endpoint, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) {
                const failure = new Error('graph');
                failure.status = response.status;
                throw failure;
            }
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) throw new Error('graph-non-json');
            payload = await response.json();
            if (!Array.isArray(payload.nodes) || !Array.isArray(payload.edges) || !payload.meta || typeof payload.meta.complete !== 'boolean') {
                throw new Error('graph-invalid-payload');
            }
            nodes.push(...payload.nodes); edges.push(...payload.edges);
            (payload.meta.attributes || []).forEach(attribute => attributes.set(attribute.id, attribute));
            templates = payload.meta.templates || templates; placeholder = String(payload.meta.id_placeholder || placeholder);
            progress.textContent = `جارٍ التحميل: ${nodes.length} / ${payload.meta.total}`;
            if (!payload.meta.complete && (!payload.meta.next_cursor || payload.meta.next_cursor <= cursor)) throw new Error('cursor');
            cursor = payload.meta.next_cursor;
        } while (!payload.meta.complete);
        data = {nodes, edges, rootId: payload.root_id};
        idx = L.index(nodes, edges);
        renderLegend();
        progress.textContent = `اكتمل التحميل: ${nodes.length} شخصًا مسموحًا بعرضه ضمن هذا العرض، و${idx.parent.size} علاقة أبوة.`;
    }

    // ---- layout -------------------------------------------------------------------------------------------------
    const measureCtx = document.createElement('canvas').getContext('2d');
    function lines(text, width, font = '700 13px Cairo, Tahoma, sans-serif', max = 3) {
        measureCtx.font = font;
        const out = [''];
        for (const word of String(text).split(/\s+/)) {
            const candidate = `${out.at(-1)} ${word}`.trim();
            if (measureCtx.measureText(candidate).width <= width) out[out.length - 1] = candidate;
            else out.push(word);
        }
        const result = out.filter(Boolean);
        if (result.length > max) { result.length = max; result[max - 1] += '…'; }
        return result;
    }
    const cardHeights = new Map();
    function measure(node, kind) {
        const w = kind === 'founder' ? L.S.founder : L.S.card;
        const nameLines = lines(node.name, w - 28, kind === 'founder' ? '800 15px Cairo, Tahoma, sans-serif' : undefined);
        const chips = (node.attributes || []).length ? 26 : 0;
        const h = ((idx.children.get(node.id) || []).some(id => scopedIds.has(id)) ? 24 : 0) + (kind === 'founder' ? 64 : 22) + nameLines.length * (kind === 'founder' ? 21 : 19) + chips + 26;
        cardHeights.set(node.id, {lines: nameLines, h});
        return {w, h};
    }
    function branchSpec() {
        const option = branchSelect?.selectedOptions[0];
        const root = Number(option?.dataset.root || 0);
        if (!root) return {};
        return {branchRoot: root, isolated: modeSelect.value === 'isolated', branchPath: branchSelect.value === initialBranch ? branchPath : null};
    }
    function relayout() {
        const started = performance.now();
        const scope = L.visible(idx, {...view, ...branchSpec()});
        scopedIds = scope.ids;
        const vis = L.displayVisible(idx, scope, displayMode, branchExpanded, collapsed, searchRevealed);
        renderedIds = vis.ids;
        visibleState = vis.state;
        app.dataset.visibleCount = String(vis.ids.size);
        app.dataset.activeDisplayMode = displayMode;
        const summary = $('[data-display-summary]');
        if (summary) summary.textContent = `عرض ${vis.ids.size} من ${scope.ids.size} سجلًا ضمن النطاق المحدد`;
        // Only a view's focus is kept as a full card; a selected chain member stays in its chain (highlighted).
        const keep = new Set([view.focus].filter(id => id !== null));
        const compact = ancestrySelect.value === 'compact';
        units = L.units(idx, vis, {collapsed, expanded, keep, compact});
        cardHeights.clear();
        ancestryWidth = Math.max(640, Math.min(1600, stage.clientWidth));
        layout = L.layout(idx, units, {measure, expanded, leafHeight: 58, compact, ancestryWidth, keep, scopeIds: scopedIds});
        app.dataset.serpentineNodes = String(layout.items.filter(item => item.serpentine).length);
        // Right-to-left: mirror horizontally so the first child appears on the right.
        const W = layout.width;
        layout.items.forEach(item => { item.x = W - item.x - item.w; (item.cells || []).forEach(cell => { cell.x = W - cell.x - cell.w; }); });
        layout.rects.forEach(rect => { rect.x = W - rect.x - rect.w; });
        layout.links.forEach(link => { (link.points || []).forEach(point => { point.x = W - point.x; }); link.from.x = W - link.from.x; if (link.spineX !== undefined) link.spineX = W - link.spineX; link.to.forEach(to => { to.x = W - to.x; }); });
        buildEdges();
        // Timings for performance checks (layout of the visible tree; drawing below).
        app.dataset.layoutMs = (performance.now() - started).toFixed(1);
        mounted.forEach(group => group.remove()); mounted = new Map();
        state(data.nodes.length ? null : empty);
        scheduleDraw();
    }
    function buildEdges() {
        viewport.querySelectorAll('.tree-edges').forEach(node => node.remove());
        const paths = {'is-verified': [], 'is-sourced': [], 'is-pending': []};
        layout.links.forEach(link => {
            link.to.forEach(to => {
                if (link.points?.length > 1) {
                    const end = link.points.at(-1), previous = link.points.at(-2);
                    const length = Math.hypot(end.x - previous.x, end.y - previous.y);
                    if (length > 0) {
                        const dx = (end.x - previous.x) / length, dy = (end.y - previous.y) / length;
                        paths[edgeClass(to.verification)].push(`M${end.x - dx * 8 - dy * 4},${end.y - dy * 8 + dx * 4}L${end.x},${end.y}L${end.x - dx * 8 + dy * 4},${end.y - dy * 8 - dx * 4}`);
                    }
                }
                paths[edgeClass(to.verification)].push(link.points ? link.points.map((point, i) => `${i ? 'L' : 'M'}${point.x},${point.y}`).join('') : link.spineX === undefined
                    ? `M${link.from.x},${link.from.y}V${link.barY}H${to.x}V${to.y}`
                    : `M${link.from.x},${link.from.y}V${link.barY}H${link.spineX}V${to.barY}H${to.x}V${to.y}`);
            });
        });
        const group = element('g', {class: 'tree-edges', 'aria-hidden': 'true'});
        Object.entries(paths).forEach(([cls, list]) => { if (list.length) group.append(element('path', {class: `tree-edge ${cls}`, d: list.join('')})); });
        viewport.prepend(group);
    }

    // ---- drawing (virtualized) -------------------------------------------------------------------------------------
    const transform = () => { viewport.setAttribute('transform', `translate(${x} ${y}) scale(${scale})`); scheduleDraw(); positionPopover(); };
    function scheduleDraw() {
        if (drawQueued) return;
        drawQueued = true;
        requestAnimationFrame(() => { drawQueued = false; draw(); });
    }
    function draw() {
        const started = performance.now();
        const nextLod = scale < 0.22 ? 'far' : scale < 0.45 ? 'mid' : 'near';
        if (nextLod !== lod) { lod = nextLod; svg.dataset.lod = lod; mounted.forEach(group => group.remove()); mounted = new Map(); }
        const margin = 300 / scale;
        const left = -x / scale - margin, top = -y / scale - margin, right = (stage.clientWidth - x) / scale + margin, bottom = (stage.clientHeight - y) / scale + margin;
        const keep = new Set();
        for (const item of layout.items) {
            if (item.x > right || item.x + item.w < left || item.y > bottom || item.y + item.h < top) continue;
            keep.add(item.key);
            if (!mounted.has(item.key)) { const group = drawItem(item); mounted.set(item.key, group); viewport.append(group); }
        }
        mounted.forEach((group, key) => { if (!keep.has(key)) { group.remove(); mounted.delete(key); } });
        app.dataset.mounted = String(mounted.size);
        app.dataset.drawMs = (performance.now() - started).toFixed(1);
    }
    const stateClass = id => `is-${visibleState.get(id) || 'visible'}${selected === id ? ' is-selected' : ''}`;
    function chip(group, attribute, cx, cy, width) {
        const g = element('g', {class: 'tree-chip'});
        g.append(element('title', {}, attribute.label));
        g.append(element('rect', {x: cx, y: cy, width, height: 20, rx: 10, style: `fill:${attribute.color}`}));
        const short = attribute.label.length > 14 ? attribute.label.slice(0, 13) + '…' : attribute.label;
        g.append(element('text', {x: cx + width / 2, y: cy + 14, 'text-anchor': 'middle', style: `fill:${attribute.text_color}`}, `◆ ${short}`));
        group.append(g);
    }
    function drawCard(item) {
        const node = nodeOf(item.id), founder = node.founder, measured = cardHeights.get(item.id) || {lines: [node.name]};
        const group = element('g', {transform: `translate(${item.x} ${item.y})`, class: `tree-node tree-card ${founder ? 'is-founder founder-' + founder.kind : ''} ${stateClass(item.id)} v-${node.verification}`, 'data-id': item.id, 'data-generation': item.generation === undefined ? null : item.generation + 1, 'data-serpentine': item.serpentine ? '1' : '0', role: 'button', tabindex: '0',
            'aria-label': `${node.name}${founder ? '، ' + founder.classification + (founder.branch ? ' ' + founder.branch : '') : ''}، ${VERIFICATION[node.verification] || 'غير موثق'}`});
        group.append(element('title', {}, node.name));
        group.append(element('rect', {class: 'tree-card-bg', width: item.w, height: item.h, rx: founder ? 18 : 12}));
        if (lod === 'far') return group;
        let top = 22;
        if (founder) {
            group.append(element('rect', {class: 'tree-founder-band', width: item.w, height: 44, rx: 18}));
            group.append(element('rect', {class: 'tree-founder-band', y: 26, width: item.w, height: 18}));
            group.append(element('text', {class: 'tree-founder-label', x: item.w / 2, y: 20, 'text-anchor': 'middle'}, `★ ${founder.classification}`));
            if (founder.branch) group.append(element('text', {class: 'tree-founder-branch', x: item.w / 2, y: 37, 'text-anchor': 'middle'}, founder.branch));
            top = 66;
        }
        const nameLines = lod === 'mid' ? measured.lines.slice(0, 1) : measured.lines;
        nameLines.forEach((line, i) => group.append(element('text', {class: founder ? 'tree-name tree-name-founder' : 'tree-name', x: item.w / 2, y: top + i * (founder ? 21 : 19), 'text-anchor': 'middle'}, line)));
        if (lod === 'near') {
            const ids = node.attributes || [];
            if (ids.length) {
                const width = ids.length > 1 ? (item.w - 32) / 2 : item.w - 28;
                ids.slice(0, 2).forEach((id, i) => { const attribute = attributes.get(id); if (attribute) chip(group, attribute, 14 + i * (width + 4), top + measured.lines.length * 19 - 6, width); });
            }
            group.append(element('text', {class: `tree-meta v-${node.verification}`, x: item.w / 2, y: item.h - 10, 'text-anchor': 'middle'}, collapsed.has(item.id) ? `ذرية مطوية (${(idx.children.get(item.id) || []).length}+)` : (VERIFICATION[node.verification] || 'غير موثق')));
        }
        if (lod !== 'far' && (idx.children.get(item.id) || []).some(id => scopedIds.has(id))) {
            const control = element('g', {'data-expand-id': item.id, class: 'tree-expand-control', role: 'button', tabindex: '0', 'aria-label': effectiveCollapsed(item.id) ? 'توسيع ذرية ' + node.name : 'طي ذرية ' + node.name, 'aria-expanded': String(!effectiveCollapsed(item.id))});
            control.append(element('circle', {cx: item.w - 17, cy: item.h - 15, r: 12}));
            control.append(element('text', {x: item.w - 17, y: item.h - 10, 'text-anchor': 'middle'}, effectiveCollapsed(item.id) ? '+' : '−'));
            group.append(control);
        }
        return group;
    }
    function drawChain(item) {
        const group = element('g', {transform: `translate(${item.x} ${item.y})`, class: 'tree-chain', 'data-chain': item.key});
        group.append(element('rect', {class: 'tree-chain-bg', width: item.w, height: item.h, rx: 14}));
        group.append(element('text', {class: 'tree-chain-head', x: item.w / 2, y: 22, 'text-anchor': 'middle'}, `سلسلة نسب مباشرة · ${generations(item.ids.length)}`));
        const lineX = item.w - 24;
        group.append(element('line', {class: 'tree-chain-line', x1: lineX, x2: lineX, y1: L.S.chainHead + 8, y2: item.h - 14}));
        if (lod === 'far') return group;
        item.rows.forEach((row, i) => {
            const rowY = L.S.chainHead + i * L.S.row;
            if (row.fold) {
                const fold = element('g', {class: 'tree-chain-fold', 'data-fold': item.key, role: 'button', tabindex: '0', 'aria-label': `إظهار ${generations(row.fold)} مختصرة`});
                fold.append(element('rect', {x: 10, y: rowY + 3, width: item.w - 20, height: L.S.row - 6, rx: 8}));
                fold.append(element('text', {x: item.w / 2, y: rowY + 20, 'text-anchor': 'middle'}, `⋯ ${generations(row.fold)} — إظهار`));
                group.append(fold);
                return;
            }
            const node = nodeOf(row.id);
            const g = element('g', {class: `tree-node tree-chain-row ${stateClass(row.id)} v-${node.verification}`, 'data-id': row.id, role: 'button', tabindex: '0', 'aria-label': `${node.name}، ${VERIFICATION[node.verification] || 'غير موثق'}`});
            g.append(element('title', {}, `${node.name} — ${VERIFICATION[node.verification] || ''}`));
            g.append(element('rect', {class: 'tree-chain-hit', x: 4, y: rowY, width: item.w - 8, height: L.S.row, rx: 8}));
            g.append(element('circle', {class: `tree-chain-dot ${edgeClass(node.verification)}`, cx: lineX, cy: rowY + L.S.row / 2, r: 5}));
            const label = lines(node.name, item.w - 60, '600 13px Cairo, Tahoma, sans-serif', 1)[0];
            g.append(element('text', {class: 'tree-chain-name', x: lineX - 14, y: rowY + 19, 'text-anchor': 'start'}, label));
            if ((node.attributes || []).length) {
                const attribute = attributes.get(node.attributes[0]);
                if (attribute) g.append(element('circle', {cx: 16, cy: rowY + L.S.row / 2, r: 6, style: `fill:${attribute.color}`}, undefined), element('title', {}, attribute.label));
            }
            group.append(g);
        });
        return group;
    }
    const drawItem = item => item.kind === 'card' ? drawCard(item) : drawChain(item);

    // ---- view state -----------------------------------------------------------------------------------------------
    function effectiveCollapsed(id) {
        return collapsed.has(id) || (idx.children.get(id) || []).some(child => scopedIds.has(child) && !renderedIds.has(child));
    }
    function keepAnchor(id, action) {
        const before = layout.rects.get(id);
        const screen = before ? {x: x + (before.x + before.w / 2) * scale, y: y + (before.y + before.h / 2) * scale} : null;
        action(); relayout();
        const after = layout.rects.get(id);
        if (screen && after) { x = screen.x - (after.x + after.w / 2) * scale; y = screen.y - (after.y + after.h / 2) * scale; }
        transform();
    }
    function toggleBranch(id) {
        const opening = effectiveCollapsed(id);
        keepAnchor(id, () => {
            if (opening) { collapsed.delete(id); branchExpanded.add(id); }
            else { branchExpanded.delete(id); collapsed.add(id); }
        });
        if (selected !== null) updatePanel();
    }
    function reveal(id) {
        let changed = false;
        if (displayMode !== 'full' && !renderedIds.has(id)) { searchRevealed.add(id); changed = true; }
        L.pathTo(idx, id).forEach(ancestor => { if (ancestor !== id && collapsed.delete(ancestor)) changed = true; });
        const vis = L.visible(idx, {...view, ...branchSpec()}, collapsed);
        if (!vis.ids.has(id)) { view = {mode: 'full', focus: null}; if (branchSelect.value) { branchSelect.value = ''; } changed = true; }
        const placed = layout.rects.get(id)?.item;
        const chain = placed?.kind === 'chain' ? placed.key : null;
        if (chain && !expanded.has(chain)) { expanded.add(chain); changed = true; }
        return changed;
    }
    function center(id, minScale = 0.75) {
        const rect = layout.rects.get(id); if (!rect) return;
        if (scale < minScale) scale = minScale;
        x = stage.clientWidth / 2 - (rect.x + rect.w / 2) * scale;
        y = Math.min(stage.clientHeight / 3, stage.clientHeight / 2) - (rect.y + rect.h / 2) * scale;
        transform();
    }
    function select(id, {push = true, recenter = true} = {}) {
        if (!idx.byId.has(id)) return;
        const changed = reveal(id);
        selected = id;
        if (changed) relayout();
        else { mounted.forEach(group => group.remove()); mounted = new Map(); scheduleDraw(); }
        if (recenter || changed) center(id);
        updatePanel(); updatePath(); updateViewButtons();
        if (push) pushHistory();
        const target = viewport.querySelector(`[data-id="${id}"]`);
        target?.focus({preventScroll: true});
        return changed;
    }
    function setView(mode) {
        if (mode !== 'full' && selected === null) return;
        view = mode === 'full' ? {mode: 'full', focus: null} : {mode, focus: selected};
        relayout(); fit(mode === 'full' && selected !== null ? null : undefined);
        if (selected !== null) center(selected, 0.6);
        updateViewButtons(); pushHistory();
    }
    function updateViewButtons() {
        app.querySelectorAll('[data-view]').forEach(button => {
            button.disabled = button.dataset.view !== 'full' && selected === null;
            button.setAttribute('aria-pressed', String(button.dataset.view === view.mode));
        });
    }
    function updatePath() {
        const bar = $('[data-tree-path]');
        if (selected === null) { bar.hidden = true; return; }
        const path = L.pathTo(idx, selected);
        const shown = path.length > 9 ? [...path.slice(0, 2), null, ...path.slice(-6)] : path;
        bar.replaceChildren();
        shown.forEach((id, i) => {
            if (i) bar.append(Object.assign(document.createElement('i'), {className: 'bi bi-chevron-left', ariaHidden: 'true'}));
            if (id === null) { bar.append(Object.assign(document.createElement('span'), {textContent: `… ${generations(path.length - 8)} …`, className: 'text-muted'})); return; }
            const button = Object.assign(document.createElement('button'), {type: 'button', textContent: nodeOf(id).name, className: id === selected ? 'is-current' : ''});
            if (id === selected) button.setAttribute('aria-current', 'true');
            button.onclick = () => select(id);
            bar.append(button);
        });
        bar.hidden = false;
    }
    function renderLegend() {
        const legend = $('[data-attribute-legend]'); legend.replaceChildren();
        const used = new Set(data.nodes.flatMap(node => node.attributes || []));
        attributes.forEach(attribute => {
            if (!used.has(attribute.id)) return;
            const badge = document.createElement('span'); badge.className = 'person-attribute tree-legend-attribute';
            badge.style.setProperty('--attribute-color', attribute.color); badge.style.setProperty('--attribute-ink', attribute.text_color);
            badge.textContent = `◆ ${attribute.label}`;
            legend.append(badge);
        });
    }

    // ---- panel, founder popover, actions ---------------------------------------------------------------------------------
    const founderHtml = founder => {
        const box = document.createElement('div');
        const title = document.createElement('strong'); title.textContent = `★ ${founder.classification}${founder.branch ? ' — ' + founder.branch : ''}`; box.append(title);
        if (founder.description) box.append(Object.assign(document.createElement('p'), {textContent: founder.description, className: 'mb-1'}));
        if (founder.verification) box.append(Object.assign(document.createElement('small'), {textContent: `توثيق الفرع: ${VERIFICATION[founder.verification] || founder.verification}`, className: 'd-block text-muted'}));
        const links = document.createElement('div'); links.className = 'd-flex flex-wrap gap-2 mt-1';
        if (founder.entity_url) links.append(Object.assign(document.createElement('a'), {href: founder.entity_url, textContent: `صفحة ${founder.entity || 'الفخذ'}`}));
        if (founder.branch_url) links.append(Object.assign(document.createElement('a'), {href: founder.branch_url, textContent: 'شجرة الفرع'}));
        box.append(links);
        return box;
    };
    function showPopover(id) {
        const node = nodeOf(id);
        // Shown for a mouse pointer (pointerover) or keyboard focus; touch uses the detail sheet instead.
        if (!node?.founder) return;
        popover.replaceChildren(Object.assign(document.createElement('div'), {className: 'tree-popover-name', textContent: node.name}), founderHtml(node.founder));
        popover.dataset.for = id; popover.hidden = false; positionPopover();
    }
    function positionPopover() {
        if (popover.hidden) return;
        const rect = layout.rects.get(Number(popover.dataset.for)); if (!rect) { popover.hidden = true; return; }
        const left = x + (rect.x + rect.w / 2) * scale, top = y + (rect.y + rect.h) * scale + 8;
        popover.style.left = `${Math.max(8, Math.min(stage.clientWidth - popover.offsetWidth - 8, left - popover.offsetWidth / 2))}px`;
        popover.style.top = `${Math.min(stage.clientHeight - popover.offsetHeight - 8, top)}px`;
    }
    const hidePopover = () => { popover.hidden = true; delete popover.dataset.keyboard; };
    function setLink(selector, href) { const link = $(selector); if (!link) return; link.hidden = !href; if (href) link.href = href; else link.removeAttribute('href'); }
    function updatePanel() {
        const node = nodeOf(selected);
        panel.hidden = !node; if (!node) return;
        $('[data-person-name]').textContent = node.name;
        $('[data-person-status]').textContent = VERIFICATION[node.verification] || 'غير موثق';
        const badges = $('[data-person-attributes]'); badges.replaceChildren();
        (node.attributes || []).forEach(id => {
            const attribute = attributes.get(id); if (!attribute) return;
            const badge = document.createElement('span'); badge.className = 'person-attribute'; badge.textContent = `◆ ${attribute.label}`;
            badge.style.setProperty('--attribute-color', attribute.color); badge.style.setProperty('--attribute-ink', attribute.text_color);
            badges.append(badge);
        });
        const founderBox = $('[data-person-founder]');
        founderBox.hidden = !node.founder; founderBox.replaceChildren(...(node.founder ? [founderHtml(node.founder)] : []));
        const path = L.pathTo(idx, selected);
        const lineage = path.slice(0, -1).reverse().slice(0, 4).map(id => 'بن ' + nodeOf(id).name).join(' ');
        const children = (idx.children.get(selected) || []).length;
        $('[data-person-context]').textContent = [lineage, `الجيل ${path.length} من الجذر المنشور`, children ? `${children} من الأبناء المنشورين` : 'لا أبناء منشورون في هذا العرض'].filter(Boolean).join(' · ');
        setLink('[data-person-link]', url('person', selected));
        setLink('[data-person-add-child]', url('add_child', selected));
        setLink('[data-person-edit]', url('edit', selected));
        setLink('[data-person-propose]', url('propose', selected));
        setLink('[data-person-manage]', url('manage', selected));
        setLink('[data-person-entity]', node.founder?.entity_url || null);
        const toggle = $('[data-person-toggle]');
        toggle.hidden = !(idx.children.get(selected) || []).some(id => scopedIds.has(id)); toggle.textContent = effectiveCollapsed(selected) ? 'توسيع الذرية' : 'طي الذرية';
        toggle.onclick = () => toggleBranch(selected);
        $('[data-person-ancestors]').onclick = () => setView('ancestors');
        $('[data-person-descendants]').onclick = () => setView('descendants');
        $('[data-person-load]').hidden = true;
        quickAdd.open(node, false);
    }

    // ---- history (back/forward, person ↔ tree) ---------------------------------------------------------------------------
    let historyDepth = 0, historyIndex = 0;
    function pushHistory() {
        const params = new URLSearchParams(location.search);
        selected !== null ? params.set('person', selected) : params.delete('person');
        view.mode !== 'full' ? params.set('view', view.mode) : params.delete('view');
        displayMode === 'full' ? params.delete('display') : params.set('display', displayMode);
        ancestrySelect.value === 'compact' ? params.set('ancestry', 'compact') : params.delete('ancestry');
        const next = `${location.pathname}?${params}`;
        if (next === `${location.pathname}${location.search}`) return;
        historyIndex++; historyDepth = historyIndex;
        history.pushState({person: selected, view: view.mode, index: historyIndex}, '', next);
        updateHistoryButtons();
    }
    function updateHistoryButtons() {
        app.querySelector('[data-tree-action="back"]').disabled = historyIndex <= 0;
        app.querySelector('[data-tree-action="forward"]').disabled = historyIndex >= historyDepth;
    }
    window.addEventListener('popstate', event => {
        const s = event.state || {};
        historyIndex = s.index || 0; updateHistoryButtons();
        const person = Number(new URLSearchParams(location.search).get('person') || 0);
        const params = new URLSearchParams(location.search);
        const mode = params.get('view') || 'full';
        displayMode = ['main_branches', 'sub_branches'].includes(params.get('display')) ? params.get('display') : 'full';
        displaySelect.value = displayMode;
        ancestrySelect.value = params.get('ancestry') === 'compact' ? 'compact' : 'serpentine';
        branchExpanded.clear(); collapsed.clear(); searchRevealed.clear();
        if (person && idx.byId.has(person)) { selected = person; view = mode === 'full' ? {mode: 'full', focus: null} : {mode, focus: person}; relayout(); select(person, {push: false}); }
        else { selected = null; view = {mode: 'full', focus: null}; panel.hidden = true; relayout(); updatePath(); updateViewButtons(); }
    });

    // ---- pan, zoom, fit, keyboard --------------------------------------------------------------------------------------------
    function fit() {
        if (!layout.width) return;
        scale = Math.max(Number.EPSILON, Math.min(1, (stage.clientWidth - 40) / layout.width, (stage.clientHeight - 40) / layout.height));
        x = (stage.clientWidth - layout.width * scale) / 2; y = 20; transform();
    }
    function zoom(factor, px = stage.clientWidth / 2, py = stage.clientHeight / 2) {
        const minimum = Math.max(Number.EPSILON, Math.min(0.02, (stage.clientWidth - 40) / Math.max(1, layout.width), (stage.clientHeight - 40) / Math.max(1, layout.height)));
        const next = Math.max(minimum, Math.min(2.5, scale * factor));
        x = px - (px - x) * next / scale; y = py - (py - y) * next / scale; scale = next; transform();
    }
    function initialView() {
        const narrow = stage.clientWidth < 700;
        const focusId = Number(branchSelect.selectedOptions[0]?.dataset.root || 0) || data.rootId;
        // Phones start readable on the root or fakhd founder; the full overview is one tap away (ملاءمة).
        if (narrow && focusId && layout.rects.has(focusId)) {
            scale = 0.8; center(focusId, 0.8);
            const root = layout.rects.get(focusId);
            if (focusId === data.rootId && root.item.serpentine) { x = stage.clientWidth - 16 - (root.x + root.w) * scale; transform(); }
            return;
        }
        const fitScale = Math.min((stage.clientWidth - 40) / layout.width, (stage.clientHeight - 40) / layout.height);
        if (fitScale >= 0.75 || !focusId || !layout.rects.has(focusId)) { fit(); return; }
        // Too large to read when fitted: start at a legible scale on the root (or fakhd founder), near the top.
        scale = 0.85;
        const rect = layout.rects.get(focusId);
        x = focusId === data.rootId && rect.item.serpentine ? stage.clientWidth - 24 - (rect.x + rect.w) * scale : stage.clientWidth / 2 - (rect.x + rect.w / 2) * scale;
        y = 24 - rect.y * scale + (focusId === data.rootId ? 0 : 80);
        transform();
    }
    app.querySelectorAll('[data-tree-action]').forEach(button => button.addEventListener('click', () => {
        const action = button.dataset.treeAction;
        if (action === 'zoom-in') zoom(1.3);
        if (action === 'zoom-out') zoom(1 / 1.3);
        if (action === 'fit') fit();
        if (action === 'reset') { displayMode = 'full'; displaySelect.value = 'full'; ancestrySelect.value = 'serpentine'; branchExpanded.clear(); searchRevealed.clear(); collapsed.clear(); expanded.clear(); selected = null; view = {mode: 'full', focus: null}; panel.hidden = true; relayout(); initialView(); updatePath(); updateViewButtons(); pushHistory(); }
        if (action === 'back') history.back();
        if (action === 'forward') history.forward();
        if (action === 'fullscreen') {
            if (document.fullscreenElement) document.exitFullscreen?.();
            else (app.requestFullscreen ? app.requestFullscreen() : Promise.reject()).catch(() => app.classList.toggle('is-fullscreen'));
        }
    }));
    app.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => setView(button.dataset.view)));
    document.addEventListener('fullscreenchange', () => { app.classList.toggle('is-fullscreen', !!document.fullscreenElement); requestAnimationFrame(() => transform()); });
    svg.addEventListener('wheel', event => {
        event.preventDefault(); const box = svg.getBoundingClientRect(); zoom(event.deltaY < 0 ? 1.12 : 1 / 1.12, event.clientX - box.left, event.clientY - box.top);
    }, {passive: false});
    svg.addEventListener('pointerdown', event => {
        if (event.button && event.pointerType === 'mouse') return;
        if (!pointers.size) moved = false;
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
    });
    svg.addEventListener('pointermove', event => {
        const previous = pointers.get(event.pointerId); if (!previous) return;
        const oldPoints = [...pointers.values()];
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        const dx = event.clientX - previous.x, dy = event.clientY - previous.y;
        if (Math.abs(dx) + Math.abs(dy) > 3) { moved = true; if (!svg.hasPointerCapture(event.pointerId)) svg.setPointerCapture(event.pointerId); hidePopover(); }
        if (pointers.size === 2) {
            const nextPoints = [...pointers.values()];
            const distance = points => Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y);
            const oldDistance = distance(oldPoints); if (oldDistance < 1) return;
            const box = svg.getBoundingClientRect();
            zoom(distance(nextPoints) / oldDistance, (oldPoints[0].x + oldPoints[1].x) / 2 - box.left, (oldPoints[0].y + oldPoints[1].y) / 2 - box.top);
        } else if (moved) { x += dx; y += dy; transform(); }
    });
    const release = event => { pointers.delete(event.pointerId); if (svg.hasPointerCapture(event.pointerId)) svg.releasePointerCapture(event.pointerId); };
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(type => svg.addEventListener(type, release));
    svg.addEventListener('click', event => {
        if (moved) return;
        const expansion = event.target.closest('[data-expand-id]');
        if (expansion) { toggleBranch(Number(expansion.dataset.expandId)); return; }
        const fold = event.target.closest('[data-fold]');
        if (fold) { expanded.add(fold.dataset.fold); relayout(); return; }
        const target = event.target.closest('[data-id]');
        if (target) select(Number(target.dataset.id), {recenter: false});
    });
    svg.addEventListener('pointerover', event => { const target = event.target.closest('.is-founder[data-id]'); if (target && event.pointerType === 'mouse') showPopover(Number(target.dataset.id)); });
    svg.addEventListener('pointerout', event => { if (event.target.closest('.is-founder[data-id]') && !event.relatedTarget?.closest?.('.is-founder[data-id]')) hidePopover(); });
    svg.addEventListener('focusin', event => { const target = event.target.closest('.is-founder[data-id]'); if (target) { popover.dataset.keyboard = '1'; showPopover(Number(target.dataset.id)); } });
    svg.addEventListener('focusout', hidePopover);
    stage.addEventListener('keydown', event => {
        if (event.target.closest('input,select,textarea')) return;
        const key = event.key;
        const expansion = event.target.closest('[data-expand-id]');
        if (expansion && (key === 'Enter' || key === ' ')) { event.preventDefault(); toggleBranch(Number(expansion.dataset.expandId)); return; }
        if (key === '+' || key === '=') { zoom(1.2); event.preventDefault(); return; }
        if (key === '-') { zoom(1 / 1.2); event.preventDefault(); return; }
        if (key === '0') { fit(); event.preventDefault(); return; }
        if (key === 'Escape') { hidePopover(); panel.hidden = true; return; }
        const focusedFold = event.target.closest?.('[data-fold]');
        if (focusedFold && (key === 'Enter' || key === ' ')) { expanded.add(focusedFold.dataset.fold); relayout(); event.preventDefault(); return; }
        const current = Number(event.target.closest?.('[data-id]')?.dataset.id || selected || 0);
        if (!current) return;
        const parent = idx.parent.get(current), kids = (idx.children.get(current) || []).filter(id => layout.rects.has(id));
        const siblings = parent !== undefined ? (idx.children.get(parent) || []).filter(id => layout.rects.has(id)) : [];
        const at = siblings.indexOf(current);
        const next = {ArrowUp: parent, ArrowDown: kids[0], ArrowRight: siblings[at - 1], ArrowLeft: siblings[at + 1], Enter: current, ' ': current}[key];
        if (next !== undefined && layout.rects.has(next)) { event.preventDefault(); select(next); }
    });
    $('[data-person-close]').addEventListener('click', () => { panel.hidden = true; selected = null; relayout(); updatePath(); updateViewButtons(); pushHistory(); });
    new ResizeObserver(() => {
        const nextWidth = Math.max(640, Math.min(1600, stage.clientWidth));
        const becameVisible = previousStageWidth === 0 && stage.clientWidth > 0;
        previousStageWidth = stage.clientWidth;
        if (data.nodes.length && becameVisible) {
            relayout(); if (selected !== null) center(selected); else initialView();
        } else if (data.nodes.length && ancestryWidth && nextWidth !== ancestryWidth && ancestrySelect.value !== 'compact') {
            keepAnchor(selected ?? data.rootId, () => {});
        } else scheduleDraw();
    }).observe(stage);

    // ---- branch / mode ------------------------------------------------------------------------------------------------------
    [branchSelect, modeSelect].forEach(control => control?.addEventListener('change', () => {
        view = {mode: 'full', focus: null}; collapsed.clear(); branchExpanded.clear(); searchRevealed.clear(); relayout();
        if (selected !== null && !renderedIds.has(selected)) { selected = null; panel.hidden = true; updatePath(); }
        else if (selected !== null) updatePanel();
        initialView(); updateViewButtons();
    }));

    displaySelect.addEventListener('change', () => {
        keepAnchor(selected ?? Number(branchSelect.selectedOptions[0]?.dataset.root || data.rootId), () => {
            displayMode = displaySelect.value; branchExpanded.clear(); collapsed.clear(); searchRevealed.clear();
            if (selected !== null) searchRevealed.add(selected);
        });
        if (selected !== null) updatePanel(); pushHistory();
    });
    ancestrySelect.addEventListener('change', () => { keepAnchor(selected ?? data.rootId, () => expanded.clear()); pushHistory(); });

    // ---- search -----------------------------------------------------------------------------------------------------------
    const input = $('[data-tree-search]'), results = $('[data-tree-search-results]');
    let timer, searchSequence = 0, searchController;
    const clearSearch = () => { clearTimeout(timer); searchSequence++; searchController?.abort(); results.hidden = true; input.setAttribute('aria-expanded', 'false'); };
    input.addEventListener('input', () => {
        clearSearch(); const q = input.value.trim(); if (q.length < 2) return;
        const ticket = searchSequence;
        timer = setTimeout(async () => {
            searchController = new AbortController();
            try {
                const endpoint = new URL(app.dataset.searchUrl, location.href); endpoint.searchParams.set('q', q);
                const response = await fetch(endpoint, {headers: {Accept: 'application/json'}, signal: searchController.signal});
                if (!response.ok) throw new Error('search');
                const payload = await response.json(); if (ticket !== searchSequence) return;
                results.replaceChildren(...payload.data.map(item => {
                    const button = document.createElement('button'); button.type = 'button'; button.setAttribute('role', 'option');
                    button.textContent = item.lineage ? `${item.text} ${item.lineage}` : item.text;
                    button.onclick = () => { clearSearch(); select(item.id); };
                    return button;
                }));
                if (!payload.data.length) results.textContent = 'لا توجد نتائج.';
                results.hidden = false; input.setAttribute('aria-expanded', 'true');
            } catch (cause) { if (ticket === searchSequence && cause.name !== 'AbortError') { results.textContent = 'تعذر البحث. حاول مجددًا.'; results.hidden = false; } }
        }, 250);
    });

    // ---- quick add of a son (UX-P0.1), unchanged behaviour ---------------------------------------------------------------------
    const quickAdd = (() => {
        const form = $('[data-quick-add]'), toggle = $('[data-quick-add-toggle]'), status = $('[data-quick-status]'), list = $('[data-quick-candidates]');
        const csrf = app.dataset.csrf;
        let father = null, confirmDistinct = false;
        if (!form || !app.dataset.quickAddUrl) return {open() { if (toggle) toggle.hidden = true; }};
        const candidatesUrl = extra => { const endpoint = new URL(app.dataset.candidatesUrl, location.href); Object.entries(extra).forEach(([k, v]) => endpoint.searchParams.set(k, v)); return endpoint; };
        const showCandidates = (candidates, blocking) => {
            list.replaceChildren(); list.hidden = !candidates.length; if (!candidates.length) return;
            const heading = document.createElement('p'); heading.className = 'small fw-bold mb-1';
            heading.textContent = blocking ? 'يوجد سجل مطابق؛ افتحه بدل إنشاء نسخة، أو أكّد أنه شخص مختلف:' : 'سجلات مشابهة قد تكون الشخص نفسه:';
            list.append(heading);
            candidates.forEach(candidate => {
                const row = document.createElement('a'); row.href = candidate.url; row.className = 'd-block small'; row.target = '_blank'; row.rel = 'noopener';
                row.textContent = `${candidate.name} ${candidate.lineage} — ${candidate.reason}`; list.append(row);
            });
            if (blocking) {
                const label = document.createElement('label'); label.className = 'small d-block mt-1';
                const box = document.createElement('input'); box.type = 'checkbox'; box.onchange = () => { confirmDistinct = box.checked; };
                label.append(box, ' أؤكد أنه شخص مختلف رغم التطابق'); list.append(label);
            }
        };
        const canAdd = node => !!url('add_child', node.id);
        const open = (node, show) => {
            toggle.hidden = !canAdd(node);
            if (father?.id !== node.id) { form.hidden = true; form.reset(); status.textContent = ''; list.hidden = true; confirmDistinct = false; }
            father = node;
            if (!show || !canAdd(node)) return;
            form.hidden = false; toggle.hidden = true;
            $('[data-quick-father]').textContent = `سيُضاف ابنًا لـ: ${node.name}`;
            $('[data-quick-context]').textContent = '';
            fetch(candidatesUrl({father_id: node.id}), {headers: {Accept: 'application/json'}})
                .then(response => response.ok ? response.json() : null).then(payload => {
                    if (!payload?.father || father?.id !== node.id) return;
                    const context = [payload.father.lineage, payload.father.branches.length ? `الفرع: ${payload.father.branches.join(' ← ')}` : '', payload.father.children.length ? `أبناؤه المسجلون: ${payload.father.children.join('، ')}` : 'لا أبناء مسجلين بعد'].filter(Boolean);
                    $('[data-quick-context]').textContent = context.join(' · ');
                }).catch(() => {});
            form.elements.full_name_ar.focus();
        };
        toggle.addEventListener('click', () => father && open(father, true));
        $('[data-quick-cancel]').addEventListener('click', () => { form.hidden = true; toggle.hidden = !(father && canAdd(father)); });
        let checkTimer;
        form.elements.full_name_ar.addEventListener('input', () => {
            clearTimeout(checkTimer); confirmDistinct = false;
            const name = form.elements.full_name_ar.value.trim(); if (name.length < 2) { list.hidden = true; return; }
            checkTimer = setTimeout(async () => {
                try {
                    const response = await fetch(candidatesUrl({full_name_ar: name, father_id: father.id}), {headers: {Accept: 'application/json'}});
                    if (response.ok) { const payload = await response.json(); showCandidates(payload.data, payload.data.some(candidate => candidate.blocking)); }
                } catch (cause) { /* the server check on save still applies */ }
            }, 350);
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const name = form.elements.full_name_ar.value.trim();
            if (!name) { status.textContent = 'أدخل اسم الابن.'; form.elements.full_name_ar.focus(); return; }
            const submit = $('[data-quick-submit]'); submit.disabled = true; status.textContent = 'جارٍ الحفظ…';
            try {
                const response = await fetch(app.dataset.quickAddUrl, {method: 'POST', headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify({father_id: father.id, full_name_ar: name, living_status: form.elements.living_status.value, confirm_distinct: confirmDistinct})});
                const payload = await response.json().catch(() => ({}));
                if (response.status === 422) {
                    status.textContent = payload.message || 'تحقق من البيانات.';
                    if (payload.candidates) showCandidates(payload.candidates, true);
                    return;
                }
                if (!response.ok) throw new Error('save');
                status.textContent = payload.message; list.hidden = true; confirmDistinct = false;
                const keepOpen = $('[data-quick-again]').checked, parentId = father.id, saved = {scale, x, y};
                await loadAll();
                relayout(); ({scale, x, y} = saved); transform();
                if (idx.byId.has(parentId)) { collapsed.delete(parentId); select(parentId, {recenter: false}); }
                if (keepOpen && idx.byId.has(parentId)) { open(nodeOf(parentId), true); form.elements.full_name_ar.value = ''; status.textContent = payload.message; }
            } catch (cause) { status.textContent = 'تعذر الحفظ. حاول مجددًا.'; }
            finally { submit.disabled = false; }
        });

        return {open};
    })();

    // ---- start -----------------------------------------------------------------------------------------------------------------
    async function start() {
        try {
            await loadAll();
        } catch (cause) {
            state(error);
            console.error('Juthoor graph loading failed', {status: cause.status || null, reason: cause.message});
            const reason = cause.status === 401 || cause.status === 403 ? 'تحقق من تسجيل الدخول وصلاحية عرض الشجرة.'
                : cause.status === 404 ? 'العائلة أو الشجرة المطلوبة غير متاحة.'
                : cause.status === 429 ? 'طلبات كثيرة؛ انتظر قليلًا ثم أعد المحاولة.'
                : cause.status >= 500 ? 'تعذر تحميل البيانات من الخادم؛ راجع سجل Laravel.'
                : cause.message === 'graph-non-json' ? 'الخادم أعاد صفحة HTML بدل بيانات JSON؛ تحقق من مسار graph وإعدادات الاستضافة.'
                : cause.message === 'graph-invalid-payload' || cause.message === 'cursor' ? 'استجابة بيانات الشجرة غير متوافقة؛ حدّث ملفات التطبيق وامسح ذاكرته المؤقتة.'
                : 'تعذر الاتصال بالخادم؛ تحقق من رابط الطلب في Network.';
            progress.textContent = `التحميل غير مكتمل${cause.status ? ` (HTTP ${cause.status})` : ''}؛ ${reason}`;
            return;
        }
        if (initialBranch) modeSelect.value = 'isolated';
        relayout(); initialView(); updateViewButtons(); updateHistoryButtons();
        const params = new URLSearchParams(location.search);
        const person = Number(params.get('person') || 0);
        if (person && idx.byId.has(person)) {
            const mode = params.get('view');
            if (mode === 'ancestors' || mode === 'descendants') { view = {mode, focus: person}; relayout(); }
            select(person, {push: false});
            history.replaceState({person, view: view.mode, index: 0}, '', location.href);
        }
        const q = params.get('q');
        if (q && q.trim().length >= 2) { input.value = q.trim().slice(0, 80); input.dispatchEvent(new Event('input')); }
    }
    $('[data-tree-retry]').addEventListener('click', start);
    document.fonts?.ready.then(() => { if (data.nodes.length) relayout(); });
    window.addEventListener('pageshow', event => { if (event.persisted) start(); });
    start();
})();
