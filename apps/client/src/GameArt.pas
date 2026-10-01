unit GameArt;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Graphics,Types,SysUtils,Math;
procedure Stone(C:TCanvas;X,Y,S,Variation:Integer;Wall:Boolean;const Biome:string;Dim:Boolean=False);
procedure Figure(C:TCanvas;X,Y,S:Integer;const Kind:string;Ally,SelfHero:Boolean;HP:Integer;ShowHP:Boolean=True);
procedure TownScene(C:TCanvas;W,H:Integer);
procedure Frame(C:TCanvas;const R:TRect);
procedure EnemyMarks(C:TCanvas;X,Y,S,HP,MaxHP:Integer;Elite,Boss:Boolean);
procedure DungeonObject(C:TCanvas;X,Y,S:Integer;const Kind:string;Used:Boolean);
function TierColor(Tier:Integer;UniqueItem:Boolean):TColor;
procedure ItemIcon(C:TCanvas;X,Y,S:Integer;const Icon:string;Tier:Integer;UniqueItem:Boolean);
procedure SpellIcon(C:TCanvas;X,Y,S:Integer;const Key:string);
{ Spec: 'i|icon|tier|unique' for gear, 's|key' for skills, '' for plain text. }
procedure DrawIconRow(C:TCanvas;const R:TRect;const Text,Spec:string;Selected:Boolean);
implementation
procedure Fill(C:TCanvas;X,Y,W,H:Integer;Color:TColor);
begin C.Brush.Style:=bsSolid;C.Brush.Color:=Color;C.FillRect(Rect(X,Y,X+W,Y+H));end;
procedure Frame(C:TCanvas;const R:TRect);
begin
  C.Brush.Style:=bsClear;C.Pen.Color:=$006A8794;C.Rectangle(R);
  C.Pen.Color:=$002E3E49;C.Rectangle(R.Left+2,R.Top+2,R.Right-2,R.Bottom-2);C.Brush.Style:=bsSolid;
end;
function Darken(Color:TColor):TColor;
begin Result:=(Color and $00FCFCFC) shr 2;end;
procedure Stone(C:TCanvas;X,Y,S,Variation:Integer;Wall:Boolean;const Biome:string;Dim:Boolean);
var Base,Light,Dark:TColor;I:Integer;
begin
  if Biome='monastery' then begin Base:=$004B454E;Light:=$006D6374;Dark:=$00322A39;end
  else if Biome='roots' then begin Base:=$00324439;Light:=$0049674D;Dark:=$001D2D23;end
  else if Biome='catacombs' then begin Base:=$00545A5E;Light:=$00808A8E;Dark:=$00303538;end
  else if Biome='glacier' then begin Base:=$00806A50;Light:=$00C8B090;Dark:=$00503E2C;end
  else if Biome='citadel' then begin Base:=$00283A5C;Light:=$003060A0;Dark:=$00141C30;end
  else if Biome='swamp' then begin Base:=$00384A3A;Light:=$00507A5A;Dark:=$00202C22;end
  else if Biome='abyss' then begin Base:=$00502838;Light:=$00904868;Dark:=$00281420;end
  else begin Base:=$004A4131;Light:=$0062533E;Dark:=$00342C20;end;
  // Fog of war: explored but unseen cells keep their shape in a quarter of the light.
  if Dim then begin Base:=Darken(Base);Light:=Darken(Light);Dark:=Darken(Dark);end;
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
    'bog_lurker':Result:=$00406048;'leech_swarm':Result:=$00283860;'mire_witch':Result:=$00507040;'toad_brute':Result:=$00409060;
    'wisp':Result:=$00F0F080;'void_stalker':Result:=$00602040;'star_spawn':Result:=$00C080A0;'mind_flayer':Result:=$00803070;
    'void_golem':Result:=$00401828;'comet_wisp':Result:=$0040C0F0;'mire_mother':Result:=$00305830;'void_king':Result:=$00A03080;
  else Result:=$003A3EB8;end;
