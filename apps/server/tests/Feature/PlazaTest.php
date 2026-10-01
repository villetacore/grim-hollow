<?php
namespace Tests\Feature;
use App\Game\World;
use GrimHollow\Core\{Game,Plaza};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlazaTest extends TestCase
{
    use RefreshDatabase;

    private function hero(string $name,string $class='guardian',string $origin='human'): array
    {
        $token=$this->postJson('/api/v1/auth/register',['email'=>$name.'@example.test','password'=>'correct-horse-battery'])->assertCreated()->json('access_token');
        $id=$this->withToken($token)->postJson('/api/v1/characters',['name'=>$name,'class_id'=>$class,'origin'=>$origin])->assertCreated()->json('id');
        return [$token,$id];
    }
    private function town(string $token,string $id,string $action,array $extra=[])
    {
        return $this->withToken($token)->postJson('/api/v1/characters/'.$id.'/town',['operation_id'=>(string)Str::uuid(),'action'=>$action]+$extra);
    }
    private function step(string $token,string $id,string $direction)
    {
        // Steps closer together than the walking pace are refused as a speed hack.
        DB::table('plaza_presence')->where('character_id',$id)->update(['moved_at'=>0]);
        return $this->withToken($token)->postJson('/api/v1/characters/'.$id.'/plaza',['action'=>'move','direction'=>$direction]);
    }

    public function test_heroes_walk_the_square_and_see_each_other(): void
    {
        [$a,$ann]=$this->hero('Ann','necromancer','undead');[$b,$bob]=$this->hero('Bob','druid','fae');
        $view=$this->withToken($a)->getJson('/api/v1/characters/'.$ann.'/plaza?full=1')->assertOk();
        $view->assertJsonPath('self.x',Plaza::SPAWN[0])->assertJsonPath('self.origin','undead')->assertJsonCount(Plaza::HEIGHT,'map')->assertJsonPath('players',[]);
        self::assertNotEmpty($view->json('buildings'));self::assertGreaterThan(15,count($view->json('npcs')));
        $this->withToken($b)->getJson('/api/v1/characters/'.$bob.'/plaza')->assertOk()->assertJsonPath('players.0.name','Ann')->assertJsonMissingPath('map');
        $this->step($a,$ann,'south')->assertOk()->assertJsonPath('result','ok')->assertJsonPath('self.y',Plaza::SPAWN[1]+1);
        $this->withToken($a)->postJson('/api/v1/characters/'.$ann.'/plaza',['action'=>'move','direction'=>'south'])->assertJsonPath('result','cooldown');
        $this->withToken($b)->getJson('/api/v1/characters/'.$bob.'/plaza')->assertJsonPath('players.0.y',Plaza::SPAWN[1]+1)->assertJsonPath('players.0.class_id','necromancer');
        // Walls and keepers block; other heroes do not.
        DB::table('plaza_presence')->where('character_id',$ann)->update(['x'=>1,'y'=>1]);
        $this->step($a,$ann,'west')->assertJsonPath('result','blocked')->assertJsonPath('self.x',1)->assertJsonPath('self.facing','west');
        DB::table('plaza_presence')->where('character_id',$ann)->update(['x'=>9,'y'=>16]);
        $this->step($a,$ann,'north')->assertJsonPath('result','blocked');
        // Talking: the smith opens the forge; too far away nobody hears.
        $talk=$this->withToken($a)->postJson('/api/v1/characters/'.$ann.'/plaza',['action'=>'talk','target'=>'smith'])->assertOk();
        $talk->assertJsonPath('talk.service','forge');self::assertNotEmpty($talk->json('talk.line'));
        $this->withToken($a)->postJson('/api/v1/characters/'.$ann.'/plaza',['action'=>'talk','target'=>'mentor'])->assertStatus(409);
        DB::table('plaza_presence')->where('character_id',$ann)->update(['x'=>34,'y'=>31]);
        $dummy=$this->withToken($a)->postJson('/api/v1/characters/'.$ann.'/plaza',['action'=>'talk','target'=>'dummy1'])->assertOk();
        self::assertStringContainsString('Вампиризм: 4%',$dummy->json('talk.line'));
        // Emotes and chat bubbles are seen by others.
        $this->withToken($a)->postJson('/api/v1/characters/'.$ann.'/plaza',['action'=>'emote','target'=>'dance'])->assertOk()->assertJsonPath('self.emote','dance');
        $this->withToken($a)->postJson('/api/v1/chat',['character_id'=>$ann,'channel'=>'global','message_id'=>(string)Str::uuid(),'body'=>'Всем привет!'])->assertOk();
        $this->withToken($b)->getJson('/api/v1/characters/'.$bob.'/plaza')->assertJsonPath('players.0.emote','dance')->assertJsonPath('players.0.bubble','Всем привет!');
        // Strangers cannot drive someone else's hero; heroes on an expedition leave the square.
        $this->withToken($b)->getJson('/api/v1/characters/'.$ann.'/plaza')->assertNotFound();
        $this->withToken($a)->postJson('/api/v1/expeditions',['character_id'=>$ann])->assertOk();
        $this->withToken($a)->getJson('/api/v1/characters/'.$ann.'/plaza')->assertStatus(409);
        $this->withToken($b)->getJson('/api/v1/characters/'.$bob.'/plaza')->assertJsonPath('players',[]);
        // A hero away longer than the presence window fades out.
        DB::table('plaza_presence')->where('character_id',$bob)->update(['seen_at'=>World::milliseconds()-Plaza::PRESENCE_MS-1,'x'=>40]);
        $this->withToken($b)->getJson('/api/v1/characters/'.$bob.'/plaza')->assertJsonPath('self.x',Plaza::SPAWN[0]);
    }

    public function test_eight_heroes_share_one_expedition(): void
    {
        // Nine accounts from one address would trip the registration limit.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$token,$host]=$this->hero('Host');
        $e=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$host])->json();
        $classes=['arcanist','ranger','warden','berserker','assassin','necromancer','druid','guardian'];
        for ($i=1;$i<Game::MAX_PARTY;$i++) {
            [$t,$id]=$this->hero('Member'.$i,$classes[$i-1]);
            $this->withToken($t)->postJson('/api/v1/expeditions',['character_id'=>$id,'join_code'=>$e['join_code']])->assertOk();
        }
        [$late,$lateId]=$this->hero('Latecomer');
        $this->withToken($late)->postJson('/api/v1/expeditions',['character_id'=>$lateId,'join_code'=>$e['join_code']])->assertConflict();
        $this->withToken($token)->postJson('/api/v1/expeditions/'.$e['id'].'/start',['character_id'=>$host])->assertOk();
        $snapshot=$this->withToken($token)->getJson('/api/v1/expeditions/'.$e['id'].'?character_id='.$host)->assertOk();
        $snapshot->assertJsonPath('world.party_size',8);
        $state=json_decode(DB::table('expeditions')->where('id',$e['id'])->value('state'),true);
        self::assertCount(8,array_unique(array_map(fn($p)=>$p['x'].','.$p['y'],$state['players'])));
        self::assertSame(['soul_harvest'],array_values(array_filter(array_map(fn($p)=>$p['class_id']==='necromancer'?$p['traits'][0]:null,$state['players']))));
    }

    public function test_reagents_come_home_and_feed_the_forge_and_the_alchemist(): void
    {
        [$token,$id]=$this->hero('Smith','assassin','elf');
        $e=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->json();
        $world=app(World::class);$world->start($e['id'],$id);
        $s=json_decode(DB::table('expeditions')->where('id',$e['id'])->value('state'),true);
        $s['players'][$id]['x']=$s['exit'][0];$s['players'][$id]['y']=$s['exit'][1];
        $s['players'][$id]['reagents']=['ember_core'=>5,'frost_shard'=>2,'rat_tail'=>1];
        DB::table('expeditions')->where('id',$e['id'])->update(['state'=>json_encode($s)]);
        $world->command($e['id'],$id,(string)Str::uuid(),['action'=>'extract']);
        $this->withToken($token)->getJson('/api/v1/characters/'.$id)->assertJsonPath('reagents.ember_core',5)->assertJsonPath('spells.blink.mana',9)
            ->assertJsonPath('spells.blink.affinity',true)->assertJsonPath('spells.mend.mana',18);
        DB::table('characters')->where('id',$id)->update(['gold'=>5000,'materials'=>100,'essence'=>20,'xp'=>20*9*10]);
        // Forge: obsidian needs ember cores, the rune Isa a frost shard.
        $this->town($token,$id,'forge',['target'=>'claws','tier'=>4,'material'=>'obsidian','rune'=>'isa','text'=>'keen'])->assertOk()
            ->assertJsonPath('alchemy.have.ember_core',4)->assertJsonPath('alchemy.have.frost_shard',1);
        $item=DB::table('character_items')->where('character_id',$id)->where('definition','claws+4@obsidian~keen!isa')->value('id');
        self::assertNotNull($item);
        $this->town($token,$id,'forge',['target'=>'claws','tier'=>4,'material'=>'bogiron'])->assertConflict();
        // Upgrading keeps material, enchantment and rune; inscribing swaps the rune.
        $this->town($token,$id,'upgrade',['target'=>$item])->assertOk();
        $this->assertDatabaseHas('character_items',['id'=>$item,'definition'=>'claws+5@obsidian~keen!isa']);
        // At +5 a rune takes two of its reagent.
        $this->town($token,$id,'inscribe',['target'=>$item,'text'=>'kenaz'])->assertOk()->assertJsonPath('alchemy.have.ember_core',2);
        $this->assertDatabaseHas('character_items',['id'=>$item,'definition'=>'claws+5@obsidian~keen!kenaz']);
        // Alchemy: a reaction elixir lasts one expedition.
        $this->town($token,$id,'brew',['text'=>'frost_shard+ember_core'])->assertOk()->assertJsonPath('alchemy.active.name','Эликсир пара')
            ->assertJsonPath('hero.stats.elixir','Эликсир пара');
        $this->town($token,$id,'brew',['text'=>'frost_shard+frost_shard'])->assertConflict();
        // Mentor: free the first time, gold to change.
        $this->town($token,$id,'mentor',['target'=>'assassin'])->assertStatus(422);
        $this->town($token,$id,'mentor',['target'=>'arcanist'])->assertOk()->assertJsonPath('hero.mentor','arcanist')
            ->assertJsonPath('hero.stats.traits',['backstab','arcane_surge']);
        $gold=DB::table('characters')->where('id',$id)->value('gold');
        $this->town($token,$id,'mentor',['target'=>'warden'])->assertOk()->assertJsonPath('hero.gold',$gold-250);
        $duel=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->json('id');
        $state=json_decode(DB::table('expeditions')->where('id',$duel)->value('state'),true);
        self::assertSame('Эликсир пара',$state['players'][$id]['elixir']);
        $this->withToken($token)->postJson('/api/v1/expeditions/'.$duel.'/leave',['character_id'=>$id])->assertOk();
        $this->assertDatabaseHas('characters',['id'=>$id,'elixir'=>null]);
    }
}
