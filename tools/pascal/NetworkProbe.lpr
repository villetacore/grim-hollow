program NetworkProbe;
{$mode objfpc}{$H+}
uses Classes,SysUtils,NativeNetwork;
var Status:Integer;Body:string;
begin
  try
    if ParamCount<>1 then raise Exception.Create('Usage: NetworkProbe HTTPS_URL');
    Body:=NativeHttps(ParamStr(1),'','','GET',Status);
    WriteLn('HTTPS_OK status=',Status,' bytes=',Length(Body));
  except on E:Exception do begin WriteLn('HTTPS_REJECTED ',E.Message);Halt(1);end;end;
end.
