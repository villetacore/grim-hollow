<?php
declare(strict_types=1);
namespace GrimHollow\Core;

use DomainException;

/**
 * Shared, versioned game rules; clients display these values, never decide them.
 *
 * Content is combinatorial: a hero is class × origin × mentor; gear is base × material ×
 * enchantment × rune × tier; a creature is species × fused species × up to three modifiers ×
 * floor omens. Each list grows the space multiplicatively instead of adding single entries.
 */
final class Catalog
{
    public const MAX_LEVEL = 100;
    public const TALENT_RANKS = 10;
    /** A hero chooses a mentor of another class from this level on. */
    public const MENTOR_LEVEL = 10;
    public const SLOTS = ['weapon', 'body', 'offhand', 'ring', 'amulet', 'head', 'hands', 'feet'];
    /** Stats that grow with tier, and flat stats that grow by one every five tiers. */
    private const SCALED = ['damage', 'armor', 'power', 'hp', 'mana', 'thorns'];
    private const FLAT = ['crit', 'leech', 'regen', 'greed'];
    public const BOSSES = ['warden', 'prior', 'rift_heart', 'bone_king', 'frost_queen', 'ash_tyrant', 'mire_mother', 'void_king'];
    /** Icon shape of each named item; generated gear uses its base key. */
    private const ICONS = ['iron_sword'=>'blade','oak_staff'=>'staff','hunting_bow'=>'bow','healer_mace'=>'mace','leather'=>'jerkin',
        'buckler'=>'shield','ember_ring'=>'ring','steel_sword'=>'blade','chainmail'=>'mail','ash_staff'=>'staff','warden_shield'=>'shield',
        'bone_axe'=>'axe','monk_staff'=>'staff','recurve_bow'=>'bow','censer_mace'=>'mace','thorn_bow'=>'bow','rift_blade'=>'blade',
        'root_staff'=>'staff','grave_hammer'=>'hammer','soul_staff'=>'staff','frost_bow'=>'bow','glacier_edge'=>'blade','ember_scepter'=>'wand',
        'tyrant_blade'=>'blade','monk_robe'=>'robe','scale_armor'=>'mail','bark_mail'=>'jerkin','bone_plate'=>'plate','frost_coat'=>'robe',
        'ash_plate'=>'plate','bell_shield'=>'shield','spell_tome'=>'tome','bone_ward'=>'orb','glacier_aegis'=>'shield','silver_ring'=>'ring',
        'viper_ring'=>'ring','sage_ring'=>'ring','king_ring'=>'signet','ember_band'=>'signet','amber_amulet'=>'amulet','prior_icon'=>'talisman',
        'heart_seed'=>'amulet','wolf_fang'=>'talisman','frost_pendant'=>'amulet','phoenix_feather'=>'talisman',
        'rusty_axe'=>'axe','shiv'=>'dagger','grave_wand'=>'wand','oak_totem'=>'totem','miner_helm'=>'helm','wool_wraps'=>'wraps',
        'road_boots'=>'boots','bog_scythe'=>'scythe','mire_hood'=>'hood','toad_boots'=>'boots','witch_totem'=>'totem','mother_eye'=>'amulet',
        'void_claws'=>'claws','star_crown'=>'circlet','abyss_gauntlets'=>'gauntlets','comet_spear'=>'spear','void_heart'=>'signet',
        'warlord_greatsword'=>'greatsword','reaper_sickle'=>'sickle','pilgrim_sandals'=>'sandals','knight_greaves'=>'greaves','sage_bracers'=>'bracers'];

    /**
     * Classes. traits drive combat rules, schools make matching spells cheaper and stronger,
     * bases flavour drops. A mentor (another class) adds its traits and schools at level 10.
     */
    public static function classes(): array {
        return [
            'guardian'=>['name'=>'Страж','mana'=>60,'weapon'=>'iron_sword','traits'=>['bulwark'],'schools'=>['war'],'bonus'=>['armor'=>1],
                'bases'=>['blade','axe','hammer','mace','plate','mail','shield','signet','helm','gauntlets','greaves'],
                'description'=>'Меч и щит. Получает на 15% меньше урона.'],
            'arcanist'=>['name'=>'Арканист','mana'=>100,'weapon'=>'oak_staff','traits'=>['arcane_surge'],'schools'=>['fire','frost','storm'],'bonus'=>[],
                'bases'=>['staff','wand','robe','tome','orb','talisman','ring','circlet','sandals'],
                'description'=>'Посох и стихии. Сила магии в заклинаниях ×1,5.'],
            'ranger'=>['name'=>'Следопыт','mana'=>70,'weapon'=>'hunting_bow','traits'=>['ranged'],'schools'=>['nature','storm'],'bonus'=>['damage'=>2],
                'bases'=>['bow','crossbow','dagger','jerkin','quiver','ring','amulet','hood','boots','wraps'],
                'description'=>'Лук. Обычная атака бьёт на 5 клеток.'],
            'warden'=>['name'=>'Хранитель','mana'=>80,'weapon'=>'healer_mace','traits'=>['ally_heal'],'schools'=>['holy'],'bonus'=>['power'=>3],
                'bases'=>['mace','staff','hammer','robe','mail','shield','talisman','circlet','bracers'],
                'description'=>'Булава и свет. Исцеляет союзников рядом.'],
            'berserker'=>['name'=>'Берсерк','mana'=>50,'weapon'=>'rusty_axe','traits'=>['rage'],'schools'=>['war','blood'],'bonus'=>['hp'=>20,'damage'=>1],
                'bases'=>['axe','greatsword','hammer','spear','jerkin','plate','amulet','helm','gauntlets','boots'],
                'description'=>'Двуручная ярость. Ниже половины здоровья бьёт в полтора раза сильнее.'],
            'assassin'=>['name'=>'Убийца','mana'=>70,'weapon'=>'shiv','traits'=>['backstab'],'schools'=>['shadow','storm'],'bonus'=>['crit'=>8],
                'bases'=>['dagger','claws','sickle','crossbow','jerkin','quiver','ring','hood','wraps','boots'],
                'description'=>'Кинжалы и тени. +10% шанса крита, крит утраивает удар.'],
            'necromancer'=>['name'=>'Некромант','mana'=>100,'weapon'=>'grave_wand','traits'=>['soul_harvest'],'schools'=>['shadow','blood'],'bonus'=>['power'=>2],
                'bases'=>['wand','scythe','sickle','robe','orb','tome','signet','circlet','bracers'],
                'description'=>'Жезл и смерть. Каждое убийство восполняет ману и здоровье.'],
            'druid'=>['name'=>'Друид','mana'=>90,'weapon'=>'oak_totem','traits'=>['venom_touch'],'schools'=>['nature','frost'],'bonus'=>['hp'=>10,'power'=>2],
                'bases'=>['totem','staff','spear','jerkin','robe','talisman','amulet','hood','sandals'],
                'description'=>'Тотем и природа. Удары оружием отравляют.'],
        ];
    }

