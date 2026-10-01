<?php
namespace Tests\Unit;
use DomainException;
use GrimHollow\Core\{Catalog,Game,Plaza,Random};
use PHPUnit\Framework\TestCase;

/** 0.6 content multiplies: every list combines with every other one. */
final class CombinationsTest extends TestCase
{
    private function arena(array $profile,array $players=[]): array
    {
        $s=Game::create(31,['hero'=>$profile]+$players);
        $s['map']=array_fill(0,32,str_repeat('.',32));$s['enemies']=[];$s['objects']=[];
        return $s;
    }
    private function foe(string $id,int $x,int $y,array $extra=[]): array
    {
        return $extra+['id'=>$id,'type'=>'scavenger','x'=>$x,'y'=>$y,'hp'=>200,'max_hp'=>200,'ready_at'=>999];
    }

    public function test_the_content_space_grows_multiplicatively(): void
    {
        $builds=count(Catalog::classes())*count(Catalog::classes())*count(Catalog::origins());
        self::assertGreaterThanOrEqual(400,$builds,'class × (mentor or none) × origin');
        $gear=count(Catalog::bases())*(count(Catalog::materials())+1)*(count(Catalog::affixes())+1)*(count(Catalog::runes())+1);
        self::assertGreaterThan(40000,$gear,'gear variants per tier');
        $species=count(array_filter(Catalog::enemies(),fn($e)=>!($e['boss']??false)));
        self::assertGreaterThan(1000,$species*($species-1),'ordered chimera pairs');
        self::assertCount(count(Catalog::reagents())*(count(Catalog::reagents())+1)/2,Catalog::elixirs());
    }

    public function test_gear_keys_combine_material_enchantment_and_rune(): void
    {
        $key=Catalog::itemKey('greatsword',10,'obsidian','vampiric','kenaz');
        self::assertSame('greatsword+10@obsidian~vampiric!kenaz',$key);
        $item=Catalog::item($key);
        self::assertSame(['greatsword','obsidian','vampiric','kenaz',10],[$item['base'],$item['material'],$item['affix'],$item['rune'],$item['tier']]);
        self::assertSame('Обсидиан: Двуручник крови +10 ‹Кеназ›',$item['name']);
        $plain=Catalog::item('greatsword+10');
        // Obsidian ×1.35 damage, rune +1 damage per tier, and vampiric leech 5 + 10/5.
        self::assertSame((int)round(7*5.5*1.35+10),$item['damage']);self::assertSame(7,$item['leech']);
        self::assertGreaterThan($plain['price'],$item['price']);
        self::assertSame('blade+7~fierce',Catalog::itemKey('blade',7,'','fierce'),'0.5 keys keep their meaning');
        foreach (['blade+2@nope','blade+2!nope','blade+2~fierce@steel'] as $bad) {
            try { Catalog::item($bad);self::fail($bad); } catch (DomainException $e) { self::assertSame('unknown_item',$e->getMessage()); }
        }
        for ($i=1;$i<=200;$i++) Catalog::item(Catalog::generate(new Random($i),1+$i%30,$i%2===0,'assassin'));
    }

    public function test_material_sets_reward_matching_pieces(): void
    {
        $three=['weapon'=>'blade+3@steel','head'=>'helm+3@steel','feet'=>'boots+3@steel'];
        $five=$three+['hands'=>'gauntlets+3@steel','body'=>'plate+3@steel'];
        self::assertSame(['steel'=>3],Catalog::sets($three));
        $a=Catalog::profile('A','guardian',0,[],$three);$b=Catalog::profile('B','guardian',0,[],array_slice($three,0,2,true));
        $gear=fn($eq)=>array_sum(array_map(fn($k)=>Catalog::item($k)['damage'],$eq));
        self::assertSame(3,$a['damage_bonus']-$gear($three),'three steel pieces add +3 damage');
        self::assertSame(0,$b['damage_bonus']-$gear(array_slice($three,0,2,true)));
        self::assertSame(6,Catalog::profile('C','guardian',0,[],$five)['damage_bonus']-$gear($five),'five pieces double the bonus');
        self::assertSame(['Сталь (3)'],$a['sets']);
    }

    public function test_class_origin_and_mentor_stack(): void
    {
        $base=Catalog::profile('A','berserker',0,[],[],['origin'=>'orc']);
        self::assertSame(['rage'],$base['traits']);self::assertSame(['war','blood'],$base['schools']);
        self::assertSame(100+20+10,$base['max_hp']);self::assertSame(1+3,$base['damage_bonus']);
        $young=Catalog::profile('A','berserker',0,[],[],['origin'=>'undead','mentor'=>'assassin']);
        self::assertNull($young['mentor'],'a mentor needs level 10');
        $xp=20*9*10;
        $veteran=Catalog::profile('A','berserker',$xp,[],[],['origin'=>'undead','mentor'=>'assassin']);
        self::assertSame(['rage','backstab','unliving'],$veteran['traits']);
        self::assertSame(['war','blood','shadow','storm'],$veteran['schools']);
        self::assertSame(0,$veteran['crit'],'the mentor lends traits and schools, not the class stat bonus');
        self::assertSame(4,$veteran['leech']);
        self::assertSame(9,Catalog::spellCost(Catalog::spells()['chain'],$veteran['schools'])-12,'storm via the assassin mentor: 28 → 21');
    }

