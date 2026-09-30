unit GameTransport;
{$mode objfpc}{$H+}
interface
uses Classes, SysUtils, fphttpclient;
type
  TResponseEvent = procedure(const Kind, Response, Error:string) of object;
  TRequestThread = class(TThread)
  private
    FOnResult: TResponseEvent;
    FUrl, FToken, FBody, FMethod, FKind, FResponse, FError: string;
    procedure Deliver;
  protected
    procedure Execute; override;
  public
    constructor Create(OnResult: TResponseEvent; const Url, Token, Body, Method, Kind: string);
  end;


implementation
uses NativeNetwork;
constructor TRequestThread.Create(OnResult: TResponseEvent; const Url, Token, Body, Method, Kind: string);
begin
  inherited Create(True); FreeOnTerminate:=True;
  FOnResult:=OnResult; FUrl:=Url; FToken:=Token; FBody:=Body; FMethod:=Method; FKind:=Kind; Start;
end;

procedure TRequestThread.Execute;
var Client: TFPHTTPClient; Output: TStringStream; Status:Integer;
begin
  if Copy(FUrl,1,8)='https://' then begin
    try FResponse:=NativeHttps(FUrl,FToken,FBody,FMethod,Status);
      if Status>=400 then FError:='HTTP '+IntToStr(Status)+': '+Copy(FResponse,1,220);
      if (Status>=300) and (Status<400) then FError:='Server redirects are not allowed.';
    except on E:Exception do FError:=E.Message;end;
    Synchronize(@Deliver);Exit;
  end;
  Client:=TFPHTTPClient.Create(nil); Output:=TStringStream.Create('');
  try
    try
      Client.ConnectTimeout:=3000; Client.IOTimeout:=3000;
      Client.AddHeader('Accept','application/json');
      Client.AddHeader('Content-Type','application/json');
      if FToken<>'' then Client.AddHeader('Authorization','Bearer '+FToken);
      if FBody<>'' then Client.RequestBody:=TStringStream.Create(FBody);
      Client.HTTPMethod(FMethod,FUrl,Output,[200,201,204,400,401,403,404,409,422,429,500,503]);
      if Output.Size>2097152 then raise Exception.Create('Response too large');
      FResponse:=Output.DataString;
      if Client.ResponseStatusCode>=400 then FError:='HTTP '+IntToStr(Client.ResponseStatusCode)+': '+Copy(FResponse,1,220);
    except on E: Exception do FError:=E.Message; end;
  finally Client.RequestBody.Free; Client.Free; Output.Free; end;
  Synchronize(@Deliver);
end;

procedure TRequestThread.Deliver;
begin FOnResult(FKind,FResponse,FError); end;


end.
