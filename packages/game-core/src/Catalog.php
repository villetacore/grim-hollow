<?php
declare(strict_types=1);
namespace GrimHollow\Core;

use DomainException;

/** Shared, versioned game rules; clients display these values, never decide them. */
final class Catalog
{
    public const MAX_LEVEL = 100;
    public const TALENT_RANKS = 10;
    public const BOSSES = ['warden', 'prior', 'rift_heart', 'bone_king', 'frost_queen', 'ash_tyrant'];
    /** Icon shape of each named item; generated gear uses its base key. */
    private const ICONS = ['iron_sword'=>'blade','oak_staff'=>'staff','hunting_bow'=>'bow','healer_mace'=>'mace','leather'=>'jerkin',
        'buckler'=>'shield','ember_ring'=>'ring','steel_sword'=>'blade','chainmail'=>'mail','ash_staff'=>'staff','warden_shield'=>'shield',
        'bone_axe'=>'axe','monk_staff'=>'staff','recurve_bow'=>'bow','censer_mace'=>'mace','thorn_bow'=>'bow','rift_blade'=>'blade',
        'root_staff'=>'staff','grave_hammer'=>'hammer','soul_staff'=>'staff','frost_bow'=>'bow','glacier_edge'=>'blade','ember_scepter'=>'wand',
        'tyrant_blade'=>'blade','monk_robe'=>'robe','scale_armor'=>'mail','bark_mail'=>'jerkin','bone_plate'=>'plate','frost_coat'=>'robe',
        'ash_plate'=>'plate','bell_shield'=>'shield','spell_tome'=>'tome','bone_ward'=>'orb','glacier_aegis'=>'shield','silver_ring'=>'ring',
        'viper_ring'=>'ring','sage_ring'=>'ring','king_ring'=>'signet','ember_band'=>'signet','amber_amulet'=>'amulet','prior_icon'=>'talisman',
        'heart_seed'=>'amulet','wolf_fang'=>'talisman','frost_pendant'=>'amulet','phoenix_feather'=>'talisman'];

    /**
     * Areas unlock in order. The first three return the fires (main campaign); the deeper
     * three are post-campaign expeditions with more floors and harder foes.
     * loot: [first-floor pool, deeper-floor pool]; unique: the boss's guaranteed drop.
     */
    public static function biomes(): array {
        return [
            'mines'=>['name'=>'Затопленные шахты','chapter'=>0,'floors'=>3,'enemy_hp'=>0,'enemy_damage'=>0,'reward'=>1,
                'boss'=>'Смотритель глубин','boss_type'=>'warden','unique'=>'warden_shield',
                'roster'=>['scavenger','archer','spitter','brute','rat_swarm','ghoul_miner'],
                'loot'=>[['ember_ring','iron_sword','leather','amber_amulet'],['steel_sword','chainmail','ash_staff','bone_axe']],
                'story'=>'Верните первый огонь из шахт. Победите смотрителя на третьем этаже и эвакуируйтесь.'],
            'monastery'=>['name'=>'Монастырь без колокола','chapter'=>1,'floors'=>3,'enemy_hp'=>10,'enemy_damage'=>1,'reward'=>2,
                'boss'=>'Безмолвный приор','boss_type'=>'prior','unique'=>'prior_icon',
                'roster'=>['scavenger','archer','acolyte','bell_wraith','gargoyle','flagellant'],
                'loot'=>[['monk_staff','monk_robe','silver_ring','recurve_bow'],['bell_shield','censer_mace','silver_ring','monk_robe']],
                'story'=>'Первый огонь горит. Найдите второй в покинутом монастыре и победите приора.'],
            'roots'=>['name'=>'Корневая бездна','chapter'=>2,'floors'=>3,'enemy_hp'=>20,'enemy_damage'=>2,'reward'=>3,
                'boss'=>'Сердце Разлома','boss_type'=>'rift_heart','unique'=>'heart_seed',
                'roster'=>['root_crawler','thorn_archer','spore_bloater','rift_hound','brute','spitter'],
                'loot'=>[['thorn_bow','scale_armor','spell_tome','viper_ring'],['rift_blade','root_staff','bark_mail','viper_ring']],
                'story'=>'Два огня защищают город. Спуститесь к Сердцу Разлома и верните последний огонь.'],
            'catacombs'=>['name'=>'Пепельные катакомбы','chapter'=>3,'floors'=>4,'enemy_hp'=>35,'enemy_damage'=>3,'reward'=>4,
                'boss'=>'Костяной король','boss_type'=>'bone_king','unique'=>'king_ring',
                'roster'=>['skeleton','bone_archer','lich_acolyte','crypt_knight','ghoul_miner','bell_wraith'],
                'loot'=>[['grave_hammer','soul_staff','wolf_fang','bone_ward'],['bone_plate','sage_ring','grave_hammer','soul_staff']],
                'story'=>'Огни горят, но под городом проснулись мёртвые. Спуститесь в катакомбы и разбейте корону костяного короля.'],
            'glacier'=>['name'=>'Стеклянный ледник','chapter'=>4,'floors'=>4,'enemy_hp'=>50,'enemy_damage'=>4,'reward'=>5,
                'boss'=>'Королева инея','boss_type'=>'frost_queen','unique'=>'frost_pendant',
                'roster'=>['frost_wolf','ice_golem','rime_witch','yeti','skeleton','thorn_archer'],
                'loot'=>[['frost_bow','glacier_edge','wolf_fang','sage_ring'],['frost_coat','glacier_aegis','frost_bow','glacier_edge']],
                'story'=>'Холод Разлома сковал перевал. Растопите сердце королевы инея, пока город не замёрз.'],
            'citadel'=>['name'=>'Пылающая цитадель','chapter'=>5,'floors'=>5,'enemy_hp'=>70,'enemy_damage'=>6,'reward'=>6,
                'boss'=>'Пепельный тиран','boss_type'=>'ash_tyrant','unique'=>'tyrant_blade',
                'roster'=>['ash_knight','cinder_mage','hellhound','magma_brute','lich_acolyte','crypt_knight'],
                'loot'=>[['ember_scepter','ash_plate','ember_band','glacier_edge'],['ember_scepter','ash_plate','ember_band','frost_coat']],
                'story'=>'Последняя твердыня Разлома. Пять этажей огня и пепельный тиран на вершине.'],
        ];
    }

