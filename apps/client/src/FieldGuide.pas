unit FieldGuide;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Classes,Forms,Controls,StdCtrls,Graphics,GameTheme;
procedure ShowFieldGuide(Owner:TComponent);
implementation
procedure ShowFieldGuide(Owner:TComponent);
var Window:TForm;Text:TMemo;CloseButton:TButton;
begin
  Window:=TForm.CreateNew(Owner);
  try
    Window.Caption:='Полевой справочник — Три огня';Window.SetBounds(0,0,760,640);
    Window.Position:=poOwnerFormCenter;Window.Color:=PanelColor;Window.Font.Name:='Tahoma';Window.Font.Size:=11;
    CloseButton:=TButton.Create(Window);CloseButton.Parent:=Window;CloseButton.Align:=alBottom;
    CloseButton.Height:=40;CloseButton.Caption:='Вернуться в игру';CloseButton.ModalResult:=mrOK;CloseButton.Cancel:=True;
    Text:=TMemo.Create(Window);Text.Parent:=Window;Text.Align:=alClient;Text.ReadOnly:=True;
    Text.Color:=TownColor;Text.Font.Color:=GoldColor;Text.ScrollBars:=ssVertical;Text.WordWrap:=True;
    Text.Lines.Text:=
      'ТРИ ОГНЯ — ВАША ЦЕЛЬ'+LineEnding+
      'Пройдите три области по порядку: шахты, монастырь, корни Разлома. В каждой победите босса третьего этажа и эвакуируйтесь у золотого выхода. Возвращённый огонь открывает следующую область. После третьего огня кампания завершена; можно продолжать походы и развивать героев.'+LineEnding+LineEnding+
      'ПЕРВЫЙ ПОХОД'+LineEnding+
      '1. Создайте аккаунт и героя во вкладке «Аккаунт». Страж крепче в ближнем бою; арканист сильнее в магии; следопыт стреляет на 5 клеток; хранитель умеет исцелять союзников.'+LineEnding+
      '2. Во вкладке «Вещи» наденьте запасное кольцо. Во вкладке «Поход» выберите первую область и нажмите «Подготовить / присоединиться».'+LineEnding+
      '3. Для группы передайте код друзьям: они должны присоединиться до старта. Затем лидер нажимает «Начать».'+LineEnding+
      '4. Щёлкните карту или нажмите Esc, чтобы управлять героем. Поле ввода чата перехватывает буквы.'+LineEnding+LineEnding+
      'УПРАВЛЕНИЕ'+LineEnding+
      'WASD / стрелки — шаг; Space — атака ближайшего врага в радиусе.'+LineEnding+
      '1 — щитовой удар; 2 — защита; 3 — выпить зелье.'+LineEnding+
      '4 — огненная стрела; 5 — исцеление; 6 — ледяное копьё; 7 — кольцо пламени. Требования и перезарядки указаны в книге магии.'+LineEnding+
      'E — спуститься дальше у выхода; X — эвакуироваться у выхода.'+LineEnding+
      'R — поднять соседнего павшего союзника: начать за 20 секунд, выдержать 3 секунды без урона. На следующий этаж группа несёт один заряд воскрешения на весь поход.'+LineEnding+
      'T — городские службы; F1 — этот справочник. Онлайн-мир не ставится на паузу при открытии окна.'+LineEnding+LineEnding+
      'РИСК И НАГРАДА'+LineEnding+
      'Золото, вещи и весь опыт похода сохраняются после эвакуации. При поражении сохраняется четверть опыта; добыча теряется. Ваше городское снаряжение остаётся. Лучше уйти раньше, чем потерять всё.'+LineEnding+LineEnding+
      'МЕЖДУ ПОХОДАМИ'+LineEnding+
      'Во вкладке «Герой» распределяйте очки характеристик. В городе: разбирайте снятые вещи, куйте предметы, запасайте зелья, покупайте и продавайте на доске. Надетая вещь привязывается и больше не продаётся игрокам. Комиссия рынка — 5% с округлением вверх.'+LineEnding+
      'Во вкладке «Хроника» городских служб выбирайте таланты (одно очко каждые 3 уровня) и смотрите прогресс кампании. Сброс характеристик и талантов бесплатный.'+LineEnding+
      'В городе также доступны друзья и гильдии. Чат: общий, групповой и гильдейский; Enter отправляет сообщение, Esc возвращает фокус карте.';
    Text.SelStart:=0;Window.ShowModal;
  finally Window.Free;end;
end;
end.
