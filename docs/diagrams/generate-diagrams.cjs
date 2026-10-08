const fs = require('fs');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../DATA_FLOW_DIAGRAMS.md'), 'utf8');
const blocks = [...source.matchAll(/## ([^\n]+)\n\n```mermaid\n([\s\S]*?)```/g)];
const slugs = ['administrator', 'veterinarian', 'staff', 'client'];
const accents = ['#176B65', '#365FAD', '#8B5B24', '#7550A0'];
const esc = s => s.replace(/[&<>"']/g, c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&apos;'}[c]));
function wrap(s, limit) {
  const lines = [''];
  for (const word of s.split(/\s+/)) {
    if (lines.at(-1).length && lines.at(-1).length + word.length + 1 > limit) lines.push(word);
    else lines[lines.length-1] += (lines.at(-1) ? ' ' : '') + word;
  }
  return lines;
}
const manifest = [];
for (const [index, block] of blocks.entries()) {
  const nodes = {}, edges = [];
  for (const raw of block[2].split('\n')) {
    const line = raw.trim();
    let m;
    if ((m = line.match(/^(\w+) -->\|(.+)\| (\w+)$/))) edges.push({from:m[1], label:m[2], to:m[3]});
    else if ((m = line.match(/^(P\d+)\("(.+)"\)$/))) nodes[m[1]] = {type:'process', label:m[2]};
    else if ((m = line.match(/^(D\d+)\[\("(.+)"\)\]$/))) nodes[m[1]] = {type:'store', label:m[2]};
    else if ((m = line.match(/^(\w+)\[(.+)\]$/))) nodes[m[1]] = {type:'actor', label:m[2]};
  }
  const actor = Object.keys(nodes).find(id=>nodes[id].type==='actor');
  const processes = Object.keys(nodes).filter(id=>nodes[id].type==='process');
  const shapes = [], color = accents[index];
  const rect=(x,y,w,h,fill,stroke='#D7E1E9',radius=0)=>shapes.push({type:'rect',x,y,w,h,fill,stroke,radius});
  const line=(x1,y1,x2,y2,stroke='#64748B',arrow=false)=>shapes.push({type:'line',x1,y1,x2,y2,stroke,arrow});
  const text=(s,x,y,size=18,fill='#25364A',align='left',bold=false)=>shapes.push({type:'text',text:s,x,y,size,fill,align,bold});
  const multiline=(s,x,y,limit,size=18,fill='#25364A',bold=false)=>{
    const ls=wrap(s,limit); ls.forEach((l,i)=>text(l,x,y+(i-(ls.length-1)/2)*(size+7),size,fill,'center',bold));
  };
  const arrow=(label,x1,x2,y)=>{
    line(x1,y,x2,y,'#62748A',true);
    const ls=wrap(label,39);
    ls.forEach((l,i)=>text(l,(x1+x2)/2,y-15-(ls.length-1-i)*23,17,'#3C4E63','center'));
  };
  text('VETRIX  /  SYSTEM DOCUMENTATION',40,42,17,color,'left',true);
  text(nodes[actor].label,40,91,38,'#142D40','left',true);
  text('Data flow diagram  •  Simplified Level 1 role view',40,127,19,'#617387');
  text('EXTERNAL USER',125,183,15,'#617387','center',true);
  text('DATA EXCHANGED',380,183,15,'#617387','center',true);
  text('SYSTEM PROCESS',720,183,15,'#617387','center',true);
  text('READ / WRITE DATA',1080,183,15,'#617387','center',true);
  text('DATA STORE',1450,183,15,'#617387','center',true);
  let y=212;
  for (const pid of processes) {
    const pe=edges.filter(e=>e.from===pid||e.to===pid);
    const stores=[...new Set(pe.flatMap(e=>[e.from,e.to]).filter(id=>nodes[id]?.type==='store'))];
    const h=Math.max(235,stores.length*154+24), cy=y+h/2;
    rect(24,y,1582,h,'#F7F9FC','#E3E9EF',16);
    rect(40,cy-90,170,200,'#FFFFFF',color);
    multiline(nodes[actor].label,125,cy,15,21,'#25364A',true);
    text('external user',125,cy+49,13,'#617387','center');
    const userIn=pe.find(e=>e.from===actor), userOut=pe.find(e=>e.to===actor);
    if(userIn) arrow(userIn.label,210,590,cy-14);
    if(userOut) arrow(userOut.label,590,210,cy+81);
    rect(590,y+14,260,h-28,'#FFFFFF',color,20);
    const match=nodes[pid].label.match(/^(\S+) (.+)$/);
    text(match[1],720,cy-57,20,color,'center',true);
    multiline(match[2],720,cy+6,20,23,'#142D40',true);
    stores.forEach((sid,i)=>{
      const sy=y+12+i*154, mid=sy+77;
      rect(1290,sy+17,292,120,'#EDF3F8','#9DAFBE');
      line(1337,sy+17,1337,sy+137,'#9DAFBE');
      text(sid,1314,mid,16,color,'center',true);
      multiline(nodes[sid].label.replace(/^D\d+ /,''),1458,mid,21,19,'#25364A',true);
      const read=pe.find(e=>e.from===sid),write=pe.find(e=>e.to===sid);
      if(read) arrow(read.label,1290,850,write?mid-15:mid+18);
      if(write) arrow(write.label,850,1290,read?mid+55:mid+18);
    });
    y+=h+20;
  }
  text('HOW TO READ',40,y+24,15,color,'left',true);
  text('Rectangle = user   •   Rounded box = process   •   Divided box = data store   •   Arrow = direction of data',40,y+53,17);
  text('Repeated users and store IDs refer to the same entity or store. Rows show data flows, not a sequence of steps.',40,y+81,17,'#617387');
  text(index===3?'Client interactions use the mobile API. Authentication and routine logging are omitted.':'Selected business processes. Authentication and routine logging are omitted.',40,y+109,17,'#617387');
  const height=y+140, width=1630;
  const svg=`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" role="img" aria-labelledby="title desc"><title id="title">Vetrix ${esc(nodes[actor].label)} data flow diagram</title><desc id="desc">${esc(processes.map(id=>nodes[id].label).join('; '))}. Labeled arrows show data exchanged with logical stores.</desc><defs><marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#62748A"/></marker></defs><rect width="100%" height="100%" fill="white"/>${shapes.map(s=>{
    if(s.type==='rect') return `<rect x="${s.x}" y="${s.y}" width="${s.w}" height="${s.h}" rx="${s.radius}" fill="${s.fill}" stroke="${s.stroke}" stroke-width="1.5"/>`;
    if(s.type==='line')return `<line x1="${s.x1}" y1="${s.y1}" x2="${s.x2}" y2="${s.y2}" stroke="${s.stroke}" stroke-width="1.7"${s.arrow?' marker-end="url(#arrow)"':''}/>`;
    return `<text x="${s.x}" y="${s.y}" font-family="Segoe UI,Arial,sans-serif" font-size="${s.size}" font-weight="${s.bold?600:400}" fill="${s.fill}" text-anchor="${s.align==='center'?'middle':'start'}" dominant-baseline="middle">${esc(s.text)}</text>`;
  }).join('')}</svg>`;
  const slug=slugs[index];
  fs.writeFileSync(path.join(__dirname,`${slug}.svg`),svg);
  fs.writeFileSync(path.join(__dirname,`${slug}.drawing.json`),JSON.stringify({width,height,shapes}));
  manifest.push({slug,title:nodes[actor].label,width,height,processes:processes.length,flows:edges.length});
}
fs.writeFileSync(path.join(__dirname,'index.html'),`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vetrix — Data flow diagrams</title><style>*{box-sizing:border-box}body{margin:0;background:#edf2f6;color:#142d40;font:16px 'Segoe UI',sans-serif}header,main{max-width:1250px;margin:auto;padding:28px}h1{margin:0 0 12px;font-size:34px}p{line-height:1.6;color:#53687a}nav{display:flex;gap:12px;flex-wrap:wrap}a,button{color:#176b65;font:inherit}nav a,button{background:white;border:1px solid #c8d7e0;border-radius:8px;padding:10px 16px;text-decoration:none;cursor:pointer}section{background:white;border-radius:16px;padding:24px;margin:0 0 30px;box-shadow:0 4px 20px #142d4008}section h2{margin-top:0}section a{margin-right:18px}img{display:block;width:100%;height:auto;margin-top:22px}.actions{display:flex;gap:12px;flex-wrap:wrap}@media print{@page{size:A3 portrait;margin:10mm}body,main{background:white;padding:0}header,.actions,h2{display:none}section{padding:0;margin:0;border-radius:0;box-shadow:none;break-after:page}section:last-child{break-after:auto}img{max-height:390mm;object-fit:contain;margin:0}}</style></head><body><header><h1>Vetrix data flow diagrams</h1><p>Four separate role views. Follow the labeled arrows to see what each user submits, how the system processes it, and which records are read or updated.</p><nav>${manifest.map(m=>`<a href="#${m.slug}">${esc(m.title)}</a>`).join('')}<button onclick="window.print()">Print / Save PDF</button></nav></header><main>${manifest.map(m=>`<section id="${m.slug}"><h2>${esc(m.title)}</h2><div class="actions"><a href="${m.slug}.png" download>Download PNG</a><a href="${m.slug}.svg" download>Download SVG</a><a href="${m.slug}.svg" target="_blank">Open full size</a></div><img src="${m.slug}.svg" alt="${esc(m.title)} data flow diagram"></section>`).join('')}</main></body></html>`);
console.log(JSON.stringify(manifest,null,2));