    /**
     * hp/damage are base values before floor and area scaling. attack/step are cooldowns in
     * ticks (10 per second); step 0 means the creature never moves.
     * specials: poison, chill, drain (on hit), heal (mends allies), explode (on death),
     * slam (area blow around a boss), summon (calls the listed minion).
     */
    public static function enemies(): array {
        return [
            'scavenger'=>['name'=>'Падальщик','hp'=>20,'damage'=>4,'range'=>1,'attack'=>15,'step'=>6,'xp'=>10,'armor'=>0],
            'archer'=>['name'=>'Лучник','hp'=>20,'damage'=>4,'range'=>4,'attack'=>15,'step'=>6,'xp'=>10,'armor'=>0],
            'spitter'=>['name'=>'Плевальщик','hp'=>20,'damage'=>4,'range'=>4,'attack'=>15,'step'=>6,'xp'=>10,'armor'=>0,'specials'=>['poison']],
            'brute'=>['name'=>'Громила','hp'=>35,'damage'=>8,'range'=>1,'attack'=>15,'step'=>6,'xp'=>10,'armor'=>0],
            'rat_swarm'=>['name'=>'Крысиная стая','hp'=>12,'damage'=>3,'range'=>1,'attack'=>8,'step'=>3,'xp'=>8,'armor'=>0],
            'ghoul_miner'=>['name'=>'Упырь-шахтёр','hp'=>30,'damage'=>6,'range'=>1,'attack'=>14,'step'=>5,'xp'=>12,'armor'=>1,'specials'=>['poison']],
            'acolyte'=>['name'=>'Послушник тишины','hp'=>22,'damage'=>5,'range'=>4,'attack'=>18,'step'=>7,'xp'=>12,'armor'=>0,'specials'=>['heal']],
            'bell_wraith'=>['name'=>'Колокольный призрак','hp'=>18,'damage'=>4,'range'=>1,'attack'=>10,'step'=>4,'xp'=>12,'armor'=>0,'specials'=>['drain']],
            'gargoyle'=>['name'=>'Горгулья','hp'=>40,'damage'=>7,'range'=>1,'attack'=>16,'step'=>7,'xp'=>14,'armor'=>3],
            'flagellant'=>['name'=>'Флагеллант','hp'=>30,'damage'=>9,'range'=>1,'attack'=>12,'step'=>5,'xp'=>13,'armor'=>0],
            'root_crawler'=>['name'=>'Корневой ползун','hp'=>28,'damage'=>6,'range'=>1,'attack'=>14,'step'=>5,'xp'=>12,'armor'=>2,'specials'=>['chill']],
            'thorn_archer'=>['name'=>'Терновый стрелок','hp'=>24,'damage'=>6,'range'=>5,'attack'=>16,'step'=>6,'xp'=>13,'armor'=>0,'specials'=>['poison']],
            'spore_bloater'=>['name'=>'Спорный пузырь','hp'=>26,'damage'=>3,'range'=>1,'attack'=>18,'step'=>8,'xp'=>12,'armor'=>0,'specials'=>['explode']],
            'rift_hound'=>['name'=>'Гончая Разлома','hp'=>24,'damage'=>7,'range'=>1,'attack'=>10,'step'=>3,'xp'=>14,'armor'=>0],
            'skeleton'=>['name'=>'Скелет-страж','hp'=>30,'damage'=>7,'range'=>1,'attack'=>14,'step'=>5,'xp'=>14,'armor'=>2],
            'bone_archer'=>['name'=>'Костяной лучник','hp'=>24,'damage'=>7,'range'=>5,'attack'=>15,'step'=>6,'xp'=>14,'armor'=>1],
            'lich_acolyte'=>['name'=>'Ученик лича','hp'=>26,'damage'=>8,'range'=>4,'attack'=>18,'step'=>7,'xp'=>16,'armor'=>0,'specials'=>['heal','drain']],
            'crypt_knight'=>['name'=>'Рыцарь склепа','hp'=>48,'damage'=>10,'range'=>1,'attack'=>16,'step'=>6,'xp'=>18,'armor'=>4],
            'frost_wolf'=>['name'=>'Ледяной волк','hp'=>30,'damage'=>8,'range'=>1,'attack'=>10,'step'=>3,'xp'=>16,'armor'=>1,'specials'=>['chill']],
            'ice_golem'=>['name'=>'Ледяной голем','hp'=>60,'damage'=>12,'range'=>1,'attack'=>20,'step'=>8,'xp'=>20,'armor'=>5],
            'rime_witch'=>['name'=>'Ведьма изморози','hp'=>30,'damage'=>9,'range'=>5,'attack'=>18,'step'=>7,'xp'=>18,'armor'=>0,'specials'=>['chill']],
            'yeti'=>['name'=>'Йети','hp'=>55,'damage'=>13,'range'=>1,'attack'=>16,'step'=>5,'xp'=>20,'armor'=>2],
            'ash_knight'=>['name'=>'Пепельный рыцарь','hp'=>55,'damage'=>13,'range'=>1,'attack'=>15,'step'=>5,'xp'=>22,'armor'=>5],
            'cinder_mage'=>['name'=>'Маг углей','hp'=>34,'damage'=>12,'range'=>5,'attack'=>17,'step'=>7,'xp'=>22,'armor'=>0,'specials'=>['poison']],
            'hellhound'=>['name'=>'Адская гончая','hp'=>36,'damage'=>11,'range'=>1,'attack'=>9,'step'=>3,'xp'=>20,'armor'=>1],
            'magma_brute'=>['name'=>'Магмовый громила','hp'=>70,'damage'=>16,'range'=>1,'attack'=>18,'step'=>7,'xp'=>24,'armor'=>4,'specials'=>['explode']],
            // Bosses
            'warden'=>['name'=>'Смотритель глубин','hp'=>120,'damage'=>12,'range'=>1,'attack'=>15,'step'=>6,'xp'=>80,'armor'=>1,'boss'=>true,'specials'=>['slam']],
            'prior'=>['name'=>'Безмолвный приор','hp'=>140,'damage'=>12,'range'=>4,'attack'=>16,'step'=>7,'xp'=>80,'armor'=>1,'boss'=>true,'specials'=>['summon','drain'],'summon'=>'bell_wraith'],
            'rift_heart'=>['name'=>'Сердце Разлома','hp'=>170,'damage'=>14,'range'=>4,'attack'=>14,'step'=>0,'xp'=>80,'armor'=>2,'boss'=>true,'specials'=>['summon','slam'],'summon'=>'root_crawler'],
            'bone_king'=>['name'=>'Костяной король','hp'=>200,'damage'=>16,'range'=>1,'attack'=>14,'step'=>6,'xp'=>90,'armor'=>4,'boss'=>true,'specials'=>['summon','slam'],'summon'=>'skeleton'],
            'frost_queen'=>['name'=>'Королева инея','hp'=>220,'damage'=>17,'range'=>5,'attack'=>15,'step'=>6,'xp'=>100,'armor'=>3,'boss'=>true,'specials'=>['chill','summon'],'summon'=>'frost_wolf'],
            'ash_tyrant'=>['name'=>'Пепельный тиран','hp'=>260,'damage'=>20,'range'=>1,'attack'=>13,'step'=>5,'xp'=>120,'armor'=>6,'boss'=>true,'specials'=>['slam','summon','poison'],'summon'=>'hellhound'],
        ];
    }

