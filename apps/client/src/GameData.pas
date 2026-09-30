unit GameData;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses fpjson, SysUtils;
function JStr(D:TJSONData; const Path:string; const DefaultValue:string=''):string;
function JInt(D:TJSONData; const Path:string; DefaultValue:Integer=0):Integer;
function JBool(D:TJSONData; const Path:string):Boolean;
function ItemSummary(D:TJSONData):string;
function SpellKey(Index:Integer):string;
const SpellCount=8;
function FriendlyError(const Code:string):string;
function GameWord(const Code:string):string;
implementation
function GameWord(const Code:string):string;
begin
  case Code of
    'armor':Result:='броня';'damage_bonus':Result:='урон';'max_hp':Result:='здоровье';
    'max_mana':Result:='мана';'power':Result:='сила магии';
    'guardian':Result:='Страж';'arcanist':Result:='Арканист';'ranger':Result:='Следопыт';'warden':Result:='Хранитель';
    'weapon':Result:='оружие';'body':Result:='доспех';'offhand':Result:='щит';'ring':Result:='кольцо';'amulet':Result:='амулет';
    'chest':Result:='сундук';'fountain':Result:='родник';'shrine':Result:='алтарь ярости';'trap':Result:='ловушка';
    'distill':Result:='Перегонка эссенции';'bounty':Result:='Награда за контракт';
    'salvage':Result:='Разбор';'craft':Result:='Изготовление';'supply':Result:='Запас зелий';
    'market_list':Result:='Выставлен лот';'market_cancel':Result:='Лот снят';'market_buy':Result:='Покупка';
    'market_sale':Result:='Продажа игроку';'expedition':Result:='Итоги похода';'npc_sale':Result:='Продажа торговцу';
    else Result:=Code;
  end;
end;
function FriendlyError(const Code:string):string;
begin
  if Pos('spell_locked',Code)>0 then Result:='Это заклинание откроется на более высоком уровне.'
  else if Pos('spell_cooldown',Code)>0 then Result:='Заклинание ещё восстанавливается.'
  else if Pos('cooldown',Code)>0 then Result:='Дождитесь готовности следующего действия.'
  else if Pos('not_enough_mana',Code)>0 then Result:='Не хватает маны. Она восстанавливается со временем.'
  else if Pos('no_spell_target',Code)>0 then Result:='Нет доступной цели в радиусе заклинания.'
  else if Pos('cannot_heal',Code)>0 then Result:='Здоровье полное или закончились зелья.'
  else if Pos('blocked',Code)>0 then Result:='Путь занят или перекрыт стеной.'
  else if Pos('exit_required',Code)>0 then Result:='Сначала подойдите к золотой лестнице.'
  else if Pos('party_not_ready',Code)>0 then Result:='Дождитесь всей группы у лестницы.'
  else if Pos('boss_alive',Code)>0 then Result:='Сначала победите босса этого этажа.'
  else if Pos('level_required',Code)>0 then Result:='Для этой вещи нужен более высокий уровень.'
  else if Pos('no_skill_points',Code)>0 then Result:='Нет свободных очков развития. Получите следующий уровень.'
  else if Pos('unequip_first',Code)>0 then Result:='Перед продажей снимите предмет.'
  else if Pos('return_to_town_first',Code)>0 then Result:='Сначала завершите поход и вернитесь в город.'
  else if Pos('not_enough_gold',Code)>0 then Result:='Не хватает крон.'
  else if Pos('not_enough_resources',Code)>0 then Result:='Не хватает крон или материалов для рецепта.'
  else if Pos('item_bound',Code)>0 then Result:='Этот предмет привязан. Его можно разобрать или продать NPC.'
  else if Pos('listing_unavailable',Code)>0 then Result:='Лот уже куплен или снят. Обновите торговую доску.'
  else if Pos('biome_locked',Code)>0 then Result:='Победите босса предыдущей области и эвакуируйтесь, чтобы открыть эту.'
  else if Pos('cannot_revive',Code)>0 then Result:='Союзника уже нельзя поднять здесь. Используйте святилище на переходе.'
  else if Pos('guild_required',Code)>0 then Result:='Сначала вступите в гильдию через городские службы.'
  else if Pos('invitation_required',Code)>0 then Result:='Нужно входящее приглашение от этого игрока или гильдии.'
  else if Pos('already_in_guild',Code)>0 then Result:='Персонаж уже состоит в гильдии.'
  else if Pos('leader_only',Code)>0 then Result:='Приглашать участников может глава гильдии.'
  else if Pos('no_object',Code)>0 then Result:='Рядом нет сундука, родника или алтаря. Встаньте вплотную.'
  else if Pos('bounty_active',Code)>0 then Result:='Сначала выполните или откажитесь от текущего контракта.'
  else if Pos('bounty_incomplete',Code)>0 then Result:='Контракт ещё не выполнен: победите нужных врагов и эвакуируйтесь.'
  else if Pos('bounty_required',Code)>0 then Result:='У вас нет активного контракта.'
  else if Pos('last_floor',Code)>0 then Result:='Это последний этаж области: победите босса и эвакуируйтесь [X].'
  else if Pos('out_of_range',Code)>0 then Result:='Цель слишком далеко или скрыта стеной.'
  else if Pos('talent_limit',Code)>0 then Result:='Нет очков талантов или уже достигнут третий ранг. Очко выдаётся каждые три уровня.'
  else if Pos('chat_muted',Code)>0 then Result:='Модератор временно ограничил отправку сообщений этим аккаунтом.'
  else if Pos('invalid_credentials',Code)>0 then Result:='Неверный email или пароль.'
  else if Pos('HTTP 401',Code)>0 then Result:='Сессия истекла. Войдите в аккаунт заново.'
  else if Pos('HTTP 429',Code)>0 then Result:='Слишком много запросов. Повторите через минуту.'
  else Result:=Code;
end;
function JStr(D:TJSONData; const Path:string; const DefaultValue:string):string;
var V:TJSONData;
begin
  Result:=DefaultValue; if D=nil then Exit; V:=D.FindPath(Path);
  if (V<>nil) and (V.JSONType<>jtNull) then Result:=V.AsString;
end;
function JInt(D:TJSONData; const Path:string; DefaultValue:Integer):Integer;
begin Result:=StrToIntDef(JStr(D,Path),DefaultValue); end;
function JBool(D:TJSONData; const Path:string):Boolean;
var V:TJSONData;
begin
  Result:=False; if D=nil then Exit; V:=D.FindPath(Path);
  if (V<>nil) and (V.JSONType=jtBoolean) then Result:=V.AsBoolean;
end;
function ItemSummary(D:TJSONData):string;
begin
  Result:=JStr(D,'name')+' | ур.'+JStr(D,'level')+LineEnding+
    'Урон +'+JStr(D,'damage')+'   Броня +'+JStr(D,'armor')+'   Магия +'+JStr(D,'power');
  if JInt(D,'hp')>0 then Result:=Result+'   HP +'+JStr(D,'hp');
  if JInt(D,'mana')>0 then Result:=Result+'   MP +'+JStr(D,'mana');
  Result:=Result+LineEnding+
    'Продажа: '+JStr(D,'price')+' крон. Слот: '+GameWord(JStr(D,'slot'));
end;
function SpellKey(Index:Integer):string;
begin
  case Index of 0:Result:='firebolt';1:Result:='mend';2:Result:='frost';3:Result:='nova';
    4:Result:='venom';5:Result:='chain';6:Result:='barrier';7:Result:='meteor';else Result:='';end;
end;
end.
