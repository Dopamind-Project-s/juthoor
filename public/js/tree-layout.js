/*
 * Juthoor hybrid genealogy layout (UX-P0.7), shared by the existing SVG renderer and Node tests.
 * Default: individually visible serpentine root ancestry, followed by iterative subtree-width-aware generation rows.
 * Siblings never wrap. Founder metadata controls expansion boundaries, never a person's genealogical generation.
 * Optional compact presentation folds ancestry only; descendant rows never wrap in either presentation.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.JuthoorTreeLayout = factory();
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';
    const S = {card: 212, founder: 272, chain: 248, gapX: 24, gapY: 52, row: 30, chainHead: 34, fold: 9, foldKeep: 3, ancestryWidth: 1400};
    const CHAIN_MIN = 3;

    /** Parents and ordered children from the edges (one primary father per person; edges in id order). */
    function index(nodes, edges) {
        const byId = new Map(nodes.map(node => [node.id, node]));
        const parent = new Map(), children = new Map(), edgeOf = new Map();
        [...edges].sort((a, b) => a.id - b.id).forEach(edge => {
            if (!byId.has(edge.child_id) || !byId.has(edge.parent_id) || parent.has(edge.child_id) || edge.child_id === edge.parent_id) return;
            parent.set(edge.child_id, edge.parent_id);
            edgeOf.set(edge.child_id, edge);
            if (!children.has(edge.parent_id)) children.set(edge.parent_id, []);
            children.get(edge.parent_id).push(edge.child_id);
        });
        return {byId, parent, children, edgeOf, nodes};
    }

    /** Root → id, following fathers (cycle-safe). */
    function pathTo(idx, id) {
        const path = [], seen = new Set();
        let current = id;
        while (current !== undefined && current !== null && !seen.has(current) && idx.byId.has(current)) {
            seen.add(current); path.push(current); current = idx.parent.get(current);
        }
        return path.reverse();
    }

    function descendants(idx, id) {
        const found = new Set([id]), queue = [id];
        for (let i = 0; i < queue.length; i++) (idx.children.get(queue[i]) || []).forEach(child => { if (!found.has(child)) { found.add(child); queue.push(child); } });
        return found;
    }

    /**
     * People shown for a view, with their state. view: {mode: 'full'|'ancestors'|'descendants', focus,
     * branchRoot, branchPath, isolated}. Collapsed people keep their card; their descendants are hidden.
     */
    function visible(idx, view = {}, collapsed = new Set()) {
        let ids;
        const state = new Map();
        const focus = view.focus !== undefined && idx.byId.has(view.focus) ? view.focus : null;
        if (view.mode === 'ancestors' && focus !== null) {
            ids = new Set(pathTo(idx, focus));
            ids.forEach(id => state.set(id, 'ancestor_path'));
        } else if (view.mode === 'descendants' && focus !== null) {
            const below = descendants(idx, focus);
            ids = new Set([...pathTo(idx, focus), ...below]);
            ids.forEach(id => state.set(id, below.has(id) ? 'branch_descendant' : 'ancestor_path'));
        } else {
            ids = new Set(idx.byId.keys());
        }
        if (view.branchRoot && idx.byId.has(view.branchRoot) && view.mode !== 'ancestors' && view.mode !== 'descendants') {
            const path = new Set((view.branchPath && view.branchPath.length ? view.branchPath.filter(id => idx.byId.has(id)) : pathTo(idx, view.branchRoot)));
            const below = descendants(idx, view.branchRoot);
            if (view.isolated) ids = new Set([...ids].filter(id => path.has(id) || below.has(id)));
            ids.forEach(id => state.set(id, id === view.branchRoot ? 'branch_root' : path.has(id) ? 'ancestor_path' : below.has(id) ? 'branch_descendant' : 'dimmed'));
        }
        if (focus !== null) state.set(focus, state.get(focus) === 'branch_root' ? 'branch_root' : 'focus');
        collapsed.forEach(id => {
            if (!ids.has(id)) return;
            descendants(idx, id).forEach(child => { if (child !== id) ids.delete(child); });
        });
        ids.forEach(id => { if (!state.has(id)) state.set(id, 'visible'); });
        return {ids, state};
    }

    const visibleChildren = (idx, ids, id) => (idx.children.get(id) || []).filter(child => ids.has(child));

    /** Rows of a chain: all members, or first/last members around a fold when long and not expanded. */
    function chainRows(unit, expanded = new Set()) {
        if (unit.ids.length <= S.fold || expanded.has(unit.key)) return unit.ids.map(id => ({id}));
        const head = unit.ids.slice(0, S.foldKeep), tail = unit.ids.slice(-S.foldKeep);
        return [...head.map(id => ({id})), {fold: unit.ids.length - head.length - tail.length, hidden: unit.ids.slice(S.foldKeep, -S.foldKeep)}, ...tail.map(id => ({id}))];
    }

    /** Chain key containing a person, if any (to expand a fold before focusing). */
    function chainOf(unitList, id) {
        const stack = [...unitList];
        while (stack.length) {
            const unit = stack.pop();
            if (unit.kind === 'chain' && unit.ids.includes(id)) return unit.key;
            (unit.children || []).forEach(child => stack.push(child));
        }
        return null;
    }

    /** All people placed by a layout, for integrity checks. */
    function placedIds(unitList) {
        const ids = [], stack = [...unitList];
        while (stack.length) {
            const unit = stack.pop();
            if (unit.kind === 'card') ids.push(unit.id); else ids.push(...unit.ids);
            (unit.children || []).forEach(child => stack.push(child));
        }
        return ids;
    }

    /** Card-only forest for the default traditional chart. The optional compact presentation keeps UX-P0.3 units. */
    function units(idx, vis, options = {}) {
        const map = new Map([...vis.ids].map(id => [id, {kind: 'card', id, children: []}]));
        const roots = [], visited = new Set();
        const candidates = [...vis.ids].filter(id => !vis.ids.has(idx.parent.get(id)));
        // The fallback also keeps malformed cyclic input finite; normal graphs are already integrity checked server-side.
        for (const id of [...candidates, ...vis.ids]) {
            if (visited.has(id)) continue;
            roots.push(map.get(id)); visited.add(id);
            const stack = [id];
            while (stack.length) {
                const parent = stack.pop();
                for (const child of idx.children.get(parent) || []) {
                    if (!map.has(child) || visited.has(child)) continue;
                    visited.add(child); map.get(parent).children.push(map.get(child)); stack.push(child);
                }
            }
        }
        return roots;
    }

    /** Branch-boundary defaults are independent of scope and explicit manual expansion/collapse. */
    function displayVisible(idx, scope, mode = 'full', opened = new Set(), closed = new Set(), revealed = new Set()) {
        let ids = new Set(scope.ids);
        const keep = new Set();
        const addPath = id => {
            let current = id;
            while (scope.ids.has(current) && !keep.has(current)) {
                keep.add(current); current = idx.parent.get(current);
            }
        };
        if (mode !== 'full') {
            for (const id of scope.ids) {
                const node = idx.byId.get(id), founder = node.founder;
                const boundaries = founder?.boundaries || (founder?.kind === 'fakhd' ? ['main'] : founder?.kind === 'sub_fakhd' ? ['sub'] : []);
                if (boundaries.includes('main') || (mode === 'sub_branches' && boundaries.includes('sub'))) addPath(id);
            }
            // A scope with no recorded branch boundaries stays useful, without inventing a founder.
            if (keep.size) {
                for (const id of revealed) addPath(id);
                const visited = new Set(), queue = [];
                for (const id of opened) if (scope.ids.has(id)) { addPath(id); queue.push(id); }
                for (let i = 0; i < queue.length; i++) {
                    const id = queue[i]; if (visited.has(id)) continue;
                    visited.add(id); keep.add(id);
                    for (const child of idx.children.get(id) || []) if (scope.ids.has(child)) queue.push(child);
                }
                ids = keep;
            }
        }
        // Explicit user collapse wins over defaults, including default paths through another founder.
        if (closed.size) {
            const queue = [], removed = new Set();
            for (const id of closed) if (ids.has(id)) queue.push(...(idx.children.get(id) || []));
            for (let i = 0; i < queue.length; i++) {
                const id = queue[i]; if (removed.has(id)) continue;
                removed.add(id); ids.delete(id); queue.push(...(idx.children.get(id) || []));
            }
        }
        return {ids, state: new Map([...scope.state].filter(([id]) => ids.has(id)))};
    }

    /** Optional folding is restricted to ancestry, never to sibling rows or descendant generation levels. */
    function compactAncestryLayout(idx, unitList, options) {
        const result = layout(idx, unitList, {...options, compact: false, serpentine: false});
        const removed = new Set(), extra = [], extraLinks = [], prefixParents = new Set(), deltas = new Map(), keep = options.keep || new Set();
        const eligibleChildren = unit => options.scopeIds ? (idx.children.get(unit.id) || []).filter(id => options.scopeIds.has(id)).length : unit.children.length;
        for (const root of unitList) {
            const prefix = []; let head = root;
            while (head.children.length === 1 && eligibleChildren(head) === 1) { prefix.push(head); head = head.children[0]; }
            if (!head.children.length && eligibleChildren(head) === 0) prefix.push(head);
            if (prefix.length < CHAIN_MIN) continue;
            const prefixIds = new Set(prefix.map(unit => unit.id)), groups = [];
            for (let i = 0; i < prefix.length;) {
                const unit = prefix[i];
                if (idx.byId.get(unit.id).founder || keep.has(unit.id)) { groups.push({kind: 'card', unit}); i++; continue; }
                const run = [];
                while (i < prefix.length && !idx.byId.get(prefix[i].id).founder && !keep.has(prefix[i].id)) run.push(prefix[i++]);
                if (run.length >= CHAIN_MIN) groups.push({kind: 'chain', ids: run.map(unit => unit.id), key: 'chain-' + run[0].id});
                else groups.push(...run.map(unit => ({kind: 'card', unit})));
            }
            const center = result.rects.get(root.id).x + result.rects.get(root.id).w / 2;
            let top = 0;
            for (const group of groups) {
                if (group.kind === 'card') {
                    const rect = result.rects.get(group.unit.id);
                    group.item = rect.item; group.item.y = top; rect.y = top;
                    group.first = group.last = group.unit.id;
                } else {
                    const rows = chainRows(group, options.expanded), h = S.chainHead + rows.length * S.row + 8;
                    const item = {kind: 'chain', key: group.key, ids: group.ids, rows, x: center - S.chain / 2, y: top, w: S.chain, h};
                    group.item = item; group.first = group.ids[0]; group.last = group.ids.at(-1); extra.push(item);
                    group.ids.forEach(id => removed.add(id));
                    rows.forEach((row, i) => {
                        const rect = {x: item.x, y: top + S.chainHead + i * S.row, w: S.chain, h: S.row, item};
                        if (row.id !== undefined) result.rects.set(row.id, rect);
                        else row.hidden.forEach(id => result.rects.set(id, {...rect, folded: true}));
                    });
                }
                top += group.item.h + S.gapY;
            }
            const below = new Set(); const queue = prefixIds.has(head.id) ? [] : [head];
            for (let i = 0; i < queue.length; i++) { below.add(queue[i].id); queue.push(...queue[i].children); }
            const delta = below.size ? top - result.rects.get(head.id).y : 0;
            for (const id of below) { const rect = result.rects.get(id); rect.y += delta; rect.item.y += delta; deltas.set(id, delta); }
            prefixIds.forEach(id => prefixParents.add(id));
            for (let i = 0; i < groups.length; i++) {
                const group = groups[i], next = groups[i + 1];
                const target = next ? next.item : below.size ? result.rects.get(head.id) : null;
                if (!target) continue;
                const childId = next ? next.first : head.id;
                extraLinks.push({fromId: group.last, from: {x: center, y: group.item.y + group.item.h}, to: [{id: childId, x: center, y: target.y, verification: idx.edgeOf.get(childId)?.verification}], barY: group.item.y + group.item.h + S.gapY / 2});
            }
        }
        result.links = result.links.filter(link => !prefixParents.has(link.fromId));
        for (const link of result.links) {
            const delta = deltas.get(link.fromId) || 0;
            link.from.y += delta; link.barY += delta; link.to.forEach(target => { target.y += delta; });
        }
        result.links.push(...extraLinks);
        result.items = result.items.filter(item => !removed.has(item.id)).concat(extra);
        const minX = result.items.reduce((min, item) => Math.min(min, item.x), 0);
        if (minX < 0) {
            result.items.forEach(item => { item.x -= minX; }); result.rects.forEach(rect => { rect.x -= minX; });
            result.links.forEach(link => { link.from.x -= minX; link.to.forEach(target => { target.x -= minX; }); });
        }
        result.width = result.items.reduce((max, item) => Math.max(max, item.x + item.w), 0);
        result.height = result.items.reduce((max, item) => Math.max(max, item.y + item.h), 0);
        return result;
    }

    /** Linear subtree footprints + generation grid, preceded only by genuinely single-child root chains. */
    function layout(idx, unitList, options = {}) {
        if (options.compact) return compactAncestryLayout(idx, unitList, options);
        const measure = options.measure || ((node, kind) => ({w: kind === 'founder' ? S.founder : S.card, h: kind === 'founder' ? 120 : 76}));
        const items = [], links = [], rects = new Map();
        const eligibleChildren = unit => options.scopeIds ? (idx.children.get(unit.id) || []).filter(id => options.scopeIds.has(id)).length : unit.children.length;
        let forestLeft = 0, height = 0;
        for (const root of unitList) {
            const all = [], stack = [{unit: root, depth: 0}], dimensions = new Map();
            while (stack.length) {
                const entry = stack.pop(); all.push(entry);
                dimensions.set(entry.unit, measure(idx.byId.get(entry.unit.id), idx.byId.get(entry.unit.id).founder ? 'founder' : 'card'));
                for (let i = entry.unit.children.length - 1; i >= 0; i--) stack.push({unit: entry.unit.children[i], depth: entry.depth + 1});
            }
            const chain = []; let head = root;
            while (head.children.length === 1 && eligibleChildren(head) === 1) { chain.push(head); head = head.children[0]; }
            if (!head.children.length && eligibleChildren(head) === 0) chain.push(head);
            if (chain.length < CHAIN_MIN || options.serpentine === false) { chain.length = 0; head = root; }
            const prefixSet = new Set(chain);
            const hierarchy = all.filter(({unit}) => !prefixSet.has(unit));
            const levelHeights = [], widths = new Map();
            for (let i = hierarchy.length - 1; i >= 0; i--) {
                const {unit, depth} = hierarchy[i], own = dimensions.get(unit), level = depth - chain.length;
                levelHeights[level] = Math.max(levelHeights[level] || 0, own.h);
                widths.set(unit, Math.max(own.w, unit.children.reduce((sum, child) => sum + widths.get(child), 0) + Math.max(0, unit.children.length - 1) * S.gapX));
            }
            const maxW = chain.reduce((max, unit) => Math.max(max, dimensions.get(unit).w), S.card);
            const maxH = chain.reduce((max, unit) => Math.max(max, dimensions.get(unit).h), 0);
            const readableWidth = Math.max(maxW, options.ancestryWidth || S.ancestryWidth);
            const columns = Math.max(1, Math.floor((readableWidth + S.gapX) / (maxW + S.gapX)));
            const rows = Math.ceil(chain.length / columns);
            const prefixWidth = Math.min(chain.length, columns) * (maxW + S.gapX) - (chain.length ? S.gapX : 0);
            const prefixHeight = rows ? rows * (maxH + S.gapY) - S.gapY : 0;
            const hierarchyWidth = hierarchy.length ? widths.get(head) : 0;
            const prefixLast = chain.length ? chain.length - 1 : 0;
            const lastRow = Math.floor(prefixLast / columns), lastColumn = lastRow % 2 ? columns - 1 - prefixLast % columns : prefixLast % columns;
            const prefixOffset = hierarchy.length && chain.length ? hierarchyWidth / 2 - (lastColumn * (maxW + S.gapX) + maxW / 2) : 0;
            const minX = Math.min(0, prefixOffset), componentWidth = Math.max(hierarchyWidth, prefixOffset + prefixWidth) - minX;
            const origin = forestLeft - minX;
            const add = (unit, x, y, generation, serpentine = false) => {
                const own = dimensions.get(unit);
                const item = {kind: 'card', key: 'card-' + unit.id, id: unit.id, x, y, w: own.w, h: own.h, generation, serpentine};
                items.push(item); rects.set(unit.id, {...item, item}); height = Math.max(height, y + own.h);
                return item;
            };
            chain.forEach((unit, i) => {
                const row = Math.floor(i / columns), col = row % 2 ? columns - 1 - i % columns : i % columns;
                const own = dimensions.get(unit);
                add(unit, origin + prefixOffset + col * (maxW + S.gapX) + (maxW - own.w) / 2, row * (maxH + S.gapY) + (maxH - own.h) / 2, i, true);
                if (!i) return;
                const prev = rects.get(chain[i - 1].id), next = rects.get(unit.id);
                let from, to;
                if (row === Math.floor((i - 1) / columns)) {
                    const right = next.x > prev.x;
                    from = {x: right ? prev.x + prev.w : prev.x, y: prev.y + prev.h / 2};
                    to = {x: right ? next.x : next.x + next.w, y: next.y + next.h / 2};
                    // Nodes are vertically centered within the row slot before connectors are routed below.
                } else {
                    from = {x: prev.x + prev.w / 2, y: prev.y + prev.h};
                    to = {x: next.x + next.w / 2, y: next.y};
                }
                links.push({fromId: chain[i - 1].id, from, to: [{id: unit.id, ...to, verification: idx.edgeOf.get(unit.id)?.verification}], points: [{...from}, {...to}]});
            });
            const levelY = [chain.length ? prefixHeight + S.gapY : 0];
            for (let i = 1; i < levelHeights.length; i++) levelY[i] = levelY[i - 1] + levelHeights[i - 1] + S.gapY;
            if (hierarchy.length) {
                const place = [{unit: head, left: origin, depth: 0}];
                for (let i = 0; i < place.length; i++) {
                    const {unit, left, depth} = place[i], own = dimensions.get(unit), width = widths.get(unit);
                    const item = add(unit, left + (width - own.w) / 2, levelY[depth], depth + chain.length);
                    const childrenWidth = unit.children.reduce((sum, child) => sum + widths.get(child), 0) + Math.max(0, unit.children.length - 1) * S.gapX;
                    let childLeft = left + (width - childrenWidth) / 2;
                    const targets = [];
                    for (const child of unit.children) {
                        const childWidth = widths.get(child);
                        targets.push({id: child.id, x: childLeft + childWidth / 2, y: levelY[depth + 1], verification: idx.edgeOf.get(child.id)?.verification});
                        place.push({unit: child, left: childLeft, depth: depth + 1}); childLeft += childWidth + S.gapX;
                    }
                    if (targets.length) links.push({fromId: unit.id, from: {x: item.x + item.w / 2, y: item.y + item.h}, to: targets, barY: levelY[depth] + levelHeights[depth] + S.gapY / 2});
                }
                if (chain.length) {
                    const prev = rects.get(chain.at(-1).id), next = rects.get(head.id);
                    links.push({fromId: chain.at(-1).id, from: {x: prev.x + prev.w / 2, y: prev.y + prev.h}, to: [{id: head.id, x: next.x + next.w / 2, y: next.y, verification: idx.edgeOf.get(head.id)?.verification}], barY: prefixHeight + S.gapY / 2});
                }
            }
            forestLeft += componentWidth + S.gapX * 2;
        }
        return {items, links, rects, width: Math.max(0, forestLeft - S.gapX * 2), height};
    }

    return {S, CHAIN_MIN, index, pathTo, descendants, visible, displayVisible, units, chainRows, layout, chainOf, placedIds};
});
