(()=>{
const fine=matchMedia("(hover:hover) and (pointer:fine)").matches, reduce=matchMedia("(prefers-reduced-motion:reduce)").matches;
const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>[...r.querySelectorAll(s)];
/* loader (once per session) */
let seen=false;try{seen=sessionStorage.getItem("b2b")}catch(e){}
if(!seen&&!reduce){
  const l=document.createElement("div");l.id="fx-loader";l.innerHTML='<div style="text-align:center"><img src="assets/img/logo-full.png" alt="Bride2B Bridal Closet"><span></span></div>';document.body.prepend(l);
  addEventListener("load",()=>setTimeout(()=>l.classList.add("out"),900));setTimeout(()=>l.classList.add("out"),2500);
  try{sessionStorage.setItem("b2b","1")}catch(e){}
}
/* progress bar */
const pb=document.createElement("div");pb.id="fx-progress";document.body.appendChild(pb);
const hd=()=>$("header.site"),rail=$(".rail");let ly=0;
addEventListener("scroll",()=>{
  const y=scrollY,h=document.documentElement.scrollHeight-innerHeight;pb.style.transform=`scaleX(${h>0?y/h:0})`;
  const H=hd();if(H&&!rail&&!$("#menu.open")){H.classList.toggle("hide",y>ly&&y>300)}ly=y;
  $$("[data-par]").forEach(e=>e.style.transform=`translateY(${y*parseFloat(e.dataset.par)}px)`);
},{passive:true});
/* cursor glow */
if(fine&&!reduce){
  const g=document.createElement("div");g.id="fx-glow";document.body.appendChild(g);
  addEventListener("pointermove",e=>{document.body.classList.add("glow");g.style.transform=`translate(${e.clientX}px,${e.clientY}px)`},{passive:true});
}
/* reveal on scroll */
const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add("rv-in");io.unobserve(e.target)}}),{threshold:.12});
function reveal(root=document){
  $$(".sec-head,.card,.step,.rev,.c-card,.map,.stat,.note",root).forEach((el,i)=>{
    if(el.classList.contains("rv"))return;el.classList.add("rv");
    const sib=[...el.parentElement.children].indexOf(el);el.style.setProperty("--d",Math.min(sib,6)*.08+"s");io.observe(el);
  });
}
reveal();
/* counters */
const cio=new IntersectionObserver(es=>es.forEach(e=>{if(!e.isIntersecting)return;cio.unobserve(e.target);
  const to=+e.target.dataset.count,t0=performance.now(),d=1400;
  (function f(t){const p=Math.min((t-t0)/d,1),v=Math.round(to*(1-Math.pow(1-p,3)));e.target.textContent=v;if(p<1)requestAnimationFrame(f)})(t0);
}),{threshold:.6});
$$("[data-count]").forEach(e=>cio.observe(e));
/* word split for hero headlines */
$$("[data-split]").forEach(h=>{let i=0;
  (function walk(n){[...n.childNodes].forEach(c=>{
    if(c.nodeType===3){const f=document.createDocumentFragment();c.textContent.split(/(\s+)/).forEach(w=>{
      if(!w.trim()){f.append(w);return}const s=document.createElement("span");s.className="w";s.style.setProperty("--i",i++);s.textContent=w;f.append(s)});c.replaceWith(f)}
    else walk(c)})})(h);
});
if(fine&&!reduce){
  /* 3D tilt + glare (delegated, works on re-rendered cards) */
  const SEL=".card,.pc,.tile";let cur=null;
  addEventListener("pointermove",e=>{
    const el=e.target.closest&&e.target.closest(SEL);
    if(cur&&cur!==el)reset(cur);
    if(!el){cur=null;return}cur=el;
    if(!el.classList.contains("tiltable")){el.classList.add("tiltable");const g=document.createElement("i");g.className="glare";el.appendChild(g)}
    const r=el.getBoundingClientRect(),x=(e.clientX-r.left)/r.width,y=(e.clientY-r.top)/r.height;
    el.style.transition="transform .12s";
    el.style.transform=`perspective(900px) rotateX(${(.5-y)*8}deg) rotateY(${(x-.5)*10}deg) translateY(-6px) scale(1.015)`;
    el.style.setProperty("--gx",x*100+"%");el.style.setProperty("--gy",y*100+"%");
  },{passive:true});
  function reset(el){el.style.transition="transform .6s cubic-bezier(.2,.7,.2,1)";el.style.transform=""}
  document.addEventListener("pointerleave",()=>cur&&reset(cur));
  /* magnetic buttons */
  addEventListener("pointermove",e=>{
    const b=e.target.closest&&e.target.closest(".btn,.t-go,.arrows button");
    $$(".mag-on").forEach(x=>{if(x!==b){x.style.transform="";x.classList.remove("mag-on")}});
    if(!b)return;const r=b.getBoundingClientRect();b.classList.add("mag-on");
    b.style.transition="transform .15s";b.style.transform=`translate(${(e.clientX-r.left-r.width/2)*.22}px,${(e.clientY-r.top-r.height/2)*.35}px)`;
  },{passive:true});
}
/* newly rendered cards (collections filters) */
const mo=new MutationObserver(()=>{if(!reduce)reveal()});
const gr=$("#grid");if(gr)mo.observe(gr,{childList:true});
})();
