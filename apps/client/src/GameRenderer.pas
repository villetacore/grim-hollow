unit GameRenderer;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Controls,Graphics,fpjson;
type
  TExplored=array[0..31] of string;
  { What the map needs besides the snapshot; the layout fields are filled in by RenderMap
    so that mouse clicks can be mapped back to cells. }
  TMapView=record
    Hero,Expedition,Target:string;
    Sheet:TJSONData;
    EstTick:Int64;
    Explored:TExplored;
    OX,OY,S,LeftCell,TopCell,Columns,Rows:Integer;
  end;
const
  { Action bar: key label, skill id (spells use their catalog key). }
  BarKeys:array[0..15] of string=('Sp','1','2','3','4','5','6','7','8','9','0','Q','Z','C','V','G');
  BarSkills:array[0..15] of string=('attack','bash','guard','potion','firebolt','mend','frost','nova','venom','chain','barrier','meteor','blink','fire_wave','whirlwind','drain_life');
procedure RenderMap(Surface:TCustomControl;Snapshot:TJSONData;var View:TMapView);
procedure ClearExplored(var View:TMapView);
procedure Explore(var View:TMapView;Map:TJSONData);
implementation
uses SysUtils,Math,Types,GameArt,GameData;
const SkillCooldowns:array[0..3] of Integer=(6,40,60,30);

procedure ClearExplored(var View:TMapView);
var Y:Integer;
begin for Y:=0 to 31 do View.Explored[Y]:=StringOfChar(' ',32);end;

procedure Explore(var View:TMapView;Map:TJSONData);
var X,Y:Integer;Row:string;
begin
  if (Map=nil) or (Map.Count<32) then Exit;
  for Y:=0 to 31 do begin Row:=Map.Items[Y].AsString;
    if Length(View.Explored[Y])<>32 then View.Explored[Y]:=StringOfChar(' ',32);
    for X:=1 to Min(32,Length(Row)) do if Row[X]<>' ' then View.Explored[Y][X]:=Row[X];
  end;
end;

procedure ActionBar(C:TCanvas;W:TJSONData;const View:TMapView;X,Y,Size:Integer);
var I,Remaining,Total,Level:Integer;Key:string;Spell:TJSONData;Locked,Dry:Boolean;SX:Integer;
begin
  for I:=0 to High(BarSkills) do begin
    Key:=BarSkills[I];SX:=X+I*(Size+4);Locked:=False;Dry:=False;
    SpellIcon(C,SX,Y,Size,Key);
    if I<4 then begin
      Total:=SkillCooldowns[I];
      if I=0 then Remaining:=JInt(W,'self.ready_at')-View.EstTick
      else Remaining:=JInt(W,'self.spell_ready.'+Key)-View.EstTick;
      if (I=3) and (JInt(W,'self.potions')<=0) then Dry:=True;
    end else begin
      Spell:=nil;if View.Sheet<>nil then Spell:=View.Sheet.FindPath('spells.'+Key);
      Total:=Max(1,JInt(Spell,'cooldown',10));Level:=JInt(Spell,'level',1);
      Locked:=JInt(W,'self.level',1)<Level;Dry:=JInt(W,'self.mana')<JInt(Spell,'mana');
      Remaining:=JInt(W,'self.spell_ready.'+Key)-View.EstTick;
    end;
    if Locked then begin C.Brush.Color:=$00101010;C.FillRect(Rect(SX,Y,SX+Size,Y+Size));
      C.Font.Size:=7;C.Font.Color:=$00707070;C.Brush.Style:=bsClear;C.TextOut(SX+3,Y+Size div 2-6,'ур.'+IntToStr(Level));C.Brush.Style:=bsSolid;end
    else begin
      if Dry then begin C.Pen.Color:=$00E08030;C.Brush.Style:=bsClear;C.Rectangle(SX,Y,SX+Size,Y+Size);C.Rectangle(SX+1,Y+1,SX+Size-1,Y+Size-1);C.Brush.Style:=bsSolid;end;
      if Remaining>0 then begin
        // The dark shutter shrinks as the cooldown runs out.
        C.Brush.Color:=$00080808;C.FillRect(Rect(SX,Y,SX+Size,Y+Size*Min(Remaining,Total) div Total));
        C.Font.Size:=8;C.Font.Color:=clWhite;C.Brush.Style:=bsClear;
        C.TextOut(SX+Size div 2-C.TextWidth(FormatFloat('0.0',Remaining/10)) div 2,Y+Size div 2-7,FormatFloat('0.0',Remaining/10));C.Brush.Style:=bsSolid;
      end;
    end;
    C.Font.Size:=7;C.Font.Color:=$0082C1DD;C.Brush.Style:=bsClear;C.TextOut(SX+2,Y+Size-12,BarKeys[I]);C.Brush.Style:=bsSolid;
  end;