    /** Gear bases: stats at tier 1; every further tier adds half of them again. crit is a flat chance. */
    public static function bases(): array {
        return [
            'blade'=>['name'=>'Клинок','slot'=>'weapon','damage'=>4],
            'axe'=>['name'=>'Секира','slot'=>'weapon','damage'=>5,'hp'=>4],
            'hammer'=>['name'=>'Молот','slot'=>'weapon','damage'=>6,'armor'=>1],
            'dagger'=>['name'=>'Кинжал','slot'=>'weapon','damage'=>3,'crit'=>6],
            'mace'=>['name'=>'Булава','slot'=>'weapon','damage'=>3,'power'=>3],
            'staff'=>['name'=>'Посох','slot'=>'weapon','damage'=>1,'power'=>5],
            'wand'=>['name'=>'Жезл','slot'=>'weapon','power'=>4,'mana'=>6],
            'bow'=>['name'=>'Лук','slot'=>'weapon','damage'=>4,'power'=>1],
            'crossbow'=>['name'=>'Арбалет','slot'=>'weapon','damage'=>5,'crit'=>3],
            'robe'=>['name'=>'Мантия','slot'=>'body','armor'=>1,'power'=>2,'mana'=>6],
            'jerkin'=>['name'=>'Куртка','slot'=>'body','armor'=>2,'damage'=>1],
            'mail'=>['name'=>'Кольчуга','slot'=>'body','armor'=>3],
            'plate'=>['name'=>'Латы','slot'=>'body','armor'=>4,'hp'=>6],
            'shield'=>['name'=>'Щит','slot'=>'offhand','armor'=>3],
            'tome'=>['name'=>'Фолиант','slot'=>'offhand','power'=>3,'mana'=>6],
            'orb'=>['name'=>'Сфера','slot'=>'offhand','power'=>4],
            'quiver'=>['name'=>'Колчан','slot'=>'offhand','damage'=>2,'crit'=>3],
            'ring'=>['name'=>'Кольцо','slot'=>'ring','damage'=>1,'power'=>1],
            'signet'=>['name'=>'Печатка','slot'=>'ring','armor'=>1,'hp'=>5],
            'amulet'=>['name'=>'Амулет','slot'=>'amulet','hp'=>8],
            'talisman'=>['name'=>'Талисман','slot'=>'amulet','power'=>2,'mana'=>6],
        ];
    }

