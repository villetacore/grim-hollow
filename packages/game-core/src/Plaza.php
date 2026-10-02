<?php
declare(strict_types=1);
namespace GrimHollow\Core;

/**
 * The town of Ashen Reach as a shared walkable square: a fixed map, buildings, service
 * keepers and townsfolk who walk their rounds on the wall clock. No monsters ever enter.
 *
 * Map legend. Walkable: . road  , grass  : paved square  * flowers  + Rift gate.
 * Blocked: # wall  ~ water  T tree  o lamp  % market stall  & fountain; capital letters are
 * building footprints (see buildings()).
 */
final class Plaza
{
    public const WIDTH = 56;
    public const HEIGHT = 36;
    /** Walking pace: one step per this many milliseconds (the client predicts the same pace). */
    public const STEP_MS = 110;
    /** A hero who stops polling leaves the square after this long. */
    public const PRESENCE_MS = 15000;
    public const SPAWN = [27, 10];
    public const EMOTES = ['wave'=>'машет рукой', 'bow'=>'кланяется', 'cheer'=>'ликует', 'dance'=>'танцует', 'sit'=>'садится отдохнуть'];

    private const MAP = [
        '##########################++++##########################',
        '#,,,,,,,,,,,,,,,,,,,,,,,,,....,,,,,,,,,,,,,,,,,,,,,,,,,#',
        '#,,,,T,,,,*,T,,,,,,BBBBBB,....,LLLLLLL,,,,T,,,,,T,,,,,,#',
        '#,,,,,,,T,,,,,,,,,,BBBBBB,....,LLLLLLL,,,,,,*,,*,,,T,,,#',
        '#,,,,,,*,*,,,,,T,,,BBBBBB,....,LLLLLLL,,,,,,,T,,,,,,,,,#',
        '#,,,,,,,,,o,,,,,,,,BBBBBB,....,LLLLLLL,,,,,,,o,,,,,,,,,#',
        '#,,..................................................,,#',
        '#,,.,,,,,,,,,,,,,,,,,..,,,....,,,,.,,,,,,,,,,,,,,,,,.,,#',
        '#,,.,,,,,,,,,,T,,,,,,,,,,o....o,,,,,,,,,,T,,,,,,,,,,.,,#',
        '#,,.,,FFFFFFF,,AAAAAAA,,,,....,,,,GGGGGGG,,%%%%%%%,,.,,#',
        '#,,.,,FFFFFFF,,AAAAAAA,,*,....,*,,GGGGGGG,,,,,,,,,,,.,,#',
        '#,,.,,FFFFFFF,,AAAAAAA,,,,....,,,,GGGGGGG,,%%%%%%%,,.,,#',
        '#,,.,,FFFFFFF,,AAAAAAA,,,,....,,,,GGGGGGG,,,,,,,,,,,.,,#',
        '#,,.,,FFFFFFF,,AAAAAAA::::::::::::GGGGGGG,,%%%%%%%,,.,,#',
        '#,,.,,FFFFFFF,,AAAAAAA::::::::::::GGGGGGG,,,,,,,,,,,.,,#',
        '#,,.,,,,,.,,,,,,,,.,,::::::::::::::,,.,,,,,,,,.,,,,,.,,#',
        '#,,.,T,,,.,,,,,,,,.,o::::::::::::::o,.,,,,,,,,.,,,T,.,,#',
        '#....................::::::&&::::::....................#',
        '#....................::::::&&::::::....................#',
        '#,,.,T,,,.,,,,,,,,.,o::::::::::::::o,,.,,,,,,,,,,,T,.,,#',
        '#,,.,,,,,.,,,,,,,,.,,::::::::::::::,,,.,,,,,,,,,,,,,.,,#',
        '#,,.,,IIIIIII,,HHHHHHH::::::::::::RRRRRRRRR,,,,,,,,,.,,#',
        '#,,.,,IIIIIII,,HHHHHHH::::::::::::RRRRRRRRR,,~~~~~~,.,,#',
        '#,,.,,IIIIIII,,HHHHHHH,,,,....,,,,RRRRRRRRR,T~~~~~~,.,,#',
        '#,,.,,IIIIIII,,HHHHHHH,*,,....,,*,RRRRRRRRR,,~~~~~~,.,,#',
        '#,,.,,IIIIIII,,HHHHHHH,,,,....,,,,RRRRRRRRR,,~~~~~~,.,,#',
        '#,,.,,IIIIIII,,HHHHHHH,T,,....,,T,RRRRRRRRR,,~~~~~~,.,,#',
        '#,,.,,,,,,,,,,,,,,,,,,,,,o....o,,,RRRRRRRRR,,,,,,,,,.,,#',
        '#,,.,,,,,,,,T,,,,,,,,,,,,,....,,,,,,,,,,,,,T,,,,,,,,.,,#',
        '#,,..................................................,,#',
        '#,,,,,,,,,o,,,,,,,,,..,,,....,:::::::::::,,,o,,,,,,,,,,#',
        '#,,,,,*,,,,,,T,,,,,KKKKKK,....,:::::::::::,,,T,,,,,T,,,#',
        '#,,,,T,,*,,,,,,,,,,KKKKKK,....,:::::::::::,,,,,*,,*,,,,#',
        '#,,,,,,,,T,,,,,,T,,KKKKKK,....,:::::::::::,,,,,,,T,,,,,#',
        '#,,,,,,,,,,,,,,,,,,,,,,,,,....,,,,,,,,,,,,,,,,,,,,,,,,,#',
        '########################################################',
    ];

