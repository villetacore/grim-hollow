<?php
declare(strict_types=1);
namespace App\Game;

use GrimHollow\Core\{Catalog,Game,Plaza};
use Illuminate\Support\Facades\DB;

/**
 * The walkable town square shared by every hero who is not on an expedition. Positions live in
 * plaza_presence; heroes who stop polling fade out after Plaza::PRESENCE_MS. There are no
 * monsters and no combat here: walking, talking to keepers, emotes and chat bubbles.
 */
final class Square
{
    /** Anything faster than this between two steps is a speed hack, not network jitter. */
    private const MIN_STEP_MS = 70;
    private const DIRS = ['north'=>[0,-1],'south'=>[0,1],'west'=>[-1,0],'east'=>[1,0]];

    private function hero(int $account,string $id): object
    {
        $c=DB::table('characters')->where('id',$id)->where('user_id',$account)->first();
        abort_unless($c,404,'character_not_found');
        abort_if($c->active_expedition,409,'in_expedition');
        return $c;
    }

    /** The hero's presence row, created at the spawn point on the first visit. */
    private function presence(object $c,int $now): object
    {
        $p=DB::table('plaza_presence')->where('character_id',$c->id)->first();
        if(!$p){
            [$x,$y]=Plaza::SPAWN;
            DB::table('plaza_presence')->insert(['character_id'=>$c->id,'x'=>$x,'y'=>$y,'facing'=>'south','moved_at'=>0,'seen_at'=>$now]);
            return DB::table('plaza_presence')->where('character_id',$c->id)->first();
        }
        // A hero back from a long absence re-enters at the spawn point.
        if($p->seen_at<$now-Plaza::PRESENCE_MS){
            [$x,$y]=Plaza::SPAWN;
            DB::table('plaza_presence')->where('character_id',$c->id)->update(['x'=>$x,'y'=>$y,'seen_at'=>$now,'emote'=>null,'emote_until'=>0]);
            $p->x=$x;$p->y=$y;$p->seen_at=$now;$p->emote=null;
        } elseif($now-$p->seen_at>1000) {
            DB::table('plaza_presence')->where('character_id',$c->id)->update(['seen_at'=>$now]);
        }
        return $p;
    }

    public function view(int $account,string $id,bool $full=false): array
    {
        $c=$this->hero($account,$id);
        $now=World::milliseconds();
        $me=$this->presence($c,$now);
        return $this->render($c,$me,$now,$full);
    }

    private function render(object $c,object $me,int $now,bool $full): array
    {
        $rows=DB::table('plaza_presence as p')->join('characters as c','c.id','=','p.character_id')
            ->where('p.seen_at','>',$now-Plaza::PRESENCE_MS)->whereNull('c.active_expedition')->where('p.character_id','!=',$c->id)
            ->orderBy('p.character_id')->limit(200)
            ->get(['c.id','c.name','c.class_id','c.origin','c.xp','p.x','p.y','p.facing','p.emote','p.emote_until']);
        // Recent global chat floats over the speaker's head for a few seconds.
        $ids=$rows->pluck('id')->push($c->id)->all();
        $bubbles=DB::table('chat_messages')->where('channel','global')->whereIn('character_id',$ids)
            ->where('created_at','>=',now()->subSeconds(8))->orderBy('id')->get(['character_id','body'])->pluck('body','character_id');
        $hero=fn($id,$name,$class,$origin,$xp,$x,$y,$facing,$emote,$until)=>['id'=>$id,'name'=>$name,'class_id'=>$class,'origin'=>$origin??'human',
            'level'=>Catalog::progression((int)$xp)['level'],'x'=>(int)$x,'y'=>(int)$y,'facing'=>$facing,
            'emote'=>$until>$now?$emote:null,'bubble'=>isset($bubbles[$id])?mb_substr($bubbles[$id],0,60):null];
        $players=$rows->map(fn($r)=>$hero($r->id,$r->name,$r->class_id,$r->origin,$r->xp,$r->x,$r->y,$r->facing,$r->emote,$r->emote_until))->values()->all();
        $npcs=Plaza::keepers();
        $view=['v'=>1,'type'=>'plaza','now'=>(string)$now,'step_ms'=>Plaza::STEP_MS,'online'=>count($players)+1,
            'self'=>$hero($c->id,$c->name,$c->class_id,$c->origin,$c->xp,$me->x,$me->y,$me->facing,$me->emote,$me->emote_until??0),
            'players'=>$players,'npcs'=>array_merge($npcs,Plaza::walkersAt($now))];
        if($full) $view+=['width'=>Plaza::WIDTH,'height'=>Plaza::HEIGHT,'map'=>Plaza::map(),'buildings'=>Plaza::buildings(),'emotes'=>Plaza::EMOTES];
        return $view;
    }

