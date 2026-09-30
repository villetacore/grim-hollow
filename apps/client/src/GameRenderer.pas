unit GameRenderer;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Controls,Graphics,fpjson;
procedure RenderMap(Surface:TCustomControl;Snapshot:TJSONData;const Hero,Expedition:string);
implementation
uses SysUtils,Math,Types,GameArt,GameData;
procedure RenderMap(Surface:TCustomControl;Snapshot:TJSONData;const Hero,Expedition:string);
var C:TCanvas;W,Map,Entities,E,Log,ExitCell:TJSONData;
  X,Y,I,J,S,OX,OY,Columns,Rows,LeftCell,TopCell,PX,PY,MX,MY:Integer;Row,Biome,Kind:string;
begin
  C:=Surface.Canvas;C.Brush.Style:=bsSolid;C.Brush.Color:=$001A1E20;C.FillRect(Surface.ClientRect);
  C.Font.Name:='Georgia';C.Font.Color:=$008DC5DC;C.Font.Size:=25;C.TextOut(24,15,'GRIM HOLLOW');
  C.Font.Name:='Tahoma';C.Font.Size:=10;C.Font.Color:=$008D9B9F;
  if (Snapshot=nil) or (Expedition='') then begin
    C.TextOut(26,61,'ПЕПЕЛЬНЫЙ ПРЕДЕЛ  /  ХРОНИКИ ТРЁХ ОГНЕЙ');TownScene(C,Surface.Width,Surface.Height);
    C.Font.Color:=$008DC5DC;C.Brush.Style:=bsClear;C.TextOut(26,Surface.Height-70,'Город [T]: кузница, рынок, аптекарь, друзья и гильдия.');
    C.Font.Color:=clSilver;C.TextOut(26,Surface.Height-46,'Аккаунт → герой → снаряжение → Поход → Подготовить → Начать.');Exit;
  end;
  W:=Snapshot.FindPath('world');Map:=W.FindPath('map');Biome:=JStr(W,'biome','mines');
  C.TextOut(26,58,JStr(W,'biome_name','Затопленные шахты')+' / Этаж '+JStr(W,'floor'));
  C.Font.Color:=$008DC5DC;
  if Snapshot.FindPath('lobby').AsBoolean then C.TextOut(26,80,'Сбор группы. Лидер начинает поход кнопкой справа.')
  else if JInt(W,'floor')>=JInt(W,'last_floor',3) then C.TextOut(26,80,'Последний этаж: '+JStr(W,'boss_name')+' стережёт выход.   F — сундук   X — эвакуация')
  else C.TextOut(26,80,'Стрелки / WASD — шаг   Space — атака   F — сундук   4–0, Q — магия   E — ниже   X — эвакуация');
  S:=32;if Surface.Height<500 then S:=24;
  Columns:=Min(32,(Surface.Width-40) div S);Rows:=Min(32,(Surface.Height-208) div S);Rows:=Max(7,Rows);
  LeftCell:=EnsureRange(JInt(W,'self.x')-Columns div 2,0,32-Columns);TopCell:=EnsureRange(JInt(W,'self.y')-Rows div 2,0,32-Rows);
  OX:=(Surface.Width-Columns*S) div 2;OY:=114;
  for Y:=TopCell to TopCell+Rows-1 do begin Row:=Map.Items[Y].AsString;
    for X:=LeftCell to LeftCell+Columns-1 do begin PX:=OX+(X-LeftCell)*S;PY:=OY+(Y-TopCell)*S;
      if Row[X+1]<>' ' then Stone(C,PX,PY,S,X*13+Y*7,Row[X+1]='#',Biome)
      else begin C.Brush.Color:=$00101213;C.FillRect(Rect(PX,PY,PX+S,PY+S));end;
    end;
  end;
  ExitCell:=W.FindPath('exit');
  if ExitCell.JSONType<>jtNull then begin X:=ExitCell.Items[0].AsInteger;Y:=ExitCell.Items[1].AsInteger;
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
      Kind:=JStr(E,'type',JStr(E,'class_id','guardian'));
      Figure(C,OX+(X-LeftCell)*S,OY+(Y-TopCell)*S,S,Kind,I=1,JStr(E,'id')=Hero,JInt(E,'hp'));
      if I=0 then EnemyMarks(C,OX+(X-LeftCell)*S,OY+(Y-TopCell)*S,S,JInt(E,'hp'),JInt(E,'max_hp'),JBool(E,'elite'),JBool(E,'boss'));
    end;
  end;
  Frame(C,Rect(OX-3,OY-3,OX+Columns*S+3,OY+Rows*S+3));
  // Compact visible-map overview, never revealing hidden cells.
  MX:=Surface.Width-116;MY:=10;
  for Y:=0 to 31 do begin Row:=Map.Items[Y].AsString;for X:=0 to 31 do begin
    case Row[X+1] of '#':C.Brush.Color:=$00404C50;'.':C.Brush.Color:=$00767C67;else C.Brush.Color:=$00131516;end;
    C.FillRect(Rect(MX+X*3,MY+Y*3,MX+X*3+3,MY+Y*3+3));end;end;
  C.Brush.Color:=$00A4D3EC;X:=JInt(W,'self.x');Y:=JInt(W,'self.y');C.FillRect(Rect(MX+X*3,MY+Y*3,MX+X*3+3,MY+Y*3+3));
  Frame(C,Rect(MX-2,MY-2,MX+98,MY+98));
  Log:=W.FindPath('log');C.Font.Name:='Tahoma';C.Font.Size:=9;C.Font.Color:=$009EAEBB;C.Brush.Style:=bsClear;
  for I:=0 to 3 do if Log.Count>I then C.TextOut(26,OY+Rows*S+14+I*17,Log.Items[Log.Count-1-I].AsString);
end;
end.
