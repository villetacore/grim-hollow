unit PlazaRender;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Graphics,fpjson;
type
  { Viewport of the town square; filled by RenderPlaza so that clicks map back to cells. }
  TPlazaView=record
    OX,OY,S,LeftCell,TopCell,Columns,Rows:Integer;
  end;
{ Town is the static square (map, buildings: GET plaza?full=1); Plaza the latest live view.
  HeroX/HeroY is the locally predicted position of the own hero. }
procedure RenderPlaza(C:TCanvas;Width,Height:Integer;Plaza,Town:TJSONData;const Hero,Talk:string;HeroX,HeroY:Integer;Now:QWord;var View:TPlazaView);
{ Whether the own hero may step on a cell: open ground not taken by a standing keeper. }
function PlazaWalkable(Town,Plaza:TJSONData;X,Y:Integer):Boolean;
{ The closest keeper or townsperson within Range steps, or nil. }
function NearestNpc(Plaza:TJSONData;X,Y,Range:Integer):TJSONData;
implementation
uses SysUtils,Math,Types,GameArt,GameData;

procedure Fill(C:TCanvas;X,Y,W,H:Integer;Color:TColor);
begin C.Brush.Style:=bsSolid;C.Brush.Color:=Color;C.FillRect(Rect(X,Y,X+W,Y+H));end;

function MapChar(Town:TJSONData;X,Y:Integer):Char;
var Map:TJSONData;Row:string;
begin
  Result:='#';if Town=nil then Exit;Map:=Town.FindPath('map');
  if (Map=nil) or (Y<0) or (Y>=Map.Count) then Exit;
  Row:=Map.Items[Y].AsString;if (X<0) or (X>=Length(Row)) then Exit;
  Result:=Row[X+1];
end;

function PlazaWalkable(Town,Plaza:TJSONData;X,Y:Integer):Boolean;
var Npcs,N:TJSONData;I:Integer;
begin
  Result:=MapChar(Town,X,Y) in ['.',',',':','*','+'];
  if not Result or (Plaza=nil) then Exit;
  Npcs:=Plaza.FindPath('npcs');if Npcs=nil then Exit;
  for I:=0 to Npcs.Count-1 do begin N:=Npcs.Items[I];
    if (JStr(N,'service')<>'') and (JInt(N,'x')=X) and (JInt(N,'y')=Y) then Exit(False);end;
end;

function NearestNpc(Plaza:TJSONData;X,Y,Range:Integer):TJSONData;
var Npcs,N:TJSONData;I,D,Best:Integer;
begin
  Result:=nil;Best:=Range+1;if Plaza=nil then Exit;Npcs:=Plaza.FindPath('npcs');if Npcs=nil then Exit;
  for I:=0 to Npcs.Count-1 do begin N:=Npcs.Items[I];
    D:=Abs(JInt(N,'x')-X)+Abs(JInt(N,'y')-Y);
    // Keepers win ties: they are the ones with something to offer.
    if (D<Best) or ((D=Best) and (Result<>nil) and (JStr(Result,'service')='') and (JStr(N,'service')<>'')) then begin Best:=D;Result:=N;end;
  end;
end;

function RoofColor(Letter:Char):TColor;
begin
  case Letter of
    'F':Result:=$003B517B;'A':Result:=$00606F3F;'G':Result:=$00605060;'I':Result:=$00305A8A;'H':Result:=$00B0B0B8;
    'R':Result:=$00404098;'B':Result:=$00506878;'L':Result:=$00805A40;'K':Result:=$00507050;
  else Result:=$00505860;end;
end;

{ One map cell. Building cells draw their share of roof or facade; BX/BY is the cell inside
  the building and BW/BH its size (BW=0 for open ground). }
