// ERD-aligned role diagrams. Table names are transcribed from erd (2).png.
const fs=require('node:fs'),path=require('node:path');
const tables=['users','pets','appointments','medical_records','vaccinations','edit_request','pet_update_logs','qr_tokens','inventory_items','inventory_movements','pos_transactions','pos_transaction_items','notifications','feedback','account_verification_tokens','email_outbox','audit_logs'];
const store=(n,read,write)=>({n,read,write});
const specs=[
 {name:'Administrator',slug:'administrator',actor:'Administrator',processes:[
  {id:'P1',no:'1.1',name:'Manage accounts',input:'Account details',output:'Account status',stores:[store(1,'Account details','Account updates'),store(15,'Verification status','Verification tokens'),store(16,null,'Email records')]},
  {id:'P2',no:'1.2',name:'Verify pets and edits',input:'Review decisions',output:'Review results',stores:[store(1,'Owner details'),store(2,'Pet profiles','Verified profiles'),store(6,'Edit requests','Review decisions'),store(7,null,'Pet change history'),store(8,'Pet QR tokens','QR token updates')]},
  {id:'P3',no:'1.3',name:'Manage clinic schedules',input:'Schedule details',output:'Clinic schedule',stores:[store(1,'Client and vet details'),store(2,'Approved pet details'),store(3,'Appointment details','Schedule updates'),store(13,null,'Appointment notices')]},
  {id:'P4',no:'1.4',name:'Manage inventory and sales',input:'Stock and sale details',output:'Stock and sale results',stores:[store(9,'Product and stock data','Product and stock updates'),store(10,null,'Stock movements'),store(11,'Sales records','Sale transactions'),store(12,'Sale item details','Sale line items')]},
  {id:'P5',no:'1.5',name:'Review reports and activity',input:'Report request',output:'Reports and activity',stores:[store(3,'Appointment data'),store(4,'Medical history'),store(5,'Vaccination history'),store(11,'Sales totals'),store(14,'Client feedback'),store(17,'Activity history')]}
 ],links:[]},
 {name:'Veterinarian',slug:'veterinarian',actor:'Veterinarian',processes:[
  {id:'P1',no:'2.1',name:'Manage visits',input:'Visit status',output:'Assigned visits',stores:[store(3,'Assigned appointments','Completed visit status')]},
  {id:'P2',no:'2.2',name:'Manage pet health',input:'Clinical findings',output:'Care summary',stores:[store(2,'Pet health details','Health updates'),store(4,'Medical history','Medical records'),store(6,'Requested pet changes','Review decisions'),store(7,null,'Pet change history')]},
  {id:'P3',no:'2.3',name:'Record vaccinations',input:'Vaccine details',output:'Vaccination schedule',stores:[store(2,'Pet identity'),store(5,'Vaccination history','Vaccine and due date')]}
 ],links:[['P1','P2','Appointment details'],['P2','P3','Pet health details']]},
 {name:'Staff',slug:'staff',actor:'Staff',processes:[
  {id:'P2',no:'3.2',name:'Schedule visits',input:'Booking details',output:'Booking confirmation',stores:[store(3,'Appointment details','Booking updates'),store(2,'Approved pet details'),store(13,null,'Appointment notices')]},
  {id:'P1',no:'3.1',name:'Manage clients and pets',input:'Client and pet details',output:'Profile and QR record',stores:[store(1,'Client details','Client records'),store(2,'Pet profiles','Pet records'),store(8,'Pet QR tokens')]},
  {id:'P3',no:'3.3',name:'Handle sales and stock',input:'Sale and stock details',output:'Sale and stock results',stores:[store(9,'Products and stock','Stock updates'),store(10,null,'Stock movements'),store(11,'Sale transactions','Sale transactions'),store(12,'Sale item details','Sale line items')]}
 ],links:[['P1','P2','Client and pet details'],['P1','P3','Client details']]},
 {name:'Client - Pet Owner',slug:'client',actor:'Client / Pet Owner',processes:[
  {id:'P2',no:'4.2',name:'Manage visits and care',input:'Booking and feedback',output:'Visit and care details',stores:[store(3,'Owned appointments','Booking or cancellation'),store(4,'Owned pets medical history'),store(5,'Owned pets vaccinations'),store(13,'Client notifications','Read status'),store(14,'Previous feedback','Rating and comment')]},
  {id:'P1',no:'4.1',name:'Manage account and pets',input:'Account and pet details',output:'Profiles and status',stores:[store(1,'Client profile','Registration and profile'),store(2,'Owned pet profiles','Pet submissions'),store(6,'Edit review status','Pet edit requests'),store(8,'Owned pets QR tokens'),store(15,'Verification status','Verification updates')]},
  {id:'P3',no:'4.3',name:'View products and purchases',input:'Product or history request',output:'Products and purchases',stores:[store(9,'Available products'),store(11,'Owned purchase history'),store(12,'Purchased item details')]}
 ],links:[['P1','P2','Owner and pet details'],['P1','P3','Client identity']]}
];
module.exports=function erdRole(index){
 const spec=specs[index],cells=['<mxCell id="0"/>','<mxCell id="1" parent="0"/>'],shapes=[],boxes={};
 const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[c]));
 const base='html=0;whiteSpace=wrap;fontFamily=Times New Roman;fontSize=16;fontColor=#000000;strokeColor=#444444;strokeWidth=1;fillColor=#ffffff;';
 const vertex=(id,val,style,x,y,w,h,parent='1',extra='')=>cells.push(`<mxCell id="${id}" value="${esc(val)}" style="${esc(style)}" vertex="1" parent="${parent}" ${extra}><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`);
 const label=(id,val,x,y,w,h,parent='1',size=16)=>vertex(id,val,`text;${base}strokeColor=none;fillColor=none;align=center;verticalAlign=middle;fontSize=${size};`,x,y,w,h,parent,'connectable="0"');
 const rect=(x,y,w,h,radius=0)=>shapes.push({type:'rect',x,y,w,h,radius,fill:'#ffffff',stroke:'#444444'});
 const line=(x1,y1,x2,y2,arrow=false)=>shapes.push({type:'line',x1,y1,x2,y2,arrow,stroke:'#444444'});
 const text=(s,x,y,size=16,bold=false)=>shapes.push({type:'text',text:s,x,y,size,bold,align:'center',fill:'#111111'});
 const wrap=(s,n=25)=>{const a=[''];for(const w of s.split(/\s+/)){if(a.at(-1).length+w.length+1>n)a.push(w);else a[a.length-1]+=(a.at(-1)?' ':'')+w;}return a;};
 const multi=(s,x,y,size=15,n=25)=>{const a=wrap(s,n);a.forEach((t,i)=>text(t,x,y+(i-(a.length-1)/2)*(size+5),size));};
 let edgeCount=0;
 function edge(a,b,val,ex,ey,ix,iy,points=[],labelPosition=0,offsetX=0,offsetY=-18){
  const A=boxes[a],B=boxes[b],start={x:A.x+A.w*ex,y:A.y+A.h*ey},end={x:B.x+B.w*ix,y:B.y+B.h*iy};
  const route=[start,...points,end];
  const id=`flow-${++edgeCount}`;
  const style=`edgeStyle=orthogonalEdgeStyle;rounded=0;html=0;endArrow=classic;endFill=1;strokeColor=#444444;strokeWidth=1;fontFamily=Times New Roman;fontSize=14;fontColor=#000000;labelBackgroundColor=#ffffff;exitX=${ex};exitY=${ey};exitPerimeter=0;entryX=${ix};entryY=${iy};entryPerimeter=0;`;
  cells.push(`<mxCell id="${id}" value="${esc(wrap(val,27).join('\n'))}" style="${style}" edge="1" source="${a}" target="${b}" parent="1"><mxGeometry x="${labelPosition}" relative="1" as="geometry">${points.length?`<Array as="points">${points.map(p=>`<mxPoint x="${p.x}" y="${p.y}"/>`).join('')}</Array>`:''}<mxPoint x="${offsetX}" y="${offsetY}" as="offset"/></mxGeometry></mxCell>`);
  route.slice(1).forEach((p,i)=>line(route[i].x,route[i].y,p.x,p.y,i===route.length-2));
  const lengths=route.slice(1).map((p,i)=>Math.abs(p.x-route[i].x)+Math.abs(p.y-route[i].y));
  let distance=lengths.reduce((a,b)=>a+b,0)*(labelPosition+1)/2,at=start;
  for(let i=0;i<lengths.length;i++){if(distance<=lengths[i]){const f=lengths[i]?distance/lengths[i]:0;at={x:route[i].x+(route[i+1].x-route[i].x)*f,y:route[i].y+(route[i+1].y-route[i].y)*f};break;}distance-=lengths[i];}
  multi(val,at.x+offsetX,at.y+offsetY,14,27);
 }
 label('title',`VETRIX - ${spec.name}`,20,18,1380,40,'1',26);text(`VETRIX - ${spec.name}`,710,38,26,true);
 label('subtitle','Data stores matched to the supplied ERD',20,62,1380,30,'1',18);text('Data stores matched to the supplied ERD',710,77,18);
 let y=130;
 for(const p of spec.processes){
  const h=Math.max(220,p.stores.length*105+20),cy=y+h/2;
  boxes[p.id]={x:490,y:cy-75,w:220,h:150};boxes[p.id+'-user']={x:35,y:cy-55,w:175,h:110};
  vertex(p.id+'-user',spec.actor,`rounded=1;arcSize=12;${base}`,35,cy-55,175,110);rect(35,cy-55,175,110,10);multi(spec.actor,122.5,cy,17,18);
  vertex(p.id,p.no,`swimlane;horizontal=1;startSize=25;rounded=0;collapsible=0;${base}swimlaneFillColor=#ffffff;`,490,cy-75,220,150);
  label(p.id+'-name',p.name,7,30,206,110,p.id,18);rect(490,cy-75,220,150);line(490,cy-50,710,cy-50);text(p.no,600,cy-62.5,17);multi(p.name,600,cy+17,18,23);
  edge(p.id+'-user',p.id,p.input,1,0.3,0,(53/150));edge(p.id,p.id+'-user',p.output,0,0.78,1,(117-20)/110);
  const ports=[];
  p.stores.forEach((s,i)=>{
   const id=p.id+'-D'+s.n,sy=y+20+i*105;
   boxes[id]={x:1080,y:sy,w:325,h:70};
   vertex(id,'','group;connectable=1;',1080,sy,325,70);
   vertex(id+'-id','D'+s.n,`${base}align=center;verticalAlign=middle;`,0,0,45,70,id,'connectable="0"');
   vertex(id+'-top','','shape=line;strokeColor=#444444;',45,0,280,0,id,'connectable="0"');vertex(id+'-bottom','','shape=line;strokeColor=#444444;',45,70,280,0,id,'connectable="0"');
   label(id+'-name',tables[s.n-1],50,0,270,70,id,16);
   rect(1080,sy,45,70);line(1125,sy,1405,sy);line(1125,sy+70,1405,sy+70);text('D'+s.n,1102.5,sy+35,16);text(tables[s.n-1],1260,sy+35,16);
   if(s.read)ports.push({id,label:s.read,read:true,sy:sy+(s.write?19:35),fraction:s.write?19/70:0.5});
   if(s.write)ports.push({id,label:s.write,read:false,sy:sy+(s.read?55:35),fraction:s.read?55/70:0.5});
  });
  ports.forEach((t,i)=>{
   const fraction=0.25+(i+1)*0.7/(ports.length+1),py=cy-75+150*fraction,rail=740+Math.abs(i-(ports.length-1)/2)*12;
   const route=[{x:rail,y:py},{x:rail,y:t.sy}];const total=(rail-710)+Math.abs(t.sy-py)+(1080-rail),position=1-2*145/total;
   if(t.read)edge(t.id,p.id,t.label,0,t.fraction,1,fraction,route.slice().reverse(),-position,0,-13);
   else edge(p.id,t.id,t.label,1,fraction,0,t.fraction,route,position,0,-13);
  });
  y+=h+90;
 }
 for(const [a,b,val]of spec.links){const down=boxes[b].y>boxes[a].y;edge(a,b,val,0.5,down?1:0,0.5,down?0:1,[],0,115,0);}
 const notes=['D1-D17 are assigned consistently from the 17 table names in your ERD; the ERD itself has no D-numbers.',
 'Only relevant tables are shown. Repeated D-numbers identify the same table; repeated user boxes identify the same person.',
 'Direct process arrows show logical data dependencies. These overview diagrams omit routine authentication and logging flows.'];
 if(index===3)notes.push('ERD scope: product browsing and recorded purchases only; order and payment-proof tables are not present in this ERD.');
 notes.forEach((s,i)=>{label('note-'+i,s,20,y+i*27,1380,25,'1',13);text(s,710,y+12+i*27,13);});
 const height=y+notes.length*27+20;
 fs.writeFileSync(path.join(__dirname,spec.slug+'-erd.drawing.json'),JSON.stringify({width:1430,height,shapes}));
 return `<mxGraphModel dx="1430" dy="${height}" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1430" pageHeight="${height}" math="0" shadow="0"><root>${cells.join('')}</root></mxGraphModel>`;
};
module.exports.tables=tables;
module.exports.specs=specs;
