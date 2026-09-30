<?php
namespace Tests\Unit;
use DomainException;
use GrimHollow\Core\{Catalog,Game};
use PHPUnit\Framework\TestCase;

final class MagicTest extends TestCase
{
    private function arena(int $xp=0): array
    {
        $s=Game::create(12,['hero'=>Catalog::profile('Mage','arcanist',$xp,[],['weapon'=>'oak_staff'])]);
        $s['map']=array_fill(0,32,str_repeat('.',32));
        $s['enemies']=['e0'=>['id'=>'e0','type'=>'scavenger','x'=>7,'y'=>5,'hp'=>25,'ready_at'=>0]];
        return $s;
    }
    public function test_spell_spends_mana_rewards_kill_once_and_recovers_mana(): void
    {
        $s=Game::command($this->arena(),'hero',['action'=>'cast','spell_id'=>'firebolt','target_id'=>'e0']);
        self::assertSame(88,$s['players']['hero']['mana']);self::assertSame(0,$s['enemies']['e0']['hp']);
        self::assertSame(10,$s['players']['hero']['xp']);
        for ($i=0;$i<20;$i++) $s=Game::tick($s);
        self::assertSame(94,$s['players']['hero']['mana']);
        $this->expectExceptionMessage('no_spell_target');
        Game::command($s,'hero',['action'=>'cast','spell_id'=>'firebolt','target_id'=>'e0']);
    }
    public function test_walls_range_mana_level_and_spell_cooldown_are_enforced(): void
    {
        foreach (['wall','range','mana','level','cooldown'] as $case) {
            $s=$this->arena();$spell='firebolt';
            if ($case==='wall') $s['map'][5][6]='#';
            if ($case==='range') $s['enemies']['e0']['x']=12;
            if ($case==='mana') $s['players']['hero']['mana']=0;
            if ($case==='level') $spell='frost';
            if ($case==='cooldown') $s['players']['hero']['spell_ready']['firebolt']=50;
            $expected=['wall'=>'no_spell_target','range'=>'no_spell_target','mana'=>'not_enough_mana','level'=>'spell_locked','cooldown'=>'spell_cooldown'][$case];
            try { Game::command($s,'hero',['action'=>'cast','spell_id'=>$spell,'target_id'=>'e0']);self::fail($case); }
            catch (DomainException $e) { self::assertSame($expected,$e->getMessage()); }
        }
    }
    public function test_healing_frost_and_nova_apply_distinct_effects(): void
    {
        $s=$this->arena(240);$s['players']['hero']['hp']=30;
        $heal=Game::command($s,'hero',['action'=>'cast','spell_id'=>'mend']);self::assertSame(70,$heal['players']['hero']['hp']);
        $frost=Game::command($s,'hero',['action'=>'cast','spell_id'=>'frost','target_id'=>'e0']);
        self::assertSame(5,$frost['enemies']['e0']['hp']);self::assertSame(20,$frost['enemies']['e0']['ready_at']);
        $s['enemies']['e1']=array_replace($s['enemies']['e0'],['id'=>'e1','x'=>5,'y'=>7]);
        $nova=Game::command($s,'hero',['action'=>'cast','spell_id'=>'nova']);
        self::assertSame(2,$nova['players']['hero']['kills']);self::assertCount(1,$nova['players']['hero']['loot']);
    }
    public function test_level_boundaries_and_legacy_lobby_profile(): void
    {
        self::assertSame(1,Catalog::progression(39)['level']);self::assertSame(2,Catalog::progression(40)['level']);
        self::assertSame(20,Catalog::progression(100000)['level']);self::assertNull(Catalog::progression(100000)['next_level_xp']);
        $s=Game::create(1,['old'=>['name'=>'Old','max_hp'=>100]]);self::assertSame(60,$s['players']['old']['mana']);
    }
}