procedure Tile(C:TCanvas;X,Y,S:Integer;Ch:Char;V,BX,BY,BW,BH:Integer);
var U,I:Integer;Roof:TColor;
begin
  U:=Max(1,S div 12);
  case Ch of
    ',','*','T','o':begin
      Fill(C,X,Y,S,S,$00305A3A);
      if V mod 3=0 then Fill(C,X+S div 4,Y+S div 3,U,2*U,$00407A4C);
      if V mod 5=0 then Fill(C,X+S*2 div 3,Y+S*2 div 3,U,2*U,$00407A4C);
      if Ch='*' then for I:=0 to 3 do begin
        case (V+I) mod 3 of 0:C.Brush.Color:=$004060F0;1:C.Brush.Color:=$0050E0F0;else C.Brush.Color:=$00F080D0;end;
        C.FillRect(Rect(X+3+I*S div 5,Y+4+((V+I*7) mod 3)*S div 4,X+3+I*S div 5+2*U,Y+4+((V+I*7) mod 3)*S div 4+2*U));end;
      if Ch='T' then begin
        Fill(C,X+S div 2-U,Y+S div 2,2*U,S div 2-2,$00204060);
        C.Brush.Color:=$00205A2A;C.Pen.Color:=$00183E20;C.Ellipse(X+2,Y+1,X+S-2,Y+S*2 div 3+2);
        Fill(C,X+S div 3,Y+S div 5,2*U,2*U,$00307A40);
      end;
      if Ch='o' then begin
        Fill(C,X+S div 2-U,Y+S div 3,2*U,S*2 div 3-2,$00283038);
        C.Brush.Color:=$0060D8F8;C.Pen.Color:=$0040A0D0;C.Ellipse(X+S div 2-3*U,Y+2,X+S div 2+3*U,Y+2+5*U);
      end;
    end;
    '.':begin Fill(C,X,Y,S,S,$00505860);C.Pen.Color:=$00404850;
      C.MoveTo(X,Y+S div 2);C.LineTo(X+S,Y+S div 2);C.MoveTo(X+(V mod 2)*S div 2+S div 4,Y);C.LineTo(X+(V mod 2)*S div 2+S div 4,Y+S div 2);
      C.MoveTo(X+((V+1) mod 2)*S div 2+S div 4,Y+S div 2);C.LineTo(X+((V+1) mod 2)*S div 2+S div 4,Y+S);end;
    ':':begin Fill(C,X,Y,S,S,$00707A80);C.Pen.Color:=$00586068;C.MoveTo(X,Y);C.LineTo(X+S,Y);C.MoveTo(X,Y);C.LineTo(X,Y+S);
      if V mod 4=0 then Fill(C,X+S div 3,Y+S div 3,S div 3,S div 3,$00808A90);end;
    '+':begin Fill(C,X,Y,S,S,$00302838);C.Brush.Style:=bsClear;C.Pen.Color:=$00C060B0;C.Ellipse(X+2,Y+2,X+S-2,Y+S-2);
      C.Pen.Color:=$00F0A0E0;C.Ellipse(X+S div 4,Y+S div 4,X+S*3 div 4,Y+S*3 div 4);C.Brush.Style:=bsSolid;end;
    '#':begin Fill(C,X,Y,S,S,$00303A40);Fill(C,X+1,Y+1,S-2,S div 2-2,$00485258);Fill(C,X+1,Y+S div 2,S-2,S div 2-2,$00404A50);
      C.Pen.Color:=$00283034;C.MoveTo(X+S div 3+(V mod 2)*S div 3,Y);C.LineTo(X+S div 3+(V mod 2)*S div 3,Y+S div 2);end;
    '~':begin Fill(C,X,Y,S,S,$00905A28);C.Pen.Color:=$00C08848;
      C.MoveTo(X+2+(V mod 3)*2,Y+S div 3);C.LineTo(X+S div 2,Y+S div 3-2);C.LineTo(X+S-4,Y+S div 3);
      C.MoveTo(X+4,Y+S*2 div 3+(V mod 2)*2);C.LineTo(X+S div 2+2,Y+S*2 div 3-2);end;
    '%':begin Fill(C,X,Y,S,S,$00505860);Fill(C,X+2,Y+S div 2,S-4,S div 2-3,$00305A80);
      for I:=0 to 3 do if I mod 2=0 then Fill(C,X+I*S div 4,Y+2,S div 4,S div 3,$002030C0) else Fill(C,X+I*S div 4,Y+2,S div 4,S div 3,$00E0E8F0);end;
    '&':begin Fill(C,X,Y,S,S,$00707A80);C.Brush.Color:=$00909AA0;C.Pen.Color:=$00505860;C.Ellipse(X+1,Y+1,X+S-1,Y+S-1);
      C.Brush.Color:=$00C08040;C.Ellipse(X+4,Y+4,X+S-4,Y+S-4);Fill(C,X+S div 2-U,Y+S div 4,2*U,S div 4,$00F8E0C0);end;
  else
    if BW=0 then begin Fill(C,X,Y,S,S,$00303840);Exit;end;
    Roof:=RoofColor(Ch);
    if BY>=BH-2 then begin
      // Facade: two rows of wall with windows and a door in the middle.
      Fill(C,X,Y,S,S,$00475459);C.Pen.Color:=$003B4348;C.MoveTo(X,Y+S div 2);C.LineTo(X+S,Y+S div 2);
      if (BX=BW div 2) and (BY=BH-1) then begin Fill(C,X+S div 5,Y+2,S*3 div 5,S-2,$001B2028);Fill(C,X+S*3 div 5,Y+S div 2,U,U,$0040B0E0);end
      else if (BY=BH-2) and (BX mod 2=1) then Fill(C,X+S div 4,Y+S div 4,S div 2,S div 2,$006CA9D3);
    end else begin
      Fill(C,X,Y,S,S,Roof);C.Pen.Color:=(Roof and $00FCFCFC) shr 1;
      for I:=1 to 3 do begin C.MoveTo(X,Y+I*S div 4);C.LineTo(X+S,Y+I*S div 4);end;
      if BY=0 then Fill(C,X,Y,S,2*U,(Roof and $00FEFEFE) shr 1+$00202020);
      if (BX=0) or (BX=BW-1) then Fill(C,X+IfThen(BX=0,0,S-2*U),Y,2*U,S,(Roof and $00FCFCFC) shr 2);
    end;
  end;
