/*
 * Juthoor tree layout (UX-P0.3). Pure functions, no DOM: used by genealogy-tree.js in the browser and by
 * tests/js/tree-layout.test.cjs in Node.
 *
 * Presentation only — the genealogy graph is never changed:
 *  - every visible person is placed exactly once (a card, a row of a compact chain, or a cell of a sibling grid);
 *  - every visible father edge is drawn (between units, or as the line inside a chain);
 *  - a compact chain is a run of ≥ CHAIN_MIN consecutive people that each have exactly one *visible* child and are not
 *    founders, the root, collapsed or the view's focus; the run keeps the exact parent → child order;
 *  - a sibling grid groups ≥ GRID_MIN children of one father that have no visible children of their own;
 *  - a father whose children would be wider than S.wrap places them in rows beside a connector corridor.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.JuthoorTreeLayout = factory();
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';
    const S = {card: 212, founder: 272, chain: 248, leaf: 170, gapX: 24, gapY: 52, row: 30, chainHead: 34, gridGap: 10, fold: 9, foldKeep: 3, wrap: 3200, corridor: 28};
    const CHAIN_MIN = 3, GRID_MIN = 5;

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
            seen.add(current); path.unshift(current); current = idx.parent.get(current);
        }
        return path;
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

    /**
     * Unit tree: {kind: 'card', id, children} | {kind: 'chain', ids, children} | {kind: 'grid', parent, ids}.
     * options: {collapsed, expanded (chain keys), keep (ids that must stay full cards), measure(node, kind) → {w, h}}.
     */
    function units(idx, vis, options = {}) {
        const keep = options.keep || new Set(), collapsed = options.collapsed || new Set();
        const kids = id => visibleChildren(idx, vis.ids, id);
        const chainable = id => kids(id).length === 1 && !collapsed.has(id) && !keep.has(id) && !idx.byId.get(id).founder;
        const roots = [...vis.ids].filter(id => !vis.ids.has(idx.parent.get(id))).sort((a, b) => a - b);
        const placed = new Set();
        const build = id => {
            if (placed.has(id)) return null;
            if (chainable(id)) {
                const run = [];
                let current = id;
                while (chainable(current) && !placed.has(current)) { run.push(current); current = kids(current)[0]; }
                if (run.length >= CHAIN_MIN) {
                    run.forEach(member => placed.add(member));
                    const next = build(current);
                    return {kind: 'chain', key: 'chain-' + run[0], ids: run, children: next ? [next] : []};
                }
            }
            placed.add(id);
            const list = kids(id);
            const leaves = list.filter(child => kids(child).length === 0 && !keep.has(child) && !idx.byId.get(child).founder && !collapsed.has(child));
            if (list.length >= GRID_MIN && leaves.length === list.length) {
                list.forEach(child => placed.add(child));
                return {kind: 'card', id, children: [{kind: 'grid', key: 'grid-' + id, parent: id, ids: list}]};
            }
            return {kind: 'card', id, children: list.map(build).filter(Boolean)};
        };
        return roots.map(build).filter(Boolean);
    }

    /** Rows of a chain: all members, or first/last members around a fold when long and not expanded. */
    function chainRows(unit, expanded = new Set()) {
        if (unit.ids.length <= S.fold || expanded.has(unit.key)) return unit.ids.map(id => ({id}));
        const head = unit.ids.slice(0, S.foldKeep), tail = unit.ids.slice(-S.foldKeep);
        return [...head.map(id => ({id})), {fold: unit.ids.length - head.length - tail.length, hidden: unit.ids.slice(S.foldKeep, -S.foldKeep)}, ...tail.map(id => ({id}))];
    }

    function gridShape(count) {
        const cols = Math.min(4, Math.max(2, Math.ceil(Math.sqrt(count * 1.5))));
        return {cols, rows: Math.ceil(count / cols)};
    }

    /**
     * Positions everything (left-to-right; the renderer mirrors x for RTL). Returns items to draw, connector paths,
     * a person → rectangle map and the total size.
     */
    function layout(idx, unitList, options = {}) {
        const measure = options.measure || ((node, kind) => ({w: kind === 'founder' ? S.founder : S.card, h: kind === 'founder' ? 120 : 76}));
        const expanded = options.expanded || new Set();
        const leafHeight = options.leafHeight || 58;
        const size = new Map();
        const box = unit => {
            if (unit.kind === 'card') {
                const node = idx.byId.get(unit.id);
                return measure(node, node.founder ? 'founder' : 'card');
            }
            if (unit.kind === 'chain') return {w: S.chain, h: S.chainHead + chainRows(unit, expanded).length * S.row + 8};
            const {cols, rows} = gridShape(unit.ids.length);
            return {w: cols * S.leaf + (cols - 1) * S.gridGap, h: rows * leafHeight + (rows - 1) * S.gridGap};
        };
        const wrap = options.wrapWidth || S.wrap;
        const measureTree = unit => {
            const own = box(unit);
            const childSizes = (unit.children || []).map(measureTree);
            const childrenWidth = childSizes.reduce((sum, child) => sum + child.width, 0) + Math.max(0, childSizes.length - 1) * S.gapX;
            let result = {own, width: Math.max(own.w, childrenWidth), height: own.h + (childSizes.length ? S.gapY + Math.max(...childSizes.map(child => child.height)) : 0), childrenWidth};
            // Wide families wrap into rows beside a connector corridor, keeping descendants grouped under their father.
            if (childSizes.length > 1 && childrenWidth > wrap) {
                const rows = [];
                let row = {items: [], width: 0, height: 0};
                childSizes.forEach((child, i) => {
                    const added = row.items.length ? S.gapX + child.width : child.width;
                    if (row.items.length && row.width + added > wrap - S.corridor) { rows.push(row); row = {items: [], width: 0, height: 0}; }
                    row.width += row.items.length ? S.gapX + child.width : child.width;
                    row.items.push(i); row.height = Math.max(row.height, child.height);
                });
                rows.push(row);
                result = {own, rows, width: Math.max(own.w, S.corridor + Math.max(...rows.map(r => r.width))), height: own.h + rows.reduce((sum, r) => sum + S.gapY + r.height, 0), childrenWidth};
            }
            size.set(unit, result);
            return result;
        };
        const items = [], links = [], rects = new Map();
        const place = (unit, left, top) => {
            const s = size.get(unit);
            const x = left + (s.width - s.own.w) / 2;
            const item = {kind: unit.kind, key: unit.key || 'card-' + unit.id, x, y: top, w: s.own.w, h: s.own.h};
            if (unit.kind === 'card') {
                item.id = unit.id; rects.set(unit.id, {x, y: top, w: s.own.w, h: s.own.h, item});
            } else if (unit.kind === 'chain') {
                item.ids = unit.ids; item.rows = chainRows(unit, expanded);
                item.rows.forEach((row, i) => {
                    const rect = {x, y: top + S.chainHead + i * S.row, w: s.own.w, h: S.row, item};
                    if (row.id !== undefined) rects.set(row.id, rect);
                    else row.hidden.forEach(id => rects.set(id, {...rect, folded: true}));
                });
            } else {
                const {cols} = gridShape(unit.ids.length);
                item.parent = unit.parent;
                item.cells = unit.ids.map((id, i) => {
                    const cell = {id, x: x + (i % cols) * (S.leaf + S.gridGap), y: top + Math.floor(i / cols) * (leafHeight + S.gridGap), w: S.leaf, h: leafHeight};
                    rects.set(id, {...cell, item});
                    return cell;
                });
            }
            items.push(item);
            const children = unit.children || [];
            const anchors = [];
            if (s.rows) {
                let rowTop = top + s.own.h + S.gapY;
                s.rows.forEach(row => {
                    let childLeft = left + S.corridor;
                    row.items.forEach(i => {
                        const placed = place(children[i], childLeft, rowTop);
                        anchors.push({x: placed.x + placed.w / 2, y: rowTop, barY: rowTop - S.gapY / 2, verification: firstVerification(idx, children[i])});
                        childLeft += size.get(children[i]).width + S.gapX;
                    });
                    rowTop += row.height + S.gapY;
                });
                links.push({from: {x: x + s.own.w / 2, y: top + s.own.h}, to: anchors, barY: top + s.own.h + S.gapY / 2, spineX: left + S.corridor / 2});
                return item;
            }
            let childLeft = left + (s.width - s.childrenWidth) / 2;
            const childTop = top + s.own.h + S.gapY;
            children.forEach(child => {
                const placed = place(child, childLeft, childTop);
                anchors.push({x: placed.x + placed.w / 2, y: childTop, verification: firstVerification(idx, child)});
                childLeft += size.get(child).width + S.gapX;
            });
            if (anchors.length) links.push({from: {x: x + s.own.w / 2, y: top + s.own.h}, to: anchors, barY: top + s.own.h + S.gapY / 2});
            return item;
        };
        let left = 0, total = {width: 0, height: 0};
        unitList.forEach(unit => {
            const s = measureTree(unit);
            place(unit, left, 0);
            left += s.width + S.gapX * 2;
            total = {width: left - S.gapX * 2, height: Math.max(total.height, s.height)};
        });
        return {items, links, rects, width: Math.max(0, total.width), height: total.height};
    }

    /** Verification of the father edge entering a unit (for connector styling). */
    function firstVerification(idx, unit) {
        const id = unit.kind === 'card' ? unit.id : unit.kind === 'chain' ? unit.ids[0] : unit.ids[0];
        return idx.edgeOf.get(id)?.verification || null;
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

    return {S, CHAIN_MIN, GRID_MIN, index, pathTo, descendants, visible, units, chainRows, layout, chainOf, placedIds, gridShape};
});
