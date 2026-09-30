<?php
namespace Tests\Unit;
use GrimHollow\Core\{Game,Catalog};
use PHPUnit\Framework\TestCase;
final class CoopTest extends TestCase {
    private function arena():array {
        $s=Game::create(5,['healer'=>Catalog::profile('Healer','warden',0,[],[]),'ally'=>'Ally']);
        $s['map']=array_fill(0,32,str_repeat('.',32));$s['enemies']=[];return $s;
    }
    public function test_revive_takes_three_seconds_and_damage_interrupts():void {
        $s=$this->arena();$s['players']['ally']['hp']=0;$s['players']['ally']['downed_until']=200;
        $s=Game::command($s,'healer',['action'=>'revive','target_id'=>'ally']);
        $interrupted=$s;$interrupted['enemies']['e0']=['id'=>'e0','type'=>'scavenger','hp'=>30,'x'=>4,'y'=>5,'ready_at'=>0];
        $interrupted=Game::tick($interrupted);self::assertArrayNotHasKey('reviving',$interrupted['players']['healer']);
        for($i=0;$i<29;$i++)$s=Game::tick($s);self::assertSame(0,$s['players']['ally']['hp']);
        $s=Game::tick($s);self::assertSame(33,$s['players']['ally']['hp']);
    }
    public function test_shrine_is_consumed_once_on_safe_transition():void {
        $s=$this->arena();$s['players']['ally']['hp']=0;$s['players']['ally']['downed_until']=1;
        $s['players']['healer']['x']=$s['exit'][0];$s['players']['healer']['y']=$s['exit'][1];
        $s=Game::command($s,'healer',['action'=>'descend']);
        self::assertSame(0,$s['shrine_charges']);self::assertSame(50,$s['players']['ally']['hp']);self::assertSame(2,$s['floor']);
    }
    public function test_warden_heals_ally_and_ranger_can_attack_at_range():void {
        $s=$this->arena();$s['players']['ally']['hp']=20;
        $s=Game::command($s,'healer',['action'=>'cast','spell_id'=>'mend','target_id'=>'ally']);
        self::assertSame(58,$s['players']['ally']['hp']);self::assertSame(100,$s['players']['healer']['hp']);
        $s['players']['ally']['class_id']='ranger';$s['enemies']['e0']=['id'=>'e0','type'=>'scavenger','hp'=>30,'x'=>10,'y'=>5,'ready_at'=>0];
        $s=Game::command($s,'ally',['action'=>'attack','target_id'=>'e0']);self::assertLessThan(30,$s['enemies']['e0']['hp']);
    }
}