    public function test_traits_change_combat(): void
    {
        // Rage: a wounded berserker hits half again as hard.
        $s=$this->arena(Catalog::profile('B','berserker',0,[],[]));$s['enemies']=['a'=>$this->foe('a',6,5)];
        $calm=Game::command($s,'hero',['action'=>'attack','target_id'=>'a']);
        $s['players']['hero']['hp']=10;$angry=Game::command($s,'hero',['action'=>'attack','target_id'=>'a']);
        self::assertGreaterThan(200-$calm['enemies']['a']['hp'],200-$angry['enemies']['a']['hp']);
        // Venom touch: druid blows poison.
        $s=$this->arena(Catalog::profile('D','druid',0,[],[]));$s['enemies']=['a'=>$this->foe('a',6,5)];
        $s=Game::command($s,'hero',['action'=>'attack','target_id'=>'a']);
        self::assertSame(40,$s['enemies']['a']['poison_until']);self::assertSame('hero',$s['enemies']['a']['poisoner']);
        // Soul harvest: a kill restores mana.
        $s=$this->arena(Catalog::profile('N','necromancer',0,[],[]));$s['players']['hero']['mana']=0;
        $s['enemies']=['a'=>$this->foe('a',6,5,['hp'=>1])];
        $s=Game::command($s,'hero',['action'=>'attack','target_id'=>'a']);
        self::assertSame(8,$s['players']['hero']['mana']);
        // Unliving: no poison sticks to an undead hero.
        $s=$this->arena(Catalog::profile('U','guardian',0,[],[],['origin'=>'undead']));
        $s['enemies']=['a'=>['id'=>'a','type'=>'spitter','x'=>6,'y'=>5,'hp'=>50,'max_hp'=>50,'ready_at'=>0]];
        $s=Game::tick($s);self::assertArrayNotHasKey('poison_until',$s['players']['hero']);
        // Bulwark: a guardian behind a shield takes a quarter, others half.
        $guardian=$this->arena(Catalog::profile('G','guardian',0,[],[]));$ranger=$this->arena(Catalog::profile('R','ranger',0,[],[]));
        foreach ([&$guardian,&$ranger] as &$t) {
            $t['players']['hero']['shield_until']=99;
            $t['enemies']=['x'=>['id'=>'x','type'=>'brute','x'=>6,'y'=>5,'hp'=>50,'max_hp'=>50,'ready_at'=>0,'damage'=>40,'level'=>1]];
            $t=Game::tick($t);
        }
        unset($t);
        // 40 → 35 after the guardian's class armour, then a quarter of it behind the shield.
        self::assertSame(8,100-$guardian['players']['hero']['hp']);self::assertSame(20,100-$ranger['players']['hero']['hp']);
    }

    public function test_thorns_regen_and_greed(): void
    {
        $s=$this->arena(Catalog::profile('T','guardian',0,[],[],['origin'=>'drakeborn']));
        $s['enemies']=['x'=>['id'=>'x','type'=>'brute','x'=>6,'y'=>5,'hp'=>50,'max_hp'=>50,'ready_at'=>0]];
        $s=Game::tick($s);self::assertSame(47,$s['enemies']['x']['hp'],'drakeborn thorns answer the blow');
        $s=$this->arena(Catalog::profile('F','guardian',0,[],['weapon'=>'totem+1']));$s['players']['hero']['hp']=50;
        for ($i=0;$i<10;$i++) $s=Game::tick($s);
        self::assertSame(51,$s['players']['hero']['hp']);
        $s=$this->arena(Catalog::profile('H','guardian',0,[],[],['origin'=>'human']));$s['enemies']=['a'=>['id'=>'a','type'=>'brute','x'=>6,'y'=>5,'hp'=>1,'ready_at'=>0]];
        $s=Game::command($s,'hero',['action'=>'attack','target_id'=>'a']);self::assertSame(5,$s['players']['hero']['gold'],'5 × 1.05 rounds to 5');
    }

