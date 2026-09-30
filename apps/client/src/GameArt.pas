unit GameArt;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Graphics,Types,SysUtils,Math;
procedure Stone(C:TCanvas;X,Y,S,Variation:Integer;Wall:Boolean;const Biome:string);
procedure Figure(C:TCanvas;X,Y,S:Integer;const Kind:string;Ally,SelfHero:Boolean;HP:Integer);
procedure TownScene(C:TCanvas;W,H:Integer);
procedure Frame(C:TCanvas;const R:TRect);
procedure EnemyMarks(C:TCanvas;X,Y,S,HP,MaxHP:Integer;Elite,Boss:Boolean);
procedure DungeonObject(C:TCanvas;X,Y,S:Integer;const Kind:string;Used:Boolean);
implementation
procedure Fill(C:TCanvas;X,Y,W,H:Integer;Color:TColor);
begin C.Brush.Style:=bsSolid;C.Brush.Color:=Color;C.FillRect(Rect(X,Y,X+W,Y+H));end;
procedure Frame(C:TCanvas;const R:TRect);
begin
  C.Brush.Style:=bsClear;C.Pen.Color:=$006A8794;C.Rectangle(R);
  C.Pen.Color:=$002E3E49;C.Rectangle(R.Left+2,R.Top+2,R.Right-2,R.Bottom-2);C.Brush.Style:=bsSolid;
end;
procedure Stone(C:TCanvas;X,Y,S,Variation:Integer;Wall:Boolean;const Biome:string);
var Base,Light,Dark:TColor;I:Integer;
begin
  if Biome='monastery' then begin Base:=$004B454E;Light:=$006D6374;Dark:=$00322A39;end
  else if Biome='roots' then begin Base:=$00324439;Light:=$0049674D;Dark:=$001D2D23;end
  else if Biome='catacombs' then begin Base:=$00545A5E;Light:=$00808A8E;Dark:=$00303538;end
  else if Biome='glacier' then begin Base:=$00806A50;Light:=$00C8B090;Dark:=$00503E2C;end
  else if Biome='citadel' then begin Base:=$00283A5C;Light:=$003060A0;Dark:=$00141C30;end
  else begin Base:=$004A4131;Light:=$0062533E;Dark:=$00342C20;end;
  if Wall then begin
    Fill(C,X,Y,S,S,Dark);Fill(C,X+1,Y+1,S-2,S-5,Base);
    C.Pen.Color:=Light;C.MoveTo(X+1,Y+1);C.LineTo(X+S-2,Y+1);C.MoveTo(X+1,Y+S div 2);C.LineTo(X+S-1,Y+S div 2);
    C.Pen.Color:=Dark;C.MoveTo(X+S div 3,Y+1);C.LineTo(X+S div 3,Y+S div 2);C.MoveTo(X+S*2 div 3,Y+S div 2);C.LineTo(X+S*2 div 3,Y+S-4);
  end else begin
    Fill(C,X,Y,S,S,$00171919);Fill(C,X+1,Y+1,S-2,S-2,Dark);
    if Variation mod 3=0 then Fill(C,X+S div 3,Y+3,S div 2,2,Base);
    if Variation mod 7=0 then begin C.Pen.Color:=Base;C.MoveTo(X+2,Y+S-4);C.LineTo(X+S div 2,Y+S-4);end;
    if Variation mod 11=0 then for I:=0 to 2 do Fill(C,X+3+I*3,Y+S-7-I,2,3,$00334B38);
  end;
end;
function EnemyCloth(const Kind:string):TColor;
begin
  case Kind of
    'brute':Result:=$00384F8C;'rat_swarm':Result:=$00506070;'ghoul_miner':Result:=$00446644;
    'spitter','acolyte':Result:=$009D5992;'archer':Result:=$004C8454;'bell_wraith':Result:=$00C8C0B0;
    'gargoyle':Result:=$00707070;'flagellant':Result:=$002A2A90;'root_crawler':Result:=$00306A40;
    'thorn_archer':Result:=$00408040;'spore_bloater':Result:=$0060A0A0;'rift_hound':Result:=$00803060;
    'rift_heart':Result:=$006030C0;'skeleton','bone_archer','bone_king':Result:=$00C0D0D8;
    'lich_acolyte':Result:=$00503050;'crypt_knight':Result:=$00585050;'frost_wolf':Result:=$00F0E0C0;
    'ice_golem':Result:=$00F0C890;'rime_witch':Result:=$00E0A070;'yeti':Result:=$00F0F0F0;
    'frost_queen':Result:=$00FFD0A0;'ash_knight':Result:=$00303050;'cinder_mage':Result:=$00206EE0;
    'hellhound':Result:=$001030A0;'magma_brute':Result:=$001050D0;'ash_tyrant':Result:=$000020C0;
    'warden':Result:=$003D70BC;'prior':Result:=$00403030;
  else Result:=$003A3EB8;end;
