#!/usr/bin/env python3
"""Build the authoritative seed proposal from explicit anchors and inspected connector paths.
Uninspected drawings and disputed trunk paths remain review observations, never fathers.
"""
import json, pathlib, hashlib, subprocess, collections, re
ROOT=pathlib.Path(__file__).resolve().parents[2];OUT=ROOT/'resources/data/genealogy/al-zoubi'
def read(p):return json.loads((ROOT/p).read_text())
def php(p):return json.loads(subprocess.check_output(['php','-r',f'echo json_encode(require "{p}", JSON_UNESCAPED_UNICODE);'],cwd=ROOT))
def write(p,d):(OUT/p).write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n')
def norm(s):
 return re.sub(r'\s+','',s.translate(str.maketrans('أإآىة','ااايه')))
people={};branches=[];reviews=[];mappings=[]
def add(key,name,father=None,source='user:2026-10-27',**kw):
 people[key]={'key':key,'name':name,'father':father,'evidence_status':'canonical_proposal' if source.startswith('user:') else 'source_supported','sources':[{'file':source,'locator':key,'original_name':name,'confidence':'explicit submission' if source.startswith('user:') else 'clear connector','review_status':'pending_review'}],**kw}
 return key
names=php('resources/data/al-zoubi/shared-ancestry.php')
# Exact current approved submission takes precedence; previous spellings remain aliases.
fix={19:'إبراهيم',20:'إسماعيل',67:'احمد ابو البقاء'}
titles={46:'جد الهاشميين',48:'عم النبي محمد',50:'حفيد الرسول',63:'اول من لقب بالزعبي والجد الاول لعشيرة الزعبي',76:'جد افخاد الزعبي بالسلط'}
for pos,name in enumerate(names,1):
 attrs=[]
 if pos in [1,7,10,13,19,20]:attrs=['prophet']
 if pos==49:attrs=['companion','rashidun-caliph']
 if pos==50:attrs=['prophet-grandson']
 if pos==63:attrs=['clan-founder','tribe-ancestor']
 if pos==76:attrs=['tribe-ancestor']
 add(f'ROOT-{pos:03}',fix.get(pos,name),f'ROOT-{pos-1:03}' if pos>1 else None,title=titles.get(pos),attributes=attrs,is_living=False,public_narrative=True,living_status_basis='supplied_ancestry',aliases=[name] if pos in fix and name!=fix[pos] else [])
qw=read('resources/data/al-qawasmeh/manifest.json')
fixqw={'QW-0005':'أعمر','QW-0013':'أحمد','QW-0026':'مأمون','QW-0033':'أحمد'}
for row in qw['people']:
 add(row['key'],fixqw.get(row['key'],row['name']),row['father'],title=row.get('title'),attributes=[],aliases=[row['name']] if row['key'] in fixqw else [])
for f in php('resources/data/al-zoubi/branch-founder-resolution.php'):
 for r in f['path']:
  key={'AZ-RAHAYMEH-SULAYMAN':'ROOT-078','AZ-RAHAYMEH-ABD-AL-QADIR':'ROOT-079'}.get(r['canonical_key'],r['canonical_key'])
  father={'AZ-RAHAYMEH-SULAYMAN':'ROOT-078','AZ-RAHAYMEH-ABD-AL-QADIR':'ROOT-079'}.get(r['father_canonical_key'],r['father_canonical_key'])
  if key not in people:
   add(key,r['name_ar'],father,'resources/data/al-zoubi/branch-founder-resolution.php',attributes=[],aliases=[])
   people[key]['sources'][0]['supporting_references']=r['source_refs']
 branches.append({'slug':f['branch_slug'],'name':f['branch_name'],'founder':f['founder_canonical_key'],'parent':f['parent_branch_slug'],'type':'sub_fakhd' if f['parent_branch_slug'] else 'fakhd'})
add('AZ-ALI-WAHSH-FOUNDER','علي (وحش)','AZ-BAKR-MUHAMMAD','resources/data/شجرة عيلة الزعبي/علي (وحش) - 1.ddd',attributes=['branch-founder'],aliases=[])
branches.append({'slug':'ali-wahsh','name':'علي (وحش)','founder':'AZ-ALI-WAHSH-FOUNDER','parent':None,'type':'family_group'})
# The eight sons of Bakr are explicitly connected in all four inspected Eid/Khamis charts.
for name in ['جمعة','حمدان','ثلجي','محمود','حمد الله','أحمد']:
 key='AZ-DESC-'+hashlib.sha256(('AZ-BAKR-FOUNDER'+'|'+norm(name)).encode()).hexdigest()[:20].upper()
 add(key,name,'AZ-BAKR-FOUNDER','resources/data/بكر عيد1.png',attributes=[],aliases=['جمعه'] if name=='جمعة' else [])
