import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';

const base='http://127.0.0.1:8080/api/v1';
let token;
async function api(path,body) {
  const r=await fetch(base+path,{method:body?'POST':'GET',headers:{Accept:'application/json','Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{})},body:body?JSON.stringify(body):undefined,signal:AbortSignal.timeout(5000)});
  const value=await r.json(); assert.ok(r.ok,JSON.stringify(value)); return value;
}
const tag=randomUUID().replaceAll('-','').slice(0,10);
token=(await api('/auth/register',{email:`bot${tag}@example.test`,password:randomUUID()})).access_token;
const hero=await api('/characters',{name:`Bot${tag}`});
const expedition=await api('/expeditions',{character_id:hero.id});
await api(`/expeditions/${expedition.id}/start`,{character_id:hero.id});
const map=Array.from({length:32},()=>Array(32).fill(' '));
const visits=new Map();
const dirs=[[0,-1,'north'],[1,0,'east'],[0,1,'south'],[-1,0,'west']];
const key=(x,y)=>`${x},${y}`;
let steps=0, finalCommand, finalResult, last;
const until=Date.now()+150000;
while (Date.now()<until) {
  const {world:w}=await api(`/expeditions/${expedition.id}?character_id=${hero.id}`); last=w;
  const p=w.self;
  assert.notEqual(p.outcome,'defeated','bot defeated before extraction');
  if (p.outcome==='extracted') break;
  if (Number(w.tick)<p.ready_at) {await new Promise(r=>setTimeout(r,100));continue;}
  for(let y=0;y<32;y++)for(let x=0;x<32;x++)if(w.map[y][x]!==' ')map[y][x]=w.map[y][x];
  let payload;
  const adjacent=w.enemies.find(e=>Math.abs(e.x-p.x)+Math.abs(e.y-p.y)===1);
  if(p.hp<=55&&p.potions>0) payload={action:'potion'};
  else if(adjacent) payload={action:'attack',target_id:adjacent.id};
  else if(w.exit&&Math.abs(w.exit[0]-p.x)+Math.abs(w.exit[1]-p.y)<=1) payload={action:'extract'};
  else {
    const q=[{x:p.x,y:p.y,first:null,d:0}],seen=new Set([key(p.x,p.y)]), candidates=[];
    for(let i=0;i<q.length;i++) {
      const cell=q[i];
      if(cell.first) {
        const unknown=dirs.filter(([dx,dy])=>map[cell.y+dy]?.[cell.x+dx]===' ').length;
        let score=cell.d+(visits.get(key(cell.x,cell.y))??0)*20;
        if(w.exit) score+=20*(Math.abs(cell.x-w.exit[0])+Math.abs(cell.y-w.exit[1]));
        else score+=unknown?0:200;
        candidates.push({...cell,score});
      }
      for(const[dx,dy,direction]of dirs){
        const x=cell.x+dx,y=cell.y+dy,k=key(x,y);
        if(seen.has(k)||map[y]?.[x]!=='.'||w.enemies.some(e=>e.x===x&&e.y===y))continue;
        seen.add(k);q.push({x,y,first:cell.first??direction,d:cell.d+1});
      }
    }
    candidates.sort((a,b)=>a.score-b.score);
    assert.ok(candidates.length,'no visible path');
    visits.set(key(p.x,p.y),(visits.get(key(p.x,p.y))??0)+1);
    payload={action:'move',direction:candidates[0].first};
  }
  const command={character_id:hero.id,command_id:randomUUID(),payload};
  const result=await api(`/expeditions/${expedition.id}/commands`,command);steps++;
  if(payload.action==='extract'){finalCommand=command;finalResult=result;}
  await new Promise(r=>setTimeout(r,100));
}
assert.equal(last.self.outcome,'extracted','bot timed out');
assert.deepEqual(await api(`/expeditions/${expedition.id}/commands`,finalCommand),finalResult);
const saved=(await api('/characters')).items.find(c=>c.id===hero.id);
assert.equal(Number(saved.gold),last.self.gold);assert.equal(Number(saved.xp),last.self.xp);
assert.equal(saved.active_expedition,null);
console.log(JSON.stringify({result:'passed',steps,kills:last.self.kills,gold:saved.gold,xp:saved.xp,checks:['visible-map exploration','server combat','extraction','persistent rewards','duplicate extraction']},null,2));
