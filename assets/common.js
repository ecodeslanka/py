const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>[...r.querySelectorAll(s)];
const money=n=>n?"LKR "+n.toLocaleString("en-LK"):"On request";
const catUrl=(c,s)=>"collections.html?cat="+encodeURIComponent(c)+(s?"&sub="+encodeURIComponent(s):"");
const isFree=(p,date)=>p.avail&&!(date&&p.booked.includes(date));
const phStyle=p=>{const [a,b]=SWATCH[p.col]||SWATCH.Ivory;return `style="--c1:${a};--c2:${b}"`};
function card(p,date){
  const free=isFree(p,date);
  return `<article class="card"><div class="ph" ${phStyle(p)}>${imgTag(p)}${p.isNew?'<span class="tag">New</span>':''}${!free?'<span class="tag booked">Booked</span>':''}</div>
  <div class="card-b"><small>${p.id} · ${p.sub}</small><h3>${p.name}</h3>
  <div class="meta">${p.col} · ${p.sizes.join(" / ")}<br>${p.svc.join(" · ")}</div>
  <div class="prices"><div><span>Rent</span><b>${p.svc.includes("Rent")?money(p.rent):"—"}</b></div><div><span>Buy</span><b>${p.sale?money(p.sale):"—"}</b></div></div>
  <button class="btn btn-gold" data-inq="${p.id}">Inquire on WhatsApp</button></div></article>`;
}
const WA_SVG='<svg viewBox="0 0 32 32"><path d="M16 3C9 3 3.4 8.6 3.4 15.500c0 2.300.6 4.400 1.700 6.300L3 29l7.400-1.900c1.800 1 3.800 1.500 5.600 1.500 6.900 0 12.600-5.600 12.600-12.500S22.900 3 16 3zm0 22.900c-1.800 0-3.500-.5-5-1.400l-.4-.2-4.400 1.100 1.200-4.300-.3-.4a10.200 10.200 0 0 1-1.600-5.500C5.500 9.900 10.200 5.300 16 5.300s10.500 4.600 10.500 10.300S21.800 25.900 16 25.900zm5.800-7.600c-.3-.2-1.900-.9-2.200-1s-.5-.2-.7.200-.8 1-1 1.200-.4.200-.7.100a8.600 8.600 0 0 1-4.300-3.700c-.3-.6.300-.5.900-1.700.1-.2.100-.4 0-.5s-.7-1.700-1-2.300c-.3-.6-.5-.5-.7-.5h-.6c-.2 0-.5.100-.8.400s-1 1-1 2.400 1 2.800 1.200 3c.1.200 2 3.100 4.900 4.300 1.800.8 2.500.9 3.400.7.600-.1 1.900-.8 2.100-1.500.3-.7.300-1.400.2-1.500s-.3-.2-.6-.4z"/></svg>';

