// Compact logical views. Each role uses one aggregate store and three processes.
const fs=require('node:fs');
const path=require('node:path');
const specs={
  1:{name:'Veterinarian',actor:'Veterinarian',slug:'veterinarian',processes:[
    {id:'P1',number:'2.1',name:'Manage visits',input:'Availability',output:'Visit schedule',read:'Appointments',write:'Visit updates'},
    {id:'P2',number:'2.2',name:'Manage pet health',input:'Clinical findings',output:'Care summary',read:'Pet history',write:'Clinical records'},
    {id:'P3',number:'2.3',name:'Record vaccinations',input:'Vaccine details',output:'Next due date',read:'Vaccine history',write:'Vaccine records'}
  ],links:[['P1','P2','Appointment details'],['P2','P3','Pet health details']]},
  2:{name:'Staff',actor:'Staff',slug:'staff',processes:[
    {id:'P2',number:'3.2',name:'Schedule visits',input:'Booking details',output:'Confirmation',read:'Appointments',write:'Booking updates'},
    {id:'P1',number:'3.1',name:'Manage clients and pets',input:'Client / pet details',output:'Profile / QR record',read:'Existing profiles',write:'Profile updates'},
    {id:'P3',number:'3.3',name:'Handle sales and stock',input:'Sale / stock details',output:'Receipt / stock level',read:'Products / orders',write:'Sales / stock updates'}
  ],links:[['P1','P2','Client and pet details'],['P1','P3','Client details']]},
  3:{name:'Client - Pet Owner',actor:'Client / Pet Owner',slug:'client',processes:[
    {id:'P2',number:'4.2',name:'Manage visits and care',input:'Booking / feedback',output:'Visit / care details',read:'Appointments / care',write:'Requests / feedback'},
    {id:'P1',number:'4.1',name:'Manage account and pets',input:'Account / pet details',output:'Profiles / status',read:'Owned profiles',write:'Profile submissions'},
    {id:'P3',number:'4.3',name:'Order products',input:'Order / payment proof',output:'Order / payment status',read:'Products / orders',write:'Orders / stock changes'}
  ],links:[['P1','P2','Owner and pet details'],['P1','P3','Client details']]}
};
module.exports=function simpleRole(index){
  const spec=specs[index];if(!spec)throw new Error('Unknown simplified role');
  const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[c]));
  const cells=['<mxCell id="0"/>','<mxCell id="1" parent="0"/>'],shapes=[];
  const common='html=0;whiteSpace=wrap;fontFamily=Times New Roman;fontSize=17;fontColor=#000000;strokeColor=#444444;strokeWidth=1;fillColor=#ffffff;';
  const vertex=(id,val,style,x,y,w,h,parent='1',extra='')=>cells.push(`<mxCell id="${id}" value="${esc(val)}" style="${esc(style)}" vertex="1" parent="${parent}" ${extra}><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`);
  const label=(id,val,x,y,w,h,parent='1',size=17)=>vertex(id,val,`text;${common}strokeColor=none;fillColor=none;align=center;verticalAlign=middle;fontSize=${size};`,x,y,w,h,parent,'connectable="0"');
  const rect=(x,y,w,h,radius=0)=>shapes.push({type:'rect',x,y,w,h,radius,fill:'#ffffff',stroke:'#444444'});
  const line=(x1,y1,x2,y2,arrow=false)=>shapes.push({type:'line',x1,y1,x2,y2,arrow,stroke:'#444444'});
  const text=(s,x,y,size=16,bold=false)=>shapes.push({type:'text',text:s,x,y,size,bold,align:'center',fill:'#111111'});
  function lines(s,n=22){const a=[''];for(const w of s.split(/\s+/)){if(a.at(-1).length+w.length+1>n)a.push(w);else a[a.length-1]+=(a.at(-1)?' ':'')+w;}return a;}
  function multi(s,x,y,size=16,n=22){const a=lines(s,n);a.forEach((t,i)=>text(t,x,y+(i-(a.length-1)/2)*(size+5),size));}
  label('title',`VETRIX - ${spec.name}`,30,20,1260,40,'1',26);
  label('subtitle','Simplified data flow diagram',30,65,1260,25,'1',18);
  text(`VETRIX - ${spec.name}`,660,40,26,true);text('Simplified data flow diagram',660,78,18);
  const pos={};let count=0;
  function edge(a,b,val,ex,ey,ix,iy,dx=0,dy=0){
    cells.push(`<mxCell id="edge-${++count}" value="${esc(lines(val,20).join('\n'))}" style="edgeStyle=orthogonalEdgeStyle;rounded=0;html=0;endArrow=classic;endFill=1;strokeColor=#444444;strokeWidth=1;fontFamily=Times New Roman;fontSize=15;fontColor=#000000;labelBackgroundColor=#ffffff;exitX=${ex};exitY=${ey};exitPerimeter=0;entryX=${ix};entryY=${iy};entryPerimeter=0;" edge="1" source="${a}" target="${b}" parent="1"><mxGeometry relative="1" as="geometry"><mxPoint x="${dx}" y="${dy}" as="offset"/></mxGeometry></mxCell>`);
    const start=pos[a],end=pos[b],sx=start.x+start.w*ex,sy=start.y+start.h*ey,tx=end.x+end.w*ix,ty=end.y+end.h*iy;
    line(sx,sy,tx,ty,true);multi(val,(sx+tx)/2+dx,(sy+ty)/2+dy,15,20);
  }
  spec.processes.forEach((p,i)=>{
    const x=110+i*440,cx=x+110;
    pos[p.id]={x,y:340,w:220,h:130};pos[p.id+'-user']={x:x+10,y:135,w:200,h:75};
    vertex(p.id+'-user',spec.actor,`rounded=1;arcSize=12;${common}`,x+10,135,200,75);
    rect(x+10,135,200,75,10);text(spec.actor,cx,172,17);
    vertex(p.id,p.number,`swimlane;horizontal=1;startSize=26;rounded=0;collapsible=0;${common}swimlaneFillColor=#ffffff;`,x,340,220,130);
    label(p.id+'-name',p.name,8,32,204,90,p.id,18);
    rect(x,340,220,130);line(x,366,x+220,366);text(p.number,cx,353,17);multi(p.name,cx,412,18);
  });
  pos.D0={x:90,y:700,w:1150,h:65};
  vertex('D0','','group;connectable=1;',90,700,1150,65);
  vertex('D0-id','D0',`${common}align=center;verticalAlign=middle;`,0,0,50,65,'D0','connectable="0"');
  vertex('D0-top','','shape=line;strokeColor=#444444;',50,0,1100,0,'D0','connectable="0"');
  vertex('D0-bottom','','shape=line;strokeColor=#444444;',50,65,1100,0,'D0','connectable="0"');
  label('D0-name','Vetrix records',55,0,1080,65,'D0',19);
  rect(90,700,50,65);line(140,700,1240,700);line(140,765,1240,765);text('D0',115,732,17);text('Vetrix records',680,732,19);
  spec.processes.forEach(p=>{
    const x=pos[p.id].x;
    edge(p.id+'-user',p.id,p.input,0.2,1,50/220,0,-80,0);
    edge(p.id,p.id+'-user',p.output,170/220,0,0.8,1,80,0);
    edge('D0',p.id,p.read,(x+50-90)/1150,0,50/220,1,-80,0);
    edge(p.id,'D0',p.write,170/220,1,(x+170-90)/1150,0,80,0);
  });
  for(const [a,b,val]of spec.links){const right=pos[b].x>pos[a].x;edge(a,b,val,right?1:0,0.58,right?0:1,0.58,0,-22);}
  const notes=[
    'Main tasks are grouped into three processes. Process numbers apply to this simplified view.',
    'D0 combines the existing database tables. Repeated user boxes represent the same person.',
    'Direct process arrows show logical data dependencies; the application uses stored records.'
  ];
  if(index===3)notes.push('The clinic approves pet submissions and records care; the client views those results.');
  notes.forEach((n,i)=>{label('note-'+i,n,30,805+i*28,1260,24,'1',14);text(n,660,817+i*28,14);});
  fs.writeFileSync(path.join(__dirname,spec.slug+'-simple.drawing.json'),JSON.stringify({width:1320,height:940,shapes}));
  return `<mxGraphModel dx="1320" dy="940" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1320" pageHeight="940" math="0" shadow="0"><root>${cells.join('')}</root></mxGraphModel>`;
};
