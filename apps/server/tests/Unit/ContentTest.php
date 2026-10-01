<?php
namespace Tests\Unit;
use DomainException;
use GrimHollow\Core\{Catalog,Command,Game};
use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase
{
    private function arena(string $biome='mines',array $profile=[]): array
    {
        $s=Game::create(31,['hero'=>Catalog::profile('Hero','guardian',0,[],[])+$profile],$biome);
        $s['map']=array_fill(0,32,str_repeat('.',32));$s['enemies']=[];$s['objects']=[];
        return $s;
    }

    public function test_catalog_references_are_consistent(): void
    {
        $items=Catalog::items();$enemies=Catalog::enemies();$chapters=[];
        foreach (Catalog::biomes() as $key=>$b) {
            $chapters[]=$b['chapter'];
            self::assertGreaterThanOrEqual(3,$b['floors'],$key);
            self::assertTrue($enemies[$b['boss_type']]['boss']??false,$key);
            self::assertArrayHasKey($b['unique'],$items,$key);
            foreach ($b['roster'] as $type) self::assertArrayHasKey($type,$enemies,$key);
            foreach (array_merge(...$b['loot']) as $item) self::assertArrayHasKey($item,$items,$key);
        }
        self::assertSame(range(0,count($chapters)-1),$chapters);
        foreach (Catalog::recipes() as $key=>$r) self::assertArrayHasKey($key,$items);
        foreach ($enemies as $key=>$e) if (in_array('summon',$e['specials']??[],true)) self::assertArrayHasKey($e['summon'],$enemies,$key);
        foreach (Catalog::talents() as $t) {
            self::assertContains($t['stat'],['armor','damage_bonus','max_mana','power','max_hp','crit','leech','thorns','regen']);
            self::assertArrayHasKey($t['class'],Catalog::classes());
        }
        foreach ($items as $item) self::assertContains($item['slot'],Catalog::SLOTS);
        foreach (Catalog::bases() as $base) self::assertContains($base['slot'],Catalog::SLOTS);
        foreach (Catalog::classes() as $key=>$class) {
            self::assertArrayHasKey($class['weapon'],$items,$key);
            foreach ($class['bases'] as $base) self::assertArrayHasKey($base,Catalog::bases(),$key);
            foreach ($class['traits'] as $trait) self::assertArrayHasKey($trait,Catalog::traits(),$key);
            foreach ($class['schools'] as $school) self::assertArrayHasKey($school,Catalog::schools(),$key);
        }
        foreach (Catalog::spells() as $key=>$spell) self::assertArrayHasKey($spell['school'],Catalog::schools(),$key);
        foreach (Catalog::BOSSES as $boss) self::assertTrue($enemies[$boss]['boss']??false,$boss);
        foreach ($enemies as $key=>$e) foreach ($e['specials']??[] as $special) self::assertArrayHasKey($special,Catalog::specials(),$key);
        foreach (Catalog::enemyAffixes() as $a) foreach ($a['specials']??[] as $special) self::assertArrayHasKey($special,Catalog::specials());
        foreach (Catalog::materials() as $m) foreach ($m['cost'] as $reagent=>$n) self::assertArrayHasKey($reagent,Catalog::reagents());
        foreach (Catalog::runes() as $r) self::assertArrayHasKey($r['cost'],Catalog::reagents());
        self::assertCount(16,Catalog::spells());
    }

    public function test_every_area_generates_and_puts_its_boss_on_the_last_floor(): void
    {
        foreach (Catalog::biomes() as $key=>$b) {
            $s=Game::create(77,['hero'=>'Hero'],$key);
            self::assertArrayNotHasKey('boss',$s['enemies']);
            self::assertNotEmpty(array_filter($s['objects'],fn($o)=>$o['type']==='chest'));
            for ($floor=2;$floor<=$b['floors'];$floor++) {
                $s['players']['hero']['x']=$s['exit'][0];$s['players']['hero']['y']=$s['exit'][1];$s['players']['hero']['ready_at']=0;
                if (isset($s['enemies']['boss'])) $s['enemies']['boss']['hp']=0;
                $s=Game::command($s,'hero',['action'=>'descend']);
            }
            self::assertSame($b['boss_type'],$s['enemies']['boss']['type'],$key);
            $s['players']['hero']['x']=$s['exit'][0];$s['players']['hero']['y']=$s['exit'][1];$s['players']['hero']['ready_at']=0;
            try { Game::command($s,'hero',['action'=>'descend']);self::fail('boss must guard the last floor'); }
            catch (DomainException $e) { self::assertSame('boss_alive',$e->getMessage()); }
        }
    }

    public function test_early_commands_are_buffered_and_run_when_ready(): void
    {
        $s=Game::command($this->arena(),'hero',['action'=>'move','direction'=>'east']);
        [$s,$status]=Game::submit($s,'hero',['action'=>'move','direction'=>'east']);
        self::assertSame('queued',$status);self::assertSame(6,$s['players']['hero']['x']);
        $s=Game::tick($s);self::assertSame(6,$s['players']['hero']['x']);
        $s=Game::tick($s);self::assertSame(7,$s['players']['hero']['x']);self::assertArrayNotHasKey('queued',$s['players']['hero']);
        // Far outside the window the command is still a speed-hack rejection.
        $s['players']['hero']['ready_at']=$s['tick']+20;
        $this->expectExceptionMessage('cooldown');
        Game::submit($s,'hero',['action'=>'guard']);
    }

    public function test_chest_fountain_shrine_and_trap(): void
    {
        $s=$this->arena();$s['players']['hero']['hp']=40;$s['players']['hero']['mana']=0;
        $s['objects']=['c'=>['id'=>'c','type'=>'chest','x'=>6,'y'=>5,'used'=>false],'f'=>['id'=>'f','type'=>'fountain','x'=>5,'y'=>6,'used'=>false],
            's'=>['id'=>'s','type'=>'shrine','x'=>4,'y'=>5,'used'=>false],'t'=>['id'=>'t','type'=>'trap','x'=>5,'y'=>4,'used'=>false]];
        $s=Game::command($s,'hero',['action'=>'interact']);
        self::assertTrue($s['objects']['c']['used']);self::assertGreaterThan(0,$s['players']['hero']['gold']);self::assertSame(4,$s['players']['hero']['potions']);
        $s['players']['hero']['ready_at']=0;$s=Game::command($s,'hero',['action'=>'interact']);
        self::assertSame(90,$s['players']['hero']['hp']);self::assertSame(60,$s['players']['hero']['mana']);
        $s['players']['hero']['ready_at']=0;$s=Game::command($s,'hero',['action'=>'interact']);
        self::assertSame(300,$s['players']['hero']['might_until']);
        $s['players']['hero']['ready_at']=0;
        try { Game::command($s,'hero',['action'=>'interact']);self::fail('traps are not interactive'); }
        catch (DomainException $e) { self::assertSame('no_object',$e->getMessage()); }
        $s=Game::command($s,'hero',['action'=>'move','direction'=>'north']);
        self::assertSame(81,$s['players']['hero']['hp']);self::assertTrue($s['objects']['t']['used']);
        Command::validate(['action'=>'interact']);
    }

    public function test_enemy_specials_poison_chill_drain_heal_and_explode(): void
    {
        $s=$this->arena();
        $s['enemies']=['a'=>['id'=>'a','type'=>'spitter','x'=>6,'y'=>5,'hp'=>50,'max_hp'=>50,'ready_at'=>0]];
        $s=Game::tick($s);self::assertSame(96,$s['players']['hero']['hp']);self::assertGreaterThan(1,$s['players']['hero']['poison_until']);
        $s['players']['hero']['hp']=2;for($i=0;$i<40;$i++){$s['enemies']['a']['ready_at']=99999;$s=Game::tick($s);}
        self::assertSame(1,$s['players']['hero']['hp'],'poison never finishes a hero');

        $s=$this->arena();$s['enemies']=['w'=>['id'=>'w','type'=>'frost_wolf','x'=>6,'y'=>5,'hp'=>50,'max_hp'=>50,'ready_at'=>0]];
        $s=Game::tick($s);self::assertSame(7,$s['players']['hero']['ready_at']);
        $s=$this->arena();$s['enemies']=['g'=>['id'=>'g','type'=>'bell_wraith','x'=>6,'y'=>5,'hp'=>50,'max_hp'=>50,'ready_at'=>0]];
        $s=Game::tick($s);self::assertSame(50,$s['players']['hero']['mana']);

        $s=$this->arena();$s['enemies']=['h'=>['id'=>'h','type'=>'acolyte','x'=>9,'y'=>5,'hp'=>30,'max_hp'=>30,'ready_at'=>0],
            'x'=>['id'=>'x','type'=>'brute','x'=>9,'y'=>7,'hp'=>5,'max_hp'=>40,'ready_at'=>99]];
        $s=Game::tick($s);self::assertSame(16,$s['enemies']['x']['hp']);

        $s=$this->arena();$s['enemies']=['b'=>['id'=>'b','type'=>'spore_bloater','x'=>6,'y'=>5,'hp'=>1,'max_hp'=>30,'ready_at'=>99]];
        $s=Game::command($s,'hero',['action'=>'attack','target_id'=>'b']);
        self::assertSame(90,$s['players']['hero']['hp']);
    }

    public function test_armor_resists_blows_but_not_spells(): void
    {
        $s=Game::create(12,['hero'=>Catalog::profile('Mage','arcanist',0,[],[])]);
        $s['map']=array_fill(0,32,str_repeat('.',32));
        $s['enemies']=['k'=>['id'=>'k','type'=>'crypt_knight','x'=>7,'y'=>5,'hp'=>100,'max_hp'=>100,'ready_at'=>99]];
        $s=Game::command($s,'hero',['action'=>'cast','spell_id'=>'firebolt','target_id'=>'k']);
        self::assertSame(80,$s['enemies']['k']['hp']);
        $s['enemies']['k']['x']=6;$s['players']['hero']['ready_at']=0;$s=Game::command($s,'hero',['action'=>'attack','target_id'=>'k']);
        self::assertGreaterThanOrEqual(80-12,$s['enemies']['k']['hp']);
    }

    public function test_new_spells_chain_venom_barrier_meteor(): void
    {
        $s=Game::create(12,['hero'=>Catalog::profile('Mage','arcanist',6000,[],[]),'ally'=>'Ally']);
        $s['map']=array_fill(0,32,str_repeat('.',32));$s['players']['hero']['mana']=500;
        $foe=fn($id,$x,$y)=>['id'=>$id,'type'=>'scavenger','x'=>$x,'y'=>$y,'hp'=>200,'max_hp'=>200,'ready_at'=>999];
        $s['enemies']=['a'=>$foe('a',8,5),'b'=>$foe('b',9,5),'c'=>$foe('c',5,8),'d'=>$foe('d',11,5)];
        $chain=Game::command($s,'hero',['action'=>'cast','spell_id'=>'chain']);
        self::assertSame([176,176,176,200],array_column($chain['enemies'],'hp'));
        $venom=Game::command($s,'hero',['action'=>'cast','spell_id'=>'venom','target_id'=>'a']);
        self::assertSame(190,$venom['enemies']['a']['hp']);
        for($i=0;$i<10;$i++) $venom=Game::tick($venom);
        self::assertSame(185,$venom['enemies']['a']['hp']);
        $meteor=Game::command($s,'hero',['action'=>'cast','spell_id'=>'meteor','target_id'=>'a']);
        self::assertSame([155,178,200,200],array_column($meteor['enemies'],'hp'));
        $barrier=Game::command($s,'hero',['action'=>'cast','spell_id'=>'barrier']);
        self::assertSame(40,$barrier['players']['ally']['shield_until']);
    }

    public function test_boss_summons_and_slams_and_elites_pay_essence(): void
    {
        $s=$this->arena('catacombs');
        $s['enemies']=['boss'=>['id'=>'boss','type'=>'bone_king','x'=>7,'y'=>5,'hp'=>300,'max_hp'=>300,'ready_at'=>0,'boss'=>true,'slam_at'=>50,'summon_at'=>0]];
        $s=Game::tick($s);
        self::assertCount(1,array_filter($s['enemies'],fn($e)=>$e['summoned']??false));
        $s['enemies']['boss']['ready_at']=0;$s['enemies']['boss']['summon_at']=999;$s['enemies']['boss']['slam_at']=0;$hp=$s['players']['hero']['hp'];
        $s=Game::tick($s);self::assertLessThan($hp,$s['players']['hero']['hp']);self::assertSame(62,$s['enemies']['boss']['slam_at']);

        $s=$this->arena('glacier');
        $s['enemies']=['e'=>['id'=>'e','type'=>'yeti','x'=>6,'y'=>5,'hp'=>1,'max_hp'=>90,'ready_at'=>99,'elite'=>true]];
        $s=Game::command($s,'hero',['action'=>'attack','target_id'=>'e']);
        self::assertSame(1,$s['players']['hero']['essence']);self::assertCount(1,$s['players']['hero']['loot']);
        self::assertSame(['yeti'=>1],$s['players']['hero']['slain']);self::assertSame(50,$s['players']['hero']['gold']);
    }
}
