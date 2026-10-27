#!/usr/bin/env python3
"""Read every XLSX worksheet without OCR; retain conflict and duplicate candidate rows."""
import pathlib, zipfile, xml.etree.ElementTree as E, json, hashlib
ROOT=pathlib.Path(__file__).resolve().parents[2];OUT=ROOT/'resources/data/genealogy/al-zoubi'
NS={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
def inspect(path):
 z=zipfile.ZipFile(path);strings=[]
 if 'xl/sharedStrings.xml' in z.namelist():strings=[''.join(n.itertext()) for n in E.fromstring(z.read('xl/sharedStrings.xml'))]
 result=[]
 for i,sh in enumerate(E.fromstring(z.read('xl/workbook.xml')).findall('s:sheets/s:sheet',NS),1):
  rows=[]
  for r in E.fromstring(z.read('xl/worksheets/sheet'+str(i)+'.xml')).findall('s:sheetData/s:row',NS):
   row={}
   for c in r:
    v=c.find('s:v',NS);inline=c.find('s:is',NS)
    row[c.attrib['r']]=strings[int(v.text)] if v is not None and c.attrib.get('t')=='s' else v.text if v is not None else ''.join(inline.itertext()) if inline is not None else ''
   rows.append(row)
  result.append({'sheet':sh.attrib['name'],'row_count':len(rows),'rows':rows})
 return result
if __name__=='__main__':
 files=[];seen={};reviews=[]
 for path in sorted((ROOT/'resources/data').rglob('*.xlsx')):
  sha=hashlib.sha256(path.read_bytes()).hexdigest();rel=str(path.relative_to(ROOT))
  if sha in seen:files.append({'file':rel,'sha256':sha,'duplicate_of':seen[sha]});continue
  seen[sha]=rel;result=inspect(path)
  files.append({'file':rel,'sha256':sha,'sheets':[{'sheet':s['sheet'],'row_count':s['row_count']} for s in result]})
  for s in result:
   if s['sheet'] in ['Possible_Duplicates','Review_Conflicts','Branch_Roots']:
    for i,row in enumerate(s['rows'][1:],2):reviews.append({'key':'workbook:'+s['sheet']+':'+str(i),'source':rel,'locator':s['sheet']+'!'+str(i),'reason':'potential duplicate; no automatic identity merge' if s['sheet']=='Possible_Duplicates' else 'workbook genealogy claim requires reconciliation','record':row})
 (OUT/'workbook-audit.json').write_text(json.dumps({'files':files,'reviews':reviews},ensure_ascii=False,indent=2)+'\n')
 print('workbooks',len(files),'unique',len(seen),'review rows',len(reviews))