branches.append({'slug':'al-qawasmeh','name':'القواسمة','founder':'QW-0003','parent':None,'type':'fakhd'})
for key,slug in [('QW-0005','amar'),('QW-0006','qasim'),('QW-0007','hamdan'),('QW-0008','samrin')]:
 branches.append({'slug':'qawasmeh-'+slug,'name':people[key]['name'],'founder':key,'parent':'al-qawasmeh','type':'sub_fakhd'})
# Awtalat's contradictory Aqil/Nu'san/Lahsan trunk cannot safely be connected.
branches.append({'slug':'al-awtalat','name':'العوتلات','founder':None,'parent':None,'type':'fakhd'})
# All these raster charts were inspected against their editable originals. Offsets identify source nodes.
reviewed={
 'بكر - جمعه - 1.ddd':'بكر جمعة1.png','بكر - جمعه - 2.ddd':'بكر جمعة2.png','بكر - حمد الله -1.ddd':'بكر حمدالله.png','بكر - أحمد - 3.ddd':'بكر احمد.pdf',
 'بكر - عيد - 1.ddd':'بكر عيد1.png','بكر - عيد - 2.ddd':'بكر عيد2.png','بكر - عيد - 3.ddd':'بكر عيد3.png','بكر - عيد - 4.ddd':'بكر عيد4.png',
 'بكر - خميس - 1.ddd':'بكر خميس1.png','بكر - خميس - 2.ddd':'بكر خميس2.png','بكر - خميس - 3.ddd':'بكر خميس3.png','بكر - خميس - 4.ddd':'بكر خميس4.png',
 'عبد الرحمن - 1.ddd':'عبدالرجمن1 رحايمه.png','عبد الرحمن - 2.ddd':'عبدالرجمن2 رحايمه.png','عبد الرحمن - 3 ).ddd':'عبدالرجمن3 رحايمه.png','عبد الرحمن - 4).ddd':'عبدالرجمن4 رحايمه.png',
 'قاسم - 1.ddd':'قواسمه1.png','قاسم - 2.ddd':'قواسمه3.png','قاسم - 3.ddd':'قواسمه2.png','قاسم - 4.ddd':'قواسمه4.png','قاسم - 5.ddd':'قواسمه5.png','قاسم - 6.ddd':'قواسمه6.png','قاسم - 7.ddd':'قواسمه7.png',
}
# Editable-only Bakr charts were rendered from original node boxes and line/arrow segments and visually inspected.
for filename in ['بكر - ثلجي - 1.ddd','بكر - ثلجي - 2.ddd','بكر - ثلجي - 3.ddd','بكر - ثلجي - 4.ddd','بكر - ثلجي - 5.ddd','بكر - محمود - 1.ddd','بكر - محمود - 2.ddd','بكر - محمود - 3.ddd','بكر - محمود - 4.ddd','بكر - حمدان - 1.ddd','بكر - حمدان - 4.ddd']:
 reviewed[filename]=None
