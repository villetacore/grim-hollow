unit GameProtocol;
{$mode objfpc}{$H+}
interface
uses SysUtils, fpjson;
procedure ValidateSnapshot(Data: TJSONData);
function NewCommandId: string;
implementation
procedure ValidateSnapshot(Data: TJSONData);
var W, Row: TJSONData; I: Integer;
begin
  if (Data.JSONType <> jtObject) or (Data.FindPath('v')=nil) or
    (Data.FindPath('v').AsInteger<>1) then raise Exception.Create('Unsupported protocol');
  W := Data.FindPath('world');
  if (W=nil) or (W.FindPath('self')=nil) or (W.FindPath('map')=nil) then
    raise Exception.Create('Incomplete snapshot');
  if W.FindPath('map').Count<>32 then raise Exception.Create('Invalid map height');
  for I:=0 to 31 do begin
    Row:=W.FindPath('map').Items[I];
    if (Row.JSONType<>jtString) or (Length(Row.AsString)<>32) then
      raise Exception.Create('Invalid map row');
  end;
end;
function NewCommandId: string;
var Id: TGuid;
begin
  if CreateGUID(Id)<>0 then raise Exception.Create('Cannot create command ID');
  Result:=LowerCase(Copy(GUIDToString(Id),2,36));
end;
end.