    public function test_new_spells_rage_roots_and_sanctuary(): void
    {
        $s=$this->arena(Catalog::profile('W','warden',20*9*10,[],[]),['ally'=>'Ally','far'=>'Far']);
        $s['players']['hero']['mana']=500;
        $s['enemies']=['a'=>$this->foe('a',7,5,['ready_at'=>0]),'b'=>$this->foe('b',5,7,['ready_at'=>0]),'c'=>$this->foe('c',12,5,['ready_at'=>0])];
        $roots=Game::command($s,'hero',['action'=>'cast','spell_id'=>'entangle']);
        self::assertSame([25,25,0],array_column($roots['enemies'],'ready_at'));
        self::assertSame([189,189,200],array_column($roots['enemies'],'hp'),'8 + the warden power of 3');
        $rage=Game::command($s,'hero',['action'=>'cast','spell_id'=>'blood_rage']);
        self::assertSame(100,$rage['players']['hero']['might_until']);
        $s['players']['ally']['hp']=10;$s['players']['far']['hp']=10;$s['players']['far']['x']=20;$s['players']['hero']['hp']=90;
        $healed=Game::command($s,'hero',['action'=>'cast','spell_id'=>'sanctuary']);
        // Holy is the warden's school: 40 mana becomes 30.
        self::assertSame(470,$healed['players']['hero']['mana']);
        // 25 + half of the warden's 3 power for everyone in reach, the caster included.
        self::assertSame([90+26,10+26],[$healed['players']['hero']['hp'],$healed['players']['ally']['hp']]);
        self::assertSame(10,$healed['players']['far']['hp']);
    }

    public function test_enemy_modifiers_split_evade_ward_and_chimeras(): void
    {
        $s=$this->arena(Catalog::profile('M','arcanist',0,[],[]));
        $s['enemies']=['a'=>$this->foe('a',6,5,['hp'=>1,'max_hp'=>60,'specials'=>['split']])];
        $s=Game::command($s,'hero',['action'=>'attack','target_id'=>'a']);
        $shards=array_filter($s['enemies'],fn($e)=>$e['shard']??false);
        self::assertCount(2,$shards);foreach ($shards as $e) self::assertSame(20,$e['max_hp']);
        $s=$this->arena(Catalog::profile('M','arcanist',0,[],[]));
        $s['enemies']=['a'=>$this->foe('a',7,5,['specials'=>['warded']])];
        $s=Game::command($s,'hero',['action'=>'cast','spell_id'=>'firebolt','target_id'=>'a']);
        self::assertSame(190,$s['enemies']['a']['hp'],'warded halves spells');
        $c=Catalog::chimera('skeleton','frost_wolf');
        self::assertSame(['Скелет-страж × ледяной волк',37,8,3,10,['chill']],[$c['name'],$c['hp'],$c['damage'],$c['step'],$c['attack'],$c['specials']]);
        // Deep parties meet chimeras, and a chimera counts for both species and drops both reagents.
        $deep=Game::create(9,['a'=>Catalog::profile('A','guardian',20*59*60,[],[])],'citadel');
        $fused=array_values(array_filter($deep['enemies'],fn($e)=>isset($e['fused'])));
        self::assertNotEmpty($fused);
        $e=$fused[0];$e['hp']=1;$e['x']=$deep['players']['a']['x']+1;$e['y']=$deep['players']['a']['y'];$e['specials']=[];$e['armor']=0;
        $deep['map']=array_fill(0,32,str_repeat('.',32));$deep['enemies']=[$e['id']=>$e];
        $deep=Game::command($deep,'a',['action'=>'attack','target_id'=>$e['id']]);
        self::assertSame(1,$deep['players']['a']['slain'][$e['type']]);self::assertSame(1,$deep['players']['a']['slain'][$e['fused']]);
    }

    public function test_omens_and_party_scaling(): void
    {
        $omened=0;
        for ($seed=1;$seed<=40;$seed++) {
            $s=Game::create($seed,['hero'=>'Hero']);
            $s['players']['hero']['x']=$s['exit'][0];$s['players']['hero']['y']=$s['exit'][1];
            $s=Game::command($s,'hero',['action'=>'descend']);
            if ($s['omens']) { $omened++;self::assertNotEmpty(Game::view($s,'hero')['omens']); }
        }
        self::assertGreaterThan(5,$omened);self::assertLessThan(35,$omened);
        $dark=Game::create(3,['hero'=>'Hero']);$dark['omens']=['darkness'];$dark['map']=array_fill(0,32,str_repeat('.',32));
        $row=Game::view($dark,'hero')['map'][5];self::assertSame(' ',$row[11]);self::assertSame('.',$row[10]);
        $solo=Game::create(8,['a'=>'A']);
        $party=Game::create(8,array_combine(range('a','h'),range('A','H')));
        self::assertCount(8,$party['players']);
        self::assertCount(8,array_unique(array_map(fn($p)=>$p['x'].','.$p['y'],$party['players'])));
        foreach ($party['players'] as $p) self::assertSame('.',$party['map'][$p['y']][$p['x']]);
        self::assertGreaterThan(count($solo['enemies']),count($party['enemies']));
        self::assertGreaterThan($solo['enemies']['e0']['max_hp']*1.5,$party['enemies']['e0']['max_hp']*($solo['enemies']['e0']['type']===$party['enemies']['e0']['type']?1:2));
        try { Game::create(1,array_fill_keys(range('a','i'),'X'));self::fail('nine is too many'); }
        catch (DomainException $e) { self::assertSame('party_full',$e->getMessage()); }
    }

