<?php
namespace Tests\Feature;
use App\Game\{Replay,World};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class ReplayTest extends TestCase {
    use RefreshDatabase;
    public function test_commands_ticks_disconnect_and_leave_replay_without_rewards_mutation():void {
        $token=$this->postJson('/api/v1/auth/register',['email'=>'replay@example.test','password'=>'correct-horse-battery'])->json('access_token');
        $hero=$this->withToken($token)->postJson('/api/v1/characters',['name'=>'ReplayHero'])->json('id');
        $id=$this->postJson('/api/v1/expeditions',['character_id'=>$hero])->json('id');$w=app(World::class);$w->start($id,$hero);
        $w->command($id,$hero,(string)Str::uuid(),['action'=>'move','direction'=>'east']);
        $w->command($id,$hero,(string)Str::uuid(),['action'=>'guard']);
        DB::table('expeditions')->where('id',$id)->update(['next_tick_at'=>0]);$w->tick();
        self::assertSame(3,app(Replay::class)->verify($id)['verified_events']);
        $w->leave($id,$hero);$before=DB::table('settlements')->count();
        self::assertSame(4,app(Replay::class)->verify($id)['verified_events']);self::assertSame($before,DB::table('settlements')->count());
    }
}
