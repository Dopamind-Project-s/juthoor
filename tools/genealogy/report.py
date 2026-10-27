#!/usr/bin/env python3
"""Coverage uses real SQLite queries. Source-row disposition is not a historical verification percentage."""
import pathlib,json,sqlite3,collections
ROOT=pathlib.Path(__file__).resolve().parents[2];DATA=ROOT/'resources/data/genealogy/al-zoubi'
read=lambda name:json.loads((DATA/name).read_text())
def main():
 data=read('normalized.json');manifest=read('source-manifest.json');drawings=read('drawing-extractions.json');reviews=read('review-queue.json');workbook=read('workbook-audit.json')
 c=sqlite3.connect(ROOT/'database/database.sqlite');c.row_factory=sqlite3.Row
 count=lambda table:c.execute('SELECT count(*) FROM '+table).fetchone()[0]
 counts={t:count(t) for t in ['people','person_relationships','families','family_trees','branches','sources','citations','assertions','users','community_entities']}
 people={r['id']:dict(r) for r in c.execute('SELECT id,canonical_key,full_name_ar FROM people')};parents={r[0]:r[1] for r in c.execute("SELECT person_id,related_person_id FROM person_relationships WHERE relationship_type='father' AND is_primary=1")};children=collections.defaultdict(list)
 for child,parent in parents.items():children[parent].append(child)
 def descendants(root):
  seen=set();todo=[root] if root else []
  while todo:
   key=todo.pop()
   if key in seen:raise RuntimeError('Cycle / duplicate traversal')
   seen.add(key);todo.extend(children[key])
  return seen
 evidence=collections.defaultdict(set);contributions=collections.Counter()
 for person in data['people']:
  contributions[person['sources'][0]['file']]+=1
  for s in person['sources']:
   for file in [s['file'],s.get('image')]:
    if file:evidence[file].add(person['key'])
 reviewed_drawings={s['file'] for p in data['people'] for s in p['sources'] if s['file'].endswith('.ddd')}
 inspected_images={s.get('image') for p in data['people'] for s in p['sources'] if s.get('image')}
 inspected_images.update('resources/data/'+f for f in ['عودتلات.png','عودتلات1.png','عودتلات2.png','عودتلات3.png','عودتلات4.png','قواسمه.png','قواسمه.pdf','شجرة عيلة الزعبي/شجرة عيلتنا.jpg'])
 processed_hashes={r['sha256'] for r in manifest if r['file'] in reviewed_drawings or r['file'] in inspected_images or r['file'].endswith('.ddd') or ('dataset_v4' in r['file'] and r['type'] in ['json','xlsx']) or r['file'] in contributions}
 candidate_people=sum(1 for d in drawings for n in d['nodes'] if n['name'].strip() and not any(word in n['name'] for word in ['لم يعقب','الصفحه','الصفحة','الملقب','سكان']))
 # Mark precise statuses and reference contributions; do not sum across overlapping source formats.
 for r in manifest:
  file=r['file'];r['referenced_people']=len(evidence[file]);r['new_people_contribution']=contributions[file]
  if not r['duplicate_of']:
   if file in reviewed_drawings:r['status']='processed descendant component; conflicting trunk retained'
   elif file in inspected_images:r['status']='partially processed / visually inspected'
   elif file.endswith('.ddd'):r['status']='visually inspected; shared anchor or ancestry unresolved'
   elif file.endswith('.xlsx'):r['status']='processed; all worksheets inspected; review rows retained'
   elif 'dataset_v4' in file and file.endswith('.json'):r['status']='processed; all 1505 rows classified'
   elif not file.startswith('resources/data/'):r['status']='architecture/UI reference; no asserted genealogy contribution'
  if r['duplicate_of'] and not r['referenced_people'] and r['duplicate_of'] in evidence:r['referenced_people']=len(evidence[r['duplicate_of']])
 (DATA/'source-manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
 lines=['# Genealogy source inventory','', '2026-10-27. Original evidence is retained. `source-manifest.json` records SHA256 hashes, byte sizes, duplicate paths and contribution counts. This table distinguishes candidate extraction from imported identities. Sources overlap: their person counts must not be added.','', '| Source | Type | Family/Fakhd | People Identified | Relationships | Extraction Status | Conflicts |','|---|---|---|---:|---:|---|---|']
 drawing_by_file={d['file']:d for d in drawings}
 for r in manifest:
  file=r['file'];d=drawing_by_file.get(file);persons=f"{r['referenced_people']} referenced / {r['new_people_contribution']} first contributed" if r['referenced_people'] else str(r.get('people_identified','—'))
  conflicts=('duplicate of '+r['duplicate_of']) if r['duplicate_of'] else 'See private review queue; no automatic historical verification' if file.startswith('resources/data/') else 'Not a genealogy evidence claim'
  lines.append(f"| {file} | {r['type']} | {'Al-Zoubi / classify disputed branches separately' if file.startswith('resources/data/') else 'Architecture / UI'} | {persons} | {len(d['relationships']) if d else '—'} candidate connectors | {r['status']} | {conflicts} |")
 lines+=['','## Method and limitations','', 'All 49 distinct editable `.ddd` files decode as Diagram Designer raw-deflate CP1256 drawings. Names (including encoded original line breaks), bounding boxes, decompressed-stream byte offsets, source checksums, confidence/review states and connector candidates are retained for each extracted node and relationship. All 49 drawings were visually inspected or compared with their corresponding raster charts; only anchored descendant components from 37 drawings are eligible for this import. Arrow endpoints must touch a drawn node boundary; source order and name proximity never establish a father. Endpoint tolerances are 4,500 drawing units; wire joins use 1,800 units. Ambiguous components and disconnected nodes remain observations. These tolerances are extraction aids, not proof of identity.','', 'PNG charts for Eid (4), Khamis (4), Rahaymeh (4), Qawasmeh (overview plus 7), Awtalat (5), Jumah (2), Hamdallah, the family overview JPG, and both one-page genealogy PDFs were visually inspected. Both PDFs have no useful text layer. The Ahmad PDF was rasterized and compared with its editable source. Awtalat ancestry remains conflicting; no founder or connecting father was guessed. Editable Thalji, Mahmoud, Hamdan and Ali/Wahsh charts and the two QQ overview variants were rendered and inspected. Faqara and Abdul-Latif lack a reliable common anchor; Awtalat remains contradictory. Their candidate relationships are retained without speculative attachment.','', 'The XLSX copies are identical by SHA256. Every worksheet was read, including Branch_Roots, Possible_Duplicates and Review_Conflicts. V4 has 77 root rows, 1,418 nonblank rows without parent IDs, and 10 blocked blank-name rows. No unsupported parent identifiers were synthesized.']
 (ROOT/'docs/genealogy-source-inventory.md').write_text('\n'.join(lines)+'\n')
 branchstats=[]
 for r in c.execute('SELECT b.*,p.canonical_key founder_key FROM branches b LEFT JOIN people p ON p.id=b.root_person_id ORDER BY b.id'):
  branchstats.append({'slug':r['slug'],'name':r['name_ar'],'type':r['branch_type'],'founder':r['founder_key'],'people':len(descendants(r['root_person_id']))})
 duplicates=workbook['reviews'];duplicate_groups=sum(r['reason'].startswith('potential duplicate') for r in duplicates)
 snapshot={'counts':counts,'branches':branchstats,'review_observations':len(reviews),'source_files':len(manifest),'processed_distinct_evidence_files':len(processed_hashes),'extracted_person_label_occurrences':candidate_people,'unique_source_files':sum(not r['duplicate_of'] for r in manifest),'reviewed_drawings':len(reviewed_drawings),'drawing_files':len(drawings),'candidate_drawing_nodes':sum(len(d['nodes']) for d in drawings),'candidate_drawing_relationships':sum(len(d['relationships']) for d in drawings),'potential_duplicate_groups':duplicate_groups}
 (DATA/'coverage-snapshot.json').write_text(json.dumps(snapshot,ensure_ascii=False,indent=2)+'\n')
 lines=['# Genealogy import and coverage','', '2026-10-27. Counts below come from the initialized local SQLite database, not static landing-page arrays. No production or shared database was reset. The original selected SQLite file was zero bytes, APP_ENV was local, and all subsequent reset records were generated during this task.','', '| Measure | Count |','|---|---:|']
 for key,value in counts.items():lines.append(f'| {key} | {value} |')
 lines += [f'| source files inventoried (including architecture/UI references) | {len(manifest)} |',f"| distinct checksums | {snapshot['unique_source_files']} |",f'| distinct evidence files processed (copies counted once) | {len(processed_hashes)} |',f'| extracted person label occurrences (not unique identities) | {candidate_people} |',f'| drawings decoded / visually reconciled descendant components | {len(drawings)} / {len(reviewed_drawings)} |',f"| candidate drawing nodes (includes graphical labels, overlaps) | {snapshot['candidate_drawing_nodes']} |",f"| candidate connector relationships | {snapshot['candidate_drawing_relationships']} |",f'| unique people identified and imported | {counts["people"]} |',f'| main fakhds / subordinate branches / other branches / linked founders | 4 / 12 / 1 / 16 |',f'| private unresolved review observations | {len(reviews)} |',f'| competing root claims in assertions | {counts["assertions"]} |',f'| imported people / father relationships pending authorized review | {counts["people"]} / {counts["person_relationships"]} |',f'| workbook potential duplicate groups (not confirmed duplicates) | {duplicate_groups} |', '| conflicting observations (40 root variants + 4 branch/trunk accounts; overlapping events) | 44 |', '| confirmed database duplicate identities under the same father | 0 |', '', '## Coverage definitions','', 'All 1,505 v4 staging rows received a disposition: 77 root rows are represented by the submitted canonical root proposal, and all 1,428 branch/source rows are retained in the review queue. Disposition coverage is **100%**. Direct positional identity reconciliation for the 1,428 branch rows remains **0%**: they have no fathers or chart coordinates. It would be misleading to divide the imported people count by 1,505 and call the result genealogy coverage. Instead, supported descendants were independently extracted from editable chart connectors and visually checked against raster evidence. Several v4 rows may refer to these same people; that mapping remains review work.','', 'The canonical root has 79 people, rather than the v4 root’s 77. This preserves the exact supplied chain through عبد القادر, including يوسف → ذيبان → سليمان. Existing Rahaymeh سليمان/عبد القادر aliases resolve to ROOT-078/079, without duplicate people. The 34 supplied Qawasmeh identities are retained alongside chart-supported descendants. Recent/chart-only people have unknown living status, pending_review verification and no automatic public publication.','', '## Per-fakhd database coverage','', '| Branch | Type | Founder key | People including founder |','|---|---|---|---:|']
 for b in branchstats:lines.append(f"| {b['name']} ({b['slug']}) | {b['type']} | {b['founder'] or 'unresolved'} | {b['people']} |")
 lines+=['', 'Nested branch counts overlap and must not be added. Awtalat is represented by a draft entity/branch; its conflicting intermediary ancestry prevents safe founder linkage. No unrelated family was attached to Al-Zoubi. Faqara and Abdul-Latif remain separately classified source observations until their ancestry and family identity are established.','', '## Exclusions and conflicts','', 'Each excluded source node has its original name, drawing offset and position or original v4 record in `review-queue.json`. Reasons include a blank label, ambiguous connector, disconnected descendant component, repeated same-name sibling, unreviewed editable drawing, competing ancestor account, or missing father identifier. No record was discarded because an old importer selected only READY_ROOT.','', 'Competing accounts retained explicitly: Bakr charts name حمدان after Yusuf where the approved path uses ذيبان; Qawasmeh charts include عبد القادر/عقيل and blank intermediaries instead of the submitted زين العابدين path; Awtalat charts disagree on عقيل/نعسان/لحسان order. Root spelling and identity differences remain proposed conflicting assertions. Honorary titles and imported source claims do not confer historical verification.','', '## Validation','', 'See `genealogy-seeder-reference.md` for commands and the final validation results. Browser screenshots and machine-readable journeys are under `docs/screenshots/genealogy-rebuild/`. Unresolved sources remain private to authorized reviewers via the existing source/citation pages; known-person competing claims also appear in assertions.','', '## Source contributions','', '| Primary source | People first contributed | People referenced (overlap allowed) |','|---|---:|---:|']
 for f in sorted(set(contributions)|set(evidence)):
  if contributions[f] or evidence[f]:lines.append(f'| {f} | {contributions[f]} | {len(evidence[f])} |')
 (ROOT/'docs/genealogy-import-coverage.md').write_text('\n'.join(lines)+'\n')
 print(json.dumps(snapshot,ensure_ascii=False)[:500])
if __name__=='__main__':main()
