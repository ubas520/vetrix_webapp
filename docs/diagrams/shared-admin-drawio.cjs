// Shared stores make the real, persisted connections between admin processes explicit.
module.exports = function sharedAdmin(nodes, flows) {
  const fs=require('node:fs'),path=require('node:path');
  const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[c]));
  const base='html=0;whiteSpace=wrap;fontFamily=Times New Roman;fontSize=16;fontColor=#000000;strokeColor=#555555;strokeWidth=1;fillColor=#ffffff;';
  const cells=['<mxCell id="0"/>','<mxCell id="1" parent="0"/>'];
  const shapes=[], boxes={};
  const rect=(x,y,w,h,radius=0)=>shapes.push({type:'rect',x,y,w,h,radius,fill:'#ffffff',stroke:'#555555'});
  const ln=(x1,y1,x2,y2,arrow=false)=>shapes.push({type:'line',x1,y1,x2,y2,stroke:'#555555',arrow});
  const txt=(text,x,y,size=16,bold=false)=>shapes.push({type:'text',text,x,y,size,bold,fill:'#111111',align:'center'});
  const wrap=(s,n=34)=>{const a=[''];for(const w of s.split(/\s+/)){if(a.at(-1).length+w.length+1>n)a.push(w);else a[a.length-1]+=(a.at(-1)?' ':'')+w;}return a;};
  const multi=(s,x,y,n=24,size=16)=>{const a=wrap(s,n);a.forEach((t,i)=>txt(t,x,y+(i-(a.length-1)/2)*(size+5),size));};
  const vertex=(id,val,style,x,y,w,h,parent='1',extra='')=>cells.push(`<mxCell id="${id}" value="${esc(val)}" style="${esc(style)}" vertex="1" parent="${parent}" ${extra}><mxGeometry x="${x}" y="${y}" width="${w}" height="${h}" as="geometry"/></mxCell>`);
  const label=(id,val,x,y,w,h,parent='1',size=16)=>vertex(id,val,`text;${base}strokeColor=none;fillColor=none;align=center;verticalAlign=middle;fontSize=${size};`,x,y,w,h,parent,'connectable="0"');
  label('title','VETRIX - Administrator Data Flow Diagram',40,20,1520,40,'1',25);
  label('subtitle','Connected role view using shared data stores',40,65,1520,30);
  txt('VETRIX - Administrator Data Flow Diagram',800,42,25,true);
  txt('Connected role view using shared data stores',800,80);
  const pkeys=['P1','P2','P3','P4','P5'];
  pkeys.forEach((p,i)=>{
    const cy=285+i*355;
    boxes[p]={x:550,y:cy-80,w:220,h:160};
    vertex(p,nodes[p].label.split(' ')[0],`swimlane;horizontal=1;startSize=26;rounded=0;collapsible=0;${base}swimlaneFillColor=#ffffff;`,550,cy-80,220,160);
    label(p+'-name',nodes[p].label.replace(/^\S+ /,''),5,30,210,125,p,17);
    rect(550,cy-80,220,160);ln(550,cy-54,770,cy-54);txt(nodes[p].label.split(' ')[0],660,cy-67);multi(nodes[p].label.replace(/^\S+ /,''),660,cy+13,23,17);
    boxes[p+'-A']={x:40,y:cy-55,w:170,h:110};
    vertex(p+'-A','Administrator',`rounded=1;arcSize=12;${base}`,40,cy-55,170,110);
    rect(40,cy-55,170,110,10);txt('Administrator',125,cy,17);
  });
  const storeCenters={D1:285,D2:640,D3:995,D5:1350,D6:1580,D7:1820};
  for(const [id,cy] of Object.entries(storeCenters)) {
    const count=flows.filter(f=>f.from===id||f.to===id).length;
    const h=Math.max(70,count*55+20),y=cy-h/2;
    boxes[id]={x:1280,y,w:300,h};
    vertex(id,'','group;connectable=1;',1280,y,300,h);
    vertex(id+'-id',id,`${base}align=center;verticalAlign=middle;`,0,0,36,h,id,'connectable="0"');
    vertex(id+'-top','','shape=line;strokeColor=#555555;',36,0,264,0,id,'connectable="0"');
    vertex(id+'-bottom','','shape=line;strokeColor=#555555;',36,h,264,0,id,'connectable="0"');
    label(id+'-name',nodes[id].label.replace(/^D\d+ /,''),42,4,252,h-8,id,16);
    rect(1280,y,36,h);ln(1316,y,1580,y);ln(1316,y+h,1580,y+h);
    txt(id,1298,cy);multi(nodes[id].label.replace(/^D\d+ /,''),1448,cy,26);
  }
  let seq=0;
  const storeFlows=flows.filter(f=>f.from.startsWith('D')||f.to.startsWith('D'));
  // Order ports by the opposite endpoint's vertical location to reduce crossings.
  const groups={};
  for(const id of [...pkeys,...Object.keys(storeCenters)])groups[id]=storeFlows.filter(f=>f.from===id||f.to===id).sort((a,b)=>{
    const ao=a.from===id?a.to:a.from,bo=b.from===id?b.to:b.from;
    return (boxes[ao].y+boxes[ao].h/2)-(boxes[bo].y+boxes[bo].h/2)||flows.indexOf(a)-flows.indexOf(b);
  });
  function edge(from,to,labelText,ex,ey,ix,iy,points=[],labelX=0,labelDy=-14) {
    const id='flow-'+(++seq),a=boxes[from],b=boxes[to];
    const style=`edgeStyle=orthogonalEdgeStyle;rounded=0;jettySize=auto;html=0;endArrow=classic;endFill=1;strokeColor=#333333;strokeWidth=1;fontFamily=Times New Roman;fontSize=13;fontColor=#000000;labelBackgroundColor=#ffffff;exitX=${ex};exitY=${ey};exitPerimeter=0;entryX=${ix};entryY=${iy};entryPerimeter=0;`;
    cells.push(`<mxCell id="${id}" value="${esc(wrap(labelText,38).join('\n'))}" style="${style}" edge="1" parent="1" source="${from}" target="${to}"><mxGeometry x="${labelX}" relative="1" as="geometry">${points.length?`<Array as="points">${points.map(p=>`<mxPoint x="${p.x}" y="${p.y}"/>`).join('')}</Array>`:''}<mxPoint x="0" y="${labelDy}" as="offset"/></mxGeometry></mxCell>`);
    const route=[{x:a.x+a.w*ex,y:a.y+a.h*ey},...points,{x:b.x+b.w*ix,y:b.y+b.h*iy}];
    route.slice(1).forEach((p,i)=>ln(route[i].x,route[i].y,p.x,p.y,i===route.length-2));
    if(!points.length)multi(labelText,(route[0].x+route.at(-1).x)/2,route[0].y-22,38,13);
    else {
      const sy=from.startsWith('D')?route[0].y:route.at(-1).y;
      multi(labelText,1100,sy-22,38,13);
    }
  }
  for(const f of flows) {
    if(f.from==='A')edge(f.to+'-A',f.to,f.label,1,0.3,0,0.3625);
    else if(f.to==='A')edge(f.from,f.from+'-A',f.label,0,0.75,1,0.8636363636);
  }
  storeFlows.forEach((f,i)=>{
    const sid=f.from.startsWith('D')?f.from:f.to,pid=f.from.startsWith('P')?f.from:f.to;
    const s=boxes[sid],p=boxes[pid];
    const si=groups[sid].indexOf(f),pi=groups[pid].indexOf(f);
    const sf=(si+1)/(groups[sid].length+1),pf=0.24+(pi+1)*0.68/(groups[pid].length+1);
    const sy=s.y+s.h*sf,py=p.y+p.h*pf;
    const rail=810+i*7;
    const route=[{x:rail,y:py},{x:rail,y:sy}];
    // Place the draw.io edge label on the horizontal run next to the shared store.
    const len=(rail-770)+Math.abs(sy-py)+(1280-rail),distanceFromStore=175;
    const pos=1-2*distanceFromStore/len;
    if(f.from===sid)edge(sid,pid,f.label,0,sf,1,pf,route.slice().reverse(),-pos);
    else edge(pid,sid,f.label,1,pf,0,sf,route,pos);
  });
  const notes=[
    'Example: 1.1 updates D1 Accounts; 1.2 reads the client ID and owner details from the same D1 store.',
    '1.2 updates D2 Pet information; 1.3 reads approved pet and owner IDs from that same store.',
    'Each data store is drawn once. Repeated Administrator boxes represent the same external user.',
    'Arrows represent stored-data dependencies, not a mandatory sequence of actions.'
  ];
  notes.forEach((s,i)=>{label('note-'+i,s,40,1910+i*30,1540,25,'1',14);txt(s,810,1922+i*30,14);});
  fs.writeFileSync(path.join(__dirname,'administrator-connected.drawing.json'),JSON.stringify({width:1630,height:2060,shapes}));
  if(seq!==flows.length)throw new Error('Admin flow count mismatch');
  return `<mxGraphModel dx="1630" dy="2060" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1630" pageHeight="2060" math="0" shadow="0"><root>${cells.join('')}</root></mxGraphModel>`;
};