end;

{ Townsfolk, keepers and animals. }
procedure Npc(C:TCanvas;X,Y,S:Integer;const Kind:string;Now:QWord);
var U,CX,CY,I,Bob:Integer;Cloth,Hat:TColor;
begin
  U:=Max(1,S div 12);CX:=X+S div 2;CY:=Y+S div 2;Bob:=Ord((Now div 400) mod 2=0)*U;
  C.Brush.Color:=$00141618;C.Pen.Color:=C.Brush.Color;C.Ellipse(X+S div 4,Y+S*3 div 4,X+S*3 div 4,Y+S-2);
  if Kind='dog' then begin
    Fill(C,CX-4*U,CY,7*U,3*U,$00305878);Fill(C,CX+2*U,CY-2*U,3*U,3*U,$00305878);Fill(C,CX-5*U,CY-U,U,2*U,$00305878);
    for I:=0 to 3 do Fill(C,CX-4*U+I*2*U,CY+3*U,U,2*U-Bob,$00284868);Exit;end;
  if Kind='cat' then begin
    Fill(C,CX-3*U,CY+U,5*U,3*U,$00303034);Fill(C,CX+U,CY-U,3*U,3*U,$00303034);Fill(C,CX+U,CY-2*U,U,U,$00303034);Fill(C,CX+3*U,CY-2*U,U,U,$00303034);
    Fill(C,CX+2*U,CY,U,U,$0040E0F0);C.Pen.Color:=$00303034;C.MoveTo(CX-3*U,CY+2*U);C.LineTo(CX-5*U,CY-Bob);Exit;end;
  if Kind='bird' then begin
    for I:=0 to 2 do begin Fill(C,X+3*U+I*3*U,Y+S div 2+((I+Ord(Bob>0)) mod 2)*2*U,2*U,2*U,$00909098);Fill(C,X+5*U+I*3*U,Y+S div 2+((I+Ord(Bob>0)) mod 2)*2*U,U,U,$0030A0E0);end;Exit;end;
  if Kind='dummy' then begin
    Fill(C,CX-U,CY-4*U,2*U,9*U,$00305A80);Fill(C,CX-5*U,CY-2*U,10*U,2*U,$00305A80);
    C.Brush.Color:=$0050B8D8;C.Pen.Color:=$00305A80;C.Ellipse(CX-3*U,CY-6*U,CX+3*U,CY);
    C.Brush.Style:=bsClear;C.Pen.Color:=$002020C0;C.Ellipse(CX-2*U,CY-5*U,CX+2*U,CY-U);C.Brush.Style:=bsSolid;Exit;end;
  case Kind of
    'smith':begin Cloth:=$00283850;Hat:=$00202020;end;'alchemist':begin Cloth:=$00508050;Hat:=$00306030;end;
    'noble':begin Cloth:=$00702050;Hat:=$0030B0E0;end;'merchant':begin Cloth:=$003070B0;Hat:=$002050A0;end;
    'innkeeper':begin Cloth:=$00406080;Hat:=$00E0E0E0;end;'priest':begin Cloth:=$00E0E0F0;Hat:=$0040C0F0;end;
    'herald':begin Cloth:=$002020A0;Hat:=$0030B0E0;end;'mentor':begin Cloth:=$00804020;Hat:=$00C0C0C0;end;
    'scholar':begin Cloth:=$00503030;Hat:=$00402020;end;'guard':begin Cloth:=$00505860;Hat:=$00909898;end;
    'seer':begin Cloth:=$00802080;Hat:=$00F060C0;end;'bard':begin Cloth:=$0020A0C0;Hat:=$002040A0;end;
    'child':begin Cloth:=$0040A040;Hat:=$00305878;end;
  else begin Cloth:=$00607080;Hat:=$00406070;end;end;
  if Kind='child' then begin U:=Max(1,U*3 div 4);CY:=CY+2*U;end;
  Fill(C,CX-3*U,CY-2*U+Bob,6*U,6*U,Cloth);Fill(C,CX-2*U,CY-5*U+Bob,4*U,3*U,$007EABD0);Fill(C,CX-2*U,CY-6*U+Bob,4*U,U,Hat);
  Fill(C,CX-2*U,CY+4*U,U,2*U,$00303840);Fill(C,CX+U,CY+4*U,U,2*U,$00303840);
  Fill(C,CX-4*U,CY-U+Bob,U,3*U,$007EABD0);Fill(C,CX+3*U,CY-U+Bob,U,3*U,$007EABD0);
  case Kind of
    'smith':Fill(C,CX+4*U,CY-3*U,2*U,3*U,$00909898);
    'guard':begin Fill(C,CX+4*U,CY-6*U,U,10*U,$00305A80);Fill(C,CX+4*U,CY-7*U,U,2*U,$00C0C8C8);end;
    'priest','mentor','seer','scholar':Fill(C,CX+4*U,CY-4*U,U,9*U,$004174AB);
    'bard':begin C.Brush.Color:=$00205A90;C.Pen.Color:=$00103050;C.Ellipse(CX+2*U,CY,CX+6*U,CY+4*U);end;
    'merchant':Fill(C,CX-6*U,CY,3*U,3*U,$00305A80);
    'alchemist':begin Fill(C,CX+4*U,CY,2*U,3*U,$0030C060);Fill(C,CX+4*U,CY-U,2*U,U,$00A0A0A0);end;
  end;
