"""Generates SAMPLE illustration images (assets/img/*.svg). Replace with real photos later."""
import os,random,zlib
OUT=os.path.join(os.path.dirname(__file__),"..","assets","img");os.makedirs(OUT,exist_ok=True)
COL={"Ivory":("#FFFBEF","#D9C28E"),"White":("#FFFFFF","#CFC6B2"),"Red":("#E8604F","#7A1414"),"Pink":("#F7C6CF","#C46F80"),
     "Gold":("#F5DC9B","#A8791F"),"Maroon":("#B5495A","#4A0E1A"),"Blush":("#FBDAD0","#D69A8C")}
BODY={ # path in 600x800 box
"mermaid":"M232 172 Q262 205 300 185 Q338 205 368 172 L352 330 C375 420 340 520 335 610 C340 680 420 730 455 775 Q300 800 145 775 C180 730 260 680 265 610 C260 520 225 420 248 330 Z",
"princess":"M236 172 Q262 205 300 185 Q338 205 364 172 L348 320 C420 420 520 600 565 770 Q300 815 35 770 C80 600 180 420 252 320 Z",
"aline":"M236 172 Q262 205 300 185 Q338 205 364 172 L350 320 C380 480 440 650 485 775 Q300 800 115 775 C160 650 220 480 250 320 Z",
"party":"M236 172 Q262 205 300 185 Q338 205 364 172 L352 300 C390 380 420 480 440 580 Q300 610 160 580 C180 480 210 380 248 300 Z",
}
def dress(kind,c1,c2,uid):
    g=f'<linearGradient id="g{uid}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{c1}"/><stop offset="1" stop-color="{c2}"/></linearGradient>'
    gold='stroke="#E3CB93" stroke-opacity=".75" fill="none"'
    s=f'<defs>{g}</defs><g>'
    # hanger (echoes the logo)
    s+=f'<path d="M300 92 L300 108 L168 158 M300 108 L432 158" {gold} stroke-width="3" stroke-linecap="round"/><path d="M300 92 C300 70 326 70 326 50 A16 16 0 1 0 296 56" {gold} stroke-width="3" stroke-linecap="round"/>'
    fill=f'fill="url(#g{uid})"'
    if kind in BODY:
        s+=f'<path d="{BODY[kind]}" {fill}/>'
        s+=f'<path d="M262 330 L338 330" {gold} stroke-width="5"/>'
        for i in range(-4,5): s+=f'<path d="M{300+i*8} 340 Q{300+i*30} 560 {300+i*50} 770" stroke="#000" stroke-opacity=".10" fill="none" stroke-width="2"/>'
        s+=f'<path d="M236 172 Q262 205 300 185 Q338 205 364 172" {gold} stroke-width="3"/>'
        for x,y in((300,260),(280,290),(320,290),(300,320)): s+=f'<circle cx="{x}" cy="{y}" r="3" fill="#E3CB93"/>'
    elif kind=="lehenga":
        s+=f'<path d="M240 172 Q300 215 360 172 L372 285 L228 285 Z" {fill}/><path d="M236 335 L364 335 C420 480 500 650 525 775 Q300 810 75 775 C100 650 180 480 236 335 Z" {fill}/>'
        s+=f'<path d="M245 176 C130 300 105 540 165 775" stroke="#E3CB93" stroke-opacity=".55" stroke-width="22" fill="none" stroke-linecap="round"/>'
        s+=f'<path d="M110 735 Q300 775 490 735" {gold} stroke-width="5"/><path d="M130 690 Q300 725 470 690" {gold} stroke-width="2"/>'
        for i in range(8): s+=f'<circle cx="{150+i*43}" cy="{738+(i%2)*10}" r="4" fill="#E3CB93"/>'
    elif kind in("saree","kandyan"):
        s+=f'<path d="M240 172 Q300 212 360 172 L374 292 L226 292 Z" {fill}/>'
        s+=f'<path d="M226 292 L374 292 C392 450 384 620 400 775 L200 775 C216 620 208 450 226 292 Z" {fill}/>'
        s+=f'<path d="M236 172 L286 180 L378 430 L378 775 L318 775 L302 460 Z" fill="#fff" fill-opacity=".22"/>'
        s+=f'<path d="M200 745 L400 745" {gold} stroke-width="6"/><path d="M200 765 L400 765" {gold} stroke-width="2"/>'
        if kind=="kandyan":
            s+=f'<circle cx="236" cy="190" r="26" {fill}/><circle cx="364" cy="190" r="26" {fill}/>'
            for i in range(11): s+=f'<path d="M300 345 L{232+i*13.6} 560" stroke="#E3CB93" stroke-opacity=".7" stroke-width="2.5"/>'
            s+=f'<path d="M232 560 Q300 580 368 560" {gold} stroke-width="3"/>'
            for x in range(215,390,22): s+=f'<path d="M{x} 700 l8 -22 l8 22 z" fill="#E3CB93" fill-opacity=".8"/>'
        else:
            for i in range(-3,4): s+=f'<path d="M{300+i*18} 300 Q{300+i*22} 540 {300+i*24} 770" stroke="#000" stroke-opacity=".12" fill="none" stroke-width="2"/>'
    return s+'</g>'
