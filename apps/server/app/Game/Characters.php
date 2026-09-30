<?php
declare(strict_types=1);
namespace App\Game;

use GrimHollow\Core\Catalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Characters
{
    // Must be called under a character row lock. Existing heroes receive the same starter kit once.
    public function starter(object $c): void
    {
        if ($c->starter_granted) return;
        $weapon=match($c->class_id){'arcanist'=>'oak_staff','ranger'=>'hunting_bow','warden'=>'healer_mace',default=>'iron_sword'};
        foreach ([$weapon,'leather','buckler','ember_ring'] as $key) {
            $this->grant($c->id,$key,$key==='ember_ring'?null:Catalog::items()[$key]['slot'],true);
        }
        DB::table('characters')->where('id',$c->id)->update(['starter_granted'=>true]);
    }
    public function grant(string $id,string $key,?string $slot=null,bool $bound=false): void
    {
        DB::table('character_items')->insert(['id'=>(string)Str::ulid(),'character_id'=>$id,'definition'=>$key,'equipped_slot'=>$slot,'bound'=>$bound||$slot!==null,'created_at'=>now()]);
    }
    public function profile(object $c): array
    {
        $equipment=DB::table('character_items')->where('character_id',$c->id)->whereNotNull('equipped_slot')->pluck('definition','equipped_slot')->all();
        $profile=Catalog::profile($c->name,$c->class_id,(int)$c->xp,(array)$c,$equipment)+['potions'=>3+min(3,(int)$c->supplies)];
        foreach(json_decode($c->talents??'{}',true) as $key=>$rank){$talent=Catalog::talents()[$key];$profile[$talent['stat']]+=$talent['amount']*$rank;}
        return $profile;
    }
    public function sheet(int $account,string $id): array
    {
        return DB::transaction(function() use($account,$id) {
            $c=DB::table('characters')->where('user_id',$account)->where('id',$id)->lockForUpdate()->first();
            abort_unless($c,404,'character_not_found'); $this->starter($c);
            $progress=Catalog::progression((int)$c->xp);
            $items=DB::table('character_items')->where('character_id',$id)->where('escrow',false)->orderBy('created_at')->orderBy('id')->get()->map(fn($i)=>(array)$i+Catalog::items()[$i->definition]);
            return ['id'=>$id,'name'=>$c->name,'class_id'=>$c->class_id,'gold'=>(int)$c->gold,'xp'=>(int)$c->xp,
                'active_expedition'=>$c->active_expedition,'campaign'=>$c->campaign,'materials'=>$c->materials,'supplies'=>$c->supplies,'craft_xp'=>$c->craft_xp,
                'biomes'=>Catalog::biomes(),'attributes'=>['strength'=>$c->strength,'vitality'=>$c->vitality,'intellect'=>$c->intellect],
                'points'=>max(0,($progress['level']-1)*2-$c->strength-$c->vitality-$c->intellect),
                'stats'=>$this->profile($c),'items'=>$items,'spells'=>Catalog::spells()]+$progress;
        },3);
    }
    public function manage(int $account,string $id,array $d): array
    {
        DB::transaction(function() use($account,$id,$d) {
            $c=DB::table('characters')->where('user_id',$account)->where('id',$id)->lockForUpdate()->first();
            abort_unless($c,404,'character_not_found');
            $hash=hash('sha256',\GrimHollow\Core\Canonical::json($d));
            $receipt=DB::table('character_operations')->where('character_id',$id)->where('operation_id',$d['operation_id'])->first();
            if ($receipt) { abort_unless(hash_equals($receipt->payload_hash,$hash),409,'operation_id_reused'); return; }
            abort_if($c->active_expedition,409,'return_to_town_first'); $this->starter($c);
            if ($d['action']==='train') {
                $stat=$d['attribute']??''; abort_unless(in_array($stat,['strength','vitality','intellect'],true),422,'invalid_attribute');
                $points=(Catalog::progression((int)$c->xp)['level']-1)*2-$c->strength-$c->vitality-$c->intellect;
                abort_unless($points>0,409,'no_skill_points');
                DB::table('characters')->where('id',$id)->increment($stat);
            } else {
                $item=DB::table('character_items')->where('id',$d['item_id']??'')->where('character_id',$id)->where('escrow',false)->first();
                abort_unless($item,404,'item_not_found'); $def=Catalog::items()[$item->definition];
                if ($d['action']==='equip') {
                    abort_unless(Catalog::progression((int)$c->xp)['level']>=$def['level'],409,'level_required');
                    DB::table('character_items')->where('character_id',$id)->where('equipped_slot',$def['slot'])->update(['equipped_slot'=>null]);
                    DB::table('character_items')->where('id',$item->id)->update(['equipped_slot'=>$def['slot'],'bound'=>true]);
                } elseif ($d['action']==='unequip') {
                    DB::table('character_items')->where('id',$item->id)->update(['equipped_slot'=>null]);
                } elseif ($d['action']==='sell') {
                    abort_if($item->equipped_slot,409,'unequip_first');
                    DB::table('character_items')->where('id',$item->id)->delete();
                    DB::table('characters')->where('id',$id)->increment('gold',$def['price']);
                    Town::ledger($id,'npc_sale',$def['price'],0,$d['operation_id']);
                }
            }
            DB::table('character_operations')->insert(['character_id'=>$id,'operation_id'=>$d['operation_id'],'payload_hash'=>$hash]);
        },3);
        return $this->sheet($account,$id);
    }
}