end;
procedure Figure(C:TCanvas;X,Y,S:Integer;const Kind:string;Ally,SelfHero:Boolean;HP:Integer;ShowHP:Boolean);
var U,CX,CY:Integer;Cloth,Skin,Metal:TColor;Shape:Char;
begin
  U:=Max(1,S div 12);CX:=X+S div 2;CY:=Y+S div 2;
  C.Brush.Color:=$00110F0F;C.Pen.Color:=C.Brush.Color;C.Ellipse(X+S div 5,Y+S*3 div 4,X+S*4 div 5,Y+S-2);
  Skin:=$007EABD0;Metal:=$009A9E9E;Shape:='h';
  if Ally then begin
    if SelfHero then Cloth:=$00B5793D else Cloth:=$00669A48;
    if Kind='arcanist' then begin Cloth:=$009D5992;Shape:='m';end;
    if Kind='ranger' then begin Cloth:=$004C8454;Shape:='r';end;
    if Kind='berserker' then begin Cloth:=$002A3AA0;Shape:='x';end;
    if Kind='assassin' then begin Cloth:=$00303038;Shape:='k';end;
    if Kind='necromancer' then begin Cloth:=$00402838;Metal:=$00C8D8E0;Shape:='m';end;
    if Kind='druid' then begin Cloth:=$00307840;Shape:='d';end;
  end else begin
    Cloth:=EnemyCloth(Kind);Skin:=$00667493;
    case Kind of
      'rat_swarm','rift_hound','frost_wolf','hellhound','leech_swarm','void_stalker':Shape:='b';
      'spore_bloater','rift_heart','wisp','star_spawn','comet_wisp':Shape:='o';
      'gargoyle','ice_golem','yeti','magma_brute','bog_lurker','toad_brute','void_golem':Shape:='g';
      'acolyte','lich_acolyte','rime_witch','cinder_mage','prior','spitter','frost_queen','mire_witch','mind_flayer','mire_mother':Shape:='m';
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
    if Shape='m' then begin Fill(C,CX+4*U,CY-4*U,U,9*U,$004174AB);Fill(C,CX+3*U,CY-5*U,3*U,2*U,Metal);end
    else if Shape='r' then begin C.Pen.Color:=$00628AB0;C.Brush.Style:=bsClear;C.Arc(CX+2*U,CY-4*U,CX+6*U,CY+5*U,270*16,180*16);C.Brush.Style:=bsSolid;end
    else if Shape='x' then begin Fill(C,CX+4*U,CY-6*U,U,11*U,$00305A80);Fill(C,CX+5*U,CY-6*U,3*U,4*U,Metal);Fill(C,CX-3*U,CY-2*U,6*U,2*U,$001820C0);end
    else if Shape='k' then begin Fill(C,CX-3*U,CY-6*U,6*U,2*U,Cloth);Fill(C,CX+4*U,CY-U,U,4*U,Metal);Fill(C,CX-5*U,CY-U,U,4*U,Metal);end
    else if Shape='d' then begin Fill(C,CX+4*U,CY-5*U,U,10*U,$00305A80);Fill(C,CX+3*U,CY-7*U,3*U,3*U,$0030B050);Fill(C,CX-2*U,CY-6*U,4*U,U,$0030A040);end
    else begin Fill(C,CX+4*U,CY-5*U,U,8*U,Metal);Fill(C,CX+3*U,CY+U,3*U,U,$005B96B3);Fill(C,CX-5*U,CY-U,3*U,4*U,Metal);end;
  end;
  if SelfHero then begin C.Brush.Style:=bsClear;C.Pen.Color:=$0094D6E5;C.Rectangle(X+1,Y+1,X+S-1,Y+S-1);C.Brush.Style:=bsSolid;end;
  if not ShowHP then Exit;
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
function TierColor(Tier:Integer;UniqueItem:Boolean):TColor;
begin
  if UniqueItem then Result:=$0030C0F0
  else if Tier>=25 then Result:=$003030E0
  else if Tier>=15 then Result:=$002090F0
  else if Tier>=10 then Result:=$00D050B0
  else if Tier>=6 then Result:=$00E09040
  else if Tier>=3 then Result:=$0050C050
  else Result:=$00B0B0B0;
