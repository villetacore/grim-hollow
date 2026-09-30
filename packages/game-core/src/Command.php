<?php
declare(strict_types=1);
namespace GrimHollow\Core;
use DomainException;
final class Command {
    /** One contract for both HTTP and WebSocket, before idempotency hashing. */
    public static function validate(array $p): void {
        $action=$p['action']??null;
        if(!is_string($action)||!in_array($action,['move','attack','bash','cast','guard','potion','extract','descend','revive','interact'],true)) throw new DomainException('invalid_action');
        foreach(['direction','target_id','spell_id'] as $key) if(isset($p[$key])&&(!is_string($p[$key])||strlen($p[$key])>32)) throw new DomainException('invalid_payload');
        if($action==='move'&&!in_array($p['direction']??null,['north','south','east','west'],true)) throw new DomainException('invalid_direction');
        if($action==='cast'&&!isset(Catalog::spells()[$p['spell_id']??''])) throw new DomainException('invalid_spell');
        if(array_diff(array_keys($p),['action','direction','target_id','spell_id'])) throw new DomainException('unexpected_field');
    }
}
