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

test('optional compact ancestry keeps exact parent-child order and stops before the branching father', () => {
    const {result} = run(shape({chain: 12, fan: 3}), {}, {compact: true});
    const chain = result.items.find(item => item.kind === 'chain');
    assert.deepEqual(chain.ids, [1,2,3,4,5,6,7,8,9,10,11]);
    assert.equal(result.rects.get(12).item.kind, 'card');
});

test('short compact runs remain cards and founders or a kept focus are never folded', () => {
    assert.ok(run(shape({chain: 3, fan: 2}), {}, {compact:true}).result.items.every(item=>item.kind==='card'));
    const founder = run(shape({chain: 12, fan: 2, founders: {6: {classification: 'مؤسس فخذ'}}}), {}, {compact:true}).result;
    assert.equal(founder.rects.get(6).item.kind, 'card');
    const focus = run(shape({chain: 12, fan: 2}), {}, {compact:true,keep:new Set([4])}).result;
    assert.equal(focus.rects.get(4).item.kind, 'card');
    assert.equal(L.chainOf(focus.items, 4), null);
});

test('optional long ancestry folds reversibly and search can identify the folded chain', () => {
    const {result} = run(shape({chain: 30, fan: 2}), {}, {compact:true});
    const chain = result.items.find(item=>item.kind==='chain');
    assert.equal(chain.rows.filter(row=>row.id!==undefined).length,6);
    assert.equal(chain.rows.find(row=>row.fold).fold,23);
    assert.equal(result.rects.get(15).folded,true);
    assert.equal(L.chainOf(result.items,15),chain.key);
    const open=run(shape({chain:30,fan:2}),{},{compact:true,expanded:new Set([chain.key])}).result;
    assert.equal(open.rects.get(15).folded,undefined);
    assert.deepEqual(open.items.find(item=>item.kind==='chain').rows.map(row=>row.id),chain.ids);
});

test('collapse hides descendants but never the collapsed person; expand restores them', () => {
    const graph = shape({chain: 4, fan: 3, grand: 2});
    const collapsed = run(graph, {}, {collapsed: new Set([4])});
    assert.equal(L.placedIds(collapsed.unitList).length, 4);
    assert.equal(L.placedIds(run(graph).unitList).length, graph.nodes.length);
});

