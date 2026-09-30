<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class ModerationController {
    public function report(Request $r){
        $d=$r->validate(['message_id'=>'required|integer|min:1','reason'=>'required|string|min:3|max:280']);$account=$r->attributes->get('account_id');
        $m=DB::table('chat_messages')->where('id',$d['message_id'])->first();abort_unless($m,404,'message_not_found');
        $allowed=$m->channel==='global';
        if(str_starts_with($m->channel,'party:')) $allowed=DB::table('expedition_members as m')->join('characters as c','c.id','=','m.character_id')->where('c.user_id',$account)->where('m.expedition_id',substr($m->channel,6))->exists();
        if(str_starts_with($m->channel,'guild:')) $allowed=DB::table('guild_members as m')->join('characters as c','c.id','=','m.character_id')->where('c.user_id',$account)->where('m.guild_id',substr($m->channel,6))->exists();
        abort_unless($allowed,404,'message_not_found');
        DB::table('chat_reports')->insertOrIgnore(['reporter_id'=>$account,'message_id'=>$m->id,'reason'=>$d['reason'],'created_at'=>now()]);return ['status'=>'reported'];
    }
    private function admin(Request $r):int {
        $id=$r->attributes->get('account_id');abort_unless(DB::table('users')->where('id',$id)->value('game_admin'),403,'admin_only');return $id;
    }
    public function index(Request $r){
        $this->admin($r);return ['items'=>DB::table('chat_reports as r')->join('chat_messages as m','m.id','=','r.message_id')->join('characters as c','c.id','=','m.character_id')
            ->where('r.status','open')->orderBy('r.id')->limit(100)->get(['r.id','r.reason','c.name','m.body','m.created_at'])];
    }
    public function decide(Request $r){
        $admin=$this->admin($r);$d=$r->validate(['report_id'=>'required|integer','action'=>'required|in:dismiss,mute','minutes'=>'required|integer|min:1|max:10080','reason'=>'required|string|min:3|max:280']);
        return DB::transaction(function()use($d,$admin){
            $report=DB::table('chat_reports')->where('id',$d['report_id'])->lockForUpdate()->first();abort_unless($report,404,'report_not_found');
            if($report->status!=='open')return ['status'=>'already_reviewed'];
            if($d['action']==='mute'){
                $user=DB::table('chat_messages as m')->join('characters as c','c.id','=','m.character_id')->where('m.id',$report->message_id)->value('c.user_id');
                DB::table('users')->where('id',$user)->lockForUpdate()->first();DB::table('users')->where('id',$user)->update(['muted_until'=>now()->addMinutes($d['minutes'])]);
            }
            DB::table('chat_reports')->where('id',$report->id)->update(['status'=>$d['action']]);
            DB::table('moderation_actions')->insert(['admin_id'=>$admin,'report_id'=>$report->id,'action'=>$d['action'],'reason'=>$d['reason'],'created_at'=>now()]);return ['status'=>'reviewed'];
        },3);
    }
}
