(() => {
    const app = document.querySelector('[data-tree-app]');
    if (!app) return;
    const $ = selector => app.querySelector(selector);
    const svg = $('[data-tree-svg]'), viewport = $('[data-tree-viewport]'), stage = $('[data-tree-stage]');
    const loading = $('[data-tree-loading]'), empty = $('[data-tree-empty]'), error = $('[data-tree-error]');
    const partial = $('[data-tree-partial]'), panel = $('[data-person-panel]');
    const branch = $('[data-branch-select]'), mode = $('[data-focus-mode]');
    const ns = 'http://www.w3.org/2000/svg';
    let graph = {nodes: [], edges: []}, collapsed = new Set(), selected = null;
    let scale = 1, x = 40, y = 40, positions = new Map(), sequence = 0, controller;
    let currentContext = '', loadedContext = '', lastOperation = () => load(), moved = false;
    const contexts = new Map(), pointers = new Map();
    const transform = () => viewport.setAttribute('transform', `translate(${x} ${y}) scale(${scale})`);
    const state = active => [loading, empty, error].forEach(el => el.hidden = el !== active);
    const contextKey = () => `${branch.value}:${mode.value}`;
    const endpoint = (base, extra = {}, includeBranch = true) => {
        const url = new URL(base, location.href);
        url.searchParams.set('mode', mode.value);
        if (includeBranch && branch.value) url.searchParams.set('branch', branch.value);
        Object.entries(extra).forEach(([key, value]) => url.searchParams.set(key, value));
        return url;
    };
    function saveContext() {
        if (currentContext && currentContext === loadedContext) contexts.set(currentContext, {graph, collapsed: new Set(collapsed), selected, scale, x, y});
    }
    function merge(payload) {
        const nodes = new Map(graph.nodes.map(node => [node.id, node]));
        payload.nodes.forEach(node => nodes.set(node.id, {...node, status: nodes.get(node.id)?.status || node.status}));
        const edges = new Map(graph.edges.map(edge => [edge.id, edge]));
        payload.edges.forEach(edge => edges.set(edge.id, edge));
        graph = {...payload, nodes: [...nodes.values()], edges: [...edges.values()]};
    }
    async function load({root = null, append = false, after = 0, focus = null, fresh = false} = {}) {
        const key = contextKey();
        if (key !== currentContext) {
            saveContext(); currentContext = key;
            const cached = contexts.get(key);
            if (cached && !fresh && root === null) {
                controller?.abort(); sequence++;
                ({graph, collapsed, selected, scale, x, y} = cached); loadedContext = key;
                state(graph.nodes.length ? null : empty);
                $('[data-person-load]').disabled = false;
                $('[data-tree-progress]').textContent = `اكتمل التحميل: ${graph.nodes.length} شخص`;
                render(); updatePanel(); return;
            }
            collapsed = new Set(); selected = null; panel.hidden = true;
        }
        const ticket = ++sequence;
        controller?.abort(); controller = new AbortController();
        const anchor = append ? positions.get(root) : null;
        lastOperation = () => load({root, append, after, focus, fresh});
        loadedContext = '';
        state(loading);
        $('[data-tree-progress]').textContent = 'جارٍ تحميل جميع السجلات تلقائيًا…';
        partial.hidden = true;
        $('[data-person-load]').disabled = true;
        try {
            let cursor = 0, payload;
            graph = {nodes: [], edges: []};
            do {
                const response = await fetch(endpoint(app.dataset.graphUrl, {all: 1, cursor, limit: 160}, false), {headers: {Accept: 'application/json'}, signal: controller.signal});
                if (!response.ok) throw new Error('graph');
                payload = await response.json();
                if (ticket !== sequence) return;
                merge(payload);
                partial.hidden = false;
                partial.textContent = `جارٍ تحميل الشجرة تلقائيًا: ${graph.nodes.length} / ${payload.meta.total}`;
                $('[data-tree-progress]').textContent = partial.textContent;
                render();
                if (!payload.meta.complete && (!payload.meta.next_cursor || payload.meta.next_cursor <= cursor)) throw new Error('cursor');
                cursor = payload.meta.next_cursor;
            } while (!payload.meta.complete);
            const branchRoot = Number(branch.selectedOptions[0]?.dataset.root || 0);
            if (branchRoot) {
                const parents = new Map(graph.edges.map(e => [e.child_id, e.parent_id]));
                const children = new Map(); graph.edges.forEach(e => children.set(e.parent_id, [...(children.get(e.parent_id) || []), e.child_id]));
                const path = new Set(), descendants = new Set(), queue = [branchRoot];
                let id = branchRoot; while (id && !path.has(id)) { path.add(id); id = parents.get(id); }
                for (let i = 0; i < queue.length; i++) { const next = queue[i]; if (descendants.has(next)) continue; descendants.add(next); queue.push(...(children.get(next) || [])); }
                graph.nodes = graph.nodes.filter(n => mode.value !== 'isolated' || path.has(n.id) || descendants.has(n.id)).map(n => ({...n, status: n.id === branchRoot ? 'branch_root' : path.has(n.id) ? 'ancestor_path' : descendants.has(n.id) ? 'branch_descendant' : 'dimmed'}));
                const ids = new Set(graph.nodes.map(n => n.id)); graph.edges = graph.edges.filter(e => ids.has(e.child_id) && ids.has(e.parent_id));
            }
            partial.hidden = true;
            $('[data-tree-progress]').textContent = `اكتمل التحميل: ${graph.nodes.length} شخص — جميع الفروع مفتوحة افتراضيًا`;
            loadedContext = key;
            state(graph.nodes.length ? null : empty);
            render();
            if (append && anchor && positions.has(root)) {
                const next = positions.get(root); x += (anchor.x-next.x)*scale; y += (anchor.y-next.y)*scale; transform();
            } else if (!append) {
                const rootPosition = positions.get(payload.root_id);
                scale = .85; x = stage.clientWidth/2-((rootPosition?.x || 0)+120)*scale; y = 30; transform();
            }
            if (focus !== null) {
                reveal(focus); selected = focus; render(); center(focus);
            }
            updatePanel();
        } catch (cause) {
            if (ticket === sequence && cause.name !== 'AbortError') { state(error); $('[data-tree-progress]').textContent = 'التحميل غير مكتمل؛ أعد المحاولة لعرض جميع السجلات.'; }
        } finally {
            if (ticket === sequence) $('[data-person-load]').disabled = false;
        }
    }
    function visibleNodes() {
        const hidden = new Set(), children = new Map();
        graph.edges.forEach(edge => children.set(edge.parent_id, [...(children.get(edge.parent_id) || []), edge.child_id]));
        const hide = id => (children.get(id) || []).forEach(child => { if (hidden.has(child)) return; hidden.add(child); hide(child); });
        collapsed.forEach(hide);
        return graph.nodes.filter(node => !hidden.has(node.id));
    }
    const measure = document.createElement('canvas').getContext('2d');
    function nameLines(name) {
        measure.font = '700 13px Cairo, Tahoma, sans-serif';
        const lines = [''];
        for (const word of name.split(/\s+/)) {
            if (measure.measureText(`${lines.at(-1)} ${word}`).width <= 216) lines[lines.length-1] = `${lines.at(-1)} ${word}`.trim();
            else if (measure.measureText(word).width <= 216) lines.push(word);
            else for (const letter of word) {
                if (measure.measureText(lines.at(-1)+letter).width > 216) lines.push('');
                lines[lines.length-1] += letter;
            }
        }
        return lines.filter(Boolean);
    }
    function render() {
            const legend = $('[data-attribute-legend]'); legend.replaceChildren();
            const attributes = new Map(graph.nodes.flatMap(n => n.attributes || []).map(a => [a.id, a]));
            attributes.forEach(a => { const badge = document.createElement('span'); badge.className = 'person-attribute'; badge.textContent = a.label; badge.style.backgroundColor = a.color; badge.style.color = a.text_color; legend.append(badge); });
        viewport.replaceChildren();
        const nodes = visibleNodes(), byId = new Map(nodes.map(node => [node.id, node]));
        const children = new Map(), parents = new Map(), boxes = new Map();
        graph.edges.forEach(edge => {
            if (!byId.has(edge.child_id) || !byId.has(edge.parent_id)) return;
            if (parents.has(edge.child_id)) return;
            parents.set(edge.child_id, edge.parent_id);
            children.set(edge.parent_id, [...(children.get(edge.parent_id) || []), edge.child_id]);
        });
        nodes.forEach(node => { const lines = nameLines(node.name); boxes.set(node.id, {lines, height: 42+lines.length*19+(node.attributes || []).length*25}); });
        const widths = new Map(), levelHeights = [], depths = new Map();
        const width = (id, depth = 0, seen = new Set()) => {
            if (seen.has(id)) return 240;
            seen.add(id); depths.set(id, depth); levelHeights[depth] = Math.max(levelHeights[depth] || 0, boxes.get(id).height);
            const list = children.get(id) || [];
            const result = Math.max(240, list.reduce((sum, child) => sum+width(child, depth+1, new Set(seen)), 0)+Math.max(0,list.length-1)*30);
            widths.set(id, result); return result;
        };
        const roots = nodes.filter(node => !parents.has(node.id)); roots.forEach(node => width(node.id));
        const offsets = [0]; levelHeights.forEach((height, i) => offsets[i+1] = offsets[i]+height+65);
        positions = new Map();
        const place = (id, left) => {
            if (positions.has(id)) return;
            positions.set(id, {x: left+(widths.get(id)-240)/2, y: offsets[depths.get(id)], height: boxes.get(id).height});
            (children.get(id) || []).forEach(child => { place(child, left); left += widths.get(child)+30; });
        };
        let left = 0; roots.forEach(node => { place(node.id, left); left += widths.get(node.id)+60; });
        graph.edges.forEach(edge => {
            const a = positions.get(edge.parent_id), b = positions.get(edge.child_id);
            if (!a || !b) return;
            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', `M${a.x+120},${a.y+a.height} C${a.x+120},${a.y+a.height+35} ${b.x+120},${b.y-35} ${b.x+120},${b.y}`);
            path.setAttribute('class', 'tree-edge'); viewport.append(path);
        });
        nodes.forEach(node => {
            const p = positions.get(node.id); if (!p) return;
            const group = document.createElementNS(ns, 'g'), rect = document.createElementNS(ns, 'rect');
            group.setAttribute('transform', `translate(${p.x} ${p.y})`);
            group.setAttribute('class', `tree-svg-node tree-svg-node--${node.status}${selected===node.id ? ' is-selected' : ''}`);
            group.dataset.id = node.id; group.setAttribute('role', 'button'); group.setAttribute('tabindex', '0'); group.setAttribute('aria-label', node.name);
            const title = document.createElementNS(ns, 'title'); title.textContent = node.name;
            rect.setAttribute('width', 240); rect.setAttribute('height', p.height); rect.setAttribute('rx', 14);
            group.append(title, rect);
            boxes.get(node.id).lines.forEach((line, index) => {
                const text = document.createElementNS(ns, 'text');
                text.setAttribute('x', 120); text.setAttribute('y', 24+index*19); text.setAttribute('text-anchor', 'middle'); text.setAttribute('direction', 'rtl');
                text.textContent = line; group.append(text);
            });
            (node.attributes || []).forEach((attribute, index) => {
                const row = document.createElementNS(ns, 'g');
                const background = document.createElementNS(ns, 'rect');
                const label = document.createElementNS(ns, 'text');
                const top = 30 + boxes.get(node.id).lines.length*19 + index*25;
                background.setAttribute('x', 12); background.setAttribute('y', top-13);
                background.setAttribute('width', 216); background.setAttribute('height', 22); background.setAttribute('rx', 6);
                background.style.fill = attribute.color;
                label.setAttribute('x', 120); label.setAttribute('y', top+2);
                label.setAttribute('text-anchor', 'middle'); label.setAttribute('direction', 'rtl');
                label.style.fill = attribute.text_color; label.style.fontSize = '11px';
                label.textContent = attribute.label.length > 28 ? attribute.label.slice(0,28)+'…' : attribute.label;
                const full = document.createElementNS(ns, 'title'); full.textContent = attribute.label;
                row.append(full, background, label); group.append(row);
            });
            const badge = document.createElementNS(ns, 'text'); badge.setAttribute('x', 120); badge.setAttribute('y', p.height-12);
            badge.setAttribute('text-anchor', 'middle'); badge.setAttribute('class', 'tree-node-meta');
            badge.textContent = collapsed.has(node.id) ? 'ذرية مطوية' : ({verified: 'موثق ومراجع', sourced: 'مسند إلى مصدر', pending_review: 'رواية مقدمة؛ قيد المراجعة', disputed: 'مختلف عليه', unresolved: 'غير محسوم'})[node.verification] || 'غير موثق';
            group.append(badge);
            group.addEventListener('click', () => { if (!moved) select(node.id); });
            group.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(node.id); } });
            viewport.append(group);
        });
        transform();
    }
    function reveal(id) {
        const parents = new Map(graph.edges.map(edge => [edge.child_id, edge.parent_id])), seen = new Set();
        while (parents.has(id) && !seen.has(id)) { seen.add(id); id = parents.get(id); collapsed.delete(id); }
    }
    function center(id) {
        const p = positions.get(id); if (!p) return;
        x = stage.clientWidth/2-(p.x+120)*scale; y = stage.clientHeight/2-(p.y+p.height/2)*scale; transform();
    }
    function select(id) {
        if (loadedContext !== currentContext || !loading.hidden) return;
        selected = id; render(); updatePanel();
    }
    function updatePanel() {
        const node = graph.nodes.find(node => node.id === selected);
        panel.hidden = !node; if (!node) return;
        $('[data-person-name]').textContent = node.name;
        $('[data-person-status]').textContent = ({verified: 'موثق', sourced: 'مسند إلى مصدر', pending_review: 'قيد المراجعة', disputed: 'مختلف عليه', rejected: 'مرفوض'})[node.verification] || 'غير موثق';
        $('[data-person-link]').href = node.url;
        const descriptors = $('[data-person-attributes]');
        if (descriptors) {
            descriptors.replaceChildren();
            (node.attributes || []).forEach(attribute => {
                const badge = document.createElement('span');
                badge.className = 'person-attribute'; badge.textContent = attribute.label;
                badge.style.setProperty('--attribute-color', attribute.color);
                badge.style.setProperty('--attribute-ink', attribute.text_color);
                descriptors.append(badge);
            });
        }

        for (const [selector, url] of [['[data-person-add-child]', node.add_child_url], ['[data-person-edit]', node.edit_url]]) {
            const link = $(selector);
            if (link) { link.hidden = !url; if (url) link.href = url; else link.removeAttribute('href'); }
        }
        const more = $('[data-person-load]'); more.hidden = !node.has_more_children;
        more.onclick = () => {
            const ids = graph.edges.filter(edge => edge.parent_id === node.id).map(edge => edge.child_id);
            load({root: node.id, after: Math.max(0, ...ids), append: true});
        };
        const toggle = $('[data-person-toggle]'); toggle.textContent = collapsed.has(node.id) ? 'توسيع الذرية' : 'طي الذرية';
        toggle.onclick = () => { collapsed.has(node.id) ? collapsed.delete(node.id) : collapsed.add(node.id); render(); updatePanel(); };
    }
    function fit() {
        if (!graph.nodes.length) return;
        const box = viewport.getBBox(); if (!box.width) return;
        scale = Math.max(.001, Math.min(.95, (stage.clientWidth-40)/box.width, (stage.clientHeight-40)/box.height));
        x = (stage.clientWidth-box.width*scale)/2-box.x*scale; y = (stage.clientHeight-box.height*scale)/2-box.y*scale; transform();
    }
    function zoom(factor, px = stage.clientWidth/2, py = stage.clientHeight/2) {
        const next = Math.max(.001, Math.min(3, scale*factor));
        x = px-(px-x)*next/scale; y = py-(py-y)*next/scale; scale = next; transform();
    }
    app.querySelectorAll('[data-tree-action]').forEach(button => button.addEventListener('click', () => {
        const action = button.dataset.treeAction;
        if (action === 'zoom-in') zoom(1.3);
        if (action === 'zoom-out') zoom(1/1.3);
        if (action === 'fit') fit();
        if (action === 'reset') { collapsed.clear(); selected = null; load({fresh: true}); }
    }));
    svg.addEventListener('wheel', event => {
        event.preventDefault(); const box = svg.getBoundingClientRect(); zoom(event.deltaY < 0 ? 1.12 : 1/1.12, event.clientX-box.left, event.clientY-box.top);
    }, {passive: false});
    svg.addEventListener('pointerdown', event => {
        if (event.button && event.pointerType === 'mouse') return;
        if (!pointers.size) moved = false;
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY}); event.target.setPointerCapture(event.pointerId);
    });
    svg.addEventListener('pointermove', event => {
        const previous = pointers.get(event.pointerId); if (!previous) return;
        const oldPoints = [...pointers.values()];
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        const nextPoints = [...pointers.values()];
        const dx = event.clientX-previous.x, dy = event.clientY-previous.y;
        if (Math.abs(dx)+Math.abs(dy) > 2) moved = true;
        if (pointers.size === 2) {
            moved = true;
            const distance = points => Math.hypot(points[0].x-points[1].x, points[0].y-points[1].y);
            const oldDistance = distance(oldPoints); if (oldDistance < 1) return;
            const box = svg.getBoundingClientRect();
            const mx = (oldPoints[0].x+oldPoints[1].x)/2-box.left, my = (oldPoints[0].y+oldPoints[1].y)/2-box.top;
            zoom(distance(nextPoints)/oldDistance, mx, my); x += dx/2; y += dy/2; transform();
        } else { x += dx; y += dy; transform(); }
    });
    const release = event => { pointers.delete(event.pointerId); if (svg.hasPointerCapture(event.pointerId)) svg.releasePointerCapture(event.pointerId); };
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(type => svg.addEventListener(type, release));
    const input = $('[data-tree-search]'), results = $('[data-tree-search-results]');
    let timer, searchSequence = 0, searchController;
    const clearSearch = () => { clearTimeout(timer); searchSequence++; searchController?.abort(); results.hidden = true; };
    [branch, mode].forEach(control => control.addEventListener('change', () => { clearSearch(); load(); }));
    $('[data-tree-retry]').addEventListener('click', () => lastOperation());
    $('[data-person-close]').addEventListener('click', () => { selected = null; panel.hidden = true; render(); });
    input.addEventListener('input', () => {
        clearSearch(); const q = input.value.trim(); if (q.length < 2) return;
        const ticket = searchSequence;
        timer = setTimeout(async () => {
            searchController = new AbortController();
            try {
                const response = await fetch(endpoint(app.dataset.searchUrl, {q}, false), {headers: {Accept: 'application/json'}, signal: searchController.signal});
                if (!response.ok) throw new Error('search');
                const payload = await response.json(); if (ticket !== searchSequence) return;
                results.replaceChildren(...payload.data.map(item => {
                    const button = document.createElement('button'); button.type = 'button'; button.textContent = item.lineage ? `${item.text} ${item.lineage}` : item.text;
                    button.onclick = () => {
                        clearSearch();
                        if (graph.nodes.some(node => node.id === item.id)) { reveal(item.id); select(item.id); center(item.id); }
                        else { branch.value = ''; load({root: item.id, focus: item.id}); }
                    }; return button;
                }));
                if (!payload.data.length) results.textContent = 'لا توجد نتائج.';
                results.hidden = false;
            } catch (cause) { if (ticket === searchSequence && cause.name !== 'AbortError') { results.textContent = 'تعذر البحث. حاول مجددًا.'; results.hidden = false; } }
        }, 250);
    });
    document.fonts?.ready.then(() => { if (graph.nodes.length) render(); });
    new ResizeObserver(() => transform()).observe(stage);
    load();
    window.addEventListener('pageshow', event => {
        if (event.persisted) { contexts.clear(); load({fresh: true}); }
    });
})();
