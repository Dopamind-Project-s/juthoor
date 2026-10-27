import sys,pathlib,json,re,zlib,struct,math
from PIL import Image,ImageDraw,ImageFont
sys.path.insert(0,str(pathlib.Path.cwd()/'tools/genealogy'));import discover
out=pathlib.Path('/tmp/juthoor-drawings');out.mkdir(exist_ok=True)
for p in pathlib.Path('resources/data').rglob('*.ddd'):
 d=discover.decode(p.resolve());b=zlib.decompress(p.read_bytes()[6:],-15);segments=[]
 for m in re.finditer(rb'\x04(?:\x05\x00Arrow|\x0c\x00StraightLine)',b):
  q=m.end();segments.append((struct.unpack_from('<4i',b,q),b'\x02\x33\x02\x00\x00' in b[q+30:q+50]))
 boxes=[n['box'] for n in d['nodes']];bounds=[max(v[i] for v in boxes) for i in [2,3]];scale=min(1050/bounds[0],1550/bounds[1]);img=Image.new('RGB',(1100,1620),'white');draw=ImageDraw.Draw(img);font=ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',12)
 for (x,y,X,Y),arrow in segments:
  a=(x*scale+10,y*scale+30);c=(X*scale+10,Y*scale+30);draw.line([a,c],fill='black',width=1)
  if arrow:
   ang=math.atan2(c[1]-a[1],c[0]-a[0]);draw.polygon([c,(c[0]-5*math.cos(ang-.5),c[1]-5*math.sin(ang-.5)),(c[0]-5*math.cos(ang+.5),c[1]-5*math.sin(ang+.5))],fill='black')
 for n in d['nodes']:
  x,y,X,Y=[v*scale for v in n['box']];draw.rectangle([x+10,y+30,X+10,Y+30],fill='#ffffb5',outline='black');draw.text(((x+X)/2+10,(y+Y)/2+30),n['name'],font=font,fill='black',anchor='mm',direction='rtl')
 draw.text((550,10),p.name,font=font,fill='black',anchor='mt',direction='rtl');img.save(out/(p.parent.name+'-'+p.stem+'.png'))