reviewed['علي (وحش) - 1.ddd']=None
reviewed['qq.ddd']=None
drawings=read('resources/data/genealogy/al-zoubi/drawing-extractions.json')
for d in drawings:
 filename=pathlib.Path(d['file']).name
 if filename not in reviewed:
  reviews.append({'key':'drawing:'+hashlib.sha256(d['file'].encode()).hexdigest()[:16],'reason':'visually inspected; disputed Awtalat trunk, unsupported shared anchor, or separate-family classification required; no person identity asserted','source':d['file'],'nodes':len(d['nodes']),'connector_candidates':len(d['relationships'])});continue
 nodes={n['offset']:n for n in d['nodes']};edges=d['relationships'];local={};family='qawasmeh' if filename.startswith('قاسم') or filename=='qq.ddd' else 'rahaymeh' if filename.startswith('عبد الرحمن') else 'bakr'
 # Repeated documented founder paths anchor descendant extraction, never the conflicting upstream chart.
 anchor='QW-0004' if family=='qawasmeh' else 'AZ-RAHAYMEH-FOUNDER' if family=='rahaymeh' else 'AZ-BAKR-FOUNDER'
 anchor_name='محمد' if family=='qawasmeh' else 'عبد الرحمن' if family=='rahaymeh' else 'بكر'
 # Select the unique node with founder's name closest to the top. Full-root charts contain earlier namesakes;
 # the expected child labels distinguish the branch node without merging names alone.
 expected={'qawasmeh':{'اعمر','قاسم','حمدان','سمرين'},'rahaymeh':{'محمد','احمد'},'bakr':{'عيد','خميس','حمدان','جمعه','محمود','احمد'}}[family]
 if filename=='علي (وحش) - 1.ddd':
  anchor='AZ-ALI-WAHSH-FOUNDER';anchor_name='علي (وحش)'
 elif filename=='qq.ddd':
  anchor='QW-0005';anchor_name='أعمر'
 elif filename.startswith('بكر - ثلجي') or filename.startswith('بكر - محمود') or filename.startswith('بكر - حمدان'):
  anchor_name='ثلجي' if 'ثلجي' in filename else 'محمود' if 'محمود' in filename else 'حمدان'
  anchor=next(p['key'] for p in people.values() if p['father']=='AZ-BAKR-FOUNDER' and norm(p['name'])==norm(anchor_name))
 elif filename.startswith('بكر - جمعه') or filename.startswith('بكر - حمد الله') or filename.startswith('بكر - أحمد'):
  anchor_name='جمعة' if 'جمعه' in filename else 'حمد الله' if 'حمد الله' in filename else 'أحمد'
  anchor=next(p['key'] for p in people.values() if p['father']=='AZ-BAKR-FOUNDER' and norm(p['name'])==norm(anchor_name))
 elif filename.startswith('بكر - عيد'):

  anchor='AZ-EID-FOUNDER';anchor_name='عيد'
 elif filename.startswith('بكر - خميس'):
  anchor='AZ-KHAMIS-FOUNDER';anchor_name='خميس'
 elif filename=='قاسم - 4.ddd':
  anchor='QW-0005';anchor_name='أعمر'
 elif filename=='قاسم - 6.ddd':
  matches=[p for p in people.values() if norm(p['name'])==norm('سليم') and p['father']=='QW-0005']
  if len(matches)==1:anchor=matches[0]['key'];anchor_name='سليم'
 candidates=[off for off,n in nodes.items() if norm(n['name'])==norm(anchor_name)]
 if filename.startswith('بكر - حمدان'):
  candidates=[off for off in candidates if any(e['child_offset']==off and norm(nodes[e['father_offset']]['name'])==norm('بكر') for e in edges)]
 if candidates:
  if family=='qawasmeh' and anchor=='QW-0004':
   scores={off:len({norm(nodes[e['child_offset']]['name']) for e in edges if e['father_offset']==off} & {norm(x) for x in expected}) for off in candidates}
   best=max(scores.values())
   candidates=[off for off in candidates if scores[off]==best] if best>=2 else []
  else:
   # The inspected chart header identifies this continuation above descendants.
   candidates=[min(candidates,key=lambda off:nodes[off]['box'][1])]
 if filename=='عبد الرحمن - 2.ddd':
  continuation=[p for p in people.values() if norm(p['name'])==norm('عبد الرحمن') and p['father'] in people and norm(people[p['father']]['name'])==norm('محمد') and people[p['father']]['father']=='AZ-RAHAYMEH-FOUNDER']
  candidates=[]
  if len(continuation)==1:
   anchor=continuation[0]['key']; candidates=[min(nodes,key=lambda off:nodes[off]['box'][1])]
 if len(candidates)!=1:
  reviews.append({'key':'anchor:'+filename,'source':d['file'],'reason':'no unique connected founder anchor','candidates':candidates});continue
 local[candidates[0]]=anchor
 # Some connectors are not joined exactly in the editable file. Never fill such gaps geometrically.
 changed=True
 while changed:
  changed=False
  for e in edges:
   f,c=e['father_offset'],e['child_offset']
   if f not in local or c in local:continue
   n=nodes[c];name=n['name'].strip();father=local[f]
   if not name or any(x in name for x in ['لم يعقب','الصفحه','الصفحة','سكان']):continue
   siblings=[x for x in edges if x['father_offset']==f and norm(nodes[x['child_offset']]['name'])==norm(name)]
   if len(siblings)!=1:continue
   existing=[p['key'] for p in people.values() if p['father']==father and norm(p['name'])==norm(name)]
   if len(existing)>1:continue
   key=existing[0] if existing else 'AZ-DESC-'+hashlib.sha256((father+'|'+norm(name)).encode()).hexdigest()[:20].upper()
   if key not in people:
    add(key,name,father,d['file'],attributes=[],aliases=[])
    people[key]['sources']=[]
   elif name!=people[key]['name'] and name not in people[key].setdefault('aliases',[]):people[key]['aliases'].append(name)
   evidence={'file':d['file'],'page':1,'image':'resources/data/'+reviewed[filename] if reviewed[filename] else None,'locator':'ddd:byte:'+str(c),'section':n['box'],'original_name':name,'father_offset':f,'father_original_name':nodes[f]['name'],'connector_offsets':e['connector_offsets'],'confidence':'clear connector; raster compared' if reviewed[filename] else 'clear connector; editable drawing rendered and inspected','review_status':'pending_review'}
   if evidence not in people[key]['sources']:people[key]['sources'].append(evidence)
   local[c]=key;changed=True
 for off,n in nodes.items():
  if off in local:
   mappings.append({'source':d['file'],'offset':off,'key':local[off]})
  elif n['name']:
   reviews.append({'key':hashlib.sha256((d['file']+':'+str(off)).encode()).hexdigest(),'source':d['file'],'locator':'ddd:byte:'+str(off),'original_name':n['name'],'section':n['box'],'reason':'outside anchored descendant component / contradictory ancestry / ambiguous connector; not imported'})