    /** Enchantments: per-tier bonus for stats, flat (+1 per 5 tiers) for crit and life leech percent. */
    public static function affixes(): array {
        return [
            'fierce'=>['name'=>'ярости','damage'=>1],
            'warding'=>['name'=>'стойкости','armor'=>1],
            'arcane'=>['name'=>'чародейства','power'=>1.5],
            'vital'=>['name'=>'жизни','hp'=>6],
            'mystic'=>['name'=>'мудрости','mana'=>5],
            'balanced'=>['name'=>'равновесия','damage'=>0.5,'armor'=>0.5,'power'=>0.5],
            'keen'=>['name'=>'меткости','crit'=>5],
            'vampiric'=>['name'=>'крови','leech'=>5],
        ];
    }

    /** Preferred weapon/armour bases per class for drops. */
    private const CLASS_BASES = [
        'guardian'=>['blade','axe','hammer','mace','plate','mail','shield','signet'],
        'arcanist'=>['staff','wand','robe','tome','orb','talisman','ring'],
        'ranger'=>['bow','crossbow','dagger','jerkin','quiver','ring','amulet'],
        'warden'=>['mace','staff','hammer','robe','mail','shield','talisman'],
    ];

    /** Resolves a named item or a generated key such as "blade+7~fierce" (base + tier ~ affix). */
    public static function item(string $key): array
    {
        $static = self::items()[$key] ?? null;
        if ($static) {
            return $static + ['icon'=>self::ICONS[$key] ?? 'ring','tier'=>intdiv($static['level'] + 2, 3),'crit'=>0,'leech'=>0,'unique'=>true,'generated'=>false];
        }
        if (! preg_match('/^([a-z]+)\+(\d{1,3})(?:~([a-z]+))?$/', $key, $m) || ! isset(self::bases()[$m[1]])) {
            throw new DomainException('unknown_item');
        }
        $tier = max(1, (int) $m[2]);
        $base = self::bases()[$m[1]];
        $affixKey = $m[3] ?? '';
        $affix = $affixKey === '' ? [] : (self::affixes()[$affixKey] ?? throw new DomainException('unknown_item'));
        $grow = 1 + 0.5 * ($tier - 1);
        $item = ['name'=>$base['name'].($affix ? ' '.$affix['name'] : '').' +'.$tier, 'slot'=>$base['slot'], 'icon'=>$m[1], 'tier'=>$tier,
            'affix'=>$affixKey, 'unique'=>false, 'generated'=>true];
        foreach (['damage', 'armor', 'power', 'hp', 'mana'] as $stat) {
            $item[$stat] = (int) round(($base[$stat] ?? 0) * $grow + ($affix[$stat] ?? 0) * $tier);
        }
        foreach (['crit', 'leech'] as $stat) {
            $item[$stat] = ($base[$stat] ?? 0) + (isset($affix[$stat]) ? $affix[$stat] + intdiv($tier, 5) : 0);
        }
        $item['level'] = min(self::MAX_LEVEL, max(1, 3 * $tier - 3));
        $item['price'] = 4 * $tier * ($tier + 1) + ($affix ? 5 * $tier : 0);

        return $item;
    }

