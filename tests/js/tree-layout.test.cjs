// Run with: node --test tests/js/
const test = require('node:test');
const assert = require('node:assert/strict');
const L = require('../../public/js/tree-layout.js');

/** Chain of `chain` people from 1, then `fan` children under the last, each child with `grand` children. */
function shape({chain = 12, fan = 3, grand = 0, leaves = 0, founders = {}} = {}) {
    const nodes = [], edges = [];
    let id = 0, edge = 0;
    const add = (father, extra = {}) => { const n = {id: ++id, name: 'شخص ' + id, ...extra, ...(founders[id + 1] ? {} : {})}; nodes.push(n); if (father) edges.push({id: ++edge, child_id: n.id, parent_id: father, verification: 'verified'}); return n.id; };
    let last = add(null);
    for (let i = 1; i < chain; i++) last = add(last);
    for (let f = 0; f < fan; f++) { const child = add(last); for (let g = 0; g < grand; g++) add(child); }
    for (let l = 0; l < leaves; l++) add(last);
    nodes.forEach(n => { if (founders[n.id]) n.founder = founders[n.id]; });
    return {nodes, edges};
}
const run = (graph, view = {}, options = {}) => {
    const idx = L.index(graph.nodes, graph.edges);
    const vis = L.visible(idx, view, options.collapsed || new Set());
    const unitList = L.units(idx, vis, options);
    return {idx, vis, unitList, result: L.layout(idx, unitList, options)};
};

test('every visible person is placed exactly once and no edge is invented', () => {
    const graph = shape({chain: 20, fan: 4, grand: 3, leaves: 6});
    const {unitList, result, idx} = run(graph);
    const placed = L.placedIds(unitList);
    assert.equal(placed.length, graph.nodes.length);
    assert.equal(new Set(placed).size, graph.nodes.length);
    assert.equal(result.rects.size, graph.nodes.length);
    // Every connector target belongs to a unit whose first person's father is the source unit's last person.
    assert.equal(idx.parent.size, graph.edges.length);
});

test('single-child runs become one compact chain in exact parent → child order; branching ends the chain', () => {
    const {unitList} = run(shape({chain: 12, fan: 3}));
    const chain = unitList[0];
    assert.equal(chain.kind, 'chain');
    // Person 12 has three children: it is a full card, not part of the chain.
    assert.deepEqual(chain.ids, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
    assert.equal(chain.children[0].kind, 'card');
    assert.equal(chain.children[0].id, 12);
});

test('short runs are not compacted; founders, the focus and collapsed people stay full cards', () => {
    assert.equal(run(shape({chain: 3, fan: 2})).unitList[0].kind, 'card');
    const withFounder = run(shape({chain: 12, fan: 2, founders: {6: {classification: 'مؤسس فخذ'}}}));
    const first = withFounder.unitList[0];
    assert.deepEqual(first.ids, [1, 2, 3, 4, 5]);
    assert.equal(first.children[0].id, 6);
    const kept = run(shape({chain: 12, fan: 2}), {}, {keep: new Set([4])});
    assert.deepEqual(kept.unitList[0].ids, [1, 2, 3]);
    assert.equal(kept.unitList[0].children[0].id, 4);
    assert.equal(L.chainOf(kept.unitList, 4), null);
});

test('long chains fold in the middle; expanding restores every row; search finds folded people', () => {
    const {unitList, result} = run(shape({chain: 30, fan: 2}));
    const rows = L.chainRows(unitList[0]);
    assert.equal(rows.filter(row => row.id !== undefined).length, 6);
    assert.equal(rows.find(row => row.fold).fold, 23);
    assert.equal(result.rects.get(15).folded, true);
    assert.equal(L.chainOf(unitList, 15), unitList[0].key);
    const open = run(shape({chain: 30, fan: 2}), {}, {expanded: new Set([unitList[0].key])});
    assert.deepEqual(L.chainRows(open.unitList[0], new Set([unitList[0].key])).map(row => row.id), unitList[0].ids);
    assert.equal(open.result.rects.get(15).folded, undefined);
    // Rows keep chain order top to bottom.
    const ys = unitList[0].ids.map(id => open.result.rects.get(id).y);
    assert.deepEqual([...ys].sort((a, b) => a - b), ys);
});

test('collapse hides descendants but never the collapsed person; expand restores them', () => {
    const graph = shape({chain: 4, fan: 3, grand: 2});
    const collapsed = run(graph, {}, {collapsed: new Set([4])});
    assert.equal(L.placedIds(collapsed.unitList).length, 4);
    assert.equal(L.placedIds(run(graph).unitList).length, graph.nodes.length);
});

test('sibling groups without children become a grid under their father', () => {
    const {unitList, result} = run(shape({chain: 2, fan: 0, leaves: 9}));
    const father = unitList[0].children[0];
    assert.equal(father.id, 2);
    assert.equal(father.children[0].kind, 'grid');
    assert.equal(father.children[0].ids.length, 9);
    const grid = result.items.find(item => item.kind === 'grid');
    assert.ok(grid.w < 9 * L.S.leaf, 'grid is narrower than one row of siblings');
});

test('ancestor, descendant and branch views keep the full ancestor path', () => {
    const graph = shape({chain: 10, fan: 3, grand: 2});
    const idx = L.index(graph.nodes, graph.edges);
    const target = graph.nodes.at(-1).id;
    const anc = L.visible(idx, {mode: 'ancestors', focus: target});
    assert.deepEqual([...anc.ids].sort((a, b) => a - b), L.pathTo(idx, target).sort((a, b) => a - b));
    const desc = L.visible(idx, {mode: 'descendants', focus: 11});
    assert.ok([...L.pathTo(idx, 11)].every(id => desc.ids.has(id)));
    assert.ok([...L.descendants(idx, 11)].every(id => desc.ids.has(id)));
    const isolated = L.visible(idx, {branchRoot: 11, isolated: true});
    assert.ok(L.pathTo(idx, 11).every(id => isolated.ids.has(id) && isolated.state.get(id) !== 'dimmed'));
    assert.ok(isolated.ids.has(12) && !isolated.ids.has(14), 'own descendants kept, sibling branch dropped');
    assert.equal(isolated.state.get(11), 'branch_root');
    // A server-provided path (BranchFocusService) is used as given.
    const served = L.visible(idx, {branchRoot: 11, isolated: true, branchPath: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]});
    assert.equal(served.ids.size, isolated.ids.size);
});

