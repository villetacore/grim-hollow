<?php
namespace Tests\Unit;
use DomainException;
use GrimHollow\Core\{Catalog,Game,Random};
use PHPUnit\Framework\TestCase;

final class EndlessTest extends TestCase
{
    private function descend(array $s, int $floors): array
    {
        for ($i = 0; $i < $floors; $i++) {
            $s['players']['hero']['x']=$s['exit'][0];$s['players']['hero']['y']=$s['exit'][1];$s['players']['hero']['ready_at']=0;
            if (isset($s['enemies']['boss'])) $s['enemies']['boss']['hp']=0;
            $s=Game::command($s,'hero',['action'=>'descend']);
        }
        return $s;
    }

    public function test_depth_never_ends_and_bosses_return_every_cycle(): void
    {
        $s=$this->descend(Game::create(5,['hero'=>'Hero']),11);
        self::assertSame(12,$s['floor']);self::assertSame(12,Game::bossFloor($s));
        self::assertTrue($s['enemies']['boss']['boss']);
        self::assertNotSame('warden',$s['enemies']['boss']['type'],'later cycles bring other bosses');
        self::assertSame(12,Game::view($s,'hero')['depth_level']);
        $roster=array_unique(array_column($s['enemies'],'type'));
        self::assertNotEmpty(array_diff($roster,Catalog::biomes()['mines']['roster'],['warden','prior','rift_heart','bone_king','frost_queen','ash_tyrant']),'deep floors mix rosters');
        self::assertGreaterThan(Game::create(5,['hero'=>'Hero'])['enemies'] ? count(Game::create(5,['hero'=>'Hero'])['enemies']) : 0,count($s['enemies']));
    }

    public function test_difficulty_follows_the_average_party_level(): void
    {
        $low=Game::create(9,['a'=>Catalog::profile('A','guardian',0,[],[]),'b'=>Catalog::profile('B','ranger',0,[],[])]);
        $high=Game::create(9,['a'=>Catalog::profile('A','guardian',20*29*30,[],[]),'b'=>Catalog::profile('B','ranger',20*9*10,[],[])]);
        self::assertSame(1,$low['party_level']);self::assertSame(20,$high['party_level']);
        $hp=fn($s)=>array_sum(array_column($s['enemies'],'max_hp'))/count($s['enemies']);
        self::assertGreaterThan(2*$hp($low),$hp($high));
        self::assertSame(20,$high['enemies']['e0']['level']);
        self::assertNotEmpty(array_filter($high['enemies'],fn($e)=>!empty($e['affixes'])),'strong parties meet modified monsters');
        self::assertEmpty(array_filter($low['enemies'],fn($e)=>array_diff($e['affixes']??[],['shaman'])),'only a healer-less coven gets a shaman');
    }

    public function test_generated_gear_scales_without_limit(): void
    {
        $a=Catalog::item('blade+1');$b=Catalog::item('blade+10~fierce');
        self::assertSame(['Клинок +1',4,1],[$a['name'],$a['damage'],$a['level']]);
        self::assertSame(['Клинок ярости +10',32,27,'blade'],[$b['name'],$b['damage'],$b['level'],$b['icon']]);
        self::assertSame(100,Catalog::item('plate+200')['level']);
        self::assertSame(7,Catalog::item('dagger+5~keen')['crit']-Catalog::item('dagger+5')['crit']+1);
        foreach (['nope+1','blade+x','blade+2~nope','blade'] as $bad) {
            try { Catalog::item($bad);self::fail($bad); } catch (DomainException $e) { self::assertSame('unknown_item',$e->getMessage()); }
        }
        $profile=Catalog::profile('H','ranger',0,[],['weapon'=>'dagger+3~vampiric','ring'=>'ring+2']);
        self::assertSame([6,5],[$profile['crit'],$profile['leech']]);
        $key=Catalog::generate(new Random(3),4,true,'arcanist');
        self::assertMatchesRegularExpression('/^[a-z]+\+4(@[a-z]+)?~[a-z]+$/',$key);Catalog::item($key);
        self::assertSame(['gold'=>128,'materials'=>6,'essence'=>2],Catalog::forgeCost(4,true));
    }