    /** A deterministic drop: class-flavoured base half of the time, optional enchantment. */
    public static function generate(Random $rng, int $tier, bool $affix, string $class): string
    {
        $bases = array_keys(self::bases());
        $pool = $rng->next(1, 100) <= 50 ? (self::CLASS_BASES[$class] ?? $bases) : $bases;
        $key = $pool[$rng->next(0, count($pool) - 1)].'+'.max(1, min(999, $tier));
        if ($affix) {
            $affixes = array_keys(self::affixes());
            $key .= '~'.$affixes[$rng->next(0, count($affixes) - 1)];
        }

        return $key;
    }

    public static function forgeCost(int $tier, bool $affix): array
    {
        return ['gold'=>6 * $tier * $tier + 8 * $tier, 'materials'=>2 + $tier, 'essence'=>intdiv($tier, 4) + ($affix ? 1 : 0)];
    }

    public static function upgradeCost(int $tier): array
    {
        return ['gold'=>4 * ($tier + 1) * ($tier + 1), 'materials'=>1 + $tier, 'essence'=>intdiv($tier + 1, 5)];
    }

    public static function enchantCost(int $tier): array
    {
        return ['gold'=>5 * $tier + 10, 'materials'=>0, 'essence'=>1 + intdiv($tier, 4)];
    }

    /** Highest tier the smith can forge for a hero of this level and deepest floor reached. */
    public static function forgeLimit(int $level, int $depth): int
    {
        return max(2, intdiv($level + 2, 3) + 1, intdiv($depth, 2) + 1);
    }

    /** Monster modifiers; several can stack on one creature. */
    public static function enemyAffixes(): array {
        return [
            'venomous'=>['name'=>'ядовитый','specials'=>['poison']],
            'frozen'=>['name'=>'ледяной','specials'=>['chill']],
            'armored'=>['name'=>'бронированный','armor'=>3],
            'swift'=>['name'=>'стремительный','step'=>-2,'attack'=>-4],
            'giant'=>['name'=>'гигант','hp'=>0.6,'damage'=>0.3],
            'vampiric'=>['name'=>'вампир','specials'=>['leech']],
            'arcane'=>['name'=>'колдун','range'=>4],
            'explosive'=>['name'=>'взрывной','specials'=>['explode']],
            'shaman'=>['name'=>'шаман','specials'=>['heal']],
            'soul_eater'=>['name'=>'пожиратель душ','specials'=>['drain']],
            'berserk'=>['name'=>'берсерк','specials'=>['berserk']],
            'regenerating'=>['name'=>'живучий','specials'=>['regen']],
        ];
    }

    public static function enemy(string $type): array {
        return self::enemies()[$type] ?? self::enemies()['scavenger'];
    }

    /** Dungeon furniture: interact with [F] next to it. Traps fire when stepped on. */
    public static function objects(): array {
        return [
            'chest'=>['name'=>'Сундук','description'=>'Кроны, зелье и иногда снаряжение.'],
            'fountain'=>['name'=>'Родник','description'=>'Восстанавливает половину здоровья и всю ману.'],
            'shrine'=>['name'=>'Алтарь ярости','description'=>'На 30 секунд +5 к урону.'],
            'trap'=>['name'=>'Ловушка','description'=>'Шипы ранят того, кто наступит.'],
        ];
    }

