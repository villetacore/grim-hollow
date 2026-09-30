<?php
namespace App\Http\Controllers;
use App\Game\Characters;
use App\Game\World;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RpgController
{
    public function sheet(Request $r,Characters $characters,string $id) { return $characters->sheet($r->attributes->get('account_id'),$id); }
    public function manage(Request $r,Characters $characters,string $id)
    {
        $d=$r->validate(['operation_id'=>'required|uuid','action'=>'required|in:equip,unequip,sell,train','item_id'=>'sometimes|ulid','attribute'=>'sometimes|in:strength,vitality,intellect']);
        return $characters->manage($r->attributes->get('account_id'),$id,$d);
    }
    private function channel(Request $r,World $world): array
    {
        $d=$r->validate(['character_id'=>'required|ulid','channel'=>'required|in:global,party,guild']);
        $c=$world->character($r->attributes->get('account_id'),$d['character_id']);
        if($d['channel']==='guild') {
            $guild=DB::table('guild_members')->where('character_id',$c->id)->value('guild_id');abort_unless($guild,409,'guild_required');
            return [$c,'guild:'.$guild];
        }
        if ($d['channel']==='party') {
            abort_unless($c->active_expedition,409,'party_required');
            return [$c,'party:'.$c->active_expedition];
        }
        return [$c,'global'];
    }
    public function chat(Request $r,World $world)
    {
        [$c,$channel]=$this->channel($r,$world);
        $d=$r->validate(['after'=>'sometimes|integer|min:0']);
        $query=DB::table('chat_messages as m')->join('characters as c','c.id','=','m.character_id')->where('m.channel',$channel);
        if (!empty($d['after'])) $query->where('m.id','>',$d['after']);
        // Newest 50 on first visit; ordered incremental pages thereafter.
        $rows=empty($d['after'])?$query->orderByDesc('m.id')->limit(50)->get(['m.id','c.name','m.body','m.created_at'])->reverse()->values():$query->orderBy('m.id')->limit(50)->get(['m.id','c.name','m.body','m.created_at']);
        return ['items'=>$rows,'cursor'=>(string)($rows->last()->id??($d['after']??0))];
    }
    public function postChat(Request $r,World $world)
    {
        $muted=DB::table('users')->where('id',$r->attributes->get('account_id'))->value('muted_until');
        abort_if($muted && $muted>now()->toDateTimeString(),403,'chat_muted');
        [$c,$channel]=$this->channel($r,$world);
        $d=$r->validate(['message_id'=>'required|uuid','body'=>'required|string|max:280']);
        $body=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$d['body'])); abort_if($body==='',422,'empty_message');
        return DB::transaction(function() use($c,$channel,$d,$body) {
            DB::table('characters')->where('id',$c->id)->lockForUpdate()->first();
            $old=DB::table('chat_messages')->where('character_id',$c->id)->where('message_id',$d['message_id'])->first();
            if ($old) { abort_unless($old->body===$body && $old->channel===$channel,409,'message_id_reused'); return ['id'=>(string)$old->id]; }
            $id=DB::table('chat_messages')->insertGetId(['character_id'=>$c->id,'channel'=>$channel,'message_id'=>$d['message_id'],'body'=>$body,'created_at'=>now()]);
            return ['id'=>(string)$id];
        },3);
    }
}
