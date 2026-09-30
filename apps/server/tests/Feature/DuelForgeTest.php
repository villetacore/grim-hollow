<?php
namespace Tests\Feature;
use App\Game\{Replay,World};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DuelForgeTest extends TestCase
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

    public function test_duel_changes_only_rating_and_replays(): void
    {
        [$a,$ann]=$this->hero('Duelist','ranger');[$b,$bob]=$this->hero('Rival');
        DB::table('characters')->where('id',$ann)->update(['gold'=>7]);
        $duel=$this->withToken($a)->postJson('/api/v1/expeditions',['character_id'=>$ann,'mode'=>'duel','biome'=>'citadel'])->assertOk()->json();
        $this->withToken($a)->postJson('/api/v1/expeditions/'.$duel['id'].'/start',['character_id'=>$ann])->assertConflict();
        $this->withToken($b)->postJson('/api/v1/expeditions',['character_id'=>$bob,'join_code'=>$duel['join_code']])->assertOk();
        [$c,$third]=$this->hero('Third');
        $this->withToken($c)->postJson('/api/v1/expeditions',['character_id'=>$third,'join_code'=>$duel['join_code']])->assertConflict();
        $w=app(World::class);$w->start($duel['id'],$ann);
        self::assertSame('duel',$w->snapshot($duel['id'],$ann)['world']['mode']);
        for ($i=0;$i<30;$i++) { DB::table('expeditions')->update(['next_tick_at'=>0]);$w->tick(); }
        $s=json_decode(DB::table('expeditions')->where('id',$duel['id'])->value('state'),true);
        $s['players'][$ann]['x']=10;$s['players'][$ann]['y']=11;$s['players'][$bob]['x']=12;$s['players'][$bob]['y']=11;$s['players'][$bob]['hp']=1;
        DB::table('expeditions')->where('id',$duel['id'])->update(['state'=>json_encode($s)]);
        $w->command($duel['id'],$ann,(string)Str::uuid(),['action'=>'attack','target_id'=>$bob]);
        $this->assertDatabaseHas('characters',['id'=>$ann,'rating'=>1016,'duel_wins'=>1,'gold'=>7,'active_expedition'=>null]);
        $this->assertDatabaseHas('characters',['id'=>$bob,'rating'=>984,'duel_losses'=>1,'active_expedition'=>null]);
        $this->assertDatabaseHas('settlements',['character_id'=>$ann,'outcome'=>'victory','gold'=>0]);
        $town=$this->withToken($a)->getJson('/api/v1/characters/'.$ann.'/town')->assertOk()->json();
        self::assertSame(['Duelist','Rival'],array_column($town['leaders']['duel'],'name'));
    }

    public function test_leaving_a_duel_concedes(): void
    {
        [$a,$ann]=$this->hero('Leaver');[$b,$bob]=$this->hero('Stayer');
        $duel=$this->withToken($a)->postJson('/api/v1/expeditions',['character_id'=>$ann,'mode'=>'duel'])->json();
        $this->withToken($b)->postJson('/api/v1/expeditions',['character_id'=>$bob,'join_code'=>$duel['join_code']])->assertOk();
        $w=app(World::class);$w->start($duel['id'],$ann);$w->leave($duel['id'],$ann);
        $this->assertDatabaseHas('settlements',['character_id'=>$bob,'outcome'=>'victory']);
        $this->assertDatabaseHas('expeditions',['id'=>$duel['id'],'status'=>'completed']);
        self::assertSame(1,app(Replay::class)->verify($duel['id'])['verified_events']);
    }

    public function test_forge_upgrade_enchant_and_limits(): void
    {
        [$t,$id]=$this->hero('Smith');
        DB::table('characters')->where('id',$id)->update(['gold'=>2000,'materials'=>100,'essence'=>10]);
        $town=$this->withToken($t)->getJson('/api/v1/characters/'.$id.'/town')->assertOk()->json();
        self::assertSame(2,$town['forge']['limit']);self::assertArrayHasKey('crossbow',$town['forge']['bases']);
        $this->act($t,$id,'forge',['target'=>'axe','tier'=>3])->assertConflict();
        $this->act($t,$id,'forge',['target'=>'axe','tier'=>2,'text'=>'nope'])->assertUnprocessable();
        $this->act($t,$id,'forge',['target'=>'axe','tier'=>2,'text'=>'vital'])->assertOk()->assertJsonPath('hero.gold',2000-40)->assertJsonPath('hero.essence',9);
        $item=DB::table('character_items')->where('character_id',$id)->where('definition','axe+2~vital')->value('id');
        $sheet=$this->withToken($t)->getJson('/api/v1/characters/'.$id)->json();
        $row=collect($sheet['items'])->firstWhere('id',$item);
        self::assertSame(['Секира жизни +2',8,'axe'],[$row['name'],$row['damage'],$row['icon']]);
        self::assertSame(['gold'=>36,'materials'=>3,'essence'=>0],$row['upgrade_cost']);
        $this->act($t,$id,'upgrade',['target'=>$item])->assertOk();
        $this->act($t,$id,'enchant',['target'=>$item,'text'=>'keen'])->assertOk();
        $this->assertDatabaseHas('character_items',['id'=>$item,'definition'=>'axe+3~keen']);
        $unique=DB::table('character_items')->where('character_id',$id)->where('definition','leather')->value('id');
        $this->act($t,$id,'upgrade',['target'=>$unique])->assertConflict();
        $this->act($t,$id,'salvage',['target'=>$item])->assertOk()->assertJsonPath('hero.materials',100-4-3+4);
    }

    public function test_depth_record_and_leaderboard(): void
    {
        [$t,$id]=$this->hero('Delver');
        $exp=$this->withToken($t)->postJson('/api/v1/expeditions',['character_id'=>$id])->json('id');
        $w=app(World::class);$w->start($exp,$id);
        $s=json_decode(DB::table('expeditions')->where('id',$exp)->value('state'),true);
        $s['floor']=7;$s['players'][$id]['x']=$s['exit'][0];$s['players'][$id]['y']=$s['exit'][1];$s['enemies']=[];
        DB::table('expeditions')->where('id',$exp)->update(['state'=>json_encode($s)]);
        $w->command($exp,$id,(string)Str::uuid(),['action'=>'extract']);
        $this->assertDatabaseHas('characters',['id'=>$id,'best_depth'=>7,'campaign'=>0]);
        $town=$this->withToken($t)->getJson('/api/v1/characters/'.$id.'/town')->json();
        self::assertSame([['name'=>'Delver','class_id'=>'guardian','best_depth'=>7]],$town['leaders']['depth']);
        self::assertSame(4,$town['forge']['limit']);
    }
}