# Add explicitly documented Bakr subordinate founders found through reviewed charts.
for name,slug in [('جمعة','jumah'),('حمدان','hamdan'),('ثلجي','thalji'),('محمود','mahmoud'),('حمد الله','hamdallah'),('أحمد','ahmad')]:
 found=[p for p in people.values() if p['father']=='AZ-BAKR-FOUNDER' and norm(p['name'])==norm(name)]
 if len(found)==1:branches.append({'slug':'bakr-'+slug,'name':found[0]['name'],'founder':found[0]['key'],'parent':'bakr','type':'sub_fakhd'})
for branch in branches:
 if branch['founder']:
  people[branch['founder']]['attributes']=sorted(set(people[branch['founder']].get('attributes',[])+(['branch-founder'] if branch['type']=='family_group' else ['branch-founder','sub-branch-founder' if branch['parent'] else 'fakhd-founder'])))
# Preserve ALL v4 source rows, including those never safe for relational import.
v4=read('resources/data/source-documents/juthoor_alzoubi_genealogy_dataset_v4.json')
for r in v4['branch_people']:
 reviews.append({'key':'v4:'+r['person_key'],'reason':'blank name' if not r.get('name_ar') or r.get('resolution_status','').startswith('unresolved') else 'v4 row has no father identifier; source-node identity not automatically merged','source':'resources/data/source-documents/juthoor_alzoubi_genealogy_dataset_v4.json','record':r})
for r in php('resources/data/al-zoubi/branch-founder-review.php'):
 reviews.append({'key':'founder:'+r['branch_slug'],'reason':'conflicting genealogy','record':r,'source':'resources/data/al-zoubi/branch-founder-review.php'})
for i,(original,canonical) in enumerate(zip(v4['common_root'],list(people.values())[:79]),1):
 if original['name_ar']!=canonical['name']:
  reviews.append({'key':'root-variant:'+str(i),'person_key':canonical['key'],'reason':'competing root spelling / account','source':'resources/data/source-documents/juthoor_alzoubi_genealogy_dataset_v4.json','original_name':original['name_ar'],'canonical_name':canonical['name']})
reviews.extend([
 {'key':'conflict:bakr-trunk','source':'resources/data/بكر عيد1.png','reason':'conflicting genealogy','canonical_path':['يوسف','ذيبان','علي','محمد','بكر'],'chart_path':['يوسف','حمدان','علي','محمد','بكر'],'decision':'Retain submitted chain; descendant extraction anchored at Bakr, never silently replace Dhiban with Hamdan.'},
 {'key':'conflict:qawasmeh-trunk','source':'resources/data/قواسمه1.png','reason':'conflicting genealogy','canonical_path':['ذيبان','سليمان','زين العابدين','سليمان','قاسم'],'chart_path':['ذيبان','[blank]','سليمان','عبد القادر','عقيل','سليمان','[blank]','قاسم'],'decision':'Retain submitted canonical proposal; chart descendant component anchored at Muhammad son of Qasim only.'},
])
write('normalized.json',{'version':1,'family':{'slug':'al-zoubi','name':'عشيرة الزعبي'},'tree':'main','root':'ROOT-001','expansion':'ROOT-076','people':list(people.values()),'branches':branches})
reviews.extend(read('resources/data/genealogy/al-zoubi/workbook-audit.json')['reviews'])
write('review-queue.json',reviews);write('founder-mappings.json',mappings)
print('people',len(people),'fathers',len(people)-1,'branches',len(branches),'reviews',len(reviews));print(collections.Counter(p['key'].split('-')[0] for p in people.values()))