    public static function map(): array
    {
        return self::MAP;
    }

    /** Labels and roofs for the client; letter is the footprint character on the map. */
    public static function buildings(): array
    {
        return [
            ['letter'=>'F', 'name'=>'Кузница', 'x'=>6, 'y'=>9, 'w'=>7, 'h'=>6],
            ['letter'=>'A', 'name'=>'Алхимическая лавка', 'x'=>15, 'y'=>9, 'w'=>7, 'h'=>6],
            ['letter'=>'G', 'name'=>'Зал гильдий', 'x'=>34, 'y'=>9, 'w'=>7, 'h'=>6],
            ['letter'=>'I', 'name'=>'Таверна «Последний огонь»', 'x'=>6, 'y'=>21, 'w'=>7, 'h'=>6],
            ['letter'=>'H', 'name'=>'Храм трёх огней', 'x'=>15, 'y'=>21, 'w'=>7, 'h'=>6],
            ['letter'=>'R', 'name'=>'Арена', 'x'=>34, 'y'=>21, 'w'=>9, 'h'=>7],
            ['letter'=>'B', 'name'=>'Ратуша', 'x'=>19, 'y'=>2, 'w'=>6, 'h'=>4],
            ['letter'=>'L', 'name'=>'Зал наставников', 'x'=>31, 'y'=>2, 'w'=>7, 'h'=>4],
            ['letter'=>'K', 'name'=>'Библиотека', 'x'=>19, 'y'=>31, 'w'=>6, 'h'=>3],
        ];
    }