    public function test_skills_have_their_own_cooldowns_and_spells_aim_where_the_hero_faces(): void
    {
        $s=Game::create(12,['hero'=>Catalog::profile('Mage','arcanist',20*11*12,[],[])]);
        $s['map']=array_fill(0,32,str_repeat('.',32));$s['objects']=[];$s['players']['hero']['mana']=500;
        $foe=fn($id,$x,$y)=>['id'=>$id,'type'=>'scavenger','x'=>$x,'y'=>$y,'hp'=>200,'max_hp'=>200,'ready_at'=>999];
        $s['enemies']=['a'=>$foe('a',6,5),'b'=>$foe('b',5,8),'c'=>$foe('c',8,5)];
        $bashed=Game::command($s,'hero',['action'=>'bash','target_id'=>'a']);
        $bashed['players']['hero']['ready_at']=0;
        try { Game::command($bashed,'hero',['action'=>'bash','target_id'=>'a']);self::fail('bash cooldown'); }
        catch (DomainException $e) { self::assertSame('skill_cooldown',$e->getMessage()); }
        Game::command($bashed,'hero',['action'=>'attack','target_id'=>'a']);
        $wave=Game::command($s,'hero',['action'=>'cast','spell_id'=>'fire_wave','direction'=>'south']);
        self::assertSame([200,174,200],array_column($wave['enemies'],'hp'));
        $wave=Game::command($s,'hero',['action'=>'cast','spell_id'=>'fire_wave','direction'=>'east']);
        self::assertSame([174,200,174],array_column($wave['enemies'],'hp'));
        $s['enemies']=[];
        $blink=Game::command($s,'hero',['action'=>'cast','spell_id'=>'blink','direction'=>'north']);
        self::assertSame([5,2,'north'],[$blink['players']['hero']['x'],$blink['players']['hero']['y'],$blink['players']['hero']['facing']]);
    }

    public function test_armor_absorbs_a_share_of_every_blow(): void
    {
        $s=Game::create(3,['hero'=>Catalog::profile('Tank','guardian',0,[],['body'=>'plate+5'])]);
        $s['map']=array_fill(0,32,str_repeat('.',32));$s['objects']=[];
        $s['enemies']=['x'=>['id'=>'x','type'=>'brute','x'=>6,'y'=>5,'hp'=>50,'max_hp'=>50,'ready_at'=>0,'damage'=>30,'level'=>1]];
        $hp=$s['players']['hero']['hp'];$s=Game::tick($s);
        self::assertSame(11,$hp-$s['players']['hero']['hp']);
    }

    public function test_duel_ends_with_one_victor_and_no_dungeon_actions(): void
    {
        $s=Game::create(4,['a'=>Catalog::profile('Ann','guardian',0,[],[]),'b'=>Catalog::profile('Bob','ranger',0,[],[])],'mines','duel');
        self::assertSame('duel',$s['mode']);self::assertSame([],$s['enemies']);
        self::assertSame(30,$s['players']['a']['ready_at']);
        self::assertCount(2,Game::view($s,'a')['players'],'duelists always see each other');
        $s['tick']=30;$s['players']['b']['x']=7;$s['players']['b']['y']=$s['players']['a']['y'];
        foreach (['interact','extract'] as $action) {
            try { Game::command($s,'a',['action'=>$action]);self::fail($action); } catch (DomainException $e) { self::assertSame('not_in_duel',$e->getMessage()); }
        }
        $s=Game::command($s,'b',['action'=>'attack','target_id'=>'a']);
        self::assertLessThan(100,$s['players']['a']['hp']);
        $s['players']['a']['hp']=1;$s['players']['b']['ready_at']=0;
        $s=Game::command($s,'b',['action'=>'attack','target_id'=>'a']);
        self::assertSame(['defeated','victory','completed'],[$s['players']['a']['outcome'],$s['players']['b']['outcome'],$s['status']]);
        $draw=Game::create(4,['a'=>'Ann','b'=>'Bob'],'mines','duel');$draw['tick']=Game::DUEL_TICKS-1;$draw=Game::tick($draw);
        self::assertSame(['draw','draw'],array_column($draw['players'],'outcome'));
    }
}
