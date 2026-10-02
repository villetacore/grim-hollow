// The live town square over the world WebSocket: two heroes see each other's steps without
// HTTP, a keeper answers in turn, and lean expedition snapshots leave an unchanged map out.
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
/** A socket with a queue of parsed messages and a waiter for the first one matching. */
async function connect(ticket, caps = []) {
  const socket = new WebSocket(wsUrl), inbox = [], waiters = [];
  socket.addEventListener('message', event => {
    const m = JSON.parse(event.data);
    const i = waiters.findIndex(w => w.test(m));
    if (i >= 0) waiters.splice(i, 1)[0].resolve(m); else inbox.push(m);
  });
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve); socket.addEventListener('error', reject); });
  socket.send(JSON.stringify({v:1, type:'hello', ticket, caps}));
  const next = (test, ms = 3000) => {
    const i = inbox.findIndex(test);
    if (i >= 0) return Promise.resolve(inbox.splice(i, 1)[0]);
    return new Promise((resolve, reject) => {
      const w = {test, resolve: m => { clearTimeout(t); resolve(m); }};
      const t = setTimeout(() => { waiters.splice(waiters.indexOf(w), 1); reject(new Error('timeout waiting for message')); }, ms);
      waiters.push(w);
    });
  };
  return {socket, next, send: m => socket.send(JSON.stringify({v:1, ...m}))};
}

const ann = await player('Ann'), bob = await player('Bob');
const a = await connect((await request('/world/tickets', ann.token, {character_id:ann.id})).ticket);
const full = await a.next(m => m.type === 'plaza');
assert.equal(full.map.length, 36);
assert.ok(full.npcs.length > 15);
const b = await connect((await request('/world/tickets', bob.token, {character_id:bob.id})).ticket);
await b.next(m => m.type === 'plaza' && m.map);

// Steps are answered at once, pipelined, and Bob sees Ann move on the next push.
const started = performance.now();
for (let seq = 1; seq <= 3; seq++) a.send({type:'plaza', action:'move', direction:'south', seq});
const acks = [await a.next(m => m.seq === 1), await a.next(m => m.seq === 2), await a.next(m => m.seq === 3)];
const ackMs = performance.now() - started;
assert.deepEqual(acks.map(m => m.result), ['ok', 'ok', 'ok']);
assert.equal(acks[2].y, full.self.y + 3);
const seen = await b.next(m => m.type === 'plaza' && m.players.some(p => p.id === ann.id && p.y === full.self.y + 3));
assert.ok(seen.walkers.length > 0 && !seen.map && !seen.npcs, 'live pushes carry walkers only');

// Talking: too far first, then next to the keeper (gatekeeper at 25,1, spawn 27,10).
a.send({type:'plaza', action:'talk', target:'gatekeeper', seq:10});
assert.equal((await a.next(m => m.seq === 10)).result, 'too_far');

// An expedition takes Ann off the square, and her lean stream omits an unchanged map.
a.socket.close();
const expedition = await request('/expeditions', ann.token, {character_id:ann.id});
await request(`/expeditions/${expedition.id}/start`, ann.token, {character_id:ann.id});
await request('/world/tickets', ann.token, {character_id:ann.id}, 'POST', 409);
const e = await connect((await request('/world/tickets', ann.token, {character_id:ann.id, expedition_id:expedition.id})).ticket, ['lean']);
const first = await e.next(m => m.type === 'snapshot');
assert.equal(first.world.map.length, 32);
e.send({type:'command', command_id:randomUUID(), payload:{action:'guard'}});
const later = await e.next(m => m.type === 'snapshot', 4000);
assert.equal(later.world.map, undefined, 'unchanged map is left out');

for (const s of [b.socket, e.socket]) s.close();
console.log(JSON.stringify({result:'passed', pipelinedStepAcksMs:Math.round(ackMs), checks:['town ticket','in-memory steps','others see steps','walker-only pushes','talk range','expedition leaves town','lean map delta']}, null, 2));
