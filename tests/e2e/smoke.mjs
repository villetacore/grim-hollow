import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const base = process.env.API_URL ?? 'http://127.0.0.1:8080/api/v1';
const wsUrl = process.env.WS_URL ?? 'ws://127.0.0.1:8082';
const suffix = randomUUID().replaceAll('-', '').slice(0, 10);
async function request(path, token, body, method = body ? 'POST' : 'GET', expected = 200) {
  const response = await fetch(base + path, {method,
    headers: {'Accept':'application/json','Content-Type':'application/json', ...(token ? {Authorization:`Bearer ${token}`} : {})},
    body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(10000)});
  const text = await response.text();
  assert.equal(response.status, expected, `${method} ${path}: ${text}`);
  return text ? JSON.parse(text) : null;
}
async function player(name) {
  const {access_token: token} = await request('/auth/register', null, {email:`${name}${suffix}@example.test`,password:randomUUID()},'POST',201);
  const {id} = await request('/characters',token,{name:name+suffix},'POST',201);
  return {token,id};
}
const first = await player('Alpha'), second = await player('Bravo');
const expedition = await request('/expeditions',first.token,{character_id:first.id});
await request(`/expeditions/${expedition.id}?character_id=${second.id}`,second.token,null,'GET',404);
await request('/expeditions',second.token,{character_id:second.id,join_code:expedition.join_code});
await request(`/expeditions/${expedition.id}/start`,first.token,{character_id:first.id});
const {ticket} = await request('/world/tickets',first.token,{character_id:first.id,expedition_id:expedition.id});
const messages=[];
const socket = new WebSocket(wsUrl);
const done = new Promise((resolve,reject)=>{
  const timeout=setTimeout(()=>reject(new Error('WebSocket timeout')),8000);
  socket.addEventListener('error',()=>{clearTimeout(timeout);reject(new Error('WebSocket failed'));});
  socket.addEventListener('open',()=>socket.send(JSON.stringify({v:1,type:'hello',ticket})));
  socket.addEventListener('message',event=>{
    const m=JSON.parse(event.data); messages.push(m);
    if (messages.filter(x=>x.type==='snapshot').length>=4) {clearTimeout(timeout);resolve();}
  });
});
try {
  await done;
  const snapshots=messages.filter(m=>m.type==='snapshot');
  assert.ok(BigInt(snapshots.at(-1).world.tick)>BigInt(snapshots[0].world.tick),'world clock advances');
  assert.equal(snapshots[0].world.players.length,2);
  assert.ok(!('seed' in snapshots[0].world));
  const command={character_id:first.id,command_id:randomUUID(),payload:{action:'guard'}};
  const a=await request(`/expeditions/${expedition.id}/commands`,first.token,command);
  const b=await request(`/expeditions/${expedition.id}/commands`,first.token,command);
  assert.deepEqual(a,b,'duplicate command returns stored result');
  await request(`/expeditions/${expedition.id}/commands`,first.token,{...command,payload:{action:'potion'}},'POST',409);
  const closed = new Promise((resolve,reject)=>{
    const timer=setTimeout(()=>reject(new Error('Revoked WebSocket stayed connected')),8000);
    socket.addEventListener('close',()=>{clearTimeout(timer);resolve();},{once:true});
  });
  const oldToken=first.token;
  first.token=(await request('/auth/refresh',oldToken,{})).access_token;
  await request('/characters',oldToken,null,'GET',401);
  await closed;
  const nextTicket=(await request('/world/tickets',first.token,{character_id:first.id,expedition_id:expedition.id})).ticket;
  const replacement=new WebSocket(wsUrl);
  try {
    await new Promise((resolve,reject)=>{
      const timer=setTimeout(()=>reject(new Error('Reconnect timeout')),8000);
      replacement.addEventListener('open',()=>replacement.send(JSON.stringify({v:1,type:'hello',ticket:nextTicket})));
      replacement.addEventListener('message',event=>{
        const m=JSON.parse(event.data);
        if(m.type==='snapshot') {clearTimeout(timer);assert.equal(m.world.self.id,first.id);resolve();}
      });
      replacement.addEventListener('error',()=>{clearTimeout(timer);reject(new Error('Reconnect failed'));});
    });
  } finally {replacement.close();}
  await request(`/expeditions/${expedition.id}/leave`,first.token,{character_id:first.id});
  await request(`/expeditions/${expedition.id}/leave`,second.token,{character_id:second.id});
  console.log(JSON.stringify({result:'passed',players:2,websocketSnapshots:snapshots.length,
    firstTick:snapshots[0].world.tick,lastTick:snapshots.at(-1).world.tick,
    expedition:expedition.id,
    checks:['account isolation','co-op lobby','WebSocket ticket','10 Hz progress','command deduplication','payload conflict','token rotation','revoked stream closes','reconnect same hero']},null,2));
} finally { socket.close(); }
