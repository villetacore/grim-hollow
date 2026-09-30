<?php
declare(strict_types=1);
namespace GrimHollow\Core;

/** Shared, versioned game rules; clients display these values, never decide them. */
final class Catalog
{
    public static function biomes(): array {
        return [
            'mines'=>['name'=>'Затопленные шахты','chapter'=>0,'enemy_hp'=>0,'reward'=>1,'boss'=>'Смотритель глубин','story'=>'Верните первый огонь из шахт. Победите смотрителя на третьем этаже и эвакуируйтесь.'],
            'monastery'=>['name'=>'Монастырь без колокола','chapter'=>1,'enemy_hp'=>10,'reward'=>2,'boss'=>'Безмолвный приор','story'=>'Первый огонь горит. Найдите второй в покинутом монастыре и победите приора.'],
            'roots'=>['name'=>'Корневая бездна','chapter'=>2,'enemy_hp'=>20,'reward'=>3,'boss'=>'Сердце Разлома','story'=>'Два огня защищают город. Спуститесь к Сердцу Разлома и верните последний огонь.'],
        ];
    }
    public static function recipes(): array {
        return ['steel_sword'=>['gold'=>15,'materials'=>3],'chainmail'=>['gold'=>12,'materials'=>3],
            'ash_staff'=>['gold'=>20,'materials'=>4],'warden_shield'=>['gold'=>30,'materials'=>6]];
    }
    public static function talents():array {
        return [
            'bulwark'=>['name'=>'Щитоносец','class'=>'guardian','stat'=>'armor','amount'=>1],
            'executioner'=>['name'=>'Каратель','class'=>'guardian','stat'=>'damage_bonus','amount'=>3],
            'stormcaller'=>['name'=>'Буревестник','class'=>'arcanist','stat'=>'max_mana','amount'=>15],
            'ashen'=>['name'=>'Пепельник','class'=>'arcanist','stat'=>'power','amount'=>4],
            'marksman'=>['name'=>'Стрелок','class'=>'ranger','stat'=>'damage_bonus','amount'=>3],
            'trapper'=>['name'=>'Ловчий','class'=>'ranger','stat'=>'max_hp','amount'=>15],
            'healer'=>['name'=>'Целитель','class'=>'warden','stat'=>'power','amount'=>4],
            'sealkeeper'=>['name'=>'Хранитель печатей','class'=>'warden','stat'=>'armor','amount'=>1],
        ];
    }
    public static function items(): array
    {
        return [
            'iron_sword' => ['name'=>'Железный меч','slot'=>'weapon','damage'=>3,'armor'=>0,'power'=>0,'price'=>8,'level'=>1],
            'oak_staff' => ['name'=>'Дубовый посох','slot'=>'weapon','damage'=>1,'armor'=>0,'power'=>5,'price'=>8,'level'=>1],
            'hunting_bow' => ['name'=>'Охотничий лук','slot'=>'weapon','damage'=>3,'armor'=>0,'power'=>2,'price'=>8,'level'=>1],
            'healer_mace' => ['name'=>'Булава хранителя','slot'=>'weapon','damage'=>2,'armor'=>0,'power'=>4,'price'=>8,'level'=>1],
            'leather' => ['name'=>'Кожаный доспех','slot'=>'body','damage'=>0,'armor'=>1,'power'=>0,'price'=>6,'level'=>1],
            'buckler' => ['name'=>'Круглый щит','slot'=>'offhand','damage'=>0,'armor'=>1,'power'=>0,'price'=>6,'level'=>1],
            'ember_ring' => ['name'=>'Кольцо углей','slot'=>'ring','damage'=>0,'armor'=>0,'power'=>3,'price'=>12,'level'=>1],
            'steel_sword' => ['name'=>'Стальной клинок','slot'=>'weapon','damage'=>7,'armor'=>0,'power'=>0,'price'=>24,'level'=>2],
            'chainmail' => ['name'=>'Кольчуга шахтёра','slot'=>'body','damage'=>0,'armor'=>3,'power'=>0,'price'=>22,'level'=>2],
            'ash_staff' => ['name'=>'Посох пепла','slot'=>'weapon','damage'=>2,'armor'=>0,'power'=>10,'price'=>30,'level'=>3],
            'warden_shield' => ['name'=>'Щит смотрителя','slot'=>'offhand','damage'=>0,'armor'=>4,'power'=>2,'price'=>40,'level'=>3],
        ];
    }

    public static function spells(): array
    {
        return [
            'firebolt'=>['name'=>'Огненная стрела','level'=>1,'mana'=>12,'cooldown'=>15,'range'=>6,'damage'=>20,'description'=>'Удар по ближайшему видимому врагу в радиусе 6 клеток.'],
            'mend'=>['name'=>'Исцеление','level'=>1,'mana'=>18,'cooldown'=>40,'range'=>0,'heal'=>35,'description'=>'Восстанавливает здоровье заклинателя.'],
            'frost'=>['name'=>'Ледяное копьё','level'=>2,'mana'=>16,'cooldown'=>25,'range'=>5,'damage'=>15,'description'=>'Урон и остановка противника на 2 секунды.'],
            'nova'=>['name'=>'Кольцо пламени','level'=>4,'mana'=>30,'cooldown'=>60,'range'=>3,'damage'=>28,'description'=>'Поражает всех видимых врагов в радиусе 3 клеток.'],
        ];
    }

    public static function progression(int $xp): array
    {
        $level=1;
        while ($level<20 && $xp>=self::threshold($level+1)) $level++;
        return ['level'=>$level,'level_xp'=>self::threshold($level),'next_level_xp'=>$level<20 ? self::threshold($level+1) : null];
    }
    private static function threshold(int $level): int { return 20*($level-1)*$level; }

    public static function profile(string $name, string $class, int $xp, array $attributes, array $equipment): array
    {
        $level=self::progression($xp)['level'];
        $damage=($attributes['strength']??0)*2; $armor=0; $power=($attributes['intellect']??0)*3;
        foreach ($equipment as $key) {
            $item=self::items()[$key]; $damage+=$item['damage']; $armor+=$item['armor']; $power+=$item['power'];
        }
        if ($class==='ranger') $damage+=2;
        if ($class==='warden') $power+=3;
        return ['name'=>$name,'class_id'=>$class,'level'=>$level,
            'max_hp'=>100+($level-1)*10+($attributes['vitality']??0)*10,
            'max_mana'=>match($class){'arcanist'=>100,'warden'=>80,'ranger'=>70,default=>60}+($level-1)*5+($attributes['intellect']??0)*5,
            'damage_bonus'=>$damage,'armor'=>$armor,'power'=>$power,'equipment'=>$equipment];
    }
}
