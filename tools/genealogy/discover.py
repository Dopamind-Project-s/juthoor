#!/usr/bin/env python3
"""Inventory original evidence and decode Diagram Designer's raw-deflate CP1256 drawings.
No father is inferred from name order. Connector candidates require a wire component
with exactly one source node and explicit arrow endpoints. Candidates still need review.
"""
import hashlib, json, pathlib, re, struct, zlib, math, os
ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / 'resources/data/genealogy/al-zoubi'
OUT.mkdir(parents=True, exist_ok=True)

def write(name, data):
    (OUT/name).write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n')

def decode(path):
    raw=path.read_bytes()
    if raw[:4]!=b'\x03DDd': raise ValueError('Unsupported drawing version')
    b=zlib.decompress(raw[6:],-15)
    nodes=[]; lines=[]
    for m in re.finditer(rb'[\x09\x04]([\x00-\x80])\x00',b):
        n=m[1][0]; pos=m.end(); q=pos+n
        if q+21>len(b):continue
        text=b[pos:q].decode('cp1256',errors='replace')
        box=list(struct.unpack_from('<4i',b,q))
        if max(map(abs,box))>10000000: continue
        if text in ['Arrow','StraightLine']:
            lines.append({'offset':m.start(),'box':box,'arrow':b'\x02\x33\x02\x00\x00' in b[q+30:q+50]})
        elif b[q+16]==0:
            size=struct.unpack_from('<I',b,q+17)[0]
            if size>200:continue
            label=b[q+21:q+21+size].decode('cp1256',errors='replace')
            if size and label.replace('\\n','').replace('\\r','') != text:continue
            label=label.replace('\\n',' ').replace('\\r',' ')
            if box[2]<=box[0] or box[3]<=box[1]:continue
            nodes.append({'offset':m.start(),'name':label,'original_written_name':b[q+21:q+21+size].decode('cp1256',errors='replace'),'box':box,'source_file':str(path.relative_to(ROOT)),'page':1,'confidence':'lossless encoded label; identity pending review' if label else 'blank label','review_status':'pending_review' if label else 'unresolved_blank'})
    # Join only endpoints touching another drawn segment; unrelated crossings do not join.
    tol=1800
    def distance(p,seg):
        x,y,X,Y=seg;dx=X-x;dy=Y-y
        t=max(0,min(1,((p[0]-x)*dx+(p[1]-y)*dy)/(dx*dx+dy*dy or 1)))
        return math.hypot(p[0]-x-t*dx,p[1]-y-t*dy)
    parents=list(range(len(lines)))
    def find(i):
        while parents[i]!=i:parents[i]=parents[parents[i]];i=parents[i]
        return i
    for i,a in enumerate(lines):
        for j in range(i):
            c=lines[j]
            if min(distance(a['box'][:2],c['box']),distance(a['box'][2:],c['box']),distance(c['box'][:2],a['box']),distance(c['box'][2:],a['box']))<=tol:
                parents[find(i)]=find(j)
    components={}
    for i,l in enumerate(lines):components.setdefault(find(i),[]).append(l)
    def touches(p,n):
        x,y,X,Y=n['box'];cx=(x+X)/2;cy=(y+Y)/2
        return min(math.hypot(p[0]-cx,p[1]-y),math.hypot(p[0]-cx,p[1]-Y),math.hypot(p[0]-x,p[1]-cy),math.hypot(p[0]-X,p[1]-cy))<=4500
    edges=[];unresolved=[]
    for comp in components.values():
        attached={};targets=set()
        for l in comp:
            for end in [0,1]:
                p=l['box'][end*2:end*2+2]
                hits=[n for n in nodes if touches(p,n)]
                for n in hits:
                    attached[n['offset']]=n
                    if end==1 and l['arrow']:targets.add(n['offset'])
        fathers=set(attached)-targets
        if len(fathers)==1 and targets and fathers.isdisjoint(targets):
            father=next(iter(fathers))
            for child in targets:
                edges.append({'father_offset':father,'child_offset':child,'connector_offsets':[l['offset'] for l in comp]})
        elif attached:unresolved.append({'nodes':list(attached),'targets':list(targets),'reason':'ambiguous connector component'})
    by_offset={n['offset']:n for n in nodes}
    for edge in edges:
        edge.update(source_file=str(path.relative_to(ROOT)),page=1,father_original_name=by_offset[edge['father_offset']]['original_written_name'],child_original_name=by_offset[edge['child_offset']]['original_written_name'],section=by_offset[edge['child_offset']]['box'],confidence='unique directed connector component; identity pending review',review_status='pending_review')
    return {'file':str(path.relative_to(ROOT)),'sha256':hashlib.sha256(raw).hexdigest(),'offset_space':'decompressed Diagram Designer stream','nodes':nodes,'relationships':edges,'unresolved_components':unresolved}