    public function act(int $account,string $id,array $d): array
    {
        $c=$this->hero($account,$id);
        $now=World::milliseconds();
        $talk=null;$result='ok';
        DB::transaction(function()use($c,$d,$now,&$talk,&$result){
            $me=DB::table('plaza_presence')->where('character_id',$c->id)->lockForUpdate()->first()??$this->presence($c,$now);
            if($d['action']==='move'){
                [$dx,$dy]=self::DIRS[$d['direction']];
                $x=$me->x+$dx;$y=$me->y+$dy;
                if($now-$me->moved_at<self::MIN_STEP_MS) $result='cooldown';
                elseif(!Plaza::walkable($x,$y)) {$result='blocked';DB::table('plaza_presence')->where('character_id',$c->id)->update(['facing'=>$d['direction'],'seen_at'=>$now]);}
                else DB::table('plaza_presence')->where('character_id',$c->id)->update(['x'=>$x,'y'=>$y,'facing'=>$d['direction'],'moved_at'=>$now,'seen_at'=>$now,'emote'=>null,'emote_until'=>0]);
            } elseif($d['action']==='emote') {
                abort_unless(isset(Plaza::EMOTES[$d['target']??'']),422,'invalid_emote');
                DB::table('plaza_presence')->where('character_id',$c->id)->update(['emote'=>$d['target'],'emote_until'=>$now+5000,'seen_at'=>$now]);
            } else {
                $talk=$this->talk($c,$me,$d['target']??'',$now);
            }
        },3);
        $me=DB::table('plaza_presence')->where('character_id',$c->id)->first();
        return $this->render($c,$me,$now,false)+['result'=>$result]+($talk?['talk'=>$talk]:[]);
    }

    /** A keeper answers and names the service it opens; walkers only greet. */
    private function talk(object $c,object $me,string $target,int $now): array
    {
        $npc=Plaza::npc($target);
        if($npc){
            abort_unless(abs($npc['x']-$me->x)+abs($npc['y']-$me->y)<=2,409,'too_far');
            $line=$npc['service']==='dummy'?$this->dummy($c):Plaza::line($npc,$now,$c->id);
            return ['npc'=>$npc['id'],'name'=>$npc['name'],'service'=>$npc['service'],'line'=>$line];
        }
        foreach(Plaza::walkersAt($now) as $w){
            if($w['id']===$target){
                abort_unless(abs($w['x']-$me->x)+abs($w['y']-$me->y)<=2,409,'too_far');
                return ['npc'=>$w['id'],'name'=>$w['name'],'service'=>'','line'=>Plaza::walkerLine($w['kind'],intdiv($now,7000))];
            }
        }
        abort(404,'npc_not_found');
    }

    /** The training dummies tell a hero what the current gear really does. */
    public function dummy(object $c): string
    {
        $p=(new Characters)->profile($c);
        $hit=13+$p['damage_bonus'];
        $backstab=Game::has($p,'backstab');
        $crit=min(100,$p['crit']+($backstab?10:0));
        $power=Game::has($p,'arcane_surge')?intdiv($p['power']*3,2):$p['power'];
        $text='Удар: ~'.$hit.' урона, крит '.$crit.'% (×'.($backstab?3:2).'). Огненная стрела: '.(20+$power).'.';
        if(Game::has($p,'ranged')) $text.=' Бьёт на 5 клеток.';
        if($p['thorns']>0) $text.=' Шипы: '.$p['thorns'].'.';
        if($p['regen']>0) $text.=' Восстановление: '.$p['regen'].' HP/с.';
        if($p['leech']>0) $text.=' Вампиризм: '.$p['leech'].'%.';
        return $text.' Броня '.$p['armor'].', HP '.$p['max_hp'].'.';
    }
}
