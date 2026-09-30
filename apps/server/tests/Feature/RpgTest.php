<?php
namespace Tests\Feature;
use App\Game\{World,Characters};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RpgTest extends TestCase
{
    use RefreshDatabase;
    private function hero(string $name): array
    {
        $token=$this->postJson('/api/v1/auth/register',['email'=>$name.'@example.test','password'=>'correct-horse-battery'])->assertCreated()->json('access_token');
        $id=$this->withToken($token)->postJson('/api/v1/characters',['name'=>$name])->assertCreated()->json('id');
        return [$token,$id];
    }
    public function test_extracted_loot_and_training_are_persistent_and_idempotent(): void
    {
        [$token,$id]=$this->hero('LootHero');$w=app(World::class);
        $exp=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->assertOk()->json('id');
        $w->start($exp,$id);$s=json_decode(DB::table('expeditions')->where('id',$exp)->value('state'),true);
        $s['players'][$id]['x']=$s['exit'][0];$s['players'][$id]['y']=$s['exit'][1];
        $s['players'][$id]['loot']=['steel_sword'];$s['players'][$id]['xp']=40;
        DB::table('expeditions')->where('id',$exp)->update(['state'=>json_encode($s)]);
        $uuid=(string)Str::uuid();$w->command($exp,$id,$uuid,['action'=>'extract']);$w->command($exp,$id,$uuid,['action'=>'extract']);
        $sheet=$this->withToken($token)->getJson('/api/v1/characters/'.$id)->assertOk()->assertJsonPath('level',2)->assertJsonPath('points',2)->json();
        self::assertCount(5,$sheet['items']);
        $train=['operation_id'=>(string)Str::uuid(),'action'=>'train','attribute'=>'strength'];
        $url='/api/v1/characters/'.$id.'/manage';
        $this->postJson($url,$train)->assertOk()->assertJsonPath('points',1);
        $this->postJson($url,$train)->assertOk()->assertJsonPath('attributes.strength',1);
        $this->postJson($url,array_replace($train,['attribute'=>'intellect']))->assertConflict();
        $this->postJson($url,array_replace($train,['operation_id'=>(string)Str::uuid()]))->assertOk()->assertJsonPath('points',0);
        $this->postJson($url,array_replace($train,['operation_id'=>(string)Str::uuid()]))->assertConflict();
    }
    public function test_defeat_discards_loot_and_grants_only_quarter_experience(): void
    {
        [$token,$id]=$this->hero('FallenHero');$w=app(World::class);
        $exp=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->json('id');$w->start($exp,$id);
        $s=json_decode(DB::table('expeditions')->where('id',$exp)->value('state'),true);
        $s['tick']=35999;$s['players'][$id]['loot']=['warden_shield'];$s['players'][$id]['gold']=100;$s['players'][$id]['xp']=40;
        DB::table('expeditions')->where('id',$exp)->update(['state'=>json_encode($s),'next_tick_at'=>0]);$w->tick();
        $this->assertDatabaseHas('characters',['id'=>$id,'xp'=>10,'gold'=>0]);
        self::assertSame(4,DB::table('character_items')->where('character_id',$id)->count());
    }
    public function test_item_ownership_slots_and_sale_cannot_be_abused(): void
    {
        [$a,$hero]=$this->hero('OwnerHero');[$b,$other]=$this->hero('OtherHero');
        $url='/api/v1/characters/'.$hero;
        $this->withToken($b)->getJson($url)->assertNotFound();
        $sheet=$this->withToken($a)->getJson($url)->json();$item=$sheet['items'][0]['id'];
        $op=['operation_id'=>(string)Str::uuid(),'action'=>'sell','item_id'=>$item];
        $this->postJson($url.'/manage',$op)->assertConflict();
        $this->withToken($b)->postJson('/api/v1/characters/'.$other.'/manage',$op)->assertNotFound();
        $this->withToken($a)->postJson($url.'/manage',['operation_id'=>(string)Str::uuid(),'action'=>'unequip','item_id'=>$item])->assertOk();
        $gold=$this->postJson($url.'/manage',$op)->assertOk()->json('gold');
        $this->postJson($url.'/manage',$op)->assertOk()->assertJsonPath('gold',$gold);
    }
    public function test_party_chat_is_isolated_and_duplicate_messages_are_not_repeated(): void
    {
        [$a,$hero]=$this->hero('ChatHero');[$b,$other]=$this->hero('ChatOther');
        foreach ([[$a,$hero],[$b,$other]] as [$token,$id]) $this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->assertOk();
        $body=['character_id'=>$hero,'channel'=>'party','message_id'=>(string)Str::uuid(),'body'=>'Секрет группы'];
        $this->withToken($a)->postJson('/api/v1/chat',$body)->assertOk();$this->postJson('/api/v1/chat',$body)->assertOk();
        $this->postJson('/api/v1/chat',array_replace($body,['body'=>'Другой текст']))->assertConflict();
        $this->withToken($b)->getJson('/api/v1/chat?character_id='.$other.'&channel=party')->assertOk()->assertJsonCount(0,'items');
        $this->withToken($a)->getJson('/api/v1/chat?character_id='.$hero.'&channel=party')->assertOk()->assertJsonCount(1,'items');
    }
}