    /** Ancestry chosen at creation; stacks with every class. */
    public static function origins(): array {
        return [
            'human'=>['name'=>'Человек','hp'=>10,'mana'=>10,'greed'=>5,'description'=>'+10 HP, +10 MP, +5% золота.'],
            'elf'=>['name'=>'Эльф','mana'=>15,'crit'=>3,'power'=>1,'description'=>'+15 MP, +3% крита, +1 магии.'],
            'dwarf'=>['name'=>'Дворф','armor'=>2,'hp'=>15,'description'=>'+2 брони, +15 HP.'],
            'orc'=>['name'=>'Орк','damage'=>3,'hp'=>10,'description'=>'+3 урона, +10 HP.'],
            'undead'=>['name'=>'Нежить','leech'=>4,'traits'=>['unliving'],'description'=>'+4% вампиризма, яд не действует.'],
            'fae'=>['name'=>'Дитя фей','power'=>4,'mana'=>10,'regen'=>1,'description'=>'+4 магии, +10 MP, +1 HP в секунду.'],
            'drakeborn'=>['name'=>'Драконорождённый','damage'=>1,'power'=>2,'armor'=>1,'thorns'=>3,'description'=>'+1 урона, +2 магии, +1 брони, шипы 3.'],
        ];
    }

    public static function traits(): array {
        return [
            'bulwark'=>'Оплот: на 15% меньше урона',
            'arcane_surge'=>'Всплеск: сила магии в заклинаниях ×1,5',
            'ranged'=>'Дальний бой: атака на 5 клеток',
            'ally_heal'=>'Целитель: исцеление союзников',
            'rage'=>'Ярость: ×1,5 урона ниже половины HP',
            'backstab'=>'Удар в спину: +10% крита, крит ×3',
            'soul_harvest'=>'Жатва душ: убийство даёт 8 MP и 5% HP',
            'venom_touch'=>'Ядовитое касание: удары оружием отравляют',
            'unliving'=>'Неживой: невосприимчив к яду',
        ];
    }

    /** Spell schools; a hero's class and mentor schools cost a quarter less mana and add 5 power. */
    public static function schools(): array {
        return ['fire'=>'огонь','frost'=>'лёд','storm'=>'буря','nature'=>'природа','holy'=>'свет','shadow'=>'тень','blood'=>'кровь','war'=>'война'];
    }

    /** Effective traits and schools of a class/origin/mentor combination. */
    public static function build(string $class, string $origin = '', ?string $mentor = null): array
    {
        $classes = self::classes();
        $own = $classes[$class] ?? $classes['guardian'];
        $traits = $own['traits'];
        $schools = $own['schools'];
        if ($mentor !== null && $mentor !== $class && isset($classes[$mentor])) {
            $traits = array_merge($traits, $classes[$mentor]['traits']);
            $schools = array_merge($schools, $classes[$mentor]['schools']);
        }
        $traits = array_merge($traits, self::origins()[$origin]['traits'] ?? []);

        return ['traits'=>array_values(array_unique($traits)), 'schools'=>array_values(array_unique($schools))];
    }

