<?php
declare(strict_types=1);
namespace App\Game;
use GrimHollow\Core\{Catalog,Canonical};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Town
{
    public static function ledger(string $id,string $reason,int $gold,int $materials,string $reference): void {
        DB::table('economy_ledger')->insert(['character_id'=>$id,'reason'=>$reason,'gold_delta'=>$gold,'materials_delta'=>$materials,'reference'=>$reference,'created_at'=>now()]);
    }
    public function overview(int $account,string $id): array {
        $sheet=(new Characters)->sheet($account,$id);
        $guild=DB::table('guild_members as m')->join('guilds as g','g.id','=','m.guild_id')->where('m.character_id',$id)->first(['g.id','g.name','g.leader_id']);
        $members=$guild?DB::table('guild_members as m')->join('characters as c','c.id','=','m.character_id')->where('m.guild_id',$guild->id)->get(['c.id','c.name','c.class_id','c.campaign']):[];
        $invites=DB::table('guild_invites as i')->join('guilds as g','g.id','=','i.guild_id')->where('i.character_id',$id)->get(['g.id','g.name']);
        $market=DB::table('market_listings as l')->join('character_items as i','i.id','=','l.item_id')->join('characters as c','c.id','=','l.seller_id')
            ->where('l.status','open')->orderByDesc('l.created_at')->limit(100)->get(['l.id','l.seller_id','l.price','c.name as seller','i.definition'])
            ->map(fn($l)=>(array)$l+array_intersect_key(Catalog::item($l->definition),array_flip(['name','icon','tier','slot','level','unique'])));
        $friends=DB::table('friendships')->where('sender_id',$account)->orWhere('receiver_id',$account)->get()->map(function($f)use($account){
            $other=$f->sender_id===$account?$f->receiver_id:$f->sender_id;
            return ['name'=>DB::table('characters')->where('user_id',$other)->orderBy('id')->value('name'),'accepted'=>(bool)$f->accepted,'incoming'=>$f->receiver_id===$account];
        });
        $talents=json_decode(DB::table('characters')->where('id',$id)->value('talents')??'{}',true);
        return ['hero'=>$sheet,'market'=>$market,'talents'=>collect(Catalog::talents())->filter(fn($t)=>$t['class']===$sheet['class_id'])->map(fn($t,$key)=>$t+['rank'=>$talents[$key]??0]),
            'talent_points'=>max(0,intdiv($sheet['level'],3)-array_sum($talents)),
            'recipes'=>collect(Catalog::recipes())->map(fn($r,$key)=>$r+array_intersect_key(Catalog::item($key),array_flip(['name','slot','level','damage','armor','power','hp','mana','icon']))),
            'forge'=>['limit'=>$sheet['forge_limit'],'bases'=>Catalog::bases(),'affixes'=>collect(Catalog::affixes())->map(fn($a)=>$a['name']),
                'costs'=>collect(range(1,$sheet['forge_limit']))->mapWithKeys(fn($t)=>[$t=>['plain'=>Catalog::forgeCost($t,false),'enchanted'=>Catalog::forgeCost($t,true)]])],
            'leaders'=>['depth'=>DB::table('characters')->where('best_depth','>',0)->orderByDesc('best_depth')->orderBy('name')->limit(10)->get(['name','class_id','best_depth']),
                'duel'=>DB::table('characters')->where(fn($q)=>$q->where('duel_wins','>',0)->orWhere('duel_losses','>',0))->orderByDesc('rating')->orderBy('name')->limit(10)->get(['name','class_id','rating','duel_wins','duel_losses'])],
            'bounty'=>$sheet['bounty'],
            'guild'=>$guild,'members'=>$members,'invites'=>$invites,'friends'=>$friends,
            'ledger'=>DB::table('economy_ledger')->where('character_id',$id)->orderByDesc('id')->limit(20)->get(['reason','gold_delta','materials_delta','created_at'])];
    }
    public function act(int $account,string $id,array $d): array {
        DB::transaction(function()use($account,$id,$d){
            $c=DB::table('characters')->where('id',$id)->where('user_id',$account)->lockForUpdate()->first();abort_unless($c,404,'character_not_found');
            $hash=hash('sha256',Canonical::json($d));
            $old=DB::table('character_operations')->where('character_id',$id)->where('operation_id',$d['operation_id'])->first();
            if($old){abort_unless(hash_equals($old->payload_hash,$hash),409,'operation_id_reused');return;}
            abort_if($c->active_expedition,409,'return_to_town_first');
            $action=$d['action'];$ref=$d['operation_id'];
            if($action==='salvage'||$action==='list') {
                $item=DB::table('character_items')->where('id',$d['target']??'')->where('character_id',$id)->where('escrow',false)->first();
                abort_unless($item,404,'item_not_found');abort_if($item->equipped_slot,409,'unequip_first');
                if($action==='salvage') {
                    $def=Catalog::item($item->definition);DB::table('character_items')->where('id',$item->id)->delete();
                    // Generated gear returns materials by tier; high-level gear also yields a little essence.
                    $n=$def['generated']?1+$def['tier']:$def['level'];$essence=$def['generated']?intdiv($def['tier'],5):($n>=5?1:0);
                    DB::table('characters')->where('id',$id)->update(['materials'=>$c->materials+$n,'essence'=>$c->essence+$essence]);self::ledger($id,'salvage',0,$n,$ref);
                } else {
                    abort_if($item->bound,409,'item_bound');$price=$d['price']??0;abort_unless($price>=1&&$price<=1000000,422,'invalid_price');
                    DB::table('character_items')->where('id',$item->id)->update(['escrow'=>true]);
                    DB::table('market_listings')->insert(['id'=>(string)Str::ulid(),'seller_id'=>$id,'item_id'=>$item->id,'price'=>$price,'created_at'=>now()]);
                    self::ledger($id,'market_list',0,0,$ref);
                }
            } elseif($action==='buy'||$action==='cancel') {
                $lot=DB::table('market_listings')->where('id',$d['target']??'')->lockForUpdate()->first();abort_unless($lot&&$lot->status==='open',409,'listing_unavailable');
                if($action==='cancel') {
                    abort_unless($lot->seller_id===$id,403,'owner_only');DB::table('character_items')->where('id',$lot->item_id)->update(['escrow'=>false]);
                    DB::table('market_listings')->where('id',$lot->id)->update(['status'=>'cancelled']);self::ledger($id,'market_cancel',0,0,$lot->id);
                } else {
                    abort_if($lot->seller_id===$id,409,'own_listing');abort_unless($c->gold>=$lot->price,409,'not_enough_gold');
                    DB::table('characters')->where('id',$lot->seller_id)->lockForUpdate()->first();
                    $fee=max(1,intdiv($lot->price*5+99,100));
                    DB::table('characters')->where('id',$id)->decrement('gold',$lot->price);
                    DB::table('characters')->where('id',$lot->seller_id)->increment('gold',$lot->price-$fee);
                    DB::table('character_items')->where('id',$lot->item_id)->update(['character_id'=>$id,'escrow'=>false]);
                    DB::table('market_listings')->where('id',$lot->id)->update(['status'=>'sold','buyer_id'=>$id]);
                    self::ledger($id,'market_buy',-$lot->price,0,$lot->id);self::ledger($lot->seller_id,'market_sale',$lot->price-$fee,0,$lot->id);
                }
            } elseif($action==='craft') {
                $key=$d['target']??'';$recipe=Catalog::recipes()[$key]??null;abort_unless($recipe,422,'invalid_recipe');
                abort_unless($c->gold>=$recipe['gold']&&$c->materials>=$recipe['materials']&&$c->essence>=$recipe['essence'],409,'not_enough_resources');
                DB::table('characters')->where('id',$id)->update(['gold'=>$c->gold-$recipe['gold'],'materials'=>$c->materials-$recipe['materials'],
                    'essence'=>$c->essence-$recipe['essence'],'craft_xp'=>$c->craft_xp+1]);
                (new Characters)->grant($id,$key);self::ledger($id,'craft',-$recipe['gold'],-$recipe['materials'],$ref);
            } elseif($action==='supply') {
                abort_unless($c->gold>=8,409,'not_enough_gold');abort_if($c->supplies>=30,409,'supply_limit');
                DB::table('characters')->where('id',$id)->update(['gold'=>$c->gold-8,'supplies'=>$c->supplies+1]);self::ledger($id,'supply',-8,0,$ref);
            } elseif($action==='forge') {
                $base=$d['target']??'';$tier=(int)($d['tier']??1);$affix=$d['text']??'';
                abort_unless(isset(Catalog::bases()[$base])&&($affix===''||isset(Catalog::affixes()[$affix])),422,'invalid_recipe');
                abort_unless($tier>=1&&$tier<=Catalog::forgeLimit(Catalog::progression((int)$c->xp)['level'],(int)$c->best_depth),409,'forge_limit');
                $cost=Catalog::forgeCost($tier,$affix!=='');$this->pay($c,$cost,'forge',$ref);
                DB::table('characters')->where('id',$id)->increment('craft_xp');
                (new Characters)->grant($id,$base.'+'.$tier.($affix!==''?'~'.$affix:''));
            } elseif($action==='upgrade'||$action==='enchant') {
                $item=DB::table('character_items')->where('id',$d['target']??'')->where('character_id',$id)->where('escrow',false)->first();
                abort_unless($item,404,'item_not_found');$def=Catalog::item($item->definition);abort_unless($def['generated'],409,'unique_item');
                if($action==='upgrade') {
                    abort_unless($def['tier']<999,409,'forge_limit');
                    $this->pay($c,Catalog::upgradeCost($def['tier']),'upgrade',$ref);
                    $key=$def['icon'].'+'.($def['tier']+1).($def['affix']!==''?'~'.$def['affix']:'');
                } else {
                    $affix=$d['text']??'';abort_unless(isset(Catalog::affixes()[$affix]),422,'invalid_recipe');
                    $this->pay($c,Catalog::enchantCost($def['tier']),'enchant',$ref);
                    $key=$def['icon'].'+'.$def['tier'].'~'.$affix;
                }
                DB::table('character_items')->where('id',$item->id)->update(['definition'=>$key]);
            } elseif($action==='distill') {
                abort_unless($c->gold>=20&&$c->materials>=10,409,'not_enough_resources');
                DB::table('characters')->where('id',$id)->update(['gold'=>$c->gold-20,'materials'=>$c->materials-10,'essence'=>$c->essence+1]);
                self::ledger($id,'distill',-20,-10,$ref);
            } elseif(str_starts_with($action,'bounty_')) {
                $this->bounty($c,$action,$ref);
            } elseif($action==='respec') {
                DB::table('characters')->where('id',$id)->update(['strength'=>0,'vitality'=>0,'intellect'=>0,'talents'=>null]);
            } elseif($action==='talent') {
                $key=$d['target']??'';$talent=Catalog::talents()[$key]??null;abort_unless($talent&&$talent['class']===$c->class_id,422,'invalid_talent');
                $ranks=json_decode($c->talents??'{}',true);$points=intdiv(Catalog::progression($c->xp)['level'],3)-array_sum($ranks);
                abort_unless($points>0&&($ranks[$key]??0)<Catalog::TALENT_RANKS,409,'talent_limit');$ranks[$key]=($ranks[$key]??0)+1;
                DB::table('characters')->where('id',$id)->update(['talents'=>json_encode($ranks)]);
            } elseif(str_starts_with($action,'guild_')) {
                $this->guild($c,$d);
            } elseif(str_starts_with($action,'friend_')) {
                $this->friend($c,$d);
            } else abort(422,'unknown_action');
            DB::table('character_operations')->insert(['character_id'=>$id,'operation_id'=>$ref,'payload_hash'=>$hash]);
        },5);
        return $this->overview($account,$id);
    }
    private function pay(object $c,array $cost,string $reason,string $ref): void {
        abort_unless($c->gold>=$cost['gold']&&$c->materials>=$cost['materials']&&$c->essence>=$cost['essence'],409,'not_enough_resources');
        DB::table('characters')->where('id',$c->id)->update(['gold'=>$c->gold-$cost['gold'],'materials'=>$c->materials-$cost['materials'],'essence'=>$c->essence-$cost['essence']]);
        self::ledger($c->id,$reason,-$cost['gold'],-$cost['materials'],$ref);
    }
    private function bounty(object $c,string $action,string $ref): void {
        $bounty=$c->bounty?json_decode($c->bounty,true):null;
        if($action==='bounty_take') {
            abort_if($bounty,409,'bounty_active');
            $roll=hexdec(substr(hash('sha256',$c->id.$ref),0,7));
            DB::table('characters')->where('id',$c->id)->update(['bounty'=>json_encode(Catalog::bounty((int)$c->campaign,$roll,intdiv($roll,13)),JSON_UNESCAPED_UNICODE)]);
        } elseif($action==='bounty_drop') {
            abort_unless($bounty,409,'bounty_required');DB::table('characters')->where('id',$c->id)->update(['bounty'=>null]);
        } else {
            abort_unless($bounty&&$bounty['have']>=$bounty['need'],409,'bounty_incomplete');
            DB::table('characters')->where('id',$c->id)->update(['bounty'=>null,'gold'=>$c->gold+$bounty['gold'],
                'materials'=>$c->materials+$bounty['materials'],'essence'=>$c->essence+$bounty['essence']]);
            self::ledger($c->id,'bounty',$bounty['gold'],$bounty['materials'],$ref);
        }
    }
    private function guild(object $c,array $d): void {
        $membership=DB::table('guild_members')->where('character_id',$c->id)->first();
        if($d['action']==='guild_create') {
            abort_if($membership,409,'already_in_guild');$name=trim($d['text']??'');
            abort_unless(mb_strlen($name)>=3&&mb_strlen($name)<=32,422,'invalid_guild_name');abort_if(DB::table('guilds')->where('name',$name)->exists(),409,'guild_name_taken');
            $id=(string)Str::ulid();DB::table('guilds')->insert(['id'=>$id,'name'=>$name,'leader_id'=>$c->id,'created_at'=>now()]);
            DB::table('guild_members')->insert(['guild_id'=>$id,'character_id'=>$c->id,'created_at'=>now()]);return;
        }
        if($d['action']==='guild_accept') {
            abort_if($membership,409,'already_in_guild');$id=$d['target']??'';
            abort_unless(DB::table('guilds')->where('id',$id)->lockForUpdate()->first(),404,'guild_not_found');
            abort_unless(DB::table('guild_invites')->where('guild_id',$id)->where('character_id',$c->id)->exists(),403,'invitation_required');
            DB::table('guild_members')->insert(['guild_id'=>$id,'character_id'=>$c->id,'created_at'=>now()]);
            DB::table('guild_invites')->where('character_id',$c->id)->delete();return;
        }
        abort_unless($membership,409,'guild_required');
        $g=DB::table('guilds')->where('id',$membership->guild_id)->lockForUpdate()->first();
        if($d['action']==='guild_leave') {
            DB::table('guild_members')->where('character_id',$c->id)->delete();
            if($g->leader_id===$c->id) {
                $next=DB::table('guild_members')->where('guild_id',$g->id)->orderBy('created_at')->orderBy('character_id')->value('character_id');
                if($next) DB::table('guilds')->where('id',$g->id)->update(['leader_id'=>$next]);else DB::table('guilds')->where('id',$g->id)->delete();
            }return;
        }
        abort_unless($g->leader_id===$c->id,403,'leader_only');
        $target=DB::table('characters')->where('name',$d['text']??'')->first();abort_unless($target,404,'character_not_found');
        abort_if(DB::table('guild_members')->where('character_id',$target->id)->exists(),409,'already_in_guild');
        DB::table('guild_invites')->insertOrIgnore(['guild_id'=>$g->id,'character_id'=>$target->id]);
    }
    private function friend(object $c,array $d): void {
        $target=DB::table('characters')->where('name',$d['text']??'')->first();abort_unless($target,404,'character_not_found');
        $a=$c->user_id;$b=$target->user_id;abort_if($a===$b,409,'same_account');
        DB::table('users')->whereIn('id',[$a,$b])->orderBy('id')->lockForUpdate()->get();
        $query=fn()=>DB::table('friendships')->where(fn($q)=>$q->where('sender_id',$a)->where('receiver_id',$b))->orWhere(fn($q)=>$q->where('sender_id',$b)->where('receiver_id',$a));
        if($d['action']==='friend_remove') {$query()->delete();return;}
        $old=$query()->first();
        if($d['action']==='friend_accept') {
            abort_unless($old&&$old->receiver_id===$a,409,'invitation_required');$query()->update(['accepted'=>true]);
        } else { if(!$old) DB::table('friendships')->insert(['sender_id'=>$a,'receiver_id'=>$b]); }
    }
}