    /** essence: rare boss/elite reagent. Existing recipes keep their 0.3 prices. */
    public static function recipes(): array {
        $r=fn(int $gold,int $materials,int $essence=0)=>['gold'=>$gold,'materials'=>$materials,'essence'=>$essence];
        return [
            'steel_sword'=>$r(15,3),'chainmail'=>$r(12,3),'ash_staff'=>$r(20,4),'warden_shield'=>$r(30,6),
            'amber_amulet'=>$r(10,2),'bone_axe'=>$r(18,3),'monk_robe'=>$r(18,3),'silver_ring'=>$r(20,3),'recurve_bow'=>$r(20,3),
            'bell_shield'=>$r(22,4),'censer_mace'=>$r(26,4),'spell_tome'=>$r(30,5),'scale_armor'=>$r(30,5),
            'viper_ring'=>$r(34,5),'thorn_bow'=>$r(36,6),'wolf_fang'=>$r(40,6,1),'bark_mail'=>$r(42,7,1),
            'rift_blade'=>$r(45,7,1),'root_staff'=>$r(45,7,1),'sage_ring'=>$r(50,8,1),
            'grave_hammer'=>$r(60,9,2),'soul_staff'=>$r(60,9,2),'bone_plate'=>$r(58,9,2),'bone_ward'=>$r(55,8,2),
            'frost_bow'=>$r(75,11,3),'glacier_edge'=>$r(75,11,3),'frost_coat'=>$r(72,11,3),'glacier_aegis'=>$r(70,10,3),
            'ember_scepter'=>$r(95,14,4),'ash_plate'=>$r(95,14,4),'ember_band'=>$r(90,13,4),
            'phoenix_feather'=>$r(150,20,8),
        ];
    }

    public static function talents():array {
        return [
            'bulwark'=>['name'=>'Щитоносец','class'=>'guardian','stat'=>'armor','amount'=>1],
            'executioner'=>['name'=>'Каратель','class'=>'guardian','stat'=>'damage_bonus','amount'=>3],
            'juggernaut'=>['name'=>'Несокрушимый','class'=>'guardian','stat'=>'max_hp','amount'=>20],
            'stormcaller'=>['name'=>'Буревестник','class'=>'arcanist','stat'=>'max_mana','amount'=>15],
            'ashen'=>['name'=>'Пепельник','class'=>'arcanist','stat'=>'power','amount'=>4],
            'wardweaver'=>['name'=>'Ткач оберегов','class'=>'arcanist','stat'=>'armor','amount'=>1],
            'marksman'=>['name'=>'Стрелок','class'=>'ranger','stat'=>'damage_bonus','amount'=>3],
            'trapper'=>['name'=>'Ловчий','class'=>'ranger','stat'=>'max_hp','amount'=>15],
            'windrunner'=>['name'=>'Бегущий с ветром','class'=>'ranger','stat'=>'max_mana','amount'=>12],
            'healer'=>['name'=>'Целитель','class'=>'warden','stat'=>'power','amount'=>4],
            'sealkeeper'=>['name'=>'Хранитель печатей','class'=>'warden','stat'=>'armor','amount'=>1],
            'pilgrim'=>['name'=>'Пилигрим','class'=>'warden','stat'=>'max_hp','amount'=>15],
        ];
    }