def sparkles(w,h,seed,n=28):
    r=random.Random(seed);o=""
    for _ in range(n):
        x,y,rad=r.uniform(0,w),r.uniform(0,h),r.choice([1.5,2,3,5,8]);o+=f'<circle cx="{x:.0f}" cy="{y:.0f}" r="{rad}" fill="#E3CB93" fill-opacity="{r.uniform(.12,.5):.2f}"/>'
    return o
def canvas(w,h,c1,c2,inner,seed,label=True):
    dk=c2
    t=f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" preserveAspectRatio="xMidYMid slice"><defs><radialGradient id="bg" cx=".5" cy=".38" r=".85"><stop offset="0" stop-color="{dk}" stop-opacity=".85"/><stop offset=".55" stop-color="#17110a"/><stop offset="1" stop-color="#070503"/></radialGradient><radialGradient id="fl" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#E3CB93" stop-opacity=".35"/><stop offset="1" stop-color="#E3CB93" stop-opacity="0"/></radialGradient></defs>'
    t+=f'<rect width="{w}" height="{h}" fill="url(#bg)"/>{sparkles(w,h,seed)}{inner}'
    if label: t+=f'<text x="{w-14}" y="{h-12}" text-anchor="end" font-family="sans-serif" font-size="{max(11,w//60)}" letter-spacing="2" fill="#E3CB93" fill-opacity=".5">SAMPLE IMAGE</text>'
    return t+'</svg>'
def product(pid,kind,col):
    c1,c2=COL[col]
    inner=f'<ellipse cx="300" cy="790" rx="230" ry="16" fill="url(#fl)"/>'+dress(kind,c1,c2,pid.replace("-",""))
    open(f"{OUT}/{pid}.svg","w").write(canvas(600,800,c1,c2,inner,zlib.crc32(pid.encode())%999))
P=[("B2B-001","princess","Ivory"),("B2B-002","mermaid","White"),("B2B-003","aline","Ivory"),("B2B-004","kandyan","Gold"),("B2B-005","kandyan","Red"),("B2B-006","kandyan","Pink"),
   ("B2B-007","lehenga","Maroon"),("B2B-008","lehenga","Blush"),("B2B-009","party","Pink"),("B2B-010","aline","White"),("B2B-011","saree","Gold"),("B2B-012","princess","Ivory")]
for p in P: product(*p)
# category tiles (square-ish 800x800) and slideshow backdrops (1600x900, dress on right)
CATI=[("cat-1","mermaid","Ivory"),("cat-2","kandyan","Gold"),("cat-3","lehenga","Maroon"),("cat-4","party","Pink"),("cat-5","saree","Gold"),("cat-6","princess","Blush")]
for n,k,c in CATI:
    c1,c2=COL[c];inner=f'<g transform="translate(100 20) scale(1)">'+dress(k,c1,c2,n.replace("-",""))+'</g>'
    open(f"{OUT}/{n}.svg","w").write(canvas(800,800,c1,c2,inner,zlib.crc32(n.encode())%999,False))
SL=[("slide-1","princess","Ivory"),("slide-2","kandyan","Red"),("slide-3","lehenga","Maroon"),("slide-4","mermaid","Gold")]
for n,k,c in SL:
    c1,c2=COL[c]
    inner=f'<ellipse cx="1180" cy="860" rx="380" ry="22" fill="url(#fl)"/><g transform="translate(880 20) scale(1.08)">'+dress(k,c1,c2,n.replace("-",""))+'</g>'
    inner+=f'<g opacity=".25" transform="translate(1280 120) scale(.5)">'+dress(k,c1,c2,n.replace("-","")+"b")+'</g>'
    open(f"{OUT}/{n}.svg","w").write(canvas(1600,900,c1,c2,inner,zlib.crc32(n.encode())%999,False))
print("ok")