end;
procedure Figure(C:TCanvas;X,Y,S:Integer;const Kind:string;Ally,SelfHero:Boolean;HP:Integer);
var U,CX,CY:Integer;Cloth,Skin,Metal:TColor;Shape:Char;
begin
  U:=Max(1,S div 12);CX:=X+S div 2;CY:=Y+S div 2;
  C.Brush.Color:=$00110F0F;C.Pen.Color:=C.Brush.Color;C.Ellipse(X+S div 5,Y+S*3 div 4,X+S*4 div 5,Y+S-2);
  Skin:=$007EABD0;Metal:=$009A9E9E;Shape:='h';
  if Ally then begin
    if SelfHero then Cloth:=$00B5793D else Cloth:=$00669A48;
    if Kind='arcanist' then begin Cloth:=$009D5992;Shape:='m';end;
    if Kind='ranger' then begin Cloth:=$004C8454;Shape:='r';end;
  end else begin
    Cloth:=EnemyCloth(Kind);Skin:=$00667493;
    case Kind of
      'rat_swarm','rift_hound','frost_wolf','hellhound':Shape:='b';
      'spore_bloater','rift_heart':Shape:='o';
      'gargoyle','ice_golem','yeti','magma_brute':Shape:='g';
      'acolyte','lich_acolyte','rime_witch','cinder_mage','prior','spitter','frost_queen':Shape:='m';
      'archer','thorn_archer','bone_archer':Shape:='r';
    end;
    if (Kind='skeleton') or (Kind='bone_archer') or (Kind='bone_king') then Skin:=$00D8E4EA;
    if Kind='warden' then Metal:=$006EA8BC;
  end;
  case Shape of
    'b':begin
      Fill(C,CX-4*U,CY-U,8*U,4*U,Cloth);Fill(C,CX+3*U,CY-3*U,3*U,3*U,Cloth);
      Fill(C,CX-4*U,CY+3*U,U,2*U,Cloth);Fill(C,CX-2*U,CY+3*U,U,2*U,Cloth);Fill(C,CX+U,CY+3*U,U,2*U,Cloth);Fill(C,CX+3*U,CY+3*U,U,2*U,Cloth);
      Fill(C,CX-5*U,CY-2*U,U,2*U,Cloth);Fill(C,CX+5*U,CY-2*U,U,U,$001010E0);
    end;
    'o':begin
      C.Brush.Color:=Cloth;C.Pen.Color:=$00101010;C.Ellipse(CX-5*U,CY-5*U,CX+5*U,CY+5*U);
      Fill(C,CX-2*U,CY-2*U,U,U,$0020E0F0);Fill(C,CX+U,CY-2*U,U,U,$0020E0F0);Fill(C,CX-3*U,CY+U,2*U,U,$00203040);Fill(C,CX+2*U,CY+2*U,U,U,$00203040);
    end;
    'g':begin
      Fill(C,CX-5*U,CY-4*U,10*U,9*U,Cloth);Fill(C,CX-2*U,CY-6*U,4*U,2*U,Cloth);Fill(C,CX-U,CY-5*U,U,U,$001010E0);Fill(C,CX+U,CY-5*U,U,U,$001010E0);
      Fill(C,CX-6*U,CY-3*U,U,6*U,Cloth);Fill(C,CX+5*U,CY-3*U,U,6*U,Cloth);C.Pen.Color:=$00202020;C.MoveTo(CX-4*U,CY);C.LineTo(CX+4*U,CY);
    end;
  else
    Fill(C,CX-3*U,CY-2*U,6*U,6*U,Cloth);Fill(C,CX-2*U,CY-5*U,4*U,3*U,Skin);
    Fill(C,CX-2*U,CY-6*U,4*U,U,Metal);Fill(C,CX-2*U,CY+4*U,U,2*U,Metal);Fill(C,CX+U,CY+4*U,U,2*U,Metal);
    Fill(C,CX-4*U,CY-U,U,3*U,Skin);Fill(C,CX+3*U,CY-U,U,3*U,Skin);
    if Shape='m' then begin Fill(C,CX+4*U,CY-4*U,U,9*U,$004174AB);Fill(C,CX+3*U,CY-5*U,3*U,2*U,$00E3BB77);end
    else if Shape='r' then begin C.Pen.Color:=$00628AB0;C.Brush.Style:=bsClear;C.Arc(CX+2*U,CY-4*U,CX+6*U,CY+5*U,270*16,180*16);C.Brush.Style:=bsSolid;end
    else begin Fill(C,CX+4*U,CY-5*U,U,8*U,Metal);Fill(C,CX+3*U,CY+U,3*U,U,$005B96B3);Fill(C,CX-5*U,CY-U,3*U,4*U,Metal);end;
  end;
  if SelfHero then begin C.Brush.Style:=bsClear;C.Pen.Color:=$0094D6E5;C.Rectangle(X+1,Y+1,X+S-1,Y+S-1);C.Brush.Style:=bsSolid;end;
  C.Font.Name:='Tahoma';C.Font.Size:=8;C.Font.Color:=clWhite;C.Brush.Color:=$00141618;
  C.TextOut(X+S div 2-C.TextWidth(IntToStr(HP)) div 2,Y-3,IntToStr(HP));