    public static function items(): array
    {
        $i=fn(string $name,string $slot,int $damage,int $armor,int $power,int $price,int $level,int $hp=0,int $mana=0)=>
            ['name'=>$name,'slot'=>$slot,'damage'=>$damage,'armor'=>$armor,'power'=>$power,'hp'=>$hp,'mana'=>$mana,'price'=>$price,'level'=>$level];
        return [
            'iron_sword' => $i('Железный меч','weapon',3,0,0,8,1),
            'oak_staff' => $i('Дубовый посох','weapon',1,0,5,8,1),
            'hunting_bow' => $i('Охотничий лук','weapon',3,0,2,8,1),
            'healer_mace' => $i('Булава хранителя','weapon',2,0,4,8,1),
            'leather' => $i('Кожаный доспех','body',0,1,0,6,1),
            'buckler' => $i('Круглый щит','offhand',0,1,0,6,1),
            'ember_ring' => $i('Кольцо углей','ring',0,0,3,12,1),
            'steel_sword' => $i('Стальной клинок','weapon',7,0,0,24,2),
            'chainmail' => $i('Кольчуга шахтёра','body',0,3,0,22,2),
            'ash_staff' => $i('Посох пепла','weapon',2,0,10,30,3),
            'warden_shield' => $i('Щит смотрителя','offhand',0,4,2,40,3),
            // 0.4: new weapons
            'bone_axe' => $i('Костяной топор','weapon',5,0,0,18,2),
            'monk_staff' => $i('Посох звонаря','weapon',2,0,7,20,2),
            'recurve_bow' => $i('Составной лук','weapon',5,0,1,20,2),
            'censer_mace' => $i('Кадильная булава','weapon',4,0,6,26,3),
            'thorn_bow' => $i('Терновый лук','weapon',8,0,2,34,4),
            'rift_blade' => $i('Клинок Разлома','weapon',10,0,0,40,5),
            'root_staff' => $i('Корневой посох','weapon',3,0,14,42,5),
            'grave_hammer' => $i('Могильный молот','weapon',13,1,0,55,7),
            'soul_staff' => $i('Посох душ','weapon',4,0,18,58,7),
            'frost_bow' => $i('Ледяной лук','weapon',14,0,3,66,9),
            'glacier_edge' => $i('Ледниковая сталь','weapon',16,0,0,70,9),
            'ember_scepter' => $i('Скипетр углей','weapon',6,0,24,85,11),
            'tyrant_blade' => $i('Клинок тирана','weapon',22,0,4,120,13),
            // armour
            'monk_robe' => $i('Ряса пилигрима','body',0,2,3,18,2,0,10),
            'scale_armor' => $i('Чешуйчатый доспех','body',0,4,0,28,4),
            'bark_mail' => $i('Корьевая броня','body',0,5,0,38,5,15),
            'bone_plate' => $i('Костяные латы','body',0,7,0,52,7,20),
            'frost_coat' => $i('Плащ инея','body',0,6,6,64,9,0,20),
            'ash_plate' => $i('Пепельные латы','body',0,10,0,84,11,30),
            // off-hand
            'bell_shield' => $i('Колокольный щит','offhand',0,3,0,22,3),
            'spell_tome' => $i('Том заклинаний','offhand',0,0,6,28,4,0,15),
            'bone_ward' => $i('Костяной оберег','offhand',0,5,3,50,7),
            'glacier_aegis' => $i('Ледяная эгида','offhand',0,7,0,64,9,20),
            // rings
            'silver_ring' => $i('Серебряное кольцо','ring',2,0,2,18,2),
            'viper_ring' => $i('Кольцо гадюки','ring',4,0,0,30,4),
            'sage_ring' => $i('Кольцо мудреца','ring',0,0,8,44,6,0,10),
            'king_ring' => $i('Перстень костяного короля','ring',5,2,5,70,8),
            'ember_band' => $i('Огненный обруч','ring',6,0,6,80,11),
            // amulets (new slot)
            'amber_amulet' => $i('Янтарный амулет','amulet',0,0,0,10,2,15),
            'prior_icon' => $i('Икона приора','amulet',0,1,6,45,3,0,10),
            'heart_seed' => $i('Семя Сердца','amulet',0,0,4,55,5,30),
            'wolf_fang' => $i('Волчий клык','amulet',3,0,0,36,6,10),
            'frost_pendant' => $i('Слеза королевы','amulet',0,0,10,80,9,25),
            'phoenix_feather' => $i('Перо феникса','amulet',4,0,8,140,12,40),
        ];
    }