end;

procedure RenderMap(Surface:TCustomControl;Snapshot:TJSONData;var View:TMapView);
var C:TCanvas;W,Map,Entities,E,Log,ExitCell:TJSONData;
  X,Y,I,J,S,OX,OY,Columns,Rows,LeftCell,TopCell,PX,PY,MX,MY,Bar:Integer;Row,Seen,Biome,Kind:string;Duel:Boolean;
begin
  C:=Surface.Canvas;C.Brush.Style:=bsSolid;C.Brush.Color:=$001A1E20;C.FillRect(Surface.ClientRect);
  C.Font.Name:='Georgia';C.Font.Color:=$008DC5DC;C.Font.Size:=25;C.TextOut(24,15,'GRIM HOLLOW');
  C.Font.Name:='Tahoma';C.Font.Size:=10;C.Font.Color:=$008D9B9F;
  View.S:=0;
  if (Snapshot=nil) or (View.Expedition='') then begin
    C.TextOut(26,61,'ПЕПЕЛЬНЫЙ ПРЕДЕЛ  /  ХРОНИКИ ТРЁХ ОГНЕЙ');TownScene(C,Surface.Width,Surface.Height);
    C.Font.Color:=$008DC5DC;C.Brush.Style:=bsClear;C.TextOut(26,Surface.Height-70,'Город [T]: кузница, рынок, аптекарь, контракты, гильдия и таблица лидеров.');
    C.Font.Color:=clSilver;C.TextOut(26,Surface.Height-46,'Аккаунт → герой → снаряжение → Поход (или Дуэль) → Подготовить → Начать.');Exit;
  end;
  W:=Snapshot.FindPath('world');Map:=W.FindPath('map');Biome:=JStr(W,'biome','mines');Duel:=JStr(W,'mode')='duel';
  if Duel then C.TextOut(26,58,'АРЕНА — дуэль до победы. Осталось '+IntToStr(Max(0,1800-JInt(W,'tick')) div 10)+' с.')
  else C.TextOut(26,58,JStr(W,'biome_name','Затопленные шахты')+' / Глубина '+JStr(W,'floor')+' / Уровень врагов '+JStr(W,'depth_level','1'));
  C.Font.Color:=$008DC5DC;
  if Snapshot.FindPath('lobby').AsBoolean then C.TextOut(26,80,'Сбор группы. Лидер начинает кнопкой справа.')
  else if Duel then C.TextOut(26,80,'Щёлкните соперника или Tab — цель. Space — удар, 4–0/Q/Z/C/V/G — умения.')
  else if JInt(W,'floor')=JInt(W,'boss_floor',3) then C.TextOut(26,80,'Здесь босс: '+JStr(W,'boss_name')+'. После победы — глубже [E] или домой [X].')
  else C.TextOut(26,80,'Босс на глубине '+JStr(W,'boss_floor')+'. Клик/Tab — цель, F — сундук, E — ниже, X — эвакуация.');
  S:=32;if Surface.Height<560 then S:=24;Bar:=Min(34,Max(22,(Surface.Width-60) div 16-4));
  Columns:=Min(32,(Surface.Width-40) div S);Rows:=Min(32,(Surface.Height-114-Bar-80) div S);Rows:=Max(7,Rows);
  LeftCell:=EnsureRange(JInt(W,'self.x')-Columns div 2,0,32-Columns);TopCell:=EnsureRange(JInt(W,'self.y')-Rows div 2,0,32-Rows);
  OX:=(Surface.Width-Columns*S) div 2;OY:=108;
  View.OX:=OX;View.OY:=OY;View.S:=S;View.LeftCell:=LeftCell;View.TopCell:=TopCell;View.Columns:=Columns;View.Rows:=Rows;
  for Y:=TopCell to TopCell+Rows-1 do begin Row:=Map.Items[Y].AsString;Seen:=View.Explored[Y];if Length(Seen)<>32 then Seen:=StringOfChar(' ',32);
    for X:=LeftCell to LeftCell+Columns-1 do begin PX:=OX+(X-LeftCell)*S;PY:=OY+(Y-TopCell)*S;
      if Row[X+1]<>' ' then Stone(C,PX,PY,S,X*13+Y*7,Row[X+1]='#',Biome)
      else if Seen[X+1]<>' ' then Stone(C,PX,PY,S,X*13+Y*7,Seen[X+1]='#',Biome,True)
      else begin C.Brush.Color:=$00101213;C.FillRect(Rect(PX,PY,PX+S,PY+S));end;
    end;
  end;
  ExitCell:=W.FindPath('exit');
  if (ExitCell<>nil) and (ExitCell.JSONType<>jtNull) then begin X:=ExitCell.Items[0].AsInteger;Y:=ExitCell.Items[1].AsInteger;
    if (X>=LeftCell) and (X<LeftCell+Columns) and (Y>=TopCell) and (Y<TopCell+Rows) then begin
      PX:=OX+(X-LeftCell)*S;PY:=OY+(Y-TopCell)*S;C.Pen.Color:=$006A9FCE;
      for I:=1 to 5 do begin C.MoveTo(PX+I*2,PY+I*5);C.LineTo(PX+S-I*2,PY+I*5);end;
    end;
  end;
  Entities:=W.FindPath('objects');
  if Entities<>nil then for J:=0 to Entities.Count-1 do begin E:=Entities.Items[J];X:=JInt(E,'x');Y:=JInt(E,'y');
    if (X<LeftCell) or (X>=LeftCell+Columns) or (Y<TopCell) or (Y>=TopCell+Rows) then Continue;
    DungeonObject(C,OX+(X-LeftCell)*S,OY+(Y-TopCell)*S,S,JStr(E,'type'),JBool(E,'used'));
  end;
  for I:=0 to 1 do begin if I=0 then Entities:=W.FindPath('enemies') else Entities:=W.FindPath('players');
    for J:=0 to Entities.Count-1 do begin E:=Entities.Items[J];X:=JInt(E,'x');Y:=JInt(E,'y');
      if (X<LeftCell) or (X>=LeftCell+Columns) or (Y<TopCell) or (Y>=TopCell+Rows) then Continue;
      Kind:=JStr(E,'type',JStr(E,'class_id','guardian'));PX:=OX+(X-LeftCell)*S;PY:=OY+(Y-TopCell)*S;
      // In a duel the opponent is drawn in enemy colours.
      Figure(C,PX,PY,S,Kind,(I=1) and not (Duel and (JStr(E,'id')<>View.Hero)),JStr(E,'id')=View.Hero,JInt(E,'hp'));
      if I=0 then EnemyMarks(C,PX,PY,S,JInt(E,'hp'),JInt(E,'max_hp'),JBool(E,'elite'),JBool(E,'boss'))
      else if Duel and (JStr(E,'id')<>View.Hero) then EnemyMarks(C,PX,PY,S,JInt(E,'hp'),JInt(E,'max_hp'),False,False);
      if (View.Target<>'') and (JStr(E,'id')=View.Target) then begin
        C.Pen.Color:=$002040FF;C.Pen.Width:=2;
        C.MoveTo(PX,PY+6);C.LineTo(PX,PY);C.LineTo(PX+6,PY);C.MoveTo(PX+S-6,PY);C.LineTo(PX+S,PY);C.LineTo(PX+S,PY+6);
        C.MoveTo(PX,PY+S-6);C.LineTo(PX,PY+S);C.LineTo(PX+6,PY+S);C.MoveTo(PX+S-6,PY+S);C.LineTo(PX+S,PY+S);C.LineTo(PX+S,PY+S-6);C.Pen.Width:=1;
      end;
    end;
  end;
  Frame(C,Rect(OX-3,OY-3,OX+Columns*S+3,OY+Rows*S+3));
  // Overview map of every explored cell; the current view is brighter.
  MX:=Surface.Width-116;MY:=10;
  for Y:=0 to 31 do begin Row:=Map.Items[Y].AsString;Seen:=View.Explored[Y];if Length(Seen)<>32 then Seen:=StringOfChar(' ',32);
    for X:=0 to 31 do begin
    if Row[X+1]='#' then C.Brush.Color:=$00404C50 else if Row[X+1]='.' then C.Brush.Color:=$00767C67
    else if Seen[X+1]='#' then C.Brush.Color:=$00262D30 else if Seen[X+1]='.' then C.Brush.Color:=$003C4034 else C.Brush.Color:=$00131516;
    C.FillRect(Rect(MX+X*3,MY+Y*3,MX+X*3+3,MY+Y*3+3));end;end;
  C.Brush.Color:=$00A4D3EC;X:=JInt(W,'self.x');Y:=JInt(W,'self.y');C.FillRect(Rect(MX+X*3,MY+Y*3,MX+X*3+3,MY+Y*3+3));
  Frame(C,Rect(MX-2,MY-2,MX+98,MY+98));
  Log:=W.FindPath('log');C.Font.Name:='Tahoma';C.Font.Size:=9;C.Font.Color:=$009EAEBB;C.Brush.Style:=bsClear;
  for I:=0 to 3 do if Log.Count>I then C.TextOut(26,OY+Rows*S+8+I*16,Log.Items[Log.Count-1-I].AsString);
  C.Brush.Style:=bsSolid;
  if not Snapshot.FindPath('lobby').AsBoolean then ActionBar(C,W,View,(Surface.Width-16*(Bar+4)) div 2,Surface.Height-Bar-8,Bar);
end;
end.