    /**
     * Keepers stand still and block their cell. service tells the client what talking opens.
     */
    public static function npcs(): array
    {
        return [
            ['id'=>'smith', 'name'=>'Кузнец Борн', 'kind'=>'smith', 'service'=>'forge', 'x'=>9, 'y'=>15, 'lines'=>[
                'Любая основа, любой металл. Принесёшь ядро углей — скую обсидиан.',
                'Три вещи из одного металла поют в лад. Пять — уже хор.',
                'Руны режу по коже, железу и кости. Главное — реагент под руну.',
                'Улучшить можно что угодно, кроме именного. Именное уже закончено.']],
            ['id'=>'alchemist', 'name'=>'Алхимик Сельма', 'kind'=>'alchemist', 'service'=>'alchemy', 'x'=>18, 'y'=>15, 'lines'=>[
                'Два реагента — один эликсир. Пар из льда и огня ещё никого не подвёл.',
                'Чистый настой из двух одинаковых — вдвое крепче. Но скучнее.',
                'Эликсир держится один поход. Потом — снова ко мне.',
                'Принеси кость и яд — сварю чуму, что колет обидчика.']],
            ['id'=>'guildmaster', 'name'=>'Мастер гильдий Оден', 'kind'=>'noble', 'service'=>'guild', 'x'=>37, 'y'=>15, 'lines'=>[
                'Гильдия — это те, кто вытащит тебя с восьмой глубины.',
                'Группа до восьми человек. Больше — уже армия, а армию Разлом чует.',
                'Друзей записывают здесь же. Без печати, на честном слове.']],
            ['id'=>'merchant', 'name'=>'Торговка Ива', 'kind'=>'merchant', 'service'=>'market', 'x'=>46, 'y'=>15, 'lines'=>[
                'Доска торгов открыта. Пять процентов — гильдии торговцев, остальное — тебе.',
                'Привязанное не продам. Разбери в кузнице — металл всегда в цене.',
                'Жемчуг с топей? Неси, неси. Золото к золоту.']],
            ['id'=>'innkeeper', 'name'=>'Трактирщик Гром', 'kind'=>'innkeeper', 'service'=>'inn', 'x'=>9, 'y'=>20, 'lines'=>[
                'Говорят, на глубине сорок химеры сплетаются по трое. Врут, наверное.',
                'Под Кровавой луной золото течёт рекой. И кровь тоже.',
                'Видел я одного орка-некроманта с наставником-берсерком. Страшное дело.',
                'В топи пиявки размножаются быстрее, чем их жгут. Бери огонь.',
                'Кто уходит вовремя — тот пьёт у меня завтра.',
                'Делящихся бей магией издалека: осколки злее, чем кажутся.',
                'Заговорённые глушат магию. Тут нужен топор, а не посох.',
                'В Звёздной бездне видят только во тьме. Или тьма видит тебя.']],
            ['id'=>'priest', 'name'=>'Жрица трёх огней', 'kind'=>'priest', 'service'=>'temple', 'x'=>18, 'y'=>20, 'lines'=>[
                'Огни помнят каждый твой выбор. И прощают — бесплатно.',
                'Таланты — одно очко за три уровня. Выбирай путь, но не бойся свернуть.',
                'Святилище в походе лечит всех рядом. В большой группе — бесценно.']],
            ['id'=>'arena_master', 'name'=>'Распорядитель арены', 'kind'=>'noble', 'service'=>'arena', 'x'=>38, 'y'=>20, 'lines'=>[
                'Один на один, три минуты, рейтинг Эло. Золото на арене не звенит.',
                'Хочешь проверить, чей наставник лучше? Выходи.',
                'Урон по героям здесь ниже — чтобы бой длился дольше двух ударов.']],
            ['id'=>'herald', 'name'=>'Глашатай ратуши', 'kind'=>'herald', 'service'=>'bounty', 'x'=>21, 'y'=>7, 'lines'=>[
                'Доска контрактов! Убей, вернись живым, получи награду!',
                'Химера считается за обе свои половины. Так велит указ.',
                'Город платит только за тех, кто вернулся с трофеями.']],
            ['id'=>'mentor', 'name'=>'Наставница Кайра', 'kind'=>'mentor', 'service'=>'mentor', 'x'=>34, 'y'=>7, 'lines'=>[
                'С десятого уровня возьми наставника другого класса: его черты станут твоими.',
                'Страж с наставником-убийцей? Почему бы и нет. Восемь классов — шестьдесят четыре пути.',
                'Школы наставника тоже твои: их заклинания стоят на четверть меньше маны.']],
            ['id'=>'librarian', 'name'=>'Летописец Ульрих', 'kind'=>'scholar', 'service'=>'library', 'x'=>21, 'y'=>30, 'lines'=>[
                'В бестиарии сорок видов существ. А химер — больше тысячи.',
                'Знамения этажа видны в начале глубины. Читай летопись похода.',
                'Каждое свойство врага — отдельная строка в имени. Учись читать имена.']],
            ['id'=>'gatekeeper', 'name'=>'Страж Врат Разлома', 'kind'=>'guard', 'service'=>'gate', 'x'=>25, 'y'=>1, 'lines'=>[
                'За воротами — Разлом. Собери группу и назови мне область.',
                'Восемь мест в отряде. Чем больше вас, тем злее то, что ждёт внизу.',
                'Вернётесь с добычей — город запомнит.']],
            ['id'=>'fortune', 'name'=>'Гадалка Мирра', 'kind'=>'seer', 'service'=>'omens', 'x'=>32, 'y'=>14, 'lines'=>[]],
            ['id'=>'bard', 'name'=>'Бард Лютик', 'kind'=>'bard', 'service'=>'inn', 'x'=>23, 'y'=>21, 'lines'=>[
                '♪ Три огня горят над Пределом, три огня — и город цел… ♪',
                '♪ Спустился смотритель в шахты, а вышел — только щит… ♪',
                '♪ Матерь топей, матерь топей, сколько ж у тебя детей… ♪',
                '♪ Не смотри в звёздную бездну — она уже смотрит в ответ… ♪']],
            ['id'=>'trainer', 'name'=>'Оружейник Дрейк', 'kind'=>'guard', 'service'=>'dummy', 'x'=>32, 'y'=>31, 'lines'=>[
                'Манекены терпят всё. Посмотри, сколько ты на самом деле бьёшь.']],
            ['id'=>'dummy1', 'name'=>'Соломенный манекен', 'kind'=>'dummy', 'service'=>'dummy', 'x'=>35, 'y'=>32, 'lines'=>[]],
            ['id'=>'dummy2', 'name'=>'Латный манекен', 'kind'=>'dummy', 'service'=>'dummy', 'x'=>37, 'y'=>32, 'lines'=>[]],
            ['id'=>'dummy3', 'name'=>'Манекен мага', 'kind'=>'dummy', 'service'=>'dummy', 'x'=>39, 'y'=>32, 'lines'=>[]],
        ];
    }

