<?php
namespace Tests\Feature;
use App\Game\{PlazaHub,World};
use GrimHollow\Core\Plaza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PlazaHubTest extends TestCase
{
    use RefreshDatabase;

    private function hero(string $name): array
    {
        $token=$this->postJson('/api/v1/auth/register',['email'=>$name.'@example.test','password'=>'correct-horse-battery'])->assertCreated()->json('access_token');
        $id=$this->withToken($token)->postJson('/api/v1/characters',['name'=>$name])->assertCreated()->json('id');
        return [$token,$id];
    }

    public function test_town_tickets_open_the_square_but_not_during_an_expedition(): void
    {
        [$token,$id]=$this->hero('Walker');
        $this->withToken($token)->postJson('/api/v1/world/tickets',['character_id'=>$id])->assertOk()->assertJsonStructure(['ticket','expires_in']);
        self::assertNull(DB::table('world_tickets')->where('character_id',$id)->value('expedition_id'));
        $this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->assertOk();
        $this->withToken($token)->postJson('/api/v1/world/tickets',['character_id'=>$id])->assertStatus(409);
    }

    public function test_live_heroes_walk_in_memory_and_meet_the_http_square(): void
    {
        [$a,$ann]=$this->hero('Ann');[$b,$bob]=$this->hero('Bob');
        $hub=new PlazaHub;$now=World::milliseconds();
        $hub->join($ann,$now);
        $full=$hub->view($ann,$hub->frame($now),$now,true);
        self::assertSame(Plaza::SPAWN[0],$full['self']['x']);self::assertCount(Plaza::HEIGHT,$full['map']);
        self::assertGreaterThan(15,count($full['npcs']));self::assertArrayNotHasKey('map',$hub->view($ann,$hub->frame($now),$now));
        // Bunched steps within the burst are honest; a sustained flood is refused.
        $results=[];
        for ($i=0;$i<12;$i++) $results[]=$hub->act($ann,['action'=>'move','direction'=>$i%2?'west':'east','seq'=>$i],$now)['result'];
        self::assertSame(['ok','ok','ok','ok','ok','ok'],array_slice($results,0,6));self::assertContains('cooldown',$results);
        $step=$hub->act($ann,['action'=>'move','direction'=>'south','seq'=>99],$now+5000);
        self::assertSame(['ok',99,Plaza::SPAWN[1]+1],[$step['result'],$step['seq'],$step['y']]);
        // The HTTP square sees the live hero after a sync, and the hub sees the HTTP hero.
        $this->withToken($b)->getJson('/api/v1/characters/'.$bob.'/plaza')->assertOk();
        $hub->sync($now+6000);
        $this->withToken($b)->getJson('/api/v1/characters/'.$bob.'/plaza')->assertJsonPath('players.0.name','Ann')->assertJsonPath('players.0.y',Plaza::SPAWN[1]+1);
        $view=$hub->view($ann,$hub->frame($now+6000),$now+6000);
        self::assertSame('Bob',$view['players'][0]['name']);self::assertSame(2,$view['online']);
        // Keepers answer in turn and must be within reach; walls block.
        $hub->leave($ann,$now+6000);
        DB::table('plaza_presence')->where('character_id',$ann)->update(['x'=>9,'y'=>16]);
        $hub->join($ann,$now+6000);
        $first=$hub->act($ann,['action'=>'talk','target'=>'smith'],$now+6000);$second=$hub->act($ann,['action'=>'talk','target'=>'smith'],$now+6000);
        self::assertSame('forge',$first['talk']['service']);self::assertNotSame($first['talk']['line'],$second['talk']['line']);
        self::assertSame('too_far',$hub->act($ann,['action'=>'talk','target'=>'mentor'],$now+6000)['result']);
        self::assertSame('blocked',$hub->act($ann,['action'=>'move','direction'=>'north'],$now+9000)['result']);
        self::assertSame('ok',$hub->act($ann,['action'=>'emote','target'=>'wave'],$now+9000)['result']);
        self::assertSame('wave',$hub->view($ann,$hub->frame($now+9000),$now+9000)['self']['emote']);
        // Setting out on an expedition takes the hero off the live square.
        $this->withToken($a)->postJson('/api/v1/expeditions',['character_id'=>$ann])->assertOk();
        self::assertSame([$ann],$hub->sync($now+20000));self::assertFalse($hub->has($ann));
    }
}
