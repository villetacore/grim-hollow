unit GameTheme;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Classes, Controls, Graphics, StdCtrls, ExtCtrls;
const TownColor=$00202A32; PanelColor=$00353E46; GoldColor=$0082C1DD;
function LabelAt(Owner:TComponent; Host:TWinControl; const AText:string; X,Y,W,H:Integer):TLabel;
function ButtonAt(Owner:TComponent; Host:TWinControl; const AText,AName:string; X,Y,W:Integer; Click:TNotifyEvent):TButton;
implementation
function LabelAt(Owner:TComponent; Host:TWinControl; const AText:string; X,Y,W,H:Integer):TLabel;
begin
  Result:=TLabel.Create(Owner); Result.Parent:=Host; Result.AutoSize:=False;
  Result.SetBounds(X,Y,W,H); Result.WordWrap:=True; Result.Caption:=AText; Result.Font.Color:=GoldColor;
end;
function ButtonAt(Owner:TComponent; Host:TWinControl; const AText,AName:string; X,Y,W:Integer; Click:TNotifyEvent):TButton;
begin
  Result:=TButton.Create(Owner); Result.Parent:=Host; Result.SetBounds(X,Y,W,30);
  Result.Caption:=AText; Result.Name:=AName; Result.OnClick:=Click; Result.Font.Color:=clBlack; Result.TabStop:=False;
end;
end.