    /**
     * Townsfolk on rounds. route: axis-aligned waypoints of a closed loop; ms: time per step;
     * offset: steps ahead of the loop's start. Walkers never block anyone.
     */
    public static function walkers(): array
    {
        $ring = [[3, 6], [52, 6], [52, 29], [3, 29], [3, 6]];

        return [
            ['id'=>'guard1', 'name'=>'Городской стражник', 'kind'=>'guard', 'route'=>$ring, 'ms'=>650, 'offset'=>0],
            ['id'=>'dog', 'name'=>'Пёс стражи', 'kind'=>'dog', 'route'=>$ring, 'ms'=>650, 'offset'=>-2],
            ['id'=>'guard2', 'name'=>'Городская стражница', 'kind'=>'guard', 'route'=>$ring, 'ms'=>650, 'offset'=>70],
            ['id'=>'child', 'name'=>'Сорванец Пип', 'kind'=>'child', 'route'=>[[22, 15], [33, 15], [33, 20], [22, 20], [22, 15]], 'ms'=>380, 'offset'=>0],
            ['id'=>'farmer', 'name'=>'Возчик Тит', 'kind'=>'townsfolk', 'route'=>[[1, 17], [20, 17], [20, 18], [1, 18], [1, 17]], 'ms'=>900, 'offset'=>10],
            ['id'=>'peddler', 'name'=>'Разносчик Фрол', 'kind'=>'merchant', 'route'=>[[35, 17], [54, 17], [54, 18], [35, 18], [35, 17]], 'ms'=>850, 'offset'=>3],
            ['id'=>'cat', 'name'=>'Кошка Зола', 'kind'=>'cat', 'route'=>[[2, 1], [18, 1], [2, 1]], 'ms'=>800, 'offset'=>0],
            ['id'=>'pigeons', 'name'=>'Голуби', 'kind'=>'bird', 'route'=>[[26, 16], [29, 16], [29, 19], [26, 19], [26, 16]], 'ms'=>500, 'offset'=>0],
            ['id'=>'pilgrim', 'name'=>'Паломница', 'kind'=>'townsfolk', 'route'=>[[26, 1], [26, 34], [29, 34], [29, 1], [26, 1]], 'ms'=>1000, 'offset'=>5],
        ];
    }

    /** Cells of a walker's loop, waypoint to waypoint. */
    public static function routeCells(array $route): array
    {
        $cells = [];
        for ($i = 0; $i < count($route) - 1; $i++) {
            [$x, $y] = $route[$i];
            [$tx, $ty] = $route[$i + 1];
            while ([$x, $y] !== [$tx, $ty]) {
                $cells[] = [$x, $y];
                $x += $tx <=> $x;
                $y += $ty <=> $y;
            }
        }

        return $cells ?: [$route[0]];
    }