    public function test_elixirs_add_aspects_and_reactions(): void
    {
        $steam=Catalog::elixir('frost_shard+ember_core');
        self::assertSame(['ember_core+frost_shard','Эликсир пара',true],[$steam['key'],$steam['name'],$steam['reaction']]);
        self::assertSame(['armor'=>3,'crit'=>6,'damage'=>2,'power'=>3],$steam['stats']);
        self::assertSame(['hp'=>60],Catalog::elixir('rat_tail+rat_tail')['stats']);
        $p=Catalog::profile('E','guardian',0,[],[],['elixir'=>'rat_tail+rat_tail']);
        self::assertSame([160,'Чистый настой: крысиный хвост'],[$p['max_hp'],$p['elixir']]);
        $this->expectExceptionMessage('invalid_elixir');Catalog::elixir('rat_tail+gold');
    }

    public function test_reagents_drop_and_every_species_has_one(): void
    {
        foreach (Catalog::enemies() as $type=>$e) self::assertArrayHasKey(Catalog::reagentOf($type),Catalog::reagents(),$type);
        $s=$this->arena(Catalog::profile('A','guardian',0,[],[]));
        $s['enemies']=['e'=>['id'=>'e','type'=>'yeti','x'=>6,'y'=>5,'hp'=>1,'max_hp'=>90,'ready_at'=>99,'elite'=>true]];
        $s=Game::command($s,'hero',['action'=>'attack','target_id'=>'e']);
        self::assertSame(['frost_shard'=>1],$s['players']['hero']['reagents']);
    }

    public function test_the_town_square_is_consistent(): void
    {
        $map=Plaza::map();
        self::assertCount(Plaza::HEIGHT,$map);foreach ($map as $row) self::assertSame(Plaza::WIDTH,strlen($row));
        self::assertTrue(Plaza::walkable(...Plaza::SPAWN));
        $ids=[];
        foreach (Plaza::npcs() as $npc) {
            self::assertContains($map[$npc['y']][$npc['x']],['.',',',':','*','+'],$npc['id']);
            self::assertFalse(Plaza::walkable($npc['x'],$npc['y']),'keepers block their cell');
            $ids[]=$npc['id'];
            if (!in_array($npc['service'],['omens','dummy'],true)) self::assertNotEmpty($npc['lines'],$npc['id']);
            self::assertNotSame('',Plaza::line($npc,123456,'hero'));
        }
        foreach (Plaza::buildings() as $b) {
            for ($y=$b['y'];$y<$b['y']+$b['h'];$y++) for ($x=$b['x'];$x<$b['x']+$b['w'];$x++) self::assertSame($b['letter'],$map[$y][$x],$b['name']);
        }
        foreach (Plaza::walkers() as $w) {
            foreach (Plaza::routeCells($w['route']) as [$x,$y]) self::assertContains($map[$y][$x],['.',',',':','*','+'],$w['id']." $x,$y");
            $ids[]=$w['id'];
        }
        self::assertSame(count($ids),count(array_unique($ids)));
        $a=Plaza::walkersAt(1000000);$b=Plaza::walkersAt(1000000+650);
        self::assertNotSame([$a[0]['x'],$a[0]['y']],[$b[0]['x'],$b[0]['y']],'the guard walks on');
        // Every walkable cell is reachable from the spawn point.
        $seen=[implode(',',Plaza::SPAWN)=>true];$queue=[Plaza::SPAWN];
        for ($i=0;$i<count($queue);$i++) foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dy]) {
            [$x,$y]=[$queue[$i][0]+$dx,$queue[$i][1]+$dy];
            if (!isset($seen["$x,$y"]) && Plaza::walkable($x,$y)) { $seen["$x,$y"]=true;$queue[]=[$x,$y]; }
        }
        for ($y=0;$y<Plaza::HEIGHT;$y++) for ($x=0;$x<Plaza::WIDTH;$x++) if (Plaza::walkable($x,$y)) self::assertArrayHasKey("$x,$y",$seen,"$x,$y");
        foreach (Plaza::npcs() as $npc) {
            $near=false;foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dy]) $near=$near||isset($seen[($npc['x']+$dx).','.($npc['y']+$dy)]);
            self::assertTrue($near,$npc['id'].' can be reached');
        }
    }
}