/* shared chrome */
function chrome(){
  const dd=Object.keys(CATS).map(c=>`<a href="${catUrl(c)}">${c}</a>`).join("");
  $("#site-header").outerHTML=`<div class="topbar">Designing | Rent | Sale &nbsp;·&nbsp; <a href="tel:${PHONES[0].tel}">${PHONES[0].show}</a> &nbsp;|&nbsp; <a href="tel:${PHONES[1].tel}">${PHONES[1].show}</a></div>
  <header class="site"><div class="wrap nav">
   <button class="burger" id="burger" aria-label="Open menu" aria-expanded="false"><span></span></button>
   <nav class="menu" id="menu" aria-label="Main navigation">
     <a href="index.html">Home</a>
     <div class="dd"><button type="button">Collections ▾</button><div class="dd-panel">${dd}</div></div>
     <a href="index.html#new">New Arrivals</a><a href="index.html#how">How to Rent</a><a href="index.html#contact">Contact</a>
   </nav>
   <a class="logo" href="index.html" aria-label="Bride2B Bridal Closet"><img src="assets/img/logo-mark.png" alt="Bride2B logo"><span><b>Bride2B</b><small>Bridal Closet</small></span></a>
   <a class="btn btn-gold nav-cta" href="#" data-wa-general>WhatsApp</a></div></header>`;
  $("#site-footer").outerHTML=`<footer class="site"><div class="wrap"><div class="f-grid">
   <div><img class="f-logo" src="assets/img/logo-full.png" alt="Bride2B Bridal Closet"><p style="font-size:.9rem;max-width:300px">Designing | Rent | Sale. Luxury bridal wear for your most beautiful day.</p><p style="margin-top:10px"><a href="tel:${PHONES[0].tel}">📞 ${PHONES[0].show}</a><a href="tel:${PHONES[1].tel}">📞 ${PHONES[1].show}</a></p></div>
   <div><h4>Collections</h4>${Object.keys(CATS).slice(0,4).map(c=>`<a href="${catUrl(c)}">${c}</a>`).join("")}</div>
   <div><h4>Information</h4><a href="index.html#how">How to Rent</a><a href="#">Rental Terms</a><a href="#">Custom Designing</a><a href="#">FAQ</a><a href="#">Privacy Policy</a></div>
   <div><h4>Follow Us</h4><a href="#">Facebook</a><a href="#">Instagram</a><a href="#" data-wa-general>WhatsApp</a></div></div>
   <div class="copy"><span>© ${new Date().getFullYear()} Bride2B Bridal Closet. All rights reserved.</span><span>Catalogue &amp; inquiry website</span></div></div></footer>
  <a class="wa-float" href="#" data-wa-general aria-label="WhatsApp inquiry">${WA_SVG}</a>
  <div class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="m-title"><div class="m-box">
   <button class="m-x" id="m-x" aria-label="Close">×</button><span class="eyebrow">WhatsApp Inquiry</span>
   <h3 id="m-title"></h3><p class="sub" id="m-sub"></p>
   <div class="field"><label for="m-name">Your Name</label><input type="text" id="m-name" autocomplete="name"></div>
   <div class="field"><label for="m-phone">Contact Number</label><input type="tel" id="m-phone" autocomplete="tel"></div>
   <div class="field"><label>Service</label><div class="seg pills" id="m-svc"></div></div>
   <div class="field"><label for="m-date">Wedding Date</label><input type="date" id="m-date"></div>
   <label>Message Preview</label><div class="preview" id="m-prev"></div>
   <button class="btn btn-wa" style="width:100%" id="m-send">Send on WhatsApp</button>
   <p class="sub" style="margin:12px 0 0;text-align:center">This is an inquiry, not a booking confirmation.</p></div></div>`;
  $("#burger").onclick=e=>{const o=$("#menu").classList.toggle("open");e.currentTarget.setAttribute("aria-expanded",o)};
}

/* WhatsApp inquiry */
let cur=null,svc="Rental",prefDate="";
function msg(){
  const n=$("#m-name").value.trim(),ph=$("#m-phone").value.trim(),d=$("#m-date").value;
  const dt=d?new Date(d+"T00:00").toLocaleDateString("en-GB",{day:"numeric",month:"long",year:"numeric"}):"Not decided yet";
  return `Hello Bride2B Bridal Closet! ❤️\n\nI'm interested ${cur?"in this dress":"in your bridal collection"}.\n`+(cur?`\nProduct: ${cur.name}\nProduct Code: ${cur.id}\n`:"")+`\nService: ${svc}\nWedding Date: ${dt}\nName: ${n||"-"}\nContact: ${ph||"-"}\n\nPlease send me more details about availability and pricing.\n\nThank you!`;
}
function openModal(p){
  cur=p;$("#m-title").textContent=p?p.name:"Chat with Bride2B";
  $("#m-sub").textContent=p?`Product Code: ${p.id}`:"Tell us about your wedding and we’ll help you find the perfect dress.";
  const list=p?p.svc.map(s=>s==="Rent"?"Rental":s):["Rental","Sale","Custom Made"];svc=list[0];
  $("#m-svc").innerHTML=list.map(v=>`<button type="button" class="pill${v===svc?" on":""}" data-s="${v}">${v}</button>`).join("");
  $("#m-date").value=window.getWeddingDate?getWeddingDate():"";
  $("#modal").classList.add("open");$("#m-prev").textContent=msg();
}
function bindModal(){
  const upd=()=>$("#m-prev").textContent=msg();
  $("#m-svc").onclick=e=>{const b=e.target.closest("[data-s]");if(!b)return;svc=b.dataset.s;$$("#m-svc .pill").forEach(x=>x.classList.toggle("on",x===b));upd()};
  ["m-name","m-phone","m-date"].forEach(i=>$("#"+i).addEventListener("input",upd));
  $("#m-send").onclick=()=>window.open(`https://wa.me/${WA_NUMBER}?text=${encodeURIComponent(msg())}`,"_blank","noopener");
  const close=()=>$("#modal").classList.remove("open");
  $("#m-x").onclick=close;$("#modal").onclick=e=>{if(e.target.id==="modal")close()};
  document.addEventListener("keydown",e=>{if(e.key==="Escape")close()});
  document.addEventListener("click",e=>{
    const inq=e.target.closest("[data-inq]");if(inq){e.preventDefault();openModal(P.find(p=>p.id===inq.dataset.inq));}
    const g=e.target.closest("[data-wa-general]");if(g){e.preventDefault();openModal(null);}
  });
}
chrome();bindModal();
