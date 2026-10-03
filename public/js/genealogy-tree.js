(() => {
    const app = document.querySelector('[data-tree-app]');
    if (!app) return;
    const svg = app.querySelector('[data-tree-svg]');
    const viewport = app.querySelector('[data-tree-viewport]');
    const stage = app.querySelector('[data-tree-stage]');
    const loading = app.querySelector('[data-tree-loading]');
    const empty = app.querySelector('[data-tree-empty]');
    const error = app.querySelector('[data-tree-error]');
    const partial = app.querySelector('[data-tree-partial]');
    const panel = app.querySelector('[data-person-panel]');
    let graph = {nodes: [], edges: [], meta: {}};
    let scale = 1, x = 40, y = 40, dragging = false, origin = null, collapsed = new Set(), selected = null;
    const ns = 'http://www.w3.org/2000/svg';
    const transform = () => viewport.setAttribute('transform', `translate(${x} ${y}) scale(${scale})`);
    const state = (name) => [loading, empty, error].forEach(el => el.hidden = el !== name);
    const params = () => {
        const p = new URLSearchParams({depth: '4', limit: '160', mode: app.querySelector('[data-focus-mode]').value});
        const branch = app.querySelector('[data-branch-select]').value;
        if (branch) p.set('branch', branch);
        return p;
    };
    async function load(extra = {}) {
        state(loading); partial.hidden = true;
        try {
            const p = params(); Object.entries(extra).forEach(([k,v]) => p.set(k,v));
            const response = await fetch(`${app.dataset.graphUrl}?${p}`, {headers: {'Accept':'application/json'}});
            if (!response.ok) throw new Error('graph');
            graph = await response.json();
            if (!graph.nodes.length) return state(empty);
            state(null); partial.hidden = graph.meta.complete;
            render(); fit();
        } catch (_) { state(error); }
    }
    function visibleNodes() {
        const hidden = new Set();
        const children = new Map(); graph.edges.forEach(e => children.set(e.parent_id, [...(children.get(e.parent_id)||[]), e.child_id]));
        const hide = id => (children.get(id)||[]).forEach(child => { hidden.add(child); hide(child); });
        collapsed.forEach(hide); return graph.nodes.filter(n => !hidden.has(n.id));
    }
    function render() {
        viewport.replaceChildren();
        const nodes = visibleNodes(), ids = new Set(nodes.map(n=>n.id)), parent = new Map(graph.edges.map(e=>[e.child_id,e.parent_id]));
        const levels = new Map(); const depth = id => parent.has(id) && ids.has(parent.get(id)) ? depth(parent.get(id))+1 : 0;
        nodes.forEach(n => { const d=depth(n.id); levels.set(d,[...(levels.get(d)||[]),n]); });
        const positions = new Map(); [...levels.entries()].forEach(([d,list]) => list.forEach((n,i) => positions.set(n.id,{x:i*210,y:d*150})));
        graph.edges.filter(e=>ids.has(e.child_id)&&ids.has(e.parent_id)).forEach(e=>{const a=positions.get(e.parent_id),b=positions.get(e.child_id); const path=document.createElementNS(ns,'path'); path.setAttribute('d',`M${a.x+80},${a.y+68} C${a.x+80},${a.y+105} ${b.x+80},${b.y-35} ${b.x+80},${b.y}`); path.setAttribute('class','tree-edge'); viewport.append(path);});
        nodes.forEach(n=>{const p=positions.get(n.id),g=document.createElementNS(ns,'g');g.setAttribute('transform',`translate(${p.x} ${p.y})`);g.setAttribute('class',`tree-svg-node tree-svg-node--${n.status}${selected===n.id?' is-selected':''}`);g.dataset.id=n.id;const rect=document.createElementNS(ns,'rect');rect.setAttribute('width','160');rect.setAttribute('height','68');rect.setAttribute('rx','14');const text=document.createElementNS(ns,'text');text.setAttribute('x','80');text.setAttribute('y','30');text.setAttribute('text-anchor','middle');text.setAttribute('direction','rtl');text.textContent=n.name.length>20?n.name.slice(0,19)+'…':n.name;const badge=document.createElementNS(ns,'text');badge.setAttribute('x','80');badge.setAttribute('y','51');badge.setAttribute('text-anchor','middle');badge.setAttribute('class','tree-node-meta');badge.textContent=n.has_more_children?'لديه ذرية إضافية +':'فتح الملخص';g.append(rect,text,badge);g.addEventListener('click',()=>select(n));viewport.append(g);}); transform();
    }
    function select(node){selected=node.id;panel.hidden=false;panel.querySelector('[data-person-name]').textContent=node.name;panel.querySelector('[data-person-status]').textContent=node.verification==='verified'?'موثق':'مسند إلى مصدر';panel.querySelector('[data-person-link]').href=node.url;const more=panel.querySelector('[data-person-load]');more.hidden=!node.has_more_children;more.onclick=()=>load({root:node.id,depth:4});panel.querySelector('[data-person-toggle]').textContent=collapsed.has(node.id)?'توسيع الذرية':'طي الذرية';panel.querySelector('[data-person-toggle]').onclick=()=>{collapsed.has(node.id)?collapsed.delete(node.id):collapsed.add(node.id);render();select(node);};render();}
    function fit(){const box=viewport.getBBox();if(!box.width)return;scale=Math.min(.95,(stage.clientWidth-80)/box.width,(stage.clientHeight-80)/box.height);x=(stage.clientWidth-box.width*scale)/2;y=40;transform();}
    app.querySelectorAll('[data-tree-action]').forEach(b=>b.addEventListener('click',()=>{const a=b.dataset.treeAction;if(a==='zoom-in')scale=Math.min(2.5,scale+.2);if(a==='zoom-out')scale=Math.max(.3,scale-.2);if(a==='fit')return fit();if(a==='reset'){scale=1;x=40;y=40;}transform();}));
    svg.addEventListener('wheel',e=>{e.preventDefault();scale=Math.max(.3,Math.min(2.5,scale+(e.deltaY<0?.1:-.1)));transform();},{passive:false});
    svg.addEventListener('pointerdown',e=>{dragging=true;origin={px:e.clientX,py:e.clientY,x,y};svg.setPointerCapture(e.pointerId);});svg.addEventListener('pointermove',e=>{if(!dragging)return;x=origin.x+e.clientX-origin.px;y=origin.y+e.clientY-origin.py;transform();});svg.addEventListener('pointerup',()=>dragging=false);
    app.querySelector('[data-branch-select]').addEventListener('change',load);app.querySelector('[data-focus-mode]').addEventListener('change',load);app.querySelector('[data-tree-retry]').addEventListener('click',load);app.querySelector('[data-person-close]').addEventListener('click',()=>panel.hidden=true);
    const input=app.querySelector('[data-tree-search]'), results=app.querySelector('[data-tree-search-results]');let timer;input.addEventListener('input',()=>{clearTimeout(timer);if(input.value.trim().length<2){results.hidden=true;return;}timer=setTimeout(async()=>{const r=await fetch(`${app.dataset.searchUrl}?q=${encodeURIComponent(input.value)}`);const j=await r.json();results.replaceChildren(...j.data.map(item=>{const b=document.createElement('button');b.type='button';b.textContent=item.text;b.onclick=()=>{results.hidden=true;const found=graph.nodes.find(n=>n.id===item.id);if(found){select(found);document.querySelector(`[data-id="${item.id}"]`)?.scrollIntoView({block:'center'});}else load({root:item.id,depth:2});};return b;}));results.hidden=!j.data.length;},250);});
    load();
})();