end;

{ Words of Text that fit Width pixels, the rest left in Text. }
function TakeLine(C:TCanvas;var Text:string;Width:Integer):string;
var Words:TStringArray;I:Integer;Next:string;
begin
  Result:='';Words:=Text.Split(' ');I:=0;
  while I<Length(Words) do begin
    if Result='' then Next:=Words[I] else Next:=Result+' '+Words[I];
    if (Result<>'') and (C.TextWidth(Next)>Width) then Break;
    Result:=Next;Inc(I);
  end;
  Text:='';for I:=I to High(Words) do if Text='' then Text:=Words[I] else Text:=Text+' '+Words[I];
end;

procedure Label_(C:TCanvas;CX,Y:Integer;const Text:string;Color,Back:TColor);
var W:Integer;
begin
  if Text='' then Exit;W:=C.TextWidth(Text);
  Fill(C,CX-W div 2-3,Y,W+6,C.TextHeight('Ag')+1,Back);C.Brush.Style:=bsClear;C.Font.Color:=Color;C.TextOut(CX-W div 2,Y,Text);C.Brush.Style:=bsSolid;
end;

procedure RenderPlaza(C:TCanvas;Width,Height:Integer;Plaza,Town:TJSONData;const Hero,Talk:string;HeroX,HeroY:Integer;Now:QWord;var View:TPlazaView);
var TW,TH,S,Columns,Rows,LeftCell,TopCell,OX,OY,X,Y,PX,PY,I,J,K,MX,MY,BI:Integer;
  Buildings,B,List,E,Near:TJSONData;Ch:Char;Text,Rest,Emote:string;
  BX,BY,BW,BH:array of Integer;BL:array of Char;
  procedure Cell(const AX,AY:Integer;out CX,CY:Integer);
  begin CX:=OX+(AX-LeftCell)*S;CY:=OY+(AY-TopCell)*S;end;
  function Visible(AX,AY:Integer):Boolean;
  begin Result:=(AX>=LeftCell) and (AX<LeftCell+Columns) and (AY>=TopCell) and (AY<TopCell+Rows);end;