def main():
    files=[];drawings=[];seen={}
    paths=[]
    for directory, folders, filenames in os.walk(ROOT):
        folders[:]=[f for f in folders if f not in ['vendor','node_modules','.git','.aws','.codex','.agents','.claude','framework','logs','screenshots','genealogy']]
        paths.extend(pathlib.Path(directory)/f for f in filenames)
    for folder in ['all']:
        for p in sorted(paths):
            if p.name in ['genealogy-source-inventory.md','genealogy-import-coverage.md','genealogy-seeder-reference.md','genealogy-validation-results.json']:continue

            if not p.is_file() or 'genealogy/al-zoubi' in str(p) or any(x in p.parts for x in ['framework','logs','screenshots']):continue
            if p.suffix.lower() not in ['.json','.csv','.xlsx','.php','.md','.txt','.pdf','.jpg','.jpeg','.png','.ddd','.pptx']:continue
            data=p.read_bytes()
            relevant=str(p).startswith(str(ROOT/'resources/data')) or p.suffix.lower() in ['.pdf','.ddd','.xlsx'] or re.search('الزعبي|زعبي|القواسمة|بكر|عيد|خميس|الرحايمة|العوتلات|مشجرة|شجرة العائلة|نسب|genealogy|ancestry|founder|branch',str(p.relative_to(ROOT))+'\n'+data.decode('utf8',errors='ignore'),re.I)
            if not relevant:continue
            sha=hashlib.sha256(data).hexdigest();rel=str(p.relative_to(ROOT));duplicate=seen.get(sha);seen.setdefault(sha,rel)
            row={'file':rel,'sha256':sha,'bytes':len(data),'type':p.suffix[1:],'duplicate_of':duplicate,'status':'duplicate source' if duplicate else 'requires review'}
            if p.suffix=='.ddd' and not duplicate:
                try:
                    d=decode(p);drawings.append(d);row.update(status='partially processed',people_identified=len([n for n in d['nodes'] if n['name']]),relationships=len(d['relationships']))
                except Exception as e:row.update(status='missing or unreadable',error=str(e))
            files.append(row)
    write('source-manifest.json',files);write('drawing-extractions.json',drawings)
    lines=['# Genealogy source inventory','', 'Generated by `python3 tools/genealogy/discover.py`. Original files are unchanged. Checksums and duplicate paths are in the private source manifest. Drawing relationships are connector candidates, not historical verification.','', '| Source | Type | Family/Fakhd | People Identified | Relationships | Extraction Status | Conflicts |','|---|---|---|---:|---:|---|---|']
    for r in files:lines.append(f"| {r['file']} | {r['type']} | {'Al-Zoubi' if r['file'].startswith('resources/data') else 'reference / inspect'} | {r.get('people_identified','—')} | {r.get('relationships','—')} | {r['status']} | {'Duplicate: '+r['duplicate_of'] if r['duplicate_of'] else r.get('error','Not yet adjudicated')} |")
    (ROOT/'docs/genealogy-source-inventory.md').write_text('\n'.join(lines)+'\n')
    print('sources',len(files),'unique',len(seen),'drawings',len(drawings),'nodes',sum(len(d['nodes']) for d in drawings),'candidate edges',sum(len(d['relationships']) for d in drawings))
if __name__=='__main__':main()