end;
procedure Line(C:TCanvas;X1,Y1,X2,Y2,W:Integer;Color:TColor);
begin C.Pen.Color:=Color;C.Pen.Width:=W;C.MoveTo(X1,Y1);C.LineTo(X2,Y2);C.Pen.Width:=1;end;
procedure Disc(C:TCanvas;CX,CY,R:Integer;Color:TColor);
begin C.Brush.Style:=bsSolid;C.Brush.Color:=Color;C.Pen.Color:=Color;C.Ellipse(CX-R,CY-R,CX+R+1,CY+R+1);end;
procedure ItemIcon(C:TCanvas;X,Y,S:Integer;const Icon:string;Tier:Integer;UniqueItem:Boolean);
const Wood=$00305A80;Leather=$00406890;Cloth=$00704868;
var U,W:Integer;M:TColor;
  function PX(N:Integer):Integer;begin Result:=X+N*U;end;
  function PY(N:Integer):Integer;begin Result:=Y+N*U;end;
begin
  U:=Max(1,S div 12);W:=Max(1,U);M:=TierColor(Tier,UniqueItem);
  Fill(C,X,Y,S,S,$00181B1D);C.Brush.Style:=bsClear;C.Pen.Color:=M;C.Rectangle(X,Y,X+S,Y+S);C.Brush.Style:=bsSolid;
  case Icon of
    'blade':begin Line(C,PX(3),PY(9),PX(9),PY(3),W*2,M);Line(C,PX(2),PY(7),PX(5),PY(10),W,Wood);Line(C,PX(2),PY(10),PX(3),PY(9),W*2,Wood);end;
    'dagger':begin Line(C,PX(4),PY(8),PX(8),PY(4),W*2,M);Line(C,PX(3),PY(7),PX(5),PY(9),W,Wood);Line(C,PX(3),PY(9),PX(4),PY(8),W*2,Wood);end;
    'axe':begin Line(C,PX(3),PY(10),PX(8),PY(3),W,Wood);C.Brush.Color:=M;C.Pen.Color:=M;C.Pie(PX(6),PY(1),PX(11),PY(6),PX(11),PY(1),PX(6),PY(6));end;
    'hammer':begin Line(C,PX(6),PY(4),PX(6),PY(11),W,Wood);Fill(C,PX(3),PY(1),6*U,3*U,M);end;
    'mace':begin Line(C,PX(4),PY(10),PX(7),PY(5),W,Wood);Disc(C,PX(8),PY(4),2*U,M);Fill(C,PX(10),PY(4),U,U,M);Fill(C,PX(8),PY(1),U,U,M);end;
    'staff':begin Line(C,PX(4),PY(11),PX(8),PY(3),W,Wood);Disc(C,PX(8),PY(3),U+U div 2,M);end;
    'wand':begin Line(C,PX(4),PY(9),PX(7),PY(5),W,Wood);Fill(C,PX(7),PY(2),U,4*U,M);Fill(C,PX(6),PY(3),3*U,U,M);end;
    'bow':begin C.Pen.Color:=Wood;C.Pen.Width:=W;C.Brush.Style:=bsClear;C.Arc(PX(2),PY(1),PX(9),PY(11),90*16,180*16);C.Brush.Style:=bsSolid;C.Pen.Width:=1;Line(C,PX(5),PY(1),PX(5),PY(11),1,M);end;
    'crossbow':begin Line(C,PX(2),PY(4),PX(10),PY(4),W,M);Line(C,PX(6),PY(3),PX(6),PY(11),W,Wood);Line(C,PX(2),PY(4),PX(6),PY(7),1,$00C0C0C0);Line(C,PX(10),PY(4),PX(6),PY(7),1,$00C0C0C0);end;
    'robe':begin C.Brush.Color:=Cloth;C.Pen.Color:=M;C.Polygon([Point(PX(4),PY(2)),Point(PX(8),PY(2)),Point(PX(10),PY(11)),Point(PX(2),PY(11))]);Fill(C,PX(5),PY(2),2*U,U,M);end;
    'jerkin':begin Fill(C,PX(3),PY(2),6*U,9*U,Leather);Fill(C,PX(3),PY(7),6*U,U,M);Fill(C,PX(1),PY(3),2*U,4*U,Leather);Fill(C,PX(9),PY(3),2*U,4*U,Leather);end;
    'mail':begin Fill(C,PX(3),PY(2),6*U,9*U,$00707070);for W:=1 to 4 do Fill(C,PX(3)+W*U,PY(3)+(W mod 2)*2*U,U,U,M);Fill(C,PX(1),PY(3),2*U,4*U,$00606060);Fill(C,PX(9),PY(3),2*U,4*U,$00606060);end;
    'plate':begin Fill(C,PX(3),PY(2),6*U,9*U,M);Line(C,PX(3),PY(5),PX(9),PY(5),1,$00303030);Line(C,PX(3),PY(8),PX(9),PY(8),1,$00303030);Fill(C,PX(1),PY(2),2*U,3*U,M);Fill(C,PX(9),PY(2),2*U,3*U,M);end;
    'shield':begin C.Brush.Color:=$00506070;C.Pen.Color:=M;C.Polygon([Point(PX(2),PY(2)),Point(PX(10),PY(2)),Point(PX(10),PY(6)),Point(PX(6),PY(11)),Point(PX(2),PY(6))]);Fill(C,PX(5),PY(3),2*U,5*U,M);end;
    'tome':begin Fill(C,PX(2),PY(2),8*U,9*U,$00304090);Fill(C,PX(3),PY(3),6*U,7*U,$0090B0C0);Fill(C,PX(5),PY(5),2*U,2*U,M);end;
    'orb':begin Disc(C,PX(6),PY(6),4*U,M);Disc(C,PX(5),PY(5),U,$00F0F0F0);end;
    'quiver':begin Fill(C,PX(4),PY(4),4*U,7*U,Leather);for W:=0 to 2 do Line(C,PX(4)+W*U+U,PY(1),PX(4)+W*U+U,PY(4),1,M);end;
    'ring':begin C.Brush.Style:=bsClear;C.Pen.Color:=M;C.Pen.Width:=W;C.Ellipse(PX(3),PY(4),PX(9),PY(10));C.Pen.Width:=1;C.Brush.Style:=bsSolid;Disc(C,PX(6),PY(3),U,$006060F0);end;
    'signet':begin C.Brush.Style:=bsClear;C.Pen.Color:=M;C.Pen.Width:=W*2;C.Ellipse(PX(3),PY(4),PX(9),PY(10));C.Pen.Width:=1;C.Brush.Style:=bsSolid;Fill(C,PX(5),PY(2),2*U,2*U,M);end;
    'amulet':begin Line(C,PX(2),PY(1),PX(6),PY(6),1,M);Line(C,PX(10),PY(1),PX(6),PY(6),1,M);Disc(C,PX(6),PY(8),2*U,M);Disc(C,PX(6),PY(8),U,$0040E060);end;
    'talisman':begin Line(C,PX(2),PY(1),PX(6),PY(5),1,M);Line(C,PX(10),PY(1),PX(6),PY(5),1,M);C.Brush.Color:=M;C.Pen.Color:=M;C.Polygon([Point(PX(6),PY(5)),Point(PX(9),PY(8)),Point(PX(6),PY(11)),Point(PX(3),PY(8))]);end;
    'greatsword':begin Line(C,PX(2),PY(10),PX(10),PY(2),W*3,M);Line(C,PX(1),PY(8),PX(4),PY(11),W*2,Wood);end;
    'spear':begin Line(C,PX(2),PY(11),PX(9),PY(4),W,Wood);C.Brush.Color:=M;C.Pen.Color:=M;C.Polygon([Point(PX(11),PY(1)),Point(PX(8),PY(3)),Point(PX(10),PY(5))]);end;
    'scythe':begin Line(C,PX(4),PY(11),PX(7),PY(2),W,Wood);C.Pen.Color:=M;C.Pen.Width:=W*2;C.Brush.Style:=bsClear;C.Arc(PX(1),PY(1),PX(10),PY(7),0,180*16);C.Brush.Style:=bsSolid;C.Pen.Width:=1;end;
    'claws':begin for W:=0 to 2 do Line(C,PX(3+W*2),PY(10),PX(5+W*2),PY(2),Max(1,U),M);Fill(C,PX(2),PY(9),8*U,2*U,Leather);end;
    'totem':begin Fill(C,PX(5),PY(3),2*U,8*U,Wood);Fill(C,PX(3),PY(1),6*U,3*U,M);Fill(C,PX(4),PY(2),U,U,$002020C0);Fill(C,PX(7),PY(2),U,U,$002020C0);end;
    'sickle':begin Line(C,PX(4),PY(11),PX(5),PY(7),W,Wood);C.Pen.Color:=M;C.Pen.Width:=W*2;C.Brush.Style:=bsClear;C.Arc(PX(3),PY(1),PX(11),PY(9),90*16,180*16);C.Brush.Style:=bsSolid;C.Pen.Width:=1;end;
    'helm':begin Fill(C,PX(3),PY(3),6*U,6*U,M);Fill(C,PX(2),PY(8),8*U,2*U,M);Fill(C,PX(5),PY(5),2*U,3*U,$00181B1D);end;
    'hood':begin C.Brush.Color:=Cloth;C.Pen.Color:=M;C.Polygon([Point(PX(6),PY(1)),Point(PX(10),PY(9)),Point(PX(2),PY(9))]);Fill(C,PX(5),PY(5),2*U,2*U,$00181B1D);end;
    'circlet':begin C.Brush.Style:=bsClear;C.Pen.Color:=M;C.Pen.Width:=W;C.Ellipse(PX(2),PY(4),PX(10),PY(9));C.Pen.Width:=1;C.Brush.Style:=bsSolid;Disc(C,PX(6),PY(4),U,$00F0A040);end;
    'gauntlets':begin Fill(C,PX(3),PY(3),6*U,7*U,M);for W:=0 to 3 do Fill(C,PX(3)+W*3*U div 2,PY(1),U,2*U,M);end;
    'wraps':begin Fill(C,PX(3),PY(3),6*U,7*U,$00A0B8C8);for W:=0 to 2 do Line(C,PX(3),PY(4+W*2),PX(9),PY(5+W*2),1,M);end;
    'bracers':begin Fill(C,PX(2),PY(4),8*U,5*U,Leather);Fill(C,PX(2),PY(5),8*U,U,M);Fill(C,PX(2),PY(7),8*U,U,M);end;
    'boots':begin Fill(C,PX(3),PY(2),4*U,7*U,Leather);Fill(C,PX(3),PY(8),7*U,3*U,Leather);Fill(C,PX(3),PY(3),4*U,U,M);end;
    'greaves':begin Fill(C,PX(2),PY(1),3*U,10*U,M);Fill(C,PX(7),PY(1),3*U,10*U,M);Line(C,PX(2),PY(5),PX(10),PY(5),1,$00303030);end;
    'sandals':begin Fill(C,PX(2),PY(8),8*U,2*U,Wood);Line(C,PX(4),PY(8),PX(6),PY(4),1,M);Line(C,PX(8),PY(8),PX(6),PY(4),1,M);end;
  else Disc(C,PX(6),PY(6),3*U,M);end;
  C.Brush.Style:=bsSolid;