    /** Where every walker is at this moment (milliseconds since the epoch). */
    public static function walkersAt(int $ms): array
    {
        static $routes = [];
        $list = [];
        foreach (self::walkers() as $w) {
            $cells = $routes[$w['id']] ??= self::routeCells($w['route']);
            $n = count($cells);
            $step = intdiv($ms, $w['ms']) + $w['offset'];
            $here = $cells[(($step % $n) + $n) % $n];
            $next = $cells[((($step + 1) % $n) + $n) % $n];
            $facing = $next[0] > $here[0] ? 'east' : ($next[0] < $here[0] ? 'west' : ($next[1] < $here[1] ? 'north' : 'south'));
            $list[] = ['id'=>$w['id'], 'name'=>$w['name'], 'kind'=>$w['kind'], 'x'=>$here[0], 'y'=>$here[1], 'facing'=>$facing, 'service'=>''];
        }

        return $list;
    }

    public static function walkable(int $x, int $y): bool
    {
        if ($x < 0 || $y < 0 || $x >= self::WIDTH || $y >= self::HEIGHT) return false;
        if (! in_array(self::MAP[$y][$x], ['.', ',', ':', '*', '+'], true)) return false;
        static $keepers = null;
        $keepers ??= array_flip(array_map(static fn ($n) => $n['x'].','.$n['y'], self::npcs()));

        return ! isset($keepers[$x.','.$y]);
    }

    public static function npc(string $id): ?array
    {
        static $byId = null;
        $byId ??= array_column(self::npcs(), null, 'id');

        return $byId[$id] ?? null;
    }

    /** Keepers as the client sees them (no lines). */
    public static function keepers(): array
    {
        return array_map(static fn ($n) => array_intersect_key($n, array_flip(['id', 'name', 'kind', 'service', 'x', 'y'])), self::npcs());
    }

    /** What townsfolk on their rounds answer, by kind. */
    public const WALKER_LINES = ['guard'=>['Порядок в городе. Проходите.', 'Ночью у ворот тихо. Слишком тихо.', 'Из Разлома вернулись? Сдайте оружие… шучу.'],
        'dog'=>['Гав!', 'Р-р-р… гав!', '*виляет хвостом*'], 'child'=>['Догони меня! Не догонишь!', 'А я видел дракона! Почти.', 'Ты герой? Настоящий?'],
        'cat'=>['Мяу.', '*трётся о ногу*', '*щурится на огонь*'], 'bird'=>['Курлык.', '*голуби взлетают и садятся снова*'],
        'townsfolk'=>['Хорошего дня, путник. Да хранят тебя огни.', 'Говорят, в шахтах опять неспокойно.', 'Огни горят — значит, живём.'],
        'merchant'=>['Пирожки! Горячие пирожки!', 'Свежий хлеб, почти даром!', 'Купи пирожок — в Разломе не накормят.']];

    /**
     * What a keeper says; the seer reads the omens instead of reciting lines. Without $turn the
     * line follows the wall clock; with it (the live town counts each hero's talks) every new
     * talk moves to the next line.
     */
    public static function line(array $npc, int $ms, string $hero, ?int $turn = null): string
    {
        if ($npc['service'] === 'omens') {
            $omens = array_values(Catalog::omens());
            $omen = $omens[(($turn ?? intdiv($ms, 9000)) + crc32($hero)) % count($omens)];

            return 'Вижу знамение: «'.$omen['name'].'». '.$omen['description'].' Будь готов.';
        }
        if ($npc['kind'] === 'dummy') {
            return $npc['name'].' покачивается и молча ждёт удара.';
        }

        return $npc['lines'][(($turn ?? intdiv($ms, 7000)) + crc32($hero.$npc['id'])) % count($npc['lines'])];
    }

    /** A walker's greeting: the kind's lines in turn. */
    public static function walkerLine(string $kind, int $turn): string
    {
        $lines = self::WALKER_LINES[$kind] ?? ['…'];

        return $lines[$turn % count($lines)];
    }
}
