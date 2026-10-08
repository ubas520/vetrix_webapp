// Native draw.io cells and connected edges; no embedded diagram images.
const fs = require('node:fs');
const path = require('node:path');
const xml = s => String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[c]));
const source = fs.readFileSync(path.join(__dirname,'../DATA_FLOW_DIAGRAMS.md'),'utf8');
const blocks = [...source.matchAll(/## ([^\n]+)\n\n```mermaid\n([\s\S]*?)```/g)];
const names=['Administrator','Veterinarian','Staff','Client - Pet Owner'];
const slugs=['administrator','veterinarian','staff','client'];
const common='html=0;whiteSpace=wrap;fontFamily=Times New Roman;fontSize=16;fontColor=#000000;strokeColor=#555555;strokeWidth=1;fillColor=#ffffff;';
const pages=[];
const summaries=[];
for (const [index,block] of blocks.entries()) {
  const nodes={}, flows=[];
  for(const line of block[2].split('\n').map(s=>s.trim())) {
    let m;
    if((m=line.match(/^(\w+) -->\|(.+)\| (\w+)$/))) flows.push({from:m[1],label:m[2],to:m[3]});
    else if((m=line.match(/^(P\d+)\("(.+)"\)$/)))nodes[m[1]]={type:'process',label:m[2]};
    else if((m=line.match(/^(D\d+)\[\("(.+)"\)\]$/)))nodes[m[1]]={type:'store',label:m[2]};
    else if((m=line.match(/^(\w+)\[(.+)\]$/)))nodes[m[1]]={type:'actor',label:m[2]};
  }
  const actor=Object.keys(nodes).find(k=>nodes[k].type==='actor');
  const processes=Object.keys(nodes).filter(k=>nodes[k].type==='process');
  const cells=['<mxCell id="0"/>','<mxCell id="1" parent="0"/>'];
  const vertex=(id,value,style,x,y,w,h,parent='1',extra='')=>cells.push(`<mxCell id="${id}" value="${xml(value)}" style="${xml(style)}" vertex="1" parent="${parent}" ${extra}><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`);
  const label=(id,value,x,y,w,h,parent='1',size=16)=>vertex(id,value,`text;${common}strokeColor=none;fillColor=none;align=center;verticalAlign=middle;fontSize=${size};`,x,y,w,h,parent,'connectable="0"');
  const line=(id,x,y,w,h,parent)=>vertex(id,'','shape=line;strokeColor=#555555;strokeWidth=1;',x,y,w,h,parent,'connectable="0"');
  let edgeCount=0;
  function edge(from,to,flow,exitX,exitY,entryX,entryY,points=[],labelPosition=0,labelOffsetY=-12) {
    const id=`edge-${++edgeCount}`;
    const style=`edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=0;endArrow=classic;endFill=1;strokeColor=#333333;strokeWidth=1;fontFamily=Times New Roman;fontSize=13;fontColor=#000000;labelBackgroundColor=#ffffff;exitX=${exitX};exitY=${exitY};exitDx=0;exitDy=0;exitPerimeter=0;entryX=${entryX};entryY=${entryY};entryDx=0;entryDy=0;entryPerimeter=0;`;
    cells.push(`<mxCell id="${id}" value="${xml(flow)}" style="${style}" edge="1" source="${from}" target="${to}" parent="1"><mxGeometry x="${labelPosition}" relative="1" as="geometry">${points.length?`<Array as="points">${points.map(p=>`<mxPoint x="${p.x}" y="${p.y}"/>`).join('')}</Array>`:''}<mxPoint x="0" y="${labelOffsetY}" as="offset"/></mxGeometry></mxCell>`);
  }
  label('title',`VETRIX - ${names[index]} Data Flow Diagram`,40,20,1320,40,'1',25);
  label('subtitle','Simplified Level 1 role view',40,65,1320,25,'1',16);
  let y=140;
  const processLayout={};
  for(const pid of processes) {
    const connected=flows.filter(f=>f.from===pid||f.to===pid);
    const stores=[...new Set(connected.flatMap(f=>[f.from,f.to]).filter(k=>nodes[k]?.type==='store'))];
    const h=Math.max(250,stores.length*120+30),cy=y+h/2;
    processLayout[pid]={top:cy-80,bottom:cy+80,rowTop:y,rowBottom:y+h};
    const roleId=`${pid}-${actor}`;
    vertex(roleId,nodes[actor].label,`rounded=1;arcSize=12;${common}align=center;verticalAlign=middle;`,40,cy-60,165,120);
    // A rectangular process with a shallow, separate number compartment.
    vertex(pid,nodes[pid].label.split(' ')[0],`swimlane;horizontal=1;startSize=26;rounded=0;collapsible=0;${common}align=center;verticalAlign=middle;swimlaneFillColor=#ffffff;`,555,cy-80,220,160);
    label(`${pid}-name`,nodes[pid].label.replace(/^\S+ /,''),6,31,208,123,pid,17);
    const incoming=connected.find(f=>f.from===actor),outgoing=connected.find(f=>f.to===actor);
    if(incoming)edge(roleId,pid,incoming.label,1,0.32,0,0.365);
    if(outgoing)edge(pid,roleId,outgoing.label,0,0.75,1,0.83);
    const ports=[];
    stores.forEach((sid,i)=>{
      const storeId=`${pid}-${sid}`,sy=y+15+i*120+30;
      // Grouped native primitives form the open-right data-store symbol.
      vertex(storeId,'','group;connectable=1;',1110,sy,285,60);
      vertex(`${storeId}-id`,sid,`${common}align=center;verticalAlign=middle;`,0,0,36,60,storeId,'connectable="0"');
      line(`${storeId}-top`,36,0,249,0,storeId);
      line(`${storeId}-bottom`,36,60,249,0,storeId);
      label(`${storeId}-name`,nodes[sid].label.replace(/^D\d+ /,''),43,3,235,54,storeId,15);
      const read=connected.find(f=>f.from===sid),write=connected.find(f=>f.to===sid);
      if(read)ports.push({storeId,label:read.label,read:true,targetY:sy+(write?15:30),fraction:write?0.25:0.5});
      if(write)ports.push({storeId,label:write.label,read:false,targetY:sy+(read?45:30),fraction:read?0.75:0.5});
    });
    ports.forEach((p,i)=>{
      const py=cy-36+(i+1)*96/(ports.length+1),fraction=(py-(cy-80))/160;
      const rail=805+Math.abs(i-(ports.length-1)/2)*14;
      // A long horizontal run next to the store leaves room for editable labels.
      const route=[{x:rail,y:py},{x:rail,y:p.targetY}];
      if(p.read)edge(p.storeId,pid,p.label,0,p.fraction,1,fraction,[...route].reverse());
      else edge(pid,p.storeId,p.label,1,fraction,0,p.fraction,route);
    });
    y+=h+55;
  }
  const directFlows=flows.filter(f=>nodes[f.from]?.type==='process'&&nodes[f.to]?.type==='process');
  const wrapFlow=s=>{const lines=[''];for(const word of s.split(/\s+/)){if(lines.at(-1).length+word.length+1>26)lines.push(word);else lines[lines.length-1]+=(lines.at(-1)?' ':'')+word;}return lines.join('\n');};
  directFlows.forEach((f,i)=>{
    const a=processLayout[f.from],b=processLayout[f.to],down=b.top>a.top;
    const outgoing=directFlows.filter(e=>e.from===f.from),incoming=directFlows.filter(e=>e.to===f.to);
    const outIndex=outgoing.indexOf(f),inIndex=incoming.indexOf(f);
    const sx=0.2+0.6*(outIndex+1)/(outgoing.length+1),tx=0.2+0.6*(inIndex+1)/(incoming.length+1);
    const startX=555+220*sx,endX=555+220*tx;
    const startY=down?a.bottom:a.top,endY=down?b.top:b.bottom;
    const outY=down?a.rowBottom+8+outIndex*10:a.rowTop-8-outIndex*10;
    const inY=down?b.rowTop-8-inIndex*10:b.rowBottom+8+inIndex*10;
    const railX=1535+i*225;
    const points=[{x:startX,y:outY},{x:railX,y:outY},{x:railX,y:inY},{x:endX,y:inY}];
    const firstLeg=Math.abs(startY-outY)+railX-startX;
    const middleLeg=Math.abs(inY-outY);
    const lastLeg=railX-endX+Math.abs(endY-inY);
    const labelPosition=2*(firstLeg+middleLeg/2)/(firstLeg+middleLeg+lastLeg)-1;
    edge(f.from,f.to,wrapFlow(f.label),sx,down?1:0,tx,down?0:1,points,labelPosition,0);
  });
  label('note','Repeated role boxes and store IDs refer to the same user or logical store. Arrows represent data, not step order.',40,y,1355,35,'1',14);
  label('scope',index===3?'Client workflows use the mobile API. Authentication and routine audit writes are omitted.':'Selected business processes. Authentication and routine audit writes are omitted.',40,y+35,1355,30,'1',14);
  if(directFlows.length)label('direct-flow-note','Direct process arrows summarize logical data dependencies; the application uses shared data stores for these exchanges.',40,y+70,1355,35,'1',14);
  const pageHeight=Math.ceil((y+140)/50)*50;
  const pageWidth=directFlows.length?Math.ceil((1535+(directFlows.length-1)*225+140)/50)*50:1440;
  const model=require('./erd-role-drawio.cjs')(index);
  const page=`<diagram id="vetrix-${slugs[index]}" name="${xml(names[index])}">${model}</diagram>`;
  pages.push(page);
  fs.writeFileSync(path.join(__dirname,`${slugs[index]}-school.drawio`),`<?xml version="1.0" encoding="UTF-8"?><mxfile host="app.diagrams.net" type="device" compressed="false">${page}</mxfile>`);
  if(edgeCount!==flows.length)throw new Error(`Missing flow on ${names[index]}`);
  const erdSpec=require('./erd-role-drawio.cjs').specs[index];
  summaries.push({page:names[index],processes:erdSpec.processes.length,connectedFlows:(model.match(/ edge="1"/g)||[]).length,uniqueERDTables:new Set(erdSpec.processes.flatMap(p=>p.stores.map(s=>s.n))).size,directProcessConnections:erdSpec.links.length});
}
fs.writeFileSync(path.join(__dirname,'VETRIX-School-DFD.drawio'),`<?xml version="1.0" encoding="UTF-8"?><mxfile host="app.diagrams.net" type="device" compressed="false">${pages.join('')}</mxfile>`);
console.log(JSON.stringify(summaries,null,2));