test('layout is overlap-free for cards on the same row', () => {
    const {result} = run(shape({chain: 3, fan: 6, grand: 3}));
    const cards = result.items.filter(item => item.kind !== 'chain');
    for (const a of cards) for (const b of cards) {
        if (a === b) continue;
        const overlap = a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
        assert.equal(overlap, false, `${a.key} overlaps ${b.key}`);
    }
});

test('wide families wrap into rows: bounded width, no overlap, every person placed, connectors in gaps', () => {
    const nodes = [{id: 1, name: 'جد'}], edges = [];
    let id = 1;
    for (let c = 0; c < 14; c++) { const child = ++id; nodes.push({id: child, name: 'ابن ' + child}); edges.push({id: child, child_id: child, parent_id: 1});
        for (let g = 0; g < 3; g++) { const grand = ++id; nodes.push({id: grand, name: 'حفيد ' + grand}); edges.push({id: grand, child_id: grand, parent_id: child});
            for (let k = 0; k < 2; k++) { const kid = ++id; nodes.push({id: kid, name: 'ابن حفيد ' + kid}); edges.push({id: kid, child_id: kid, parent_id: grand}); } } }
    const {unitList, result} = run({nodes, edges});
    assert.equal(L.placedIds(unitList).length, nodes.length);
    assert.ok(result.width <= L.S.wrap + 200, `width ${result.width} stays near the wrap target`);
    const boxes = result.items;
    for (const a of boxes) for (const b of boxes) {
        if (a === b) continue;
        assert.equal(a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h, false, `${a.key} overlaps ${b.key}`);
    }
    const wrapped = result.links.find(link => link.spineX !== undefined);
    assert.ok(wrapped, 'the wide family wraps');
    // The corridor and every row bar lie outside all cards.
    for (const item of boxes) {
        assert.ok(!(wrapped.spineX > item.x && wrapped.spineX < item.x + item.w && item.y > wrapped.from.y), 'spine never crosses a card below the father');
        for (const to of wrapped.to) assert.ok(!(to.barY > item.y && to.barY < item.y + item.h && to.x > item.x && to.x < item.x + item.w), 'row bars run in the gaps');
    }
});

test('scales to 10,000 people in reasonable time', () => {
    const nodes = [{id: 1, name: 'جد'}], edges = [];
    let id = 1;
    for (let i = 2; i <= 10000; i++) { const father = Math.max(1, Math.floor((i - 2) / 3) + 1); nodes.push({id: ++id, name: 'شخص ' + id}); edges.push({id: i, child_id: id, parent_id: father}); }
    const started = process.hrtime.bigint();
    const {unitList} = run({nodes, edges});
    const ms = Number(process.hrtime.bigint() - started) / 1e6;
    assert.equal(L.placedIds(unitList).length, 10000);
    assert.ok(ms < 1500, `layout took ${ms} ms`);
});

test('a person is never placed twice even if two father edges arrive', () => {
    const graph = shape({chain: 4, fan: 3});
    graph.edges.push({id: 999, child_id: 6, parent_id: 2, verification: 'pending_review'});
    const {unitList, idx} = run(graph);
    const placed = L.placedIds(unitList);
    assert.equal(placed.length, graph.nodes.length);
    assert.equal(new Set(placed).size, graph.nodes.length);
    assert.equal(idx.parent.get(6), 4, 'the first (lowest id) primary edge is kept');
});
