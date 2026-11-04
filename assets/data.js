/* ===== CONFIG – edit these ===== */
const WA_NUMBER = "94753876008"; // WhatsApp number: country code first, no + or spaces (075 387 6008)
const PHONES = [{show:"075 387 6008",tel:"+94753876008"},{show:"077 411 6691",tel:"+94774116691"}];
const CATS = {
  "Bridal Gowns":["Luxury Bridal Gowns","Mermaid Gowns","Princess Gowns","A-Line Gowns","Reception Gowns","Minimal Bridal Gowns"],
  "Kandyan Sarees":["Luxury Kandyan","Modern Kandyan","Traditional Kandyan","Red & Gold Collection","Other Colour Collection"],
  "Bridal Lehengas":["Luxury Lehengas","Traditional Lehengas","Modern Lehengas","Engagement Lehengas"],
  "Engagement & Reception":["Engagement Dresses","Reception Dresses","Going Away Dresses","Pre-shoot Dresses"],
  "Bridal Sarees":["Designer Sarees","Wedding Sarees","Luxury Sarees"],
  "Special Collections":["New Arrivals","Best Sellers","Exclusive Designs","Sale Collection"]
};
const COLOURS=["Ivory","White","Red","Pink","Gold","Maroon","Blush"];
const SIZES=["XS","S","M","L","XL","Custom"];
const DYN={
  "Bridal Gowns":{Silhouette:["Mermaid","Princess","A-Line","Minimal"],Neckline:["Sweetheart","V-Neck","Off-Shoulder","Square"],Sleeve:["Sleeveless","Cap","Long","Off-Shoulder"]},
  "Kandyan Sarees":{Style:["Traditional","Modern","Luxury"],Jacket:["Full Sleeve","Short Sleeve","Back Design"]},
  "Bridal Lehengas":{Embroidery:["Zari","Sequin","Stone Work"],Style:["Traditional","Modern","Luxury"]}
};
/* Sample products – later replaced by Admin Panel / database */
const P=[
 {id:"B2B-001",name:"Luxury Bridal Gown",cat:"Bridal Gowns",sub:"Princess Gowns",col:"Ivory",sizes:["S","M","L","Custom"],svc:["Rent","Sale"],rent:45000,sale:185000,avail:true,isNew:true,best:true,booked:["2027-03-15"],a:{Silhouette:"Princess",Neckline:"Sweetheart",Sleeve:"Sleeveless"}},
 {id:"B2B-002",name:"Mermaid Lace Gown",cat:"Bridal Gowns",sub:"Mermaid Gowns",col:"White",sizes:["XS","S","M"],svc:["Rent","Sale","Custom Made"],rent:38000,sale:150000,avail:true,isNew:true,best:false,booked:[],a:{Silhouette:"Mermaid",Neckline:"V-Neck",Sleeve:"Long"}},
 {id:"B2B-003",name:"Minimal Satin Gown",cat:"Bridal Gowns",sub:"Minimal Bridal Gowns",col:"Ivory",sizes:["M","L","XL"],svc:["Rent"],rent:30000,sale:null,avail:false,isNew:false,best:true,booked:[],a:{Silhouette:"Minimal",Neckline:"Square",Sleeve:"Cap"}},
 {id:"B2B-004",name:"Royal Kandyan Saree",cat:"Kandyan Sarees",sub:"Luxury Kandyan",col:"Gold",sizes:["Custom"],svc:["Rent","Sale"],rent:35000,sale:120000,avail:true,isNew:false,best:true,booked:["2027-02-20"],a:{Style:"Luxury",Jacket:"Full Sleeve"}},
 {id:"B2B-005",name:"Red & Gold Kandyan",cat:"Kandyan Sarees",sub:"Red & Gold Collection",col:"Red",sizes:["Custom"],svc:["Rent","Sale","Custom Made"],rent:32000,sale:110000,avail:true,isNew:true,best:false,booked:[],a:{Style:"Traditional",Jacket:"Back Design"}},
 {id:"B2B-006",name:"Modern Kandyan Set",cat:"Kandyan Sarees",sub:"Modern Kandyan",col:"Pink",sizes:["S","M","L"],svc:["Rent"],rent:28000,sale:null,avail:true,isNew:false,best:false,booked:[],a:{Style:"Modern",Jacket:"Short Sleeve"}},
 {id:"B2B-007",name:"Zari Bridal Lehenga",cat:"Bridal Lehengas",sub:"Luxury Lehengas",col:"Maroon",sizes:["S","M","L","Custom"],svc:["Rent","Sale"],rent:40000,sale:165000,avail:true,isNew:true,best:true,booked:[],a:{Embroidery:"Zari",Style:"Luxury"}},
 {id:"B2B-008",name:"Engagement Lehenga",cat:"Bridal Lehengas",sub:"Engagement Lehengas",col:"Blush",sizes:["XS","S","M"],svc:["Rent","Sale"],rent:26000,sale:95000,avail:false,isNew:false,best:false,booked:[],a:{Embroidery:"Sequin",Style:"Modern"}},
 {id:"B2B-009",name:"Reception Evening Dress",cat:"Engagement & Reception",sub:"Reception Dresses",col:"Pink",sizes:["S","M","L"],svc:["Rent","Sale"],rent:18000,sale:70000,avail:true,isNew:true,best:false,booked:[],a:{}},
 {id:"B2B-010",name:"Pre-shoot Flowing Gown",cat:"Engagement & Reception",sub:"Pre-shoot Dresses",col:"White",sizes:["XS","S","M","L"],svc:["Rent"],rent:15000,sale:null,avail:true,isNew:false,best:true,booked:[],a:{}},
 {id:"B2B-011",name:"Designer Wedding Saree",cat:"Bridal Sarees",sub:"Wedding Sarees",col:"Gold",sizes:["Custom"],svc:["Rent","Sale"],rent:22000,sale:85000,avail:true,isNew:false,best:false,booked:[],a:{}},
 {id:"B2B-012",name:"Princess Ball Gown",cat:"Bridal Gowns",sub:"Princess Gowns",col:"Ivory",sizes:["M","L","XL"],svc:["Rent","Sale","Custom Made"],rent:52000,sale:210000,avail:true,isNew:true,best:true,booked:[],a:{Silhouette:"Princess",Neckline:"Off-Shoulder",Sleeve:"Off-Shoulder"}}
];
const SWATCH={Ivory:["#FBF3DE","#E6D3A4"],White:["#FFFFFF","#E9E1CF"],Red:["#E8B4A8","#A8322A"],Pink:["#F8DCDD","#D99AA3"],Gold:["#F2DFA8","#B8924A"],Maroon:["#D9A3A3","#6E1F2B"],Blush:["#FBE6E0","#E3B3A8"]};
/* Category tile colours + blurb */
const CAT_META={
  "Bridal Gowns":{c:["#FBF3DE","#CFAE63"],t:"Mermaid, princess & A-line"},
  "Kandyan Sarees":{c:["#F2DFA8","#8F6D2E"],t:"Heritage, redefined"},
  "Bridal Lehengas":{c:["#E8B4A8","#6E1F2B"],t:"Zari & statement bridal"},
  "Engagement & Reception":{c:["#F8DCDD","#C98A94"],t:"Every celebration"},
  "Bridal Sarees":{c:["#EFE0C0","#B8924A"],t:"Designer & wedding"},
  "Special Collections":{c:["#E3CB93","#2a2216"],t:"New, best & exclusive"}
};

/* Images: put your real photos in assets/photos/ (B2B-001.jpg ... cat-1.jpg ... slide-1.jpg).
   If a photo is missing, the sample illustration in assets/img/ is shown instead. */
P.forEach(p=>{p.photo="assets/photos/"+p.id+".jpg";p.img="assets/img/"+p.id+".svg"});
Object.keys(CAT_META).forEach((c,i)=>{const n="cat-"+(i+1);CAT_META[c].bg=`url(assets/photos/${n}.jpg), url(assets/img/${n}.svg)`});
const imgTag=p=>`<img src="${p.photo}" alt="${p.name} – ${p.cat}" loading="lazy" onerror="this.onerror=null;this.src='${p.img}'">`;