    /**
     * Areas unlock in order. The first three return the fires (main campaign); the deeper
     * ones are post-campaign expeditions with more floors and harder foes.
     * loot: [first-floor pool, deeper-floor pool]; unique: the boss's guaranteed drop.
     */
    public static function biomes(): array {
        return [
            'mines'=>['name'=>'Затопленные шахты','chapter'=>0,'floors'=>3,'enemy_hp'=>0,'enemy_damage'=>0,'reward'=>1,
                'boss'=>'Смотритель глубин','boss_type'=>'warden','unique'=>'warden_shield',
                'roster'=>['scavenger','archer','spitter','brute','rat_swarm','ghoul_miner'],
                'loot'=>[['ember_ring','iron_sword','leather','amber_amulet','miner_helm'],['steel_sword','chainmail','ash_staff','bone_axe','road_boots']],
                'story'=>'Верните первый огонь из шахт. Победите смотрителя на третьем этаже и эвакуируйтесь.'],
            'monastery'=>['name'=>'Монастырь без колокола','chapter'=>1,'floors'=>3,'enemy_hp'=>10,'enemy_damage'=>1,'reward'=>2,
                'boss'=>'Безмолвный приор','boss_type'=>'prior','unique'=>'prior_icon',
                'roster'=>['scavenger','archer','acolyte','bell_wraith','gargoyle','flagellant'],
                'loot'=>[['monk_staff','monk_robe','silver_ring','recurve_bow','wool_wraps'],['bell_shield','censer_mace','silver_ring','monk_robe','pilgrim_sandals']],
                'story'=>'Первый огонь горит. Найдите второй в покинутом монастыре и победите приора.'],
            'roots'=>['name'=>'Корневая бездна','chapter'=>2,'floors'=>3,'enemy_hp'=>20,'enemy_damage'=>2,'reward'=>3,
                'boss'=>'Сердце Разлома','boss_type'=>'rift_heart','unique'=>'heart_seed',
                'roster'=>['root_crawler','thorn_archer','spore_bloater','rift_hound','brute','spitter'],
                'loot'=>[['thorn_bow','scale_armor','spell_tome','viper_ring'],['rift_blade','root_staff','bark_mail','viper_ring','sage_bracers']],
                'story'=>'Два огня защищают город. Спуститесь к Сердцу Разлома и верните последний огонь.'],
            'catacombs'=>['name'=>'Пепельные катакомбы','chapter'=>3,'floors'=>4,'enemy_hp'=>35,'enemy_damage'=>3,'reward'=>4,
                'boss'=>'Костяной король','boss_type'=>'bone_king','unique'=>'king_ring',
                'roster'=>['skeleton','bone_archer','lich_acolyte','crypt_knight','ghoul_miner','bell_wraith'],
                'loot'=>[['grave_hammer','soul_staff','wolf_fang','bone_ward'],['bone_plate','sage_ring','grave_hammer','soul_staff','reaper_sickle']],
                'story'=>'Огни горят, но под городом проснулись мёртвые. Спуститесь в катакомбы и разбейте корону костяного короля.'],
            'glacier'=>['name'=>'Стеклянный ледник','chapter'=>4,'floors'=>4,'enemy_hp'=>50,'enemy_damage'=>4,'reward'=>5,
                'boss'=>'Королева инея','boss_type'=>'frost_queen','unique'=>'frost_pendant',
                'roster'=>['frost_wolf','ice_golem','rime_witch','yeti','skeleton','thorn_archer'],
                'loot'=>[['frost_bow','glacier_edge','wolf_fang','sage_ring'],['frost_coat','glacier_aegis','frost_bow','glacier_edge','knight_greaves']],
                'story'=>'Холод Разлома сковал перевал. Растопите сердце королевы инея, пока город не замёрз.'],
            'citadel'=>['name'=>'Пылающая цитадель','chapter'=>5,'floors'=>5,'enemy_hp'=>70,'enemy_damage'=>6,'reward'=>6,
                'boss'=>'Пепельный тиран','boss_type'=>'ash_tyrant','unique'=>'tyrant_blade',
                'roster'=>['ash_knight','cinder_mage','hellhound','magma_brute','lich_acolyte','crypt_knight'],
                'loot'=>[['ember_scepter','ash_plate','ember_band','glacier_edge'],['ember_scepter','ash_plate','ember_band','frost_coat','warlord_greatsword']],
                'story'=>'Твердыня Разлома. Пять этажей огня и пепельный тиран на вершине.'],
            'swamp'=>['name'=>'Гнилая топь','chapter'=>6,'floors'=>5,'enemy_hp'=>90,'enemy_damage'=>8,'reward'=>7,
                'boss'=>'Матерь топей','boss_type'=>'mire_mother','unique'=>'mother_eye',
                'roster'=>['bog_lurker','leech_swarm','mire_witch','toad_brute','wisp','spore_bloater'],
                'loot'=>[['bog_scythe','mire_hood','toad_boots','witch_totem'],['bog_scythe','witch_totem','mire_hood','phoenix_feather']],
                'story'=>'За цитаделью гниёт топь. Её Матерь рождает пиявок быстрее, чем их успевают сжигать.'],
            'abyss'=>['name'=>'Звёздная бездна','chapter'=>7,'floors'=>6,'enemy_hp'=>120,'enemy_damage'=>10,'reward'=>8,
                'boss'=>'Владыка пустоты','boss_type'=>'void_king','unique'=>'void_heart',
                'roster'=>['void_stalker','star_spawn','mind_flayer','void_golem','comet_wisp','wisp'],
                'loot'=>[['void_claws','star_crown','comet_spear','abyss_gauntlets'],['void_claws','star_crown','comet_spear','abyss_gauntlets','phoenix_feather']],
                'story'=>'Разлом раскрылся в небо. На дне бездны звёзды смотрят в ответ.'],
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
            'cinder_mage'=>['name'=>'Маг углей','hp'=>34,'damage'=>12,'range'=>5,'attack'=>17,'step'=>7,'xp'=>22,'armor'=>0,'specials'=>['burn']],
            'hellhound'=>['name'=>'Адская гончая','hp'=>36,'damage'=>11,'range'=>1,'attack'=>9,'step'=>3,'xp'=>20,'armor'=>1],
            'magma_brute'=>['name'=>'Магмовый громила','hp'=>70,'damage'=>16,'range'=>1,'attack'=>18,'step'=>7,'xp'=>24,'armor'=>4,'specials'=>['explode']],
            // 0.6: the rotten swamp and the starry abyss
            'bog_lurker'=>['name'=>'Топляк','hp'=>62,'damage'=>14,'range'=>1,'attack'=>16,'step'=>6,'xp'=>24,'armor'=>3,'specials'=>['chill']],
            'leech_swarm'=>['name'=>'Рой пиявок','hp'=>26,'damage'=>8,'range'=>1,'attack'=>8,'step'=>3,'xp'=>16,'armor'=>0,'specials'=>['leech']],
            'mire_witch'=>['name'=>'Болотная ведьма','hp'=>40,'damage'=>13,'range'=>5,'attack'=>18,'step'=>7,'xp'=>26,'armor'=>0,'specials'=>['poison','heal']],
            'toad_brute'=>['name'=>'Жаб-громила','hp'=>80,'damage'=>17,'range'=>1,'attack'=>18,'step'=>7,'xp'=>28,'armor'=>2,'specials'=>['explode','poison']],
            'wisp'=>['name'=>'Блуждающий огонёк','hp'=>24,'damage'=>10,'range'=>4,'attack'=>10,'step'=>3,'xp'=>20,'armor'=>0,'specials'=>['drain','evade']],
            'void_stalker'=>['name'=>'Охотник пустоты','hp'=>48,'damage'=>16,'range'=>1,'attack'=>9,'step'=>3,'xp'=>30,'armor'=>2,'specials'=>['blink']],
            'star_spawn'=>['name'=>'Звёздное отродье','hp'=>44,'damage'=>15,'range'=>5,'attack'=>16,'step'=>6,'xp'=>30,'armor'=>1,'specials'=>['burn']],
            'mind_flayer'=>['name'=>'Пожиратель разума','hp'=>52,'damage'=>14,'range'=>3,'attack'=>14,'step'=>6,'xp'=>32,'armor'=>1,'specials'=>['drain','warded']],
            'void_golem'=>['name'=>'Голем пустоты','hp'=>95,'damage'=>19,'range'=>1,'attack'=>20,'step'=>8,'xp'=>34,'armor'=>7,'specials'=>['thorns']],
            'comet_wisp'=>['name'=>'Кометный дух','hp'=>36,'damage'=>12,'range'=>1,'attack'=>12,'step'=>4,'xp'=>26,'armor'=>0,'specials'=>['explode','burn']],
            // Bosses
            'warden'=>['name'=>'Смотритель глубин','hp'=>120,'damage'=>12,'range'=>1,'attack'=>15,'step'=>6,'xp'=>80,'armor'=>1,'boss'=>true,'specials'=>['slam']],
            'prior'=>['name'=>'Безмолвный приор','hp'=>140,'damage'=>12,'range'=>4,'attack'=>16,'step'=>7,'xp'=>80,'armor'=>1,'boss'=>true,'specials'=>['summon','drain'],'summon'=>'bell_wraith'],
            'rift_heart'=>['name'=>'Сердце Разлома','hp'=>170,'damage'=>14,'range'=>4,'attack'=>14,'step'=>0,'xp'=>80,'armor'=>2,'boss'=>true,'specials'=>['summon','slam'],'summon'=>'root_crawler'],
            'bone_king'=>['name'=>'Костяной король','hp'=>200,'damage'=>16,'range'=>1,'attack'=>14,'step'=>6,'xp'=>90,'armor'=>4,'boss'=>true,'specials'=>['summon','slam'],'summon'=>'skeleton'],
            'frost_queen'=>['name'=>'Королева инея','hp'=>220,'damage'=>17,'range'=>5,'attack'=>15,'step'=>6,'xp'=>100,'armor'=>3,'boss'=>true,'specials'=>['chill','summon'],'summon'=>'frost_wolf'],
            'ash_tyrant'=>['name'=>'Пепельный тиран','hp'=>260,'damage'=>20,'range'=>1,'attack'=>13,'step'=>5,'xp'=>120,'armor'=>6,'boss'=>true,'specials'=>['slam','summon','poison'],'summon'=>'hellhound'],
            'mire_mother'=>['name'=>'Матерь топей','hp'=>300,'damage'=>22,'range'=>4,'attack'=>14,'step'=>6,'xp'=>140,'armor'=>5,'boss'=>true,'specials'=>['summon','poison','heal'],'summon'=>'leech_swarm'],
            'void_king'=>['name'=>'Владыка пустоты','hp'=>360,'damage'=>25,'range'=>1,'attack'=>13,'step'=>5,'xp'=>170,'armor'=>8,'boss'=>true,'specials'=>['slam','summon','drain','blink'],'summon'=>'void_stalker'],
        ];
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
            // 0.6
            'thorned'=>['name'=>'шипастый','specials'=>['thorns']],
            'burning'=>['name'=>'пылающий','specials'=>['burn']],
            'splitting'=>['name'=>'делящийся','specials'=>['split']],
            'warlord'=>['name'=>'вожак','specials'=>['warlord']],
            'blinking'=>['name'=>'мерцающий','specials'=>['blink']],
            'spectral'=>['name'=>'призрачный','specials'=>['evade']],
            'warded'=>['name'=>'заговорённый','specials'=>['warded']],
            'colossal'=>['name'=>'колосс','hp'=>0.25,'armor'=>2,'step'=>2],
        ];
    }

    /** What each special does, for the bestiary and tooltips. */
    public static function specials(): array {
        return ['poison'=>'отравляет','chill'=>'замедляет','drain'=>'выпивает ману','heal'=>'лечит союзников','explode'=>'взрывается при смерти',
            'slam'=>'бьёт по площади','summon'=>'призывает подмогу','leech'=>'пьёт кровь','berserk'=>'свирепеет при ранении','regen'=>'восстанавливается',
            'thorns'=>'ранит бьющего оружием','burn'=>'поджигает','split'=>'делится при смерти','warlord'=>'усиливает соседей',
            'blink'=>'мчится рывками','evade'=>'уклоняется от ударов оружием','warded'=>'наполовину гасит магию'];
    }

    /**
     * Floor omens: a floor may roll one or two, changing spawns, rewards and sight. They stack
     * with the area and its boss cycle, so the same area plays differently every descent.
     */
    public static function omens(): array {
        return [
            'bloodmoon'=>['name'=>'Кровавая луна','description'=>'Враги бьют на 20% сильнее, золото ×1,5.','damage'=>0.2,'gold'=>0.5],
            'swarm'=>['name'=>'Нашествие','description'=>'Врагов вдвое больше, но они слабее.','budget'=>1.0,'hp'=>-0.25],
            'treasure'=>['name'=>'Сокровищница','description'=>'Три лишних сундука.','chests'=>3],
            'darkness'=>['name'=>'Непроглядная тьма','description'=>'Видно только на 5 клеток.','sight'=>5],
            'blessing'=>['name'=>'Благословение','description'=>'Лишний родник и алтарь.','fountains'=>1,'shrines'=>1],
            'champions'=>['name'=>'Час чемпионов','description'=>'Матёрых врагов намного больше.','elite'=>25],
            'fortified'=>['name'=>'Укрепление','description'=>'Враги в броне +3, опыт ×1,3.','armor'=>3,'xp'=>0.3],
            'cursed_ground'=>['name'=>'Проклятая земля','description'=>'Вдвое больше ловушек и реагентов.','traps'=>2,'reagents'=>1],
            'chimeras'=>['name'=>'Сплетение','description'=>'Химеры встречаются втрое чаще.','chimera'=>2],
        ];
    }

    /**
     * Crafting reagents. Every species drops one; a chimera drops both of its halves.
     * aspect: what the reagent lends to an elixir.
     */
    public static function reagents(): array {
        return [
            'rat_tail'=>['name'=>'Крысиный хвост','aspect'=>['hp'=>30],'from'=>['scavenger','rat_swarm','ghoul_miner','brute','archer']],
            'venom_gland'=>['name'=>'Ядовитая железа','aspect'=>['damage'=>4],'from'=>['spitter','thorn_archer','mire_witch','toad_brute']],
            'bell_bronze'=>['name'=>'Колокольная бронза','aspect'=>['power'=>5],'from'=>['acolyte','bell_wraith','gargoyle','flagellant']],
            'root_fiber'=>['name'=>'Корневое волокно','aspect'=>['regen'=>2],'from'=>['root_crawler','spore_bloater','rift_hound','bog_lurker']],
            'bone_dust'=>['name'=>'Костная пыль','aspect'=>['leech'=>5],'from'=>['skeleton','bone_archer','crypt_knight']],
            'soul_shard'=>['name'=>'Осколок души','aspect'=>['mana'=>30],'from'=>['lich_acolyte','wisp','mind_flayer']],
            'frost_shard'=>['name'=>'Ледяной осколок','aspect'=>['armor'=>3],'from'=>['frost_wolf','ice_golem','rime_witch','yeti']],
            'ember_core'=>['name'=>'Тлеющее ядро','aspect'=>['damage'=>2,'power'=>3],'from'=>['ash_knight','cinder_mage','hellhound','magma_brute','comet_wisp']],
            'bog_pearl'=>['name'=>'Болотная жемчужина','aspect'=>['greed'=>15],'from'=>['leech_swarm']],
            'void_dust'=>['name'=>'Пыль пустоты','aspect'=>['crit'=>5],'from'=>['void_stalker','star_spawn','void_golem']],
            'monster_heart'=>['name'=>'Сердце чудовища','aspect'=>['hp'=>15,'damage'=>2,'power'=>2,'armor'=>1],'from'=>[]],
        ];
    }

    public static function reagentOf(string $type): string
    {
        if (self::enemy($type)['boss'] ?? false) return 'monster_heart';
        foreach (self::reagents() as $key => $r) {
            if (in_array($type, $r['from'], true)) return $key;
        }

        return 'rat_tail';
    }

    /** Named alchemical reactions: these pairs make more than the sum of their aspects. */
    private const REACTIONS = [
        'ember_core+frost_shard'=>['name'=>'Эликсир пара','crit'=>6],
        'bone_dust+venom_gland'=>['name'=>'Чумной настой','thorns'=>8],
        'bell_bronze+bone_dust'=>['name'=>'Сумеречная вода','power'=>5],
        'rat_tail+root_fiber'=>['name'=>'Живая вода','regen'=>2,'hp'=>20],
        'bell_bronze+soul_shard'=>['name'=>'Эликсир ясности','mana'=>30],
        'monster_heart+void_dust'=>['name'=>'Звёздная кровь','crit'=>5,'damage'=>4],
        'bog_pearl+monster_heart'=>['name'=>'Зелье алчности','greed'=>25],
    ];

    /**
     * An elixir brewed from two reagents: the sum of both aspects, doubled for a pure pair,
     * plus a reaction bonus for the named combinations. Lasts one expedition.
     */
    public static function elixir(string $key): array
    {
        $parts = explode('+', $key);
        $reagents = self::reagents();
        if (count($parts) !== 2 || ! isset($reagents[$parts[0]], $reagents[$parts[1]])) {
            throw new DomainException('invalid_elixir');
        }
        sort($parts);
        $stats = [];
        foreach ($parts as $part) {
            foreach ($reagents[$part]['aspect'] as $stat => $value) $stats[$stat] = ($stats[$stat] ?? 0) + $value;
        }
        $reaction = self::REACTIONS[implode('+', $parts)] ?? null;
        if ($parts[0] === $parts[1]) {
            $name = 'Чистый настой: '.mb_strtolower($reagents[$parts[0]]['name']);
        } elseif ($reaction) {
            $name = $reaction['name'];
            foreach ($reaction as $stat => $value) if ($stat !== 'name') $stats[$stat] = ($stats[$stat] ?? 0) + $value;
        } else {
            $name = 'Отвар: '.mb_strtolower($reagents[$parts[0]]['name']).' и '.mb_strtolower($reagents[$parts[1]]['name']);
        }
        ksort($stats);

        return ['key'=>implode('+', $parts), 'name'=>$name, 'stats'=>$stats, 'reaction'=>$reaction !== null];
    }

    /** Every brewable elixir keyed by its normalised reagent pair. */
    public static function elixirs(): array
    {
        $keys = array_keys(self::reagents());
        $all = [];
        foreach ($keys as $i => $a) {
            foreach (array_slice($keys, $i) as $b) {
                $e = self::elixir($a.'+'.$b);
                $all[$e['key']] = $e;
            }
        }

        return $all;
    }

    public static function enemy(string $type): array {
        return self::enemies()[$type] ?? self::enemies()['scavenger'];
    }

    /**
     * A chimera fuses two species: the body of the first and the habits of the second.
     * It is tougher than either half and inherits every special ability of both.
     */
    public static function chimera(string $a, string $b): array
    {
        $x = self::enemy($a);
        $y = self::enemy($b);
        $steps = array_filter([$x['step'], $y['step']]);

        return ['name'=>$x['name'].' × '.mb_strtolower($y['name']), 'hp'=>intdiv(($x['hp'] + $y['hp']) * 5, 8),
            'damage'=>max($x['damage'], $y['damage']), 'range'=>max($x['range'], $y['range']), 'attack'=>min($x['attack'], $y['attack']),
            'step'=>$steps ? min($steps) : 0, 'xp'=>intdiv(($x['xp'] + $y['xp']) * 3, 4), 'armor'=>max($x['armor'], $y['armor']),
            'specials'=>array_values(array_unique(array_merge($x['specials'] ?? [], $y['specials'] ?? [])))];
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
            'greatsword'=>['name'=>'Двуручник','slot'=>'weapon','damage'=>7,'hp'=>2],
            'spear'=>['name'=>'Копьё','slot'=>'weapon','damage'=>5,'crit'=>2],
            'scythe'=>['name'=>'Коса','slot'=>'weapon','damage'=>4,'power'=>3,'leech'=>2],
            'claws'=>['name'=>'Когти','slot'=>'weapon','damage'=>3,'crit'=>5,'leech'=>1],
            'totem'=>['name'=>'Тотем','slot'=>'weapon','power'=>4,'hp'=>5,'regen'=>1],
            'sickle'=>['name'=>'Серп','slot'=>'weapon','damage'=>2,'power'=>2,'crit'=>3],
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
            'helm'=>['name'=>'Шлем','slot'=>'head','armor'=>2,'hp'=>4],
            'hood'=>['name'=>'Капюшон','slot'=>'head','crit'=>2,'mana'=>4],
            'circlet'=>['name'=>'Венец','slot'=>'head','power'=>3,'mana'=>4],
            'gauntlets'=>['name'=>'Латные перчатки','slot'=>'hands','damage'=>1,'armor'=>1],
            'wraps'=>['name'=>'Обмотки','slot'=>'hands','damage'=>1,'crit'=>2],
            'bracers'=>['name'=>'Наручи','slot'=>'hands','armor'=>1,'power'=>1],
            'boots'=>['name'=>'Сапоги','slot'=>'feet','armor'=>1,'hp'=>4],
            'greaves'=>['name'=>'Поножи','slot'=>'feet','armor'=>2],
            'sandals'=>['name'=>'Сандалии','slot'=>'feet','power'=>1,'mana'=>6],
        ];
    }

    /** Enchantments: per-tier bonus for scaled stats, flat (+1 per 5 tiers) for crit, leech, regen and greed. */
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
            'spiked'=>['name'=>'шипов','thorns'=>1.5],
            'renewing'=>['name'=>'обновления','regen'=>1],
            'fortune'=>['name'=>'удачи','greed'=>8],
        ];
    }

    /**
     * Forge materials. mult scales every scaled stat; flat adds to flat stats. Wearing three
     * pieces of one material grants its set bonus, five pieces double it.
     * cost: reagents consumed per forged piece (scaled by tier/5 + 1).
     */
    public static function materials(): array {
        return [
            'steel'=>['name'=>'Сталь','mult'=>['damage'=>1.2,'armor'=>1.1],'set'=>['damage'=>3],'cost'=>['rat_tail'=>1],'essence'=>0],
            'mithril'=>['name'=>'Мифрил','mult'=>['damage'=>1.15,'armor'=>1.15,'power'=>1.15,'hp'=>1.15,'mana'=>1.15],'set'=>['mana'=>25,'armor'=>2],'cost'=>['bell_bronze'=>1],'essence'=>1],
            'obsidian'=>['name'=>'Обсидиан','mult'=>['damage'=>1.35,'armor'=>0.8],'set'=>['crit'=>6],'cost'=>['ember_core'=>1],'essence'=>1],
            'bone'=>['name'=>'Кость','mult'=>['power'=>1.1],'flat'=>['leech'=>3],'set'=>['leech'=>4],'cost'=>['bone_dust'=>1],'essence'=>0],
            'frostglass'=>['name'=>'Ледостекло','mult'=>['armor'=>1.3,'mana'=>1.2],'set'=>['thorns'=>6,'armor'=>2],'cost'=>['frost_shard'=>1],'essence'=>1],
            'emberite'=>['name'=>'Жар-сталь','mult'=>['power'=>1.3,'damage'=>1.1],'set'=>['power'=>6],'cost'=>['ember_core'=>1,'venom_gland'=>1],'essence'=>1],
            'shadowsteel'=>['name'=>'Теневая сталь','mult'=>['damage'=>1.1],'flat'=>['crit'=>4],'set'=>['crit'=>5,'damage'=>2],'cost'=>['void_dust'=>1],'essence'=>2],
            'heartwood'=>['name'=>'Живое дерево','mult'=>['hp'=>1.4,'power'=>1.1],'flat'=>['regen'=>1],'set'=>['regen'=>2,'hp'=>30],'cost'=>['root_fiber'=>1],'essence'=>0],
            'bogiron'=>['name'=>'Болотное железо','mult'=>['armor'=>1.2,'hp'=>1.2],'flat'=>['greed'=>5],'set'=>['greed'=>15],'cost'=>['bog_pearl'=>1],'essence'=>1],
        ];
    }

    /** Runes are inscribed into gear; each adds a scaled or flat effect on top of the enchantment. */
    public static function runes(): array {
        return [
            'fehu'=>['name'=>'Феху','greed'=>6,'cost'=>'bog_pearl'],
            'uruz'=>['name'=>'Уруз','hp'=>5,'cost'=>'rat_tail'],
            'thurisaz'=>['name'=>'Турисаз','thorns'=>2,'cost'=>'venom_gland'],
            'ansuz'=>['name'=>'Ансуз','power'=>1,'mana'=>2,'cost'=>'soul_shard'],
            'raido'=>['name'=>'Райдо','regen'=>1,'cost'=>'root_fiber'],
            'kenaz'=>['name'=>'Кеназ','damage'=>1,'cost'=>'ember_core'],
            'isa'=>['name'=>'Иса','armor'=>1,'cost'=>'frost_shard'],
            'sowilo'=>['name'=>'Соулу','crit'=>3,'cost'=>'void_dust'],
            'algiz'=>['name'=>'Альгиз','armor'=>0.5,'hp'=>3,'cost'=>'bell_bronze'],
            'berkana'=>['name'=>'Беркана','leech'=>2,'cost'=>'bone_dust'],
        ];
    }

    /** Builds a gear key from its parts; the inverse of item(). */
    public static function itemKey(string $base, int $tier, string $material = '', string $affix = '', string $rune = ''): string
    {
        return $base.'+'.max(1, min(999, $tier)).($material !== '' ? '@'.$material : '').($affix !== '' ? '~'.$affix : '').($rune !== '' ? '!'.$rune : '');
    }

    /**
     * Resolves a named item or a generated key such as "blade+7@steel~fierce!kenaz"
     * (base + tier, optional @material, ~enchantment and !rune).
     */
    public static function item(string $key): array
    {
        $static = self::items()[$key] ?? null;
        if ($static) {
            return $static + ['icon'=>self::ICONS[$key] ?? 'ring','base'=>self::ICONS[$key] ?? 'ring','tier'=>intdiv($static['level'] + 2, 3),
                'crit'=>0,'leech'=>0,'thorns'=>0,'regen'=>0,'greed'=>0,'material'=>'','affix'=>'','rune'=>'','unique'=>true,'generated'=>false];
        }
        if (! preg_match('/^([a-z]+)\+(\d{1,3})(?:@([a-z]+))?(?:~([a-z]+))?(?:!([a-z]+))?$/', $key, $m) || ! isset(self::bases()[$m[1]])) {
            throw new DomainException('unknown_item');
        }
        $tier = max(1, (int) $m[2]);
        $base = self::bases()[$m[1]];
        $materialKey = $m[3] ?? '';
        $affixKey = $m[4] ?? '';
        $runeKey = $m[5] ?? '';
        $material = $materialKey === '' ? [] : (self::materials()[$materialKey] ?? throw new DomainException('unknown_item'));
        $affix = $affixKey === '' ? [] : (self::affixes()[$affixKey] ?? throw new DomainException('unknown_item'));
        $rune = $runeKey === '' ? [] : (self::runes()[$runeKey] ?? throw new DomainException('unknown_item'));
        $grow = 1 + 0.5 * ($tier - 1);
        $name = ($material ? $material['name'].': ' : '').$base['name'].($affix ? ' '.$affix['name'] : '').' +'.$tier.($rune ? ' ‹'.$rune['name'].'›' : '');
        $item = ['name'=>$name, 'slot'=>$base['slot'], 'icon'=>$m[1], 'base'=>$m[1], 'tier'=>$tier,
            'material'=>$materialKey, 'affix'=>$affixKey, 'rune'=>$runeKey, 'unique'=>false, 'generated'=>true];
        foreach (self::SCALED as $stat) {
            $item[$stat] = (int) round(($base[$stat] ?? 0) * $grow * ($material['mult'][$stat] ?? 1) + ($affix[$stat] ?? 0) * $tier + ($rune[$stat] ?? 0) * $tier);
        }
        foreach (self::FLAT as $stat) {
            $item[$stat] = ($base[$stat] ?? 0) + ($material['flat'][$stat] ?? 0)
                + (isset($affix[$stat]) ? (int) $affix[$stat] + intdiv($tier, 5) : 0) + (isset($rune[$stat]) ? (int) $rune[$stat] + intdiv($tier, 5) : 0);
        }
        $item['level'] = min(self::MAX_LEVEL, max(1, 3 * $tier - 3));
        $item['price'] = 4 * $tier * ($tier + 1) + ($affix ? 5 * $tier : 0) + ($material ? 6 * $tier : 0) + ($rune ? 4 * $tier : 0);

        return $item;
    }

    /** A deterministic drop: class-flavoured base half of the time, optional enchantment, deeper drops gain materials and runes. */
    public static function generate(Random $rng, int $tier, bool $affix, string $class): string
    {
        $bases = array_keys(self::bases());
        $pool = $rng->next(1, 100) <= 50 ? (self::classes()[$class]['bases'] ?? $bases) : $bases;
        $base = $pool[$rng->next(0, count($pool) - 1)];
        $affixKey = '';
        if ($affix) {
            $affixes = array_keys(self::affixes());
            $affixKey = $affixes[$rng->next(0, count($affixes) - 1)];
        }
        $material = '';
        $rune = '';
        if ($tier >= 3 && $rng->next(1, 100) <= min(45, 5 + 2 * $tier)) {
            $materials = array_keys(self::materials());
            $material = $materials[$rng->next(0, count($materials) - 1)];
        }
        if ($tier >= 5 && $rng->next(1, 100) <= min(30, 2 * $tier)) {
            $runes = array_keys(self::runes());
            $rune = $runes[$rng->next(0, count($runes) - 1)];
        }

        return self::itemKey($base, $tier, $material, $affixKey, $rune);
    }

    public static function forgeCost(int $tier, bool $affix): array
    {
        return ['gold'=>6 * $tier * $tier + 8 * $tier, 'materials'=>2 + $tier, 'essence'=>intdiv($tier, 4) + ($affix ? 1 : 0)];
    }

    /** Reagents a material or rune adds to a forging at this tier. */
    public static function reagentCost(int $tier, string $material = '', string $rune = ''): array
    {
        $need = [];
        $count = 1 + intdiv($tier, 5);
        if ($material !== '') {
            foreach (self::materials()[$material]['cost'] ?? [] as $key => $n) $need[$key] = ($need[$key] ?? 0) + $n * $count;
        }
        if ($rune !== '') {
            $key = self::runes()[$rune]['cost'];
            $need[$key] = ($need[$key] ?? 0) + $count;
        }
        ksort($need);

        return $need;
    }

    public static function upgradeCost(int $tier): array
    {
        return ['gold'=>4 * ($tier + 1) * ($tier + 1), 'materials'=>1 + $tier, 'essence'=>intdiv($tier + 1, 5)];
    }

    public static function enchantCost(int $tier): array
    {
        return ['gold'=>5 * $tier + 10, 'materials'=>0, 'essence'=>1 + intdiv($tier, 4)];
    }

    public static function inscribeCost(int $tier): array
    {
        return ['gold'=>4 * $tier + 15, 'materials'=>1 + intdiv($tier, 3), 'essence'=>intdiv($tier, 6)];
    }

    /** Highest tier the smith can forge for a hero of this level and deepest floor reached. */
    public static function forgeLimit(int $level, int $depth): int
    {
        return max(2, intdiv($level + 2, 3) + 1, intdiv($depth, 2) + 1);
    }

    /** Dungeon furniture: interact with [F] next to it. Traps fire when stepped on. */
    public static function objects(): array {
        return [
            'chest'=>['name'=>'Сундук','description'=>'Кроны, зелье, реагент и иногда снаряжение.'],
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
            'miner_helm'=>$r(12,2),'wool_wraps'=>$r(14,2),'road_boots'=>$r(12,2),'pilgrim_sandals'=>$r(22,4),'sage_bracers'=>$r(40,6,1),
            'reaper_sickle'=>$r(62,9,2),'knight_greaves'=>$r(70,10,3),'warlord_greatsword'=>$r(110,15,5),
            'bog_scythe'=>$r(120,16,6),'mire_hood'=>$r(110,15,5),'toad_boots'=>$r(105,15,5),'witch_totem'=>$r(120,16,6),
            'void_claws'=>$r(160,22,9),'star_crown'=>$r(150,21,8),'abyss_gauntlets'=>$r(150,21,8),'comet_spear'=>$r(165,22,9),
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
            'bloodlust'=>['name'=>'Жажда крови','class'=>'berserker','stat'=>'leech','amount'=>1],
            'warcry'=>['name'=>'Боевой клич','class'=>'berserker','stat'=>'damage_bonus','amount'=>3],
            'hide'=>['name'=>'Дублёная шкура','class'=>'berserker','stat'=>'max_hp','amount'=>25],
            'shadowblade'=>['name'=>'Теневой клинок','class'=>'assassin','stat'=>'crit','amount'=>2],
            'cutthroat'=>['name'=>'Головорез','class'=>'assassin','stat'=>'damage_bonus','amount'=>3],
            'evasion'=>['name'=>'Неуловимость','class'=>'assassin','stat'=>'armor','amount'=>1],
            'gravecaller'=>['name'=>'Зов могил','class'=>'necromancer','stat'=>'power','amount'=>4],
            'bonecage'=>['name'=>'Костяная клеть','class'=>'necromancer','stat'=>'thorns','amount'=>2],
            'deathpact'=>['name'=>'Посмертный договор','class'=>'necromancer','stat'=>'max_mana','amount'=>15],
            'grove'=>['name'=>'Роща','class'=>'druid','stat'=>'regen','amount'=>1],
            'barkskin'=>['name'=>'Кора','class'=>'druid','stat'=>'armor','amount'=>1],
            'wildheart'=>['name'=>'Дикое сердце','class'=>'druid','stat'=>'power','amount'=>4],
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
            'rusty_axe' => $i('Ржавая секира','weapon',4,0,0,8,1,5),
            'shiv' => $i('Заточка','weapon',3,0,0,8,1),
            'grave_wand' => $i('Жезл могильщика','weapon',1,0,5,8,1,0,5),
            'oak_totem' => $i('Дубовый тотем','weapon',1,0,4,8,1,8),
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
            'reaper_sickle' => $i('Серп жнеца','weapon',9,0,9,58,7),
            'frost_bow' => $i('Ледяной лук','weapon',14,0,3,66,9),
            'glacier_edge' => $i('Ледниковая сталь','weapon',16,0,0,70,9),
            'ember_scepter' => $i('Скипетр углей','weapon',6,0,24,85,11),
            'warlord_greatsword' => $i('Двуручник полководца','weapon',21,0,0,100,12,20),
            'tyrant_blade' => $i('Клинок тирана','weapon',22,0,4,120,13),
            'bog_scythe' => $i('Болотная коса','weapon',16,0,16,120,14),
            'witch_totem' => $i('Тотем ведьмы','weapon',4,0,30,120,14,30),
            'void_claws' => $i('Когти пустоты','weapon',26,0,6,160,17),
            'comet_spear' => $i('Кометное копьё','weapon',28,0,4,165,17,20),
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
            'void_heart' => $i('Печать Владыки пустоты','ring',10,4,10,200,18,30),
            // amulets
            'amber_amulet' => $i('Янтарный амулет','amulet',0,0,0,10,2,15),
            'prior_icon' => $i('Икона приора','amulet',0,1,6,45,3,0,10),
            'heart_seed' => $i('Семя Сердца','amulet',0,0,4,55,5,30),
            'wolf_fang' => $i('Волчий клык','amulet',3,0,0,36,6,10),
            'frost_pendant' => $i('Слеза королевы','amulet',0,0,10,80,9,25),
            'phoenix_feather' => $i('Перо феникса','amulet',4,0,8,140,12,40),
            'mother_eye' => $i('Око Матери топей','amulet',6,2,12,160,15,40),
            // 0.6: head, hands and feet
            'miner_helm' => $i('Шахтёрская каска','head',0,2,0,10,2,5),
            'mire_hood' => $i('Капюшон топи','head',4,2,6,110,14,0,20),
            'star_crown' => $i('Звёздный венец','head',0,3,16,150,17,0,30),
            'wool_wraps' => $i('Шерстяные обмотки','hands',1,1,0,10,2),
            'sage_bracers' => $i('Наручи мудреца','hands',0,2,5,40,6,0,10),
            'abyss_gauntlets' => $i('Перчатки бездны','hands',8,5,0,150,17),
            'road_boots' => $i('Дорожные сапоги','feet',0,1,0,10,2,10),
            'pilgrim_sandals' => $i('Сандалии пилигрима','feet',0,1,3,22,3,0,15),
            'knight_greaves' => $i('Рыцарские поножи','feet',0,6,0,70,9,15),
            'toad_boots' => $i('Сапоги из жабьей кожи','feet',0,5,4,105,14,25),
        ];
    }

    /**
     * kind: bolt (one target), nova (all around), chain (three nearest), meteor (target + splash),
     * heal, barrier, whirl, blink, wave (line), drain, rage (self fury), root (area freeze),
     * sanctuary (heal every ally around). school: see schools().
     */
    public static function spells(): array
    {
        return [
            'firebolt'=>['name'=>'Огненная стрела','school'=>'fire','kind'=>'bolt','level'=>1,'mana'=>12,'cooldown'=>15,'range'=>6,'damage'=>20,'key'=>'4','description'=>'Удар по ближайшему видимому врагу в радиусе 6 клеток.'],
            'mend'=>['name'=>'Исцеление','school'=>'holy','kind'=>'heal','level'=>1,'mana'=>18,'cooldown'=>40,'range'=>0,'heal'=>35,'key'=>'5','description'=>'Восстанавливает здоровье заклинателя; целитель лечит союзника рядом.'],
            'frost'=>['name'=>'Ледяное копьё','school'=>'frost','kind'=>'bolt','level'=>2,'mana'=>16,'cooldown'=>25,'range'=>5,'damage'=>15,'freeze'=>20,'key'=>'6','description'=>'Урон и остановка противника на 2 секунды.'],
            'nova'=>['name'=>'Кольцо пламени','school'=>'fire','kind'=>'nova','level'=>4,'mana'=>30,'cooldown'=>60,'range'=>3,'damage'=>28,'key'=>'7','description'=>'Поражает всех видимых врагов в радиусе 3 клеток.'],
            'venom'=>['name'=>'Ядовитый шип','school'=>'nature','kind'=>'bolt','level'=>3,'mana'=>14,'cooldown'=>20,'range'=>6,'damage'=>10,'poison'=>5,'key'=>'8','description'=>'Слабый удар и яд: 5 урона в секунду на 6 секунд.'],
            'chain'=>['name'=>'Цепная молния','school'=>'storm','kind'=>'chain','level'=>6,'mana'=>28,'cooldown'=>45,'range'=>6,'damage'=>24,'key'=>'9','description'=>'Бьёт до трёх ближайших видимых врагов в радиусе 6.'],
            'barrier'=>['name'=>'Святой барьер','school'=>'holy','kind'=>'barrier','level'=>8,'mana'=>30,'cooldown'=>90,'range'=>3,'duration'=>40,'key'=>'0','description'=>'Вы и союзники в радиусе 3 получают вдвое меньше урона 4 секунды.'],
            'meteor'=>['name'=>'Метеор','school'=>'fire','kind'=>'meteor','level'=>12,'mana'=>45,'cooldown'=>90,'range'=>6,'damage'=>45,'key'=>'Q','description'=>'45 урона по цели и половина — всем врагам рядом с ней.'],
            'whirlwind'=>['name'=>'Вихрь клинков','school'=>'war','kind'=>'whirl','level'=>3,'mana'=>15,'cooldown'=>30,'range'=>1,'damage'=>14,'key'=>'V','description'=>'Удар оружием по всем соседним врагам: 14 + бонус урона.'],
            'blink'=>['name'=>'Скачок','school'=>'storm','kind'=>'blink','level'=>5,'mana'=>12,'cooldown'=>50,'range'=>3,'key'=>'Z','description'=>'Мгновенный рывок до 3 клеток в сторону взгляда.'],
            'fire_wave'=>['name'=>'Огненная волна','school'=>'fire','kind'=>'wave','level'=>7,'mana'=>24,'cooldown'=>40,'range'=>5,'damage'=>26,'key'=>'C','description'=>'Пламя бьёт всех врагов на линии в 5 клеток по направлению взгляда.'],
            'drain_life'=>['name'=>'Похищение жизни','school'=>'blood','kind'=>'drain','level'=>9,'mana'=>22,'cooldown'=>35,'range'=>5,'damage'=>18,'key'=>'G','description'=>'Вытягивает здоровье цели и лечит заклинателя на ту же величину.'],
            'blood_rage'=>['name'=>'Кровавая ярость','school'=>'blood','kind'=>'rage','level'=>4,'mana'=>20,'cooldown'=>80,'range'=>0,'duration'=>100,'key'=>'B','description'=>'10 секунд +5 к урону, как у алтаря ярости.'],
            'entangle'=>['name'=>'Хватка корней','school'=>'nature','kind'=>'root','level'=>6,'mana'=>26,'cooldown'=>70,'range'=>3,'damage'=>8,'freeze'=>25,'key'=>'N','description'=>'Корни держат всех видимых врагов в радиусе 3 на 2,5 секунды.'],
            'bone_spear'=>['name'=>'Костяное копьё','school'=>'shadow','kind'=>'wave','level'=>8,'mana'=>26,'cooldown'=>40,'range'=>6,'damage'=>32,'key'=>'M','description'=>'Пронзает всех врагов на линии в 6 клеток по направлению взгляда.'],
            'sanctuary'=>['name'=>'Святилище','school'=>'holy','kind'=>'sanctuary','level'=>10,'mana'=>40,'cooldown'=>120,'range'=>3,'heal'=>25,'key'=>'H','description'=>'Исцеляет вас и всех союзников в радиусе 3 — для больших групп.'],
        ];
    }

    /** Mana cost for a hero: a quarter less in one of the hero's schools. */
    public static function spellCost(array $spell, array $schools): int
    {
        return in_array($spell['school'] ?? '', $schools, true) ? intdiv($spell['mana'] * 3, 4) : $spell['mana'];
    }

    /** Town bounty board: slay a kind of foe, extract, then claim the reward. */
    public static function bounty(int $campaign, int $roll, int $size): array
    {
        $areas = self::biomes();
        $open=array_values(array_filter($areas,fn($b)=>$b['chapter']<=min($campaign,count($areas)-1)));
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

    /** Set bonuses of the equipped generated gear: [material => pieces] for every material worn three times or more. */
    public static function sets(array $equipment): array
    {
        $count = [];
        foreach ($equipment as $key) {
            $material = self::item($key)['material'];
            if ($material !== '') $count[$material] = ($count[$material] ?? 0) + 1;
        }
        ksort($count);

        return array_filter($count, static fn ($n) => $n >= 3);
    }

    /**
     * extra: origin, mentor and elixir (a brewed reagent pair). Every source of stats lands in
     * the same profile keys, so a build is just the sum of its choices.
     */
    public static function profile(string $name, string $class, int $xp, array $attributes, array $equipment, array $extra = []): array
    {
        $level=self::progression($xp)['level'];
        $classes = self::classes();
        $def = $classes[$class] ?? $classes['guardian'];
        // Heroes from before 0.6 (and bare test profiles) have no origin and no origin bonus.
        $origin = isset(self::origins()[$extra['origin'] ?? '']) ? $extra['origin'] : '';
        $mentor = ($extra['mentor'] ?? null) !== null && $level >= self::MENTOR_LEVEL ? $extra['mentor'] : null;
        $sum = ['damage'=>($attributes['strength']??0)*2, 'armor'=>0, 'power'=>($attributes['intellect']??0)*3, 'hp'=>0, 'mana'=>0,
            'crit'=>0, 'leech'=>0, 'thorns'=>0, 'regen'=>0, 'greed'=>0];
        $add = static function (array $stats) use (&$sum): void {
            foreach ($stats as $stat => $value) if (isset($sum[$stat]) && is_numeric($value)) $sum[$stat] += (int) $value;
        };
        foreach ($equipment as $key) $add(self::item($key));
        $sets = [];
        foreach (self::sets($equipment) as $material => $pieces) {
            $bonus = self::materials()[$material]['set'];
            $add($bonus);
            if ($pieces >= 5) $add($bonus);
            $sets[] = self::materials()[$material]['name'].' ('.$pieces.')';
        }
        $add($def['bonus']);
        if ($origin !== '') $add(self::origins()[$origin]);
        $elixir = null;
        if (! empty($extra['elixir'])) {
            $brew = self::elixir($extra['elixir']);
            $add($brew['stats']);
            $elixir = $brew['name'];
        }
        $build = self::build($class, $origin, $mentor);
        return ['name'=>$name,'class_id'=>$class,'origin'=>$origin,'mentor'=>$mentor,'level'=>$level,
            'max_hp'=>100+($level-1)*10+($attributes['vitality']??0)*10+$sum['hp'],
            'max_mana'=>$def['mana']+($level-1)*5+($attributes['intellect']??0)*5+$sum['mana'],
            'damage_bonus'=>$sum['damage'],'armor'=>$sum['armor'],'power'=>$sum['power'],'crit'=>min(50,$sum['crit']),'leech'=>min(30,$sum['leech']),
            'thorns'=>$sum['thorns'],'regen'=>min(20,$sum['regen']),'greed'=>min(200,$sum['greed']),
            'traits'=>$build['traits'],'schools'=>$build['schools'],'sets'=>$sets,'elixir'=>$elixir,'equipment'=>$equipment];
    }
}
