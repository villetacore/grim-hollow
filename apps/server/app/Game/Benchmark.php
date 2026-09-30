<?php
declare(strict_types=1);
namespace App\Game;
use GrimHollow\Core\{Game,Canonical};
final class Benchmark {
    /** CPU/rules benchmark, explicitly not a network/DB CCU claim. */
    public static function run(int $seconds,int $players):array {
        $zones=[];for($i=0;$i<(int)ceil($players/4);$i++)$zones[]=Game::create($i+1,['a'=>'A','b'=>'B','c'=>'C','d'=>'D']);
        $samples=[];$views=0;$start=microtime(true);$ticks=0;
        do {
            $t=microtime(true);
            foreach($zones as $i=>$s){
                // Repeat complete deterministic fights rather than idle completed snapshots.
                if($s['status']!=='active')$s=Game::create($i+1,['a'=>'A','b'=>'B','c'=>'C','d'=>'D']);
                $s=Game::tick($s);foreach(array_keys($s['players']) as $pid){Canonical::json(Game::view($s,$pid));$views++;}$zones[$i]=$s;
            }
            $samples[]=(microtime(true)-$t)*1000;$ticks++;
            $remaining=100000-(int)((microtime(true)-$t)*1000000);if($remaining>0)usleep($remaining);
        }while(microtime(true)-$start<$seconds);
        sort($samples);$quantile=fn(float $p)=>round($samples[min(count($samples)-1,(int)floor(count($samples)*$p))],3);
        return ['scope'=>'rules_and_visibility_only_no_database_or_network','players'=>$players,'zones'=>count($zones),'seconds'=>round(microtime(true)-$start,2),'ticks'=>$ticks,
            'views'=>$views,'frame_p50_ms'=>$quantile(.5),'frame_p95_ms'=>$quantile(.95),'frame_p99_ms'=>$quantile(.99),'peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,2)];
    }
}
