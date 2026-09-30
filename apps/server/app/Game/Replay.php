<?php
declare(strict_types=1);
namespace App\Game;
use GrimHollow\Core\{Game,Canonical};
use Illuminate\Support\Facades\DB;
use RuntimeException;
final class Replay {
    public function verify(string $id): array {
        return DB::transaction(function()use($id){
            $e=DB::table('expeditions')->where('id',$id)->lockForUpdate()->first();
            if(!$e||!$e->genesis) throw new RuntimeException('No replay genesis: expedition predates replay or has not started.');
            $s=json_decode($e->genesis,true,512,JSON_THROW_ON_ERROR);
            if(($s['content_version']??'')!==Game::CONTENT_VERSION) throw new RuntimeException('Replay requires the matching content/runtime version.');
            $revision=(int)$e->genesis_revision;$count=0;
            foreach(DB::table('game_events')->where('expedition_id',$id)->where('revision','>',$revision)->orderBy('revision')->cursor() as $event){
                if((int)$event->revision!==++$revision) throw new RuntimeException('Missing revision '.$revision);
                $p=json_decode($event->payload,true);
                if($event->kind==='command') {
                    if(in_array($p['result']['status'],['executed','queued'],true)) $s=Game::submit($s,$p['character_id'],$p['command'])[0];
                } elseif($event->kind==='tick') {
                    foreach($p['absent']??[] as $pid) if(($s['players'][$pid]['outcome']??'')===null) $s['players'][$pid]['outcome']='abandoned';
                    $s=Game::tick($s);
                } elseif($event->kind==='leave') {
                    foreach($s['players'] as $pid=>&$player) if($player['outcome']===null&&(($p['cancel_lobby']??false)||$pid===$p['character_id'])) $player['outcome']='abandoned';
                    unset($player);
                    $s=Game::resolveDuel($s);
                    if(!array_filter($s['players'],fn($x)=>$x['outcome']===null)) $s['status']='completed';
                } else throw new RuntimeException('Unknown event kind');
                if(!hash_equals($event->state_hash,hash('sha256',Canonical::json($s)))) throw new RuntimeException('Replay mismatch at revision '.$revision);
                $count++;
            }
            if($revision!==(int)$e->revision||Canonical::json($s)!==Canonical::json(json_decode($e->state,true))) throw new RuntimeException('Final snapshot mismatch');
            return ['expedition'=>$id,'verified_events'=>$count,'revision'=>$revision,'sha256'=>hash('sha256',Canonical::json($s))];
        });
    }
}
