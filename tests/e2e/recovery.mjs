import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {readFile,writeFile,unlink,mkdir} from 'node:fs/promises';
const file=new URL('../../.tools/recovery-fixture.json',import.meta.url);
const base='http://127.0.0.1:8080/api/v1';
let token;
async function api(path,body){
  const r=await fetch(base+path,{method:body?'POST':'GET',headers:{Accept:'application/json','Content-Type':'application/json',...(token?{Authorization:'Bearer '+token}:{})},body:body?JSON.stringify(body):undefined,signal:AbortSignal.timeout(10000)});
  const text=await r.text();const d=text?JSON.parse(text):null;assert.ok(r.ok,JSON.stringify(d));return d;
}
if(process.argv[2]==='before'){
  await mkdir(new URL('../../.tools/',import.meta.url),{recursive:true});
  const tag=randomUUID().slice(0,8);
  token=(await api('/auth/register',{email:`restart${tag}@example.test`,password:randomUUID()})).access_token;
  const hero=await api('/characters',{name:'Restart'+tag});
  const exp=await api('/expeditions',{character_id:hero.id});
  await api(`/expeditions/${exp.id}/start`,{character_id:hero.id});
  const command={character_id:hero.id,command_id:randomUUID(),payload:{action:'move',direction:'east'}};
  const result=await api(`/expeditions/${exp.id}/commands`,command);
  assert.equal(result.status,'executed');
  const snapshot=await api(`/expeditions/${exp.id}?character_id=${hero.id}`);
  await writeFile(file,JSON.stringify({token,hero:hero.id,exp:exp.id,command,result,x:snapshot.world.self.x,tick:snapshot.world.tick}));
  console.log('RECOVERY_PREPARED: committed movement; restart world now');
}else if(process.argv[2]==='after'){
  const saved=JSON.parse(await readFile(file,'utf8'));token=saved.token;
  const snapshot=await api(`/expeditions/${saved.exp}?character_id=${saved.hero}`);
  assert.equal(snapshot.world.self.x,saved.x);
  assert.ok(BigInt(snapshot.world.tick)>=BigInt(saved.tick));
  assert.deepEqual(await api(`/expeditions/${saved.exp}/commands`,saved.command),saved.result);
  await api(`/expeditions/${saved.exp}/leave`,{character_id:saved.hero});
  const hero=await api('/characters/'+saved.hero);
  assert.equal(hero.active_expedition,null);assert.equal(Number(hero.gold),0);
  await api('/auth/logout',{});await unlink(file);
  console.log(JSON.stringify({result:'passed',expedition:saved.exp,checks:['active state survives world restart','command receipt survives restart','no extra gold','return to town']}));
}else throw new Error('Use before, restart world, then after');