end;
procedure EnemyMarks(C:TCanvas;X,Y,S,HP,MaxHP:Integer;Elite,Boss:Boolean);
var W,M:Integer;
begin
  if MaxHP>0 then begin
    W:=Max(1,(S-6)*Min(HP,MaxHP) div MaxHP);
    Fill(C,X+3,Y+S-4,S-6,3,$00202020);if Boss then Fill(C,X+3,Y+S-4,W,3,$002020D0) else Fill(C,X+3,Y+S-4,W,3,$003CA03C);
  end;
  C.Brush.Style:=bsClear;M:=X+S div 2;
  if Boss then begin
    C.Pen.Color:=$002020E0;C.Rectangle(X,Y,X+S,Y+S);C.Brush.Style:=bsSolid;C.Brush.Color:=$0030C0F0;C.Pen.Color:=$0030C0F0;
    C.Polygon([Point(M-6,Y+6),Point(M-6,Y+1),Point(M-3,Y+4),Point(M,Y),Point(M+3,Y+4),Point(M+6,Y+1),Point(M+6,Y+6)]);
  end else if Elite then begin C.Pen.Color:=$0030C0F0;C.Rectangle(X+1,Y+1,X+S-1,Y+S-1);end;
  C.Brush.Style:=bsSolid;
end;
procedure DungeonObject(C:TCanvas;X,Y,S:Integer;const Kind:string;Used:Boolean);
var U,CX,CY,I:Integer;Tone:TColor;
begin
  U:=Max(1,S div 12);CX:=X+S div 2;CY:=Y+S div 2;
  if Kind='chest' then begin
    if Used then Tone:=$00283440 else Tone:=$00204A7A;
    Fill(C,CX-4*U,CY-U,8*U,5*U,Tone);Fill(C,CX-4*U,CY-3*U,8*U,2*U,Tone);
    if Used then Fill(C,CX-4*U,CY-5*U,8*U,U,$00182028) else begin Fill(C,CX-4*U,CY-U,8*U,U,$0030B0E0);Fill(C,CX-U,CY,2*U,2*U,$0030B0E0);end;
  end else if Kind='fountain' then begin
    Fill(C,CX-5*U,CY+U,10*U,3*U,$00606868);
    if Used then Tone:=$00504C48 else Tone:=$00D0A040;
    C.Brush.Color:=Tone;C.Pen.Color:=$00404040;C.Ellipse(CX-4*U,CY-3*U,CX+4*U,CY+2*U);
    if not Used then Fill(C,CX-U,CY-5*U,2*U,3*U,$00F0D890);
  end else if Kind='shrine' then begin
    if Used then Tone:=$00505050 else Tone:=$00A040A0;
    C.Brush.Color:=Tone;C.Pen.Color:=$00202020;C.Polygon([Point(CX,CY-5*U),Point(CX+4*U,CY),Point(CX,CY+5*U),Point(CX-4*U,CY)]);
    if not Used then Fill(C,CX-U,CY-U,2*U,2*U,$0060E0FF);
  end else if Kind='trap' then begin
    if Used then Tone:=$00404448 else Tone:=$00A0A8B0;C.Pen.Color:=Tone;
    for I:=0 to 3 do begin C.MoveTo(X+S div 5+I*S div 6,Y+S*3 div 4);C.LineTo(X+S div 5+I*S div 6+S div 12,Y+S div 2);C.LineTo(X+S div 5+I*S div 6+S div 6,Y+S*3 div 4);end;
  end;
  C.Brush.Style:=bsSolid;