    /** kind: bolt (one target), nova (all around), chain (three nearest), meteor (target + splash), heal, barrier. */
    public static function spells(): array
    {
        return [
            'firebolt'=>['name'=>'Огненная стрела','kind'=>'bolt','level'=>1,'mana'=>12,'cooldown'=>15,'range'=>6,'damage'=>20,'key'=>'4','description'=>'Удар по ближайшему видимому врагу в радиусе 6 клеток.'],
            'mend'=>['name'=>'Исцеление','kind'=>'heal','level'=>1,'mana'=>18,'cooldown'=>40,'range'=>0,'heal'=>35,'key'=>'5','description'=>'Восстанавливает здоровье заклинателя; хранитель лечит союзника рядом.'],
            'frost'=>['name'=>'Ледяное копьё','kind'=>'bolt','level'=>2,'mana'=>16,'cooldown'=>25,'range'=>5,'damage'=>15,'freeze'=>20,'key'=>'6','description'=>'Урон и остановка противника на 2 секунды.'],
            'nova'=>['name'=>'Кольцо пламени','kind'=>'nova','level'=>4,'mana'=>30,'cooldown'=>60,'range'=>3,'damage'=>28,'key'=>'7','description'=>'Поражает всех видимых врагов в радиусе 3 клеток.'],
            'venom'=>['name'=>'Ядовитый шип','kind'=>'bolt','level'=>3,'mana'=>14,'cooldown'=>20,'range'=>6,'damage'=>10,'poison'=>5,'key'=>'8','description'=>'Слабый удар и яд: 5 урона в секунду на 6 секунд.'],
            'chain'=>['name'=>'Цепная молния','kind'=>'chain','level'=>6,'mana'=>28,'cooldown'=>45,'range'=>6,'damage'=>24,'key'=>'9','description'=>'Бьёт до трёх ближайших видимых врагов в радиусе 6.'],
            'barrier'=>['name'=>'Святой барьер','kind'=>'barrier','level'=>8,'mana'=>30,'cooldown'=>90,'range'=>3,'duration'=>40,'key'=>'0','description'=>'Вы и союзники в радиусе 3 получают вдвое меньше урона 4 секунды.'],
            'meteor'=>['name'=>'Метеор','kind'=>'meteor','level'=>12,'mana'=>45,'cooldown'=>90,'range'=>6,'damage'=>45,'key'=>'Q','description'=>'45 урона по цели и половина — всем врагам рядом с ней.'],
            'whirlwind'=>['name'=>'Вихрь клинков','kind'=>'whirl','level'=>3,'mana'=>15,'cooldown'=>30,'range'=>1,'damage'=>14,'key'=>'V','description'=>'Удар оружием по всем соседним врагам: 14 + бонус урона.'],
            'blink'=>['name'=>'Скачок','kind'=>'blink','level'=>5,'mana'=>12,'cooldown'=>50,'range'=>3,'key'=>'Z','description'=>'Мгновенный рывок до 3 клеток в сторону взгляда.'],
            'fire_wave'=>['name'=>'Огненная волна','kind'=>'wave','level'=>7,'mana'=>24,'cooldown'=>40,'range'=>5,'damage'=>26,'key'=>'C','description'=>'Пламя бьёт всех врагов на линии в 5 клеток по направлению взгляда.'],
            'drain_life'=>['name'=>'Похищение жизни','kind'=>'drain','level'=>9,'mana'=>22,'cooldown'=>35,'range'=>5,'damage'=>18,'key'=>'G','description'=>'Вытягивает здоровье цели и лечит заклинателя на ту же величину.'],
        ];
    }

    /** Town bounty board: slay a kind of foe, extract, then claim the reward. */
    public static function bounty(int $campaign, int $roll, int $size): array
    {
        $open=array_values(array_filter(self::biomes(),fn($b)=>$b['chapter']<=min($campaign,5)));
        $biome=$open[$roll%count($open)];
        $type=$biome['roster'][intdiv($roll,7)%count($biome['roster'])];
        $need=6+$size%9;$tier=$biome['chapter']+1;
        return ['type'=>$type,'name'=>self::enemy($type)['name'],'area'=>$biome['name'],'need'=>$need,'have'=>0,
            'gold'=>$need*3*$tier,'materials'=>1+$tier,'essence'=>$biome['chapter']>=2?intdiv($tier,2):0];
    }

    public static function progression(int $xp): array
    {
        $level=1;
        while ($level<self::MAX_LEVEL && $xp>=self::threshold($level+1)) $level++;
        return ['level'=>$level,'level_xp'=>self::threshold($level),'next_level_xp'=>$level<self::MAX_LEVEL ? self::threshold($level+1) : null];
    }
    private static function threshold(int $level): int { return 20*($level-1)*$level; }

    public static function profile(string $name, string $class, int $xp, array $attributes, array $equipment): array
    {
        $level=self::progression($xp)['level'];
        $damage=($attributes['strength']??0)*2; $armor=0; $power=($attributes['intellect']??0)*3; $hp=0; $mana=0; $crit=0; $leech=0;
        foreach ($equipment as $key) {
            $item=self::item($key); $damage+=$item['damage']; $armor+=$item['armor']; $power+=$item['power'];
            $hp+=$item['hp']; $mana+=$item['mana']; $crit+=$item['crit']; $leech+=$item['leech'];
        }
        if ($class==='ranger') $damage+=2;
        if ($class==='warden') $power+=3;
        return ['name'=>$name,'class_id'=>$class,'level'=>$level,
            'max_hp'=>100+($level-1)*10+($attributes['vitality']??0)*10+$hp,
            'max_mana'=>match($class){'arcanist'=>100,'warden'=>80,'ranger'=>70,default=>60}+($level-1)*5+($attributes['intellect']??0)*5+$mana,
            'damage_bonus'=>$damage,'armor'=>$armor,'power'=>$power,'crit'=>min(50,$crit),'leech'=>min(30,$leech),'equipment'=>$equipment];
    }
}
