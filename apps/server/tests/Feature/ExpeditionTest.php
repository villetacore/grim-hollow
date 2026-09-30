<?php

namespace Tests\Feature;

use App\Game\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExpeditionTest extends TestCase
{
    use RefreshDatabase;

    private function hero(string $name): array
    {
        $token = $this->postJson('/api/v1/auth/register', ['email' => $name.'@example.test', 'password' => 'correct-horse-battery'])->assertCreated()->json('access_token');
        $id = $this->withToken($token)->postJson('/api/v1/characters', ['name' => $name])->assertCreated()->json('id');

        return [$token, $id];
    }

    public function test_two_players_share_a_lobby_and_outsider_cannot_read_it(): void
    {
        [$token,$hero] = $this->hero('Guardian');
        $e = $this->withToken($token)->postJson('/api/v1/expeditions', ['character_id' => $hero])->assertOk()->json();
        [$otherToken,$other] = $this->hero('Ranger');
        $this->withToken($otherToken)->getJson('/api/v1/expeditions/'.$e['id'].'?character_id='.$other)->assertNotFound();
        $this->withToken($otherToken)->postJson('/api/v1/expeditions', ['character_id' => $other, 'join_code' => $e['join_code']])->assertOk();
        $this->withToken($otherToken)->postJson('/api/v1/expeditions/'.$e['id'].'/start', ['character_id' => $other])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/expeditions/'.$e['id'].'/start', ['character_id' => $hero])->assertOk();
        $this->assertDatabaseCount('expedition_members', 2);
    }

    public function test_duplicate_command_and_extraction_only_settle_once(): void
    {
        [$token,$hero] = $this->hero('Explorer');
        $e = $this->withToken($token)->postJson('/api/v1/expeditions', ['character_id' => $hero])->json();
        $world = app(World::class);
        $world->start($e['id'], $hero);
        $row = DB::table('expeditions')->where('id', $e['id'])->first();
        $s = json_decode($row->state, true);
        $s['players'][$hero]['x'] = $s['exit'][0];
        $s['players'][$hero]['y'] = $s['exit'][1];
        $s['players'][$hero]['gold'] = 25;
        $s['players'][$hero]['xp'] = 40;
        DB::table('expeditions')->where('id', $e['id'])->update(['state' => json_encode($s)]);
        $id = (string) Str::uuid();
        $a = $world->command($e['id'], $hero, $id, ['action' => 'extract']);
        $b = (new World)->command($e['id'], $hero, $id, ['action' => 'extract']);
        self::assertEquals($a, $b);
        $this->assertDatabaseCount('settlements', 1);
        $this->assertDatabaseHas('characters', ['id' => $hero, 'gold' => 25, 'xp' => 40, 'active_expedition' => null]);
    }

    public function test_reusing_command_id_with_other_payload_is_conflict(): void
    {
        [$token,$hero] = $this->hero('Knight');
        $e = $this->withToken($token)->postJson('/api/v1/expeditions', ['character_id' => $hero])->json();
        app(World::class)->start($e['id'], $hero);
        $base = ['character_id' => $hero, 'command_id' => (string) Str::uuid()];
        $url = '/api/v1/expeditions/'.$e['id'].'/commands';
        $this->withToken($token)->postJson($url, $base + ['payload' => ['action' => 'guard']])->assertOk();
        $this->withToken($token)->postJson($url, $base + ['payload' => ['action' => 'potion']])->assertConflict();
    }

    public function test_restart_uses_last_committed_state_and_tick_is_not_double_applied(): void
    {
        [$token,$hero] = $this->hero('Survivor');
        $e = $this->withToken($token)->postJson('/api/v1/expeditions', ['character_id' => $hero])->json();
        $w = new World;
        $w->start($e['id'], $hero);
        DB::table('expeditions')->where('id', $e['id'])->update(['next_tick_at' => 0]);
        $w->tick();
        $before = $w->snapshot($e['id'], $hero);
        $restarted = new World;
        $after = $restarted->snapshot($e['id'], $hero);
        self::assertSame($before, $after);
        $restarted->tick();
        self::assertSame($after['revision'], $restarted->snapshot($e['id'], $hero)['revision']);
    }

    public function test_session_revocation_blocks_access(): void
    {
        [$token] = $this->hero('Logout');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->withToken($token)->getJson('/api/v1/characters')->assertUnauthorized();
    }

    public function test_server_outage_does_not_consume_disconnect_grace(): void
    {
        [$token,$hero]=$this->hero('Reconnect');
        $e=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$hero])->json();
        $world=new World(); $world->start($e['id'],$hero);
        $before=World::milliseconds()-180000;
        DB::table('expedition_members')->where('expedition_id',$e['id'])->update(['last_seen'=>$before]);
        DB::table('expeditions')->where('id',$e['id'])->update(['next_tick_at'=>$before]);
        $world->tick();
        self::assertNull($world->snapshot($e['id'],$hero)['world']['self']['outcome']);
    }

    public function test_host_can_cancel_lobby_and_free_both_characters(): void
    {
        [$token,$hero]=$this->hero('Leader');
        $e=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$hero])->json();
        [$secondToken,$second]=$this->hero('Member');
        $this->withToken($secondToken)->postJson('/api/v1/expeditions',['character_id'=>$second,'join_code'=>$e['join_code']])->assertOk();
        $this->withToken($token)->postJson('/api/v1/expeditions/'.$e['id'].'/leave',['character_id'=>$hero])->assertOk();
        foreach ([$hero,$second] as $id) $this->assertDatabaseHas('characters',['id'=>$id,'active_expedition'=>null,'gold'=>0]);
        $this->assertDatabaseHas('expeditions',['id'=>$e['id'],'status'=>'completed']);
    }

    public function test_member_leave_preserves_lobby_and_cannot_rejoin_settled_run(): void
    {
        [$token,$hero]=$this->hero('LobbyHost');
        $e=$this->withToken($token)->postJson('/api/v1/expeditions',['character_id'=>$hero])->json();
        [$secondToken,$second]=$this->hero('Departing');
        $join=['character_id'=>$second,'join_code'=>$e['join_code']];
        $this->withToken($secondToken)->postJson('/api/v1/expeditions',$join)->assertOk();
        $this->withToken($secondToken)->postJson('/api/v1/expeditions/'.$e['id'].'/leave',['character_id'=>$second])->assertOk();
        $this->assertDatabaseHas('expeditions',['id'=>$e['id'],'status'=>'lobby']);
        $this->assertDatabaseHas('characters',['id'=>$second,'active_expedition'=>null]);
        $this->withToken($secondToken)->postJson('/api/v1/expeditions',$join)->assertConflict();
    }
}