end;
procedure TownScene(C:TCanvas;W,H:Integer);
var B:TBitmap;P:TCanvas;I,X,Y:Integer;
  procedure House(HX,HY,HW,HH:Integer;const Title:string;Roof:TColor);
  var J:Integer;
  begin
    Fill(P,HX+8,HY+HH,HW,10,$00100F0E);Fill(P,HX,HY,HW,HH,$00475459);
    for J:=0 to HH div 12 do begin P.Pen.Color:=$003B4348;P.MoveTo(HX,HY+J*12);P.LineTo(HX+HW,HY+J*12);end;
    P.Brush.Color:=Roof;P.Pen.Color:=$002A2A35;P.Polygon([Point(HX-12,HY),Point(HX+HW div 2,HY-48),Point(HX+HW+12,HY)]);
    Fill(P,HX+HW div 2-12,HY+HH-38,24,38,$001B2028);Fill(P,HX+14,HY+17,18,24,$006CA9D3);Fill(P,HX+HW-32,HY+17,18,24,$006CA9D3);
    P.Font.Size:=11;P.Font.Color:=$0091CAE2;P.Brush.Color:=$0020272C;P.TextOut(HX+HW div 2-P.TextWidth(Title) div 2,HY+HH+13,Title);
  end;
begin
  B:=TBitmap.Create;
  try
    B.SetSize(900,440);P:=B.Canvas;Fill(P,0,0,900,440,$00232B2B);
    P.Brush.Color:=$00323C39;P.Pen.Color:=P.Brush.Color;
    P.Polygon([Point(0,120),Point(110,28),Point(210,115),Point(350,6),Point(510,120),Point(680,20),Point(900,120)]);
    Fill(P,0,126,900,314,$00343A32);
    for I:=0 to 140 do begin X:=(I*73) mod 900;Y:=140+(I*47) mod 298;Fill(P,X,Y,3,2,$0045503D);end;
    Fill(P,0,312,900,55,$004B5251);Fill(P,402,180,96,260,$004B5251);
    for I:=0 to 34 do begin P.Pen.Color:=$003A4243;P.MoveTo(I*28,314);P.LineTo(I*28+10,365);end;
    House(55,202,166,96,'КУЗНИЦА',$003B517B);House(667,202,166,96,'РЫНОК',$005D6654);
    House(280,104,130,103,'ГИЛЬДИИ',$00605060);House(502,104,130,103,'АПТЕКАРЬ',$00606F3F);
    Fill(P,432,227,36,49,$0056636C);Fill(P,421,218,58,13,$00747C81);
    Fill(P,442,190,17,27,$003B85CC);Fill(P,447,181,8,31,$0079CBEC);
    Figure(P,426,339,48,'guardian',True,True,100);
    P.Font.Size:=9;P.Font.Color:=$008CB7C7;P.Brush.Style:=bsClear;
    P.TextOut(330,405,'Три огня защитят город от Разлома.');
    C.StretchDraw(Rect(20,110,W-20,H-88),B);Frame(C,Rect(18,108,W-18,H-86));
  finally B.Free;end;
end;
end.
