unit GameArt;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Graphics,Types,SysUtils,Math;
procedure Stone(C:TCanvas;X,Y,S,Variation:Integer;Wall:Boolean;const Biome:string);
procedure Figure(C:TCanvas;X,Y,S:Integer;const Kind:string;Ally,SelfHero:Boolean;HP:Integer);
procedure TownScene(C:TCanvas;W,H:Integer);
procedure Frame(C:TCanvas;const R:TRect);
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
procedure Figure(C:TCanvas;X,Y,S:Integer;const Kind:string;Ally,SelfHero:Boolean;HP:Integer);
var U,CX,CY:Integer;Cloth,Skin,Metal:TColor;
begin
  U:=Max(1,S div 12);CX:=X+S div 2;CY:=Y+S div 2;
  C.Brush.Color:=$00110F0F;C.Pen.Color:=C.Brush.Color;C.Ellipse(X+S div 5,Y+S*3 div 4,X+S*4 div 5,Y+S-2);
  Skin:=$007EABD0;Metal:=$009A9E9E;
  if Ally then begin if SelfHero then Cloth:=$00B5793D else Cloth:=$00669A48;end else begin Cloth:=$003A3EB8;Skin:=$00667493;end;
  if (Kind='arcanist') or (Kind='spitter') then Cloth:=$009D5992;
  if (Kind='ranger') or (Kind='archer') then Cloth:=$004C8454;
  if (Kind='warden') and not Ally then begin Cloth:=$003D70BC;Metal:=$006EA8BC;end;
  Fill(C,CX-3*U,CY-2*U,6*U,6*U,Cloth);Fill(C,CX-2*U,CY-5*U,4*U,3*U,Skin);
  Fill(C,CX-2*U,CY-6*U,4*U,U,Metal);Fill(C,CX-2*U,CY+4*U,U,2*U,Metal);Fill(C,CX+U,CY+4*U,U,2*U,Metal);
  Fill(C,CX-4*U,CY-U,U,3*U,Skin);Fill(C,CX+3*U,CY-U,U,3*U,Skin);
  if (Kind='arcanist') or (Kind='spitter') then begin Fill(C,CX+4*U,CY-4*U,U,9*U,$004174AB);Fill(C,CX+3*U,CY-5*U,3*U,2*U,$00E3BB77);end
  else if (Kind='ranger') or (Kind='archer') then begin C.Pen.Color:=$00628AB0;C.Brush.Style:=bsClear;C.Arc(CX+2*U,CY-4*U,CX+6*U,CY+5*U,270*16,180*16);C.Brush.Style:=bsSolid;end
  else begin Fill(C,CX+4*U,CY-5*U,U,8*U,Metal);Fill(C,CX+3*U,CY+U,3*U,U,$005B96B3);Fill(C,CX-5*U,CY-U,3*U,4*U,Metal);end;
  if SelfHero then begin C.Brush.Style:=bsClear;C.Pen.Color:=$0094D6E5;C.Rectangle(X+1,Y+1,X+S-1,Y+S-1);C.Brush.Style:=bsSolid;end;
  C.Font.Name:='Tahoma';C.Font.Size:=8;C.Font.Color:=clWhite;C.Brush.Color:=$00141618;
  C.TextOut(X+S div 2-C.TextWidth(IntToStr(HP)) div 2,Y-3,IntToStr(HP));
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