end;
procedure SpellIcon(C:TCanvas;X,Y,S:Integer;const Key:string);
var U:Integer;
  function PX(N:Integer):Integer;begin Result:=X+N*U;end;
  function PY(N:Integer):Integer;begin Result:=Y+N*U;end;
begin
  U:=Max(1,S div 12);Fill(C,X,Y,S,S,$00282420);C.Brush.Style:=bsClear;C.Pen.Color:=$00607080;C.Rectangle(X,Y,X+S,Y+S);C.Brush.Style:=bsSolid;
  case Key of
    'attack':begin Line(C,PX(3),PY(9),PX(9),PY(3),2*U,$00C0C0C0);Line(C,PX(2),PY(7),PX(5),PY(10),U,$00305A80);end;
    'bash':begin C.Brush.Color:=$00506070;C.Pen.Color:=$00C0C0C0;C.Polygon([Point(PX(2),PY(2)),Point(PX(9),PY(2)),Point(PX(9),PY(6)),Point(PX(5),PY(10)),Point(PX(2),PY(6))]);Line(C,PX(8),PY(8),PX(11),PY(11),U,$0020A0F0);end;
    'guard':begin C.Brush.Color:=$00A06030;C.Pen.Color:=$00F0C080;C.Polygon([Point(PX(2),PY(2)),Point(PX(10),PY(2)),Point(PX(10),PY(6)),Point(PX(6),PY(11)),Point(PX(2),PY(6))]);end;
    'potion':begin Fill(C,PX(5),PY(1),2*U,3*U,$00A0A0A0);Disc(C,PX(6),PY(7),3*U,$002020C0);Fill(C,PX(5),PY(5),U,U,$008080FF);end;
    'firebolt':begin Line(C,PX(2),PY(10),PX(6),PY(6),U,$000060D0);Disc(C,PX(7),PY(5),2*U,$0000A0FF);Disc(C,PX(7),PY(5),U,$0080E0FF);end;
    'mend':begin Fill(C,PX(5),PY(2),2*U,8*U,$0040D040);Fill(C,PX(2),PY(5),8*U,2*U,$0040D040);end;
    'frost':begin C.Brush.Color:=$00F0D090;C.Pen.Color:=$00FFF0D0;C.Polygon([Point(PX(6),PY(1)),Point(PX(8),PY(6)),Point(PX(6),PY(11)),Point(PX(4),PY(6))]);end;
    'nova':begin C.Brush.Style:=bsClear;C.Pen.Color:=$0000A0FF;C.Pen.Width:=U;C.Ellipse(PX(2),PY(2),PX(10),PY(10));C.Pen.Width:=1;C.Brush.Style:=bsSolid;Disc(C,PX(6),PY(6),U,$0080E0FF);end;
    'venom':begin C.Brush.Color:=$0030B040;C.Pen.Color:=$0030B040;C.Polygon([Point(PX(6),PY(1)),Point(PX(9),PY(7)),Point(PX(3),PY(7))]);Disc(C,PX(6),PY(8),3*U,$0030B040);end;
    'chain':begin C.Pen.Color:=$0040F0F0;C.Pen.Width:=U;C.MoveTo(PX(7),PY(1));C.LineTo(PX(4),PY(6));C.LineTo(PX(8),PY(6));C.LineTo(PX(5),PY(11));C.Pen.Width:=1;end;
    'barrier':begin C.Brush.Style:=bsClear;C.Pen.Color:=$00FFC060;C.Pen.Width:=U;C.Ellipse(PX(1),PY(1),PX(11),PY(11));C.Pen.Width:=1;C.Brush.Style:=bsSolid;Fill(C,PX(5),PY(4),2*U,4*U,$00FFC060);end;
    'meteor':begin Line(C,PX(10),PY(1),PX(6),PY(5),2*U,$000040E0);Disc(C,PX(5),PY(7),3*U,$00304050);Disc(C,PX(4),PY(6),U,$0000A0FF);end;
    'whirlwind':begin C.Pen.Color:=$00D0D0D0;C.Pen.Width:=U;C.Brush.Style:=bsClear;C.Arc(PX(1),PY(1),PX(11),PY(11),0,270*16);C.Arc(PX(3),PY(3),PX(9),PY(9),180*16,270*16);C.Pen.Width:=1;C.Brush.Style:=bsSolid;end;
    'blink':begin Line(C,PX(2),PY(6),PX(9),PY(6),U,$00F060C0);C.Brush.Color:=$00F060C0;C.Pen.Color:=$00F060C0;C.Polygon([Point(PX(8),PY(3)),Point(PX(11),PY(6)),Point(PX(8),PY(9))]);Fill(C,PX(2),PY(3),U,U,$00FFC0F0);Fill(C,PX(3),PY(9),U,U,$00FFC0F0);end;
    'fire_wave':begin C.Pen.Width:=U;C.Brush.Style:=bsClear;C.Pen.Color:=$0000A0FF;C.Arc(PX(1),PY(2),PX(7),PY(10),270*16,180*16);C.Pen.Color:=$000060E0;C.Arc(PX(4),PY(2),PX(10),PY(10),270*16,180*16);C.Pen.Width:=1;C.Brush.Style:=bsSolid;end;
    'drain_life':begin Disc(C,PX(4),PY(7),3*U,$002020B0);Line(C,PX(6),PY(4),PX(10),PY(1),U,$006060FF);Fill(C,PX(9),PY(1),2*U,U,$006060FF);end;
    'blood_rage':begin Disc(C,PX(6),PY(6),4*U,$001010A0);Fill(C,PX(4),PY(4),U,2*U,$004040F0);Fill(C,PX(7),PY(4),U,2*U,$004040F0);Line(C,PX(4),PY(9),PX(8),PY(8),U,$004040F0);end;
    'entangle':begin C.Pen.Width:=U;C.Brush.Style:=bsClear;C.Pen.Color:=$0030A040;C.Arc(PX(1),PY(3),PX(7),PY(11),0,180*16);C.Arc(PX(5),PY(1),PX(11),PY(9),180*16,180*16);C.Pen.Width:=1;C.Brush.Style:=bsSolid;Fill(C,PX(5),PY(8),2*U,3*U,$00306040);end;
    'bone_spear':begin Line(C,PX(1),PY(11),PX(9),PY(3),U,$00D0E0E8);C.Brush.Color:=$00F0F8FF;C.Pen.Color:=$00F0F8FF;C.Polygon([Point(PX(11),PY(1)),Point(PX(8),PY(3)),Point(PX(10),PY(4))]);end;
    'sanctuary':begin C.Brush.Style:=bsClear;C.Pen.Color:=$0060E0FF;C.Pen.Width:=U;C.Ellipse(PX(1),PY(3),PX(11),PY(11));C.Pen.Width:=1;C.Brush.Style:=bsSolid;Fill(C,PX(5),PY(1),2*U,6*U,$0060E0FF);Fill(C,PX(3),PY(3),6*U,2*U,$0060E0FF);end;
  else Disc(C,PX(6),PY(6),3*U,$00808080);end;
  C.Brush.Style:=bsSolid;