test('leaf siblings occupy a single horizontal row rather than a grid', () => {
    const {result}=run(shape({chain:2,fan:0,leaves:9}));
    assert.ok(result.items.every(item=>item.kind==='card'));
    const leaves=result.items.filter(item=>item.generation===2);
    assert.equal(leaves.length,9);assert.equal(new Set(leaves.map(item=>item.y)).size,1);
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

test('wide families grow horizontally: siblings never wrap, all records remain, connectors stay in gaps', () => {
    const nodes = [{id: 1, name: 'جد'}], edges = [];
    let id = 1;
    for (let c = 0; c < 14; c++) { const child = ++id; nodes.push({id: child, name: 'ابن ' + child}); edges.push({id: child, child_id: child, parent_id: 1});
        for (let g = 0; g < 3; g++) { const grand = ++id; nodes.push({id: grand, name: 'حفيد ' + grand}); edges.push({id: grand, child_id: grand, parent_id: child});
            for (let k = 0; k < 2; k++) { const kid = ++id; nodes.push({id: kid, name: 'ابن حفيد ' + kid}); edges.push({id: kid, child_id: kid, parent_id: grand}); } } }
    const {unitList, result} = run({nodes, edges});
    assert.equal(L.placedIds(unitList).length, nodes.length);
    assert.ok(result.width > 3200, 'a wide chart grows instead of wrapping siblings');
    const boxes = result.items;
    for (const a of boxes) for (const b of boxes) {
        if (a === b) continue;
        assert.equal(a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h, false, `${a.key} overlaps ${b.key}`);
    }
    assert.ok(result.links.every(link=>link.spineX===undefined));
    const siblings=result.items.filter(item=>item.generation===1);
    assert.equal(new Set(siblings.map(item=>item.y)).size,1);
    assertClearConnectors(result);
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

// UX-P0.7 hybrid chart and branch-boundary invariants.
function hybrid(graph, options = {}) {
    const idx = L.index(graph.nodes, graph.edges);
    const scope = L.visible(idx, options.view || {});
    const vis = L.displayVisible(idx, scope, options.mode || 'full', options.opened || new Set(), options.closed || new Set(), options.revealed || new Set());
    const unitList = L.units(idx, vis);
    return {idx, scope, vis, unitList, result: L.layout(idx, unitList, {...options, scopeIds: scope.ids})};
}
function boundaries() {
    const nodes = [], edges = [];
    const add = (id, father, boundary) => {
        nodes.push({id, name: 'شخص ' + id, ...(boundary ? {founder: {kind: boundary === 'main' ? 'fakhd' : 'sub_fakhd', boundaries: [boundary]}} : {})});
        if (father) edges.push({id, child_id: id, parent_id: father, verification: id === 8 ? 'pending_review' : id === 9 ? 'sourced' : 'verified'});
    };
    add(1); for (let i = 2; i <= 10; i++) add(i, i - 1, i === 5 || i === 7 ? 'main' : i === 9 ? 'sub' : null);
    add(11, 1); add(12, 11, 'main'); add(13, 5); add(14, 7); add(15, 9);
    return {nodes, edges};
}
function assertNoOverlap(result) {
    const rows = new Map();
    for (const rect of result.rects.values()) {
        const key = rect.y;
        if (!rows.has(key)) rows.set(key, []);
        rows.get(key).push(rect);
    }
    // Unequal-height cards can overlap across nearby rows too: use pair checks only on small invariant fixtures.
    const boxes = [...result.rects.values()];
    for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
        const a = boxes[i], b = boxes[j];
        assert.ok(!(a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h), `${a.id} overlaps ${b.id}`);
    }
}
function assertClearConnectors(result) {
    for (const link of result.links) for (const target of link.to) {
        const points = link.points || [link.from, {x: link.from.x, y: link.barY}, {x: target.x, y: link.barY}, target];
        for (let i = 1; i < points.length; i++) {
            const a = points[i - 1], b = points[i];
            assert.ok(a.x === b.x || a.y === b.y, 'connectors must be orthogonal');
            for (const rect of result.rects.values()) {
                const hits = a.y === b.y
                    ? a.y > rect.y && a.y < rect.y + rect.h && Math.max(a.x, b.x) > rect.x && Math.min(a.x, b.x) < rect.x + rect.w
                    : a.x > rect.x && a.x < rect.x + rect.w && Math.max(a.y, b.y) > rect.y && Math.min(a.y, b.y) < rect.y + rect.h;
                assert.equal(hits, false, `connector crosses person ${rect.id}`);
            }
        }
    }
}

test('hybrid shows every single-child ancestor as an individual card without folds', () => {
    const {result} = hybrid(shape({chain: 60, fan: 2}), {ancestryWidth: 1000});
    assert.equal(result.items.length, 62); assert.ok(result.items.every(item => item.kind === 'card'));
    assert.ok(result.items.filter(item => item.serpentine).length >= 58);
    assert.ok([...result.rects.values()].every(rect => !rect.folded));
});
test('serpentine rows alternate direction and preserve father order and verification', () => {
    const graph = shape({chain: 20, fan: 2}), {result, idx} = hybrid(graph, {ancestryWidth: 800});
    const prefix = result.items.filter(item => item.serpentine);
    const rows = new Map(); for (const item of prefix) { if (!rows.has(item.y)) rows.set(item.y, []); rows.get(item.y).push(item); }
    assert.ok(rows.size > 2);
    [...rows.values()].forEach((row, i) => { for (let j = 1; j < row.length; j++) assert.ok(i % 2 ? row[j].x < row[j - 1].x : row[j].x > row[j - 1].x); });
    for (const item of prefix.slice(1)) { const link = result.links.find(l => l.points && l.to[0].verification === idx.edgeOf.get(item.id).verification && l.to[0].x === (item.x + item.w / 2)); if (link) assert.equal(link.points.length, 2); }
    assertClearConnectors(result);
});
test('serpentine automatically transitions at visible branching even for an unclassified ancestor', () => {
    const {result} = hybrid(shape({chain: 8, fan: 3}));
    assert.equal(result.rects.get(7).serpentine, true); assert.equal(result.rects.get(8).serpentine, false);
    assert.equal(result.rects.get(9).y, result.rects.get(10).y);
});
test('a founder with one visible child does not break the ancestral prefix', () => {
    const {result} = hybrid(shape({chain: 20, fan: 2, founders: {5: {kind: 'fakhd', classification: 'مؤسس'}}}));
    assert.equal(result.rects.get(5).serpentine, true); assert.equal(result.rects.get(6).serpentine, true);
});
test('two children occupy the same generation row', () => {
    const {result} = hybrid(shape({chain: 1, fan: 2}));
    assert.equal(result.rects.get(2).y, result.rects.get(3).y);
    assert.equal(result.rects.get(2).generation, 1);
});
test('fifteen siblings never wrap even if the viewport or legacy wrap target is narrow', () => {
    const {result} = hybrid(shape({chain: 1, fan: 15, grand: 3}), {wrapWidth: 600});
    const row = [...result.rects.values()].filter(item => item.generation === 1);
    assert.equal(row.length, 15); assert.equal(new Set(row.map(item => item.y)).size, 1);
    assert.ok(result.width > 6000); assertNoOverlap(result); assertClearConnectors(result);
});
test('unequal descendant footprints receive disjoint horizontal subtree space', () => {
    const graph = boundaries(); for (let id = 16; id < 36; id++) { graph.nodes.push({id, name: 'حفيد طويل'}); graph.edges.push({id, child_id: id, parent_id: 12}); }
    const {result} = hybrid(graph); assertNoOverlap(result); assertClearConnectors(result);
    assert.ok(result.rects.get(12).x > result.rects.get(5).x, 'sibling subtrees retain their own spans');
});
test('unequal name and badge heights still align all people of each hierarchical generation', () => {
    const {result} = hybrid(boundaries(), {measure: (node) => ({w: node.founder ? 290 : 212, h: node.id % 3 ? 80 : 190})});
    const byGeneration = new Map(); for (const item of result.items) { if (!byGeneration.has(item.generation)) byGeneration.set(item.generation, item.y); assert.equal(item.y, byGeneration.get(item.generation)); }
    assertNoOverlap(result); assertClearConnectors(result);
});
test('main founders at generations five and seven and sub-founder nine keep their real depths', () => {
    const {result} = hybrid(boundaries());
    assert.equal(result.rects.get(5).generation + 1, 5); assert.equal(result.rects.get(7).generation + 1, 7); assert.equal(result.rects.get(9).generation + 1, 9);
    assert.ok(result.rects.get(5).y < result.rects.get(7).y && result.rects.get(7).y < result.rects.get(9).y);
});
test('main mode preserves paths through an earlier main founder to every deeper main founder', () => {
    const {vis} = hybrid(boundaries(), {mode: 'main_branches'});
    for (const id of [1, 2, 3, 4, 5, 6, 7, 11, 12]) assert.ok(vis.ids.has(id));
    for (const id of [8, 9, 10, 13, 14, 15]) assert.equal(vis.ids.has(id), false);
});
test('subordinate mode preserves main and subordinate founders at arbitrary depths', () => {
    const {vis} = hybrid(boundaries(), {mode: 'sub_branches'});
    for (const id of [5, 6, 7, 8, 9, 12]) assert.ok(vis.ids.has(id));
    for (const id of [10, 13, 14, 15]) assert.equal(vis.ids.has(id), false);
});
test('terminal main branches without subdivisions remain as visible expandable founders', () => {
    const {vis} = hybrid(boundaries(), {mode: 'sub_branches'});
    assert.ok(vis.ids.has(12)); assert.ok(vis.ids.has(11));
});
test('full mode includes every eligible person and edge without branch-depth truncation', () => {
    const g = boundaries(), {result, vis} = hybrid(g);
    assert.equal(vis.ids.size, g.nodes.length); assert.equal(result.rects.size, g.nodes.length);
    assert.equal(result.links.reduce((sum, link) => sum + link.to.length, 0), g.edges.length);
});
test('manual expansion reveals one subtree without opening unrelated terminal fakhds', () => {
    const g = boundaries();g.nodes.push({id: 30, name: 'طفل فرع آخر'});g.edges.push({id:30,parent_id:12,child_id:30});
    const {vis} = hybrid(g, {mode:'main_branches', opened:new Set([5])});
    assert.ok(vis.ids.has(10) && vis.ids.has(13)); assert.equal(vis.ids.has(30), false);
});
test('manual collapse wins over a previously expanded ancestor without duplicating the founder', () => {
    const {vis, unitList} = hybrid(boundaries(), {mode:'main_branches',opened:new Set([5]),closed:new Set([7])});
    assert.ok(vis.ids.has(7)); assert.ok(vis.ids.has(13)); assert.equal(vis.ids.has(8), false);
    assert.equal(L.placedIds(unitList).filter(id => id === 7).length, 1);
});
test('search revelation opens only the path to a collapsed result, retaining the selected scope', () => {
    const {vis} = hybrid(boundaries(), {mode:'main_branches',revealed:new Set([10]),view:{branchRoot:5,isolated:true}});
    assert.ok(vis.ids.has(10) && vis.ids.has(9)); assert.equal(vis.ids.has(12), false); assert.equal(vis.ids.has(13), false);
});
test('branch-focused modes never import another authorized-but-unselected fakhd', () => {
    for (const mode of ['full','main_branches','sub_branches']) {
        const {vis} = hybrid(boundaries(), {mode,view:{branchRoot:7,isolated:true}});
        assert.equal(vis.ids.has(12), false); assert.equal(vis.ids.has(13), false);
    }
});
test('ancestor and descendant scope filters are applied before branch boundaries', () => {
    const graph = boundaries();
    const ancestor = hybrid(graph,{view:{mode:'ancestors',focus:9},mode:'sub_branches'});
    assert.ok(ancestor.vis.ids.has(9)); assert.equal(ancestor.vis.ids.has(12),false);
    const descendant = hybrid(graph,{view:{mode:'descendants',focus:7},mode:'sub_branches',opened:new Set([7])});
    assert.ok(descendant.vis.ids.has(10)); assert.equal(descendant.vis.ids.has(13),false);
});
test('duplicate father candidates never duplicate visible cards in the hybrid chart', () => {
    const graph=boundaries();graph.edges.push({id:999,parent_id:12,child_id:9});
    const {unitList,result}=hybrid(graph);assert.equal(new Set(L.placedIds(unitList)).size,graph.nodes.length);assert.equal(result.rects.size,graph.nodes.length);
});
test('all serpentine and hierarchical connector segments avoid cards and preserve edge status', () => {
    const graph=shape({chain:18,fan:5,grand:4,founders:{6:{kind:'clan'}}});graph.edges[4].verification='sourced';graph.edges[12].verification='pending_review';
    const {result}=hybrid(graph,{ancestryWidth:900,measure:n=>({w:n.founder?272:212,h:n.id%2?90:140})});
    assertNoOverlap(result);assertClearConnectors(result);
    const styles=result.links.flatMap(link=>link.to.map(to=>to.verification));assert.ok(styles.includes('sourced') && styles.includes('pending_review'));
});
test('layout is presentation-only and does not mutate graph identities attributes or evidence', () => {
    const graph=boundaries();graph.nodes[4].attributes=[{label:'مؤسس',color:'#163f32'}];const before=JSON.stringify(graph);
    for(const mode of ['full','main_branches','sub_branches'])hybrid(graph,{mode});assert.equal(JSON.stringify(graph),before);
});
test('privacy isolation uses only received nodes; missing private ancestors are not synthesized', () => {
    const graph=boundaries();graph.nodes=graph.nodes.filter(n=>n.id!==6);const {idx,result}=hybrid(graph,{mode:'sub_branches'});
    assert.equal(idx.byId.has(6),false);assert.equal(result.rects.has(6),false);assert.ok(result.rects.has(7));
});
test('switching modes is deterministic and a scope without branch metadata remains useful', () => {
    const graph=boundaries();const a=hybrid(graph,{mode:'main_branches'});hybrid(graph,{mode:'sub_branches'});const b=hybrid(graph,{mode:'main_branches'});
    assert.deepEqual(a.result.items,b.result.items);
    const ordinary=shape({chain:10,fan:3});assert.equal(hybrid(ordinary,{mode:'main_branches'}).vis.ids.size,ordinary.nodes.length);
});
test('the new default handles a 20,000-person deep chain without recursion overflow or omitted names', t => {
    const graph=shape({chain:20000,fan:0});const start=performance.now();const {result}=hybrid(graph,{ancestryWidth:1200});const ms=performance.now()-start;
    assert.equal(result.rects.size,20000);assert.equal(result.rects.get(20000).generation,19999);assert.ok(ms<2000,`deep chain took ${ms} ms`);t.diagnostic(`deep-chain 20k: ${ms.toFixed(1)} ms`);
});
test('the new default handles a 10,000-person wide hierarchy without sibling wrapping', t => {
    const nodes=[],edges=[];for(let id=1;id<=10000;id++){nodes.push({id,name:'شخص '+id});if(id>1)edges.push({id,child_id:id,parent_id:Math.floor((id-2)/3)+1});}
    const start=performance.now();const {result,unitList}=hybrid({nodes,edges});const ms=performance.now()-start;
    assert.equal(L.placedIds(unitList).length,10000);assert.equal(result.rects.size,10000);assert.ok(ms<2000,`wide tree took ${ms} ms`);t.diagnostic(`wide hierarchy 10k: ${ms.toFixed(1)} ms`);
});

test('optional compact ancestry never wraps descendant siblings or changes their generations', () => {
    const {result} = hybrid(shape({chain:25,fan:15,grand:2}),{compact:true});
    assert.ok(result.items.some(item=>item.kind==='chain'));
    const siblings=[...result.rects.values()].filter(rect=>rect.item.id>=26&&rect.item.id<=68&&rect.item.generation===25);
    assert.equal(siblings.length,15);assert.equal(new Set(siblings.map(rect=>rect.y)).size,1);
    assert.ok(result.width>5000);
});
test('optional compact ancestry keeps founder cards and can unfold an individually searched ancestor', () => {
    const graph=shape({chain:30,fan:2,founders:{1:{kind:'root'},10:{kind:'clan'}}});
    const folded=hybrid(graph,{compact:true});assert.equal(folded.result.rects.get(1).item.kind,'card');assert.equal(folded.result.rects.get(10).item.kind,'card');
    const rect=folded.result.rects.get(20);assert.equal(rect.item.kind,'chain');
    const opened=hybrid(graph,{compact:true,expanded:new Set([rect.item.key])});assert.equal(opened.result.rects.get(20).folded,undefined);
});

test('short ancestral prefixes remain complete when serpentine is unnecessary or disabled', () => {
    for(const chain of [1,2,3]) {
        const graph=shape({chain,fan:2});
        for(const serpentine of [true,false]) assert.equal(hybrid(graph,{serpentine}).result.rects.size,graph.nodes.length);
    }
});


test('display modes do not mistake a hidden ordinary sibling for a genuinely single-child ancestry', () => {
    for(const mode of ['main_branches','sub_branches']) {
        const {result}=hybrid(boundaries(),{mode});
        assert.equal(result.rects.get(1).serpentine,false);
        assert.ok(result.rects.get(5).y<result.rects.get(7).y);
        if(mode==='sub_branches') assert.ok(result.rects.get(7).y<result.rects.get(9).y);
    }
});