begin
  C.Brush.Style:=bsSolid;C.Brush.Color:=$001A1E20;C.FillRect(Rect(0,0,Width,Height));
  C.Font.Name:='Georgia';C.Font.Color:=$008DC5DC;C.Font.Size:=25;C.TextOut(24,15,'GRIM HOLLOW');
  C.Font.Name:='Tahoma';C.Font.Size:=10;C.Font.Color:=$008D9B9F;
  View.S:=0;
  if (Town=nil) or (Plaza=nil) then begin C.TextOut(26,61,'Город загружается…');Exit;end;
  TW:=JInt(Town,'width',56);TH:=JInt(Town,'height',36);
  C.TextOut(26,58,'ПЕПЕЛЬНЫЙ ПРЕДЕЛ — город. Героев на площади: '+JStr(Plaza,'online','1'));
  C.Font.Color:=$008DC5DC;C.TextOut(26,80,'WASD — ходить, F — поговорить, 1–5 — жесты, T — городские службы. Поход — у Стража Врат на севере.');
  S:=32;if Height<560 then S:=24;
  Columns:=Min(TW,(Width-40) div S);Rows:=Max(7,Min(TH,(Height-190) div S));
  LeftCell:=EnsureRange(HeroX-Columns div 2,0,TW-Columns);TopCell:=EnsureRange(HeroY-Rows div 2,0,TH-Rows);
  OX:=(Width-Columns*S) div 2;OY:=108;
  View.OX:=OX;View.OY:=OY;View.S:=S;View.LeftCell:=LeftCell;View.TopCell:=TopCell;View.Columns:=Columns;View.Rows:=Rows;
  Buildings:=Town.FindPath('buildings');K:=0;if Buildings<>nil then K:=Buildings.Count;
  SetLength(BX,K);SetLength(BY,K);SetLength(BW,K);SetLength(BH,K);SetLength(BL,K);
  for I:=0 to K-1 do begin B:=Buildings.Items[I];BX[I]:=JInt(B,'x');BY[I]:=JInt(B,'y');BW[I]:=JInt(B,'w');BH[I]:=JInt(B,'h');
    Text:=JStr(B,'letter','?');BL[I]:=Text[1];end;
  for Y:=TopCell to TopCell+Rows-1 do for X:=LeftCell to LeftCell+Columns-1 do begin
    Ch:=MapChar(Town,X,Y);Cell(X,Y,PX,PY);BI:=-1;
    if Ch in ['A'..'Z'] then for I:=0 to K-1 do if (BL[I]=Ch) and (X>=BX[I]) and (X<BX[I]+BW[I]) and (Y>=BY[I]) and (Y<BY[I]+BH[I]) then BI:=I;
    if BI>=0 then Tile(C,PX,PY,S,Ch,X*13+Y*7,X-BX[BI],Y-BY[BI],BW[BI],BH[BI]) else Tile(C,PX,PY,S,Ch,X*13+Y*7,0,0,0,0);
  end;
  // Signboards over every building in view.
  C.Font.Size:=9;
  for I:=0 to K-1 do if Visible(BX[I]+BW[I] div 2,BY[I]) or Visible(BX[I],BY[I]+BH[I]-1) then begin
    Cell(BX[I],BY[I],PX,PY);Text:=JStr(Buildings.Items[I],'name');J:=C.TextWidth(Text) div 2+6;
    Label_(C,Max(OX+J,Min(OX+Columns*S-J,PX+BW[I]*S div 2)),Max(OY+2,PY+4),Text,$0090D8F0,$00202830);end;
  // Keepers and walkers, then heroes; labels last so that they stay readable.
  List:=Plaza.FindPath('npcs');
  if List<>nil then for I:=0 to List.Count-1 do begin E:=List.Items[I];
    if Visible(JInt(E,'x'),JInt(E,'y')) then begin Cell(JInt(E,'x'),JInt(E,'y'),PX,PY);Npc(C,PX,PY,S,JStr(E,'kind'),Now+QWord(I)*137);end;end;
  List:=Plaza.FindPath('players');
  if List<>nil then for I:=0 to List.Count-1 do begin E:=List.Items[I];
    if Visible(JInt(E,'x'),JInt(E,'y')) then begin Cell(JInt(E,'x'),JInt(E,'y'),PX,PY);Figure(C,PX,PY,S,JStr(E,'class_id','guardian'),True,False,0,False);end;end;
  if Visible(HeroX,HeroY) then begin Cell(HeroX,HeroY,PX,PY);Figure(C,PX,PY,S,JStr(Plaza,'self.class_id','guardian'),True,True,0,False);end;
  C.Font.Size:=7;
  List:=Plaza.FindPath('npcs');
  if List<>nil then for I:=0 to List.Count-1 do begin E:=List.Items[I];
    if (JStr(E,'service')<>'') and Visible(JInt(E,'x'),JInt(E,'y')) then begin Cell(JInt(E,'x'),JInt(E,'y'),PX,PY);
      Label_(C,PX+S div 2,PY-11,JStr(E,'name'),$0050C8E8,$00181C1E);end;end;
  List:=Plaza.FindPath('players');K:=0;if List<>nil then K:=List.Count;
  for I:=0 to K do begin
    // Index K is the own hero, drawn at its predicted cell.
    if I<K then begin E:=List.Items[I];X:=JInt(E,'x');Y:=JInt(E,'y');end else begin E:=Plaza.FindPath('self');X:=HeroX;Y:=HeroY;end;
    if (E=nil) or not Visible(X,Y) then Continue;Cell(X,Y,PX,PY);
    // Short labels keep a crowd readable; the build shows for the own hero and on the figure.
    Text:=JStr(E,'name')+' ['+JStr(E,'level','1')+']';
    if I=K then Text:=Text+' '+GameWord(JStr(E,'origin'))+' '+GameWord(JStr(E,'class_id'));
    if I=K then Label_(C,PX+S div 2,PY-11,Text,$0080F0FF,$00303018) else Label_(C,PX+S div 2,PY-11,Text,$00F0F0F0,$00282018);
    Emote:=JStr(E,'emote');
    if Emote<>'' then begin
      case Emote of 'wave':Emote:='* машет *';'bow':Emote:='* кланяется *';'cheer':Emote:='* ликует *';'dance':Emote:='* танцует *';'sit':Emote:='* отдыхает *';end;
      Label_(C,PX+S div 2,PY+S,Emote,$0080F080,$00182018);end;
    Text:=JStr(E,'bubble');
    if Text<>'' then begin C.Font.Size:=8;Rest:=Text;Text:=TakeLine(C,Rest,220);if Rest<>'' then Text:=Text+'…';
      Label_(C,PX+S div 2,PY-28,Text,$00101010,$00E8F4F8);C.Font.Size:=7;end;
  end;
  Frame(C,Rect(OX-3,OY-3,OX+Columns*S+3,OY+Rows*S+3));
  // Overview of the whole town: heroes in blue, keepers in gold, the own hero bright.
  MX:=Width-TW*2-14;MY:=8;
  for Y:=0 to TH-1 do for X:=0 to TW-1 do begin Ch:=MapChar(Town,X,Y);
    case Ch of ',','*':C.Brush.Color:=$00284A30;'.',':','+':C.Brush.Color:=$00586068;'~':C.Brush.Color:=$00804A20;'T':C.Brush.Color:=$00183A20;
    else if Ch in ['A'..'Z'] then C.Brush.Color:=RoofColor(Ch) else C.Brush.Color:=$00303840;end;
    C.FillRect(Rect(MX+X*2,MY+Y*2,MX+X*2+2,MY+Y*2+2));end;
  List:=Plaza.FindPath('npcs');
  if List<>nil then for I:=0 to List.Count-1 do if JStr(List.Items[I],'service')<>'' then Fill(C,MX+JInt(List.Items[I],'x')*2,MY+JInt(List.Items[I],'y')*2,2,2,$0030C0F0);
  List:=Plaza.FindPath('players');
  if List<>nil then for I:=0 to List.Count-1 do Fill(C,MX+JInt(List.Items[I],'x')*2-1,MY+JInt(List.Items[I],'y')*2-1,3,3,$00F0A040);
  Fill(C,MX+HeroX*2-1,MY+HeroY*2-1,4,4,$0080F8FF);
  Frame(C,Rect(MX-2,MY-2,MX+TW*2+2,MY+TH*2+2));
  // Who is near, and what the last keeper said.
  C.Font.Size:=9;Y:=OY+Rows*S+8;
  Near:=NearestNpc(Plaza,HeroX,HeroY,2);
  C.Brush.Style:=bsClear;C.Font.Color:=$009EAEBB;
  if Near<>nil then C.TextOut(26,Y,'Рядом: '+JStr(Near,'name')+'. Нажмите F, чтобы поговорить.')
  else C.TextOut(26,Y,'Подойдите к жителю и нажмите F. Над героями видны сообщения общего чата.');
  C.Brush.Style:=bsSolid;
  // The conversation: a box over the lower part of the square, sized to the whole answer.
  // Talk is "Name: words", optionally followed by a line feed and a hint.
  if Talk<>'' then begin
    Text:=Talk;Emote:='';J:=Pos(#10,Text);
    if J>0 then begin Emote:=Copy(Text,J+1,MaxInt);Text:=Copy(Text,1,J-1);end;
    C.Font.Size:=10;K:=Min(Columns*S-24,720);
    // Count the wrapped lines first, then draw.
    Rest:=Text;J:=0;while (Rest<>'') and (J<8) do begin TakeLine(C,Rest,K-24);Inc(J);end;
    TW:=K;TH:=16+J*19+IfThen(Emote<>'',24,0);
    MX:=OX+(Columns*S-TW) div 2;MY:=OY+Rows*S-TH-10;
    Fill(C,MX,MY,TW,TH,$00182028);Frame(C,Rect(MX,MY,MX+TW,MY+TH));
    C.Brush.Style:=bsClear;C.Font.Color:=$00A0E0F0;Rest:=Text;
    for I:=0 to J-1 do C.TextOut(MX+12,MY+8+I*19,TakeLine(C,Rest,K-24));
    if Emote<>'' then begin C.Font.Size:=9;C.Font.Color:=$0080C890;C.TextOut(MX+12,MY+TH-24,Emote);end;
    C.Brush.Style:=bsSolid;
  end;
end;
end.