end;
procedure DrawIconRow(C:TCanvas;const R:TRect;const Text,Spec:string;Selected:Boolean);
var Parts:TStringArray;S,TX:Integer;
begin
  if Selected then C.Brush.Color:=$00604830 else C.Brush.Color:=$00202A32;
  C.FillRect(R);S:=R.Bottom-R.Top-4;TX:=R.Left+4;
  if Spec<>'' then begin
    Parts:=Spec.Split('|');
    if (Parts[0]='i') and (Length(Parts)>=4) then ItemIcon(C,R.Left+2,R.Top+2,S,Parts[1],StrToIntDef(Parts[2],1),Parts[3]='1')
    else if (Parts[0]='s') and (Length(Parts)>=2) then SpellIcon(C,R.Left+2,R.Top+2,S,Parts[1]);
    TX:=R.Left+S+8;
    if (Parts[0]='i') and (Length(Parts)>=4) then C.Font.Color:=TierColor(StrToIntDef(Parts[2],1),Parts[3]='1') else C.Font.Color:=$0082C1DD;
  end else C.Font.Color:=$0082C1DD;
  C.Brush.Style:=bsClear;C.TextOut(TX,R.Top+(R.Bottom-R.Top-C.TextHeight('Ag')) div 2,Text);C.Brush.Style:=bsSolid;
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
