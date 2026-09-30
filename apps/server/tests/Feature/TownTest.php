<?php
namespace Tests\Feature;
use App\Game\{Characters,World};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class TownTest extends TestCase {
    use RefreshDatabase;
    private function hero(string $name,string $class='guardian'): array {
        $token=$this->postJson('/api/v1/auth/register',['email'=>$name.'@example.test','password'=>'correct-horse-battery'])->assertCreated()->json('access_token');
        $id=$this->withToken($token)->postJson('/api/v1/characters',['name'=>$name,'class_id'=>$class])->assertCreated()->json('id');return [$token,$id];
    }
    private function act(string $token,string $id,string $action,array $extra=[]){
        return $this->withToken($token)->postJson('/api/v1/characters/'.$id.'/town',['operation_id'=>(string)Str::uuid(),'action'=>$action]+$extra);
    }
    public function test_market_escrow_ownership_fee_and_retries(): void {
        [$a,$seller]=$this->hero('Seller');[$b,$buyer]=$this->hero('Buyer');
        DB::table('characters')->where('id',$buyer)->update(['gold'=>100]);(new Characters)->grant($seller,'steel_sword');
        $item=DB::table('character_items')->where('character_id',$seller)->where('definition','steel_sword')->value('id');
        $lot=$this->act($a,$seller,'list',['target'=>$item,'price'=>20])->assertOk()->json('market.0.id');
        $this->withToken($a)->getJson('/api/v1/characters/'.$seller)->assertJsonCount(4,'items');
        $this->act($a,$seller,'buy',['target'=>$lot])->assertConflict();
        $this->act($b,$buyer,'cancel',['target'=>$lot])->assertForbidden();
        $op=['operation_id'=>(string)Str::uuid(),'action'=>'buy','target'=>$lot];
        $url='/api/v1/characters/'.$buyer.'/town';$this->withToken($b)->postJson($url,$op)->assertOk()->assertJsonPath('hero.gold',80);
        $this->postJson($url,$op)->assertOk()->assertJsonPath('hero.gold',80);
        $this->assertDatabaseHas('characters',['id'=>$seller,'gold'=>19]);$this->assertDatabaseHas('character_items',['id'=>$item,'character_id'=>$buyer,'escrow'=>false]);
        $this->act($b,$buyer,'buy',['target'=>$lot])->assertConflict();
    }
    public function test_crafting_supply_respec_and_bound_items(): void {
        [$token,$id]=$this->hero('Crafter','ranger');DB::table('characters')->where('id',$id)->update(['gold'=>100,'materials'=>5,'xp'=>40,'strength'=>2]);
        $this->act($token,$id,'craft',['target'=>'chainmail'])->assertOk()->assertJsonPath('hero.materials',2)->assertJsonPath('hero.gold',88)->assertJsonPath('hero.craft_xp',1);
        $this->act($token,$id,'craft',['target'=>'chainmail'])->assertConflict();
        $this->act($token,$id,'respec')->assertOk()->assertJsonPath('hero.points',2);
        $this->act($token,$id,'supply')->assertOk()->assertJsonPath('hero.supplies',1);
        $item=DB::table('character_items')->where('character_id',$id)->where('definition','hunting_bow')->value('id');
        $this->withToken($token)->postJson('/api/v1/characters/'.$id.'/manage',['operation_id'=>(string)Str::uuid(),'action'=>'unequip','item_id'=>$item])->assertOk();
        $this->act($token,$id,'list',['target'=>$item,'price'=>10])->assertConflict();
        $exp=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id])->json('id');
        $w=app(World::class);self::assertSame(4,$w->snapshot($exp,$id)['world']['self']['potions']);
        $w->start($exp,$id);$w->start($exp,$id);$this->assertDatabaseHas('characters',['id'=>$id,'supplies'=>0]);
    }
    public function test_campaign_unlocks_on_extracted_boss_only_once(): void {
        [$token,$id]=$this->hero('Campaign','warden');
        $this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$id,'biome'=>'monastery'])->assertConflict();
        foreach(['mines','monastery','roots'] as $chapter=>$biome){
            $exp=$this->postJson('/api/v1/expeditions',['character_id'=>$id,'biome'=>$biome])->assertOk()->json('id');
            $w=app(World::class);$w->start($exp,$id);$s=json_decode(DB::table('expeditions')->where('id',$exp)->value('state'),true);
            $s['floor']=3;$s['enemies']['boss']=['hp'=>0];$s['players'][$id]['x']=$s['exit'][0];$s['players'][$id]['y']=$s['exit'][1];
            DB::table('expeditions')->where('id',$exp)->update(['state'=>json_encode($s)]);$uuid=(string)Str::uuid();
            $w->command($exp,$id,$uuid,['action'=>'extract']);$w->command($exp,$id,$uuid,['action'=>'extract']);
            $this->assertDatabaseHas('characters',['id'=>$id,'campaign'=>$chapter+1]);
        }
        $this->assertDatabaseHas('characters',['id'=>$id,'gold'=>300,'xp'=>240]);
    }
    public function test_guild_invitation_chat_and_leader_succession(): void {
        [$a,$leader]=$this->hero('GuildLeader');[$b,$member]=$this->hero('GuildMember');
        $guild=$this->act($a,$leader,'guild_create',['text'=>'Три огня'])->assertOk()->json('guild.id');
        $this->act($b,$member,'guild_accept',['target'=>$guild])->assertForbidden();
        $this->act($a,$leader,'guild_invite',['text'=>'GuildMember'])->assertOk();
        $this->act($b,$member,'guild_accept',['target'=>$guild])->assertOk()->assertJsonPath('guild.id',$guild);
        $this->withToken($a)->postJson('/api/v1/chat',['character_id'=>$leader,'channel'=>'guild','message_id'=>(string)Str::uuid(),'body'=>'В поход!'])->assertOk();
        $this->withToken($b)->getJson('/api/v1/chat?character_id='.$member.'&channel=guild')->assertOk()->assertJsonCount(1,'items');
        $this->act($a,$leader,'guild_leave')->assertOk();$this->assertDatabaseHas('guilds',['id'=>$guild,'leader_id'=>$member]);
        $this->withToken($a)->getJson('/api/v1/chat?character_id='.$leader.'&channel=guild')->assertConflict();
    }
    public function test_talent_points_class_limits_and_respec(): void {
        [$token,$id]=$this->hero('TalentGuard');
        $this->act($token,$id,'talent',['target'=>'bulwark'])->assertConflict();
        DB::table('characters')->where('id',$id)->update(['xp'=>120]);
        $this->act($token,$id,'talent',['target'=>'stormcaller'])->assertUnprocessable();
        $op=['operation_id'=>(string)Str::uuid(),'action'=>'talent','target'=>'bulwark'];
        $url='/api/v1/characters/'.$id.'/town';
        $this->withToken($token)->postJson($url,$op)->assertOk()->assertJsonPath('talents.bulwark.rank',1)->assertJsonPath('talent_points',0);
        $this->postJson($url,$op)->assertOk()->assertJsonPath('talents.bulwark.rank',1);
        $this->act($token,$id,'talent',['target'=>'executioner'])->assertConflict();
        $this->act($token,$id,'respec')->assertOk()->assertJsonPath('talent_points',1)->assertJsonPath('talents.bulwark.rank',0);
    }
    public function test_friendship_requires_the_other_account_to_accept(): void {
        [$a,$alice]=$this->hero('FriendAlice');[$b,$bob]=$this->hero('FriendBob');
        $this->act($a,$alice,'friend_request',['text'=>'FriendBob'])->assertOk()->assertJsonPath('friends.0.accepted',false);
        $this->act($a,$alice,'friend_accept',['text'=>'FriendBob'])->assertConflict();
        $this->act($b,$bob,'friend_accept',['text'=>'FriendAlice'])->assertOk()->assertJsonPath('friends.0.accepted',true);
        $this->act($b,$bob,'friend_remove',['text'=>'FriendAlice'])->assertOk()->assertJsonCount(0,'friends');
    }
}
