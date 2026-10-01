<?php
namespace Tests\Feature;
use App\Game\{Characters,Replay,World};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExpansionTest extends TestCase
{
    use RefreshDatabase;

    private function hero(string $name,string $class='guardian'): array
    {
        $token=$this->postJson('/api/v1/auth/register',['email'=>$name.'@example.test','password'=>'correct-horse-battery'])->assertCreated()->json('access_token');
        $id=$this->withToken($token)->postJson('/api/v1/characters',['name'=>$name,'class_id'=>$class])->assertCreated()->json('id');
        return [$token,$id];
    }
    private function act(string $token,string $id,string $action,array $extra=[])
    {
        return $this->withToken($token)->postJson('/api/v1/characters/'.$id.'/town',['operation_id'=>(string)Str::uuid(),'action'=>$action]+$extra);
    }

    public function test_distill_and_essence_recipes(): void
    {
        [$token,$id]=$this->hero('Alchemist');
        DB::table('characters')->where('id',$id)->update(['gold'=>200,'materials'=>30]);
        $this->act($token,$id,'craft',['target'=>'rift_blade'])->assertConflict();
        $this->act($token,$id,'distill')->assertOk()->assertJsonPath('hero.essence',1)->assertJsonPath('hero.materials',20)->assertJsonPath('hero.gold',180);
        $this->act($token,$id,'craft',['target'=>'rift_blade'])->assertOk()->assertJsonPath('hero.essence',0)->assertJsonPath('hero.materials',13)
            ->assertJsonPath('recipes.rift_blade.level',5)->assertJsonPath('recipes.rift_blade.slot','weapon');
        $this->assertDatabaseHas('character_items',['character_id'=>$id,'definition'=>'rift_blade']);
        $blade=DB::table('character_items')->where('character_id',$id)->where('definition','rift_blade')->value('id');
        $this->act($token,$id,'salvage',['target'=>$blade])->assertOk()->assertJsonPath('hero.essence',1)->assertJsonPath('hero.materials',18);
    }

    public function test_amulet_slot_adds_health_to_the_profile(): void
    {
        [$token,$id]=$this->hero('Wearer');DB::table('characters')->where('id',$id)->update(['xp'=>40]);
        (new Characters)->grant($id,'amber_amulet');
        $item=DB::table('character_items')->where('character_id',$id)->where('definition','amber_amulet')->value('id');
        $this->withToken($token)->postJson('/api/v1/characters/'.$id.'/manage',['operation_id'=>(string)Str::uuid(),'action'=>'equip','item_id'=>$item])
            ->assertOk()->assertJsonPath('stats.max_hp',135)->assertJsonPath('stats.equipment.amulet','amber_amulet');
        // 100 base + 10 for level 2 + 15 amulet + 10 for the default human origin.
    }

    public function test_bounty_counts_only_extracted_kills_and_pays_once(): void
    {
        [$token,$id]=$this->hero('Hunter');
        $bounty=$this->act($token,$id,'bounty_take')->assertOk()->json('bounty');
        self::assertSame('Затопленные шахты',$bounty['area']);
        $this->act($token,$id,'bounty_take')->assertConflict();
        $this->act($token,$id,'bounty_claim')->assertConflict();
        $w=app(World::class);
        foreach (['defeated'=>0,'extracted'=>$bounty['need']] as $outcome=>$have) {
            $exp=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->json('id');$w->start($exp,$id);
            $s=json_decode(DB::table('expeditions')->where('id',$exp)->value('state'),true);
            $s['players'][$id]['slain']=[$bounty['type']=>$bounty['need']+5];
            if ($outcome==='extracted') {$s['players'][$id]['x']=$s['exit'][0];$s['players'][$id]['y']=$s['exit'][1];}
            else {$s['tick']=35999;}
            DB::table('expeditions')->where('id',$exp)->update(['state'=>json_encode($s),'next_tick_at'=>0]);
            if ($outcome==='extracted') $w->command($exp,$id,(string)Str::uuid(),['action'=>'extract']); else $w->tick();
            self::assertSame($have,json_decode(DB::table('characters')->where('id',$id)->value('bounty'),true)['have']);
        }
        $gold=DB::table('characters')->where('id',$id)->value('gold');
        $this->act($token,$id,'bounty_claim')->assertOk()->assertJsonPath('bounty',null)->assertJsonPath('hero.gold',$gold+$bounty['gold']);
        $this->act($token,$id,'bounty_claim')->assertConflict();
    }

    public function test_deep_areas_unlock_in_order_and_boss_pays_essence(): void
    {
        [$token,$id]=$this->hero('Delver');
        $this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id,'biome'=>'catacombs'])->assertConflict();
        DB::table('characters')->where('id',$id)->update(['campaign'=>3]);
        $exp=$this->postJson('/api/v1/expeditions',['character_id'=>$id,'biome'=>'catacombs'])->assertOk()->json('id');
        $w=app(World::class);$w->start($exp,$id);
        self::assertSame(4,$w->snapshot($exp,$id)['world']['last_floor']);
        $s=json_decode(DB::table('expeditions')->where('id',$exp)->value('state'),true);
        $s['floor']=4;$s['enemies']['boss']=['id'=>'boss','type'=>'bone_king','hp'=>0];$s['players'][$id]['essence']=5;
        $s['players'][$id]['x']=$s['exit'][0];$s['players'][$id]['y']=$s['exit'][1];
        DB::table('expeditions')->where('id',$exp)->update(['state'=>json_encode($s)]);
        $w->command($exp,$id,(string)Str::uuid(),['action'=>'extract']);
        $this->assertDatabaseHas('characters',['id'=>$id,'campaign'=>4,'essence'=>5,'gold'=>200]);
        $this->postJson('/api/v1/expeditions',['character_id'=>$id,'biome'=>'glacier'])->assertOk();
    }

    public function test_one_batched_tick_advances_every_due_expedition_and_feeds_the_stream_cache(): void
    {
        $w=app(World::class);$ids=[];
        foreach (['BatchA','BatchB'] as $name) {
            [$token,$id]=$this->hero($name);
            $exp=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->json('id');$w->start($exp,$id);$ids[$exp]=$id;
        }
        DB::table('expeditions')->update(['next_tick_at'=>0]);
        self::assertSame(2,$w->tick());
        foreach ($ids as $exp=>$hero) {
            $row=$w->cached($exp);
            self::assertSame((string)DB::table('expeditions')->where('id',$exp)->value('revision'),$row['revision']);
            self::assertEquals($w->snapshot($exp,$hero,false),$w->view($row,$exp,$hero));
        }
        // A change made by another process (the HTTP API) is picked up by one revision query.
        $exp=array_key_first($ids);
        (new World)->command($exp,$ids[$exp],(string)Str::uuid(),['action'=>'guard']);
        $rows=$w->refresh(array_keys($ids));
        self::assertSame((string)DB::table('expeditions')->where('id',$exp)->value('revision'),$rows[$exp]['revision']);
        self::assertSame(20+1,$rows[$exp]['state']['players'][$ids[$exp]]['shield_until']);
    }

    public function test_buffered_command_replays_and_interact_is_accepted_over_http(): void
    {
        [$token,$id]=$this->hero('Buffered');
        $exp=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->json('id');
        $w=app(World::class);$w->start($exp,$id);
        $url='/api/v1/expeditions/'.$exp.'/commands';
        $this->postJson($url,['character_id'=>$id,'command_id'=>(string)Str::uuid(),'payload'=>['action'=>'guard']])->assertOk()->assertJsonPath('status','executed');
        $this->postJson($url,['character_id'=>$id,'command_id'=>(string)Str::uuid(),'payload'=>['action'=>'interact']])->assertOk()->assertJsonPath('status','rejected');
        for ($i=0;$i<8;$i++) { DB::table('expeditions')->update(['next_tick_at'=>0]);$w->tick(); }
        $this->postJson($url,['character_id'=>$id,'command_id'=>(string)Str::uuid(),'payload'=>['action'=>'potion']])->assertOk()->assertJsonPath('status','queued');
        for ($i=0;$i<3;$i++) { DB::table('expeditions')->update(['next_tick_at'=>0]);$w->tick(); }
        self::assertSame(14,app(Replay::class)->verify($exp)['verified_events']);
    }
}
