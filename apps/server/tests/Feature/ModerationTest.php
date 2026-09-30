<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class ModerationTest extends TestCase {
    use RefreshDatabase;
    public function test_refresh_revokes_the_previous_session():void {
        $token=$this->postJson('/api/v1/auth/register',['email'=>'rotate@example.test','password'=>'correct-horse-battery'])->json('access_token');
        $new=$this->withToken($token)->postJson('/api/v1/auth/refresh')->assertOk()->json('access_token');
        self::assertNotSame($token,$new);$this->withToken($token)->getJson('/api/v1/characters')->assertUnauthorized();
        $this->withToken($new)->getJson('/api/v1/characters')->assertOk();
    }
    public function test_only_admin_can_review_and_mute_is_enforced():void {
        $token=$this->postJson('/api/v1/auth/register',['email'=>'reported@example.test','password'=>'correct-horse-battery'])->json('access_token');
        $hero=$this->withToken($token)->postJson('/api/v1/characters',['name'=>'Reported'])->json('id');
        $message=$this->postJson('/api/v1/chat',['character_id'=>$hero,'channel'=>'global','message_id'=>(string)Str::uuid(),'body'=>'test'])->json('id');
        $this->postJson('/api/v1/chat/reports',['message_id'=>$message,'reason'=>'test report'])->assertOk();
        $this->getJson('/api/v1/admin/reports')->assertForbidden();
        DB::table('users')->where('email','reported@example.test')->update(['game_admin'=>true]);
        $report=$this->getJson('/api/v1/admin/reports')->assertOk()->json('items.0.id');
        $body=['report_id'=>$report,'action'=>'mute','minutes'=>60,'reason'=>'test decision'];
        $this->postJson('/api/v1/admin/reports',$body)->assertOk();$this->postJson('/api/v1/admin/reports',$body)->assertOk();
        $this->assertDatabaseCount('moderation_actions',1);
        $this->postJson('/api/v1/chat',['character_id'=>$hero,'channel'=>'global','message_id'=>(string)Str::uuid(),'body'=>'blocked'])->assertForbidden();
    }
}
