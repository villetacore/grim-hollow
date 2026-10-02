unit NativeNetwork;
{$mode objfpc}{$H+}
interface
uses Classes,SysUtils;
type
  TWorldMessage=procedure(const Payload,ErrorText:string) of object;
  TWorldConnection=class(TThread)
  private
    FUrl,FTicket,FMessage,FError:string;
    FCallback:TWorldMessage;
    FSocket:Pointer;
    FLock:TRTLCriticalSection;
    procedure Deliver;
  protected
    procedure Execute;override;
  public
    constructor Create(const Url,Ticket:string;Callback:TWorldMessage);
    function SendText(const Payload:RawByteString):Boolean;
    procedure Stop;
    destructor Destroy;override;
  end;
function NativeHttps(const Url,Token,Body,Method:string;out Status:Integer):string;
{ HTTPS download for client updates: follows HTTPS-only redirects (release files live on a CDN). }
function NativeDownload(const Url,Accept:string;MaxBytes:Integer;out Status:Integer):RawByteString;
function SupportsNativeNetwork:Boolean;
{ WinHTTP WebSockets exist from Windows 8; older systems play over HTTP polling. }
function SupportsWorldStream:Boolean;
implementation
{$IFDEF WINDOWS}
uses Windows,WinHttp,URIParser;
function HttpTimeouts(H:HINTERNET;ResolveMS,ConnectMS,SendMS,ReceiveMS:Integer):LongBool;stdcall;external 'winhttp.dll' name 'WinHttpSetTimeouts';
// Resolved at run time so that the client still starts on Windows XP/Vista/7.
type
  TWsUpgrade=function(hRequest:HINTERNET;pContext:DWORD_PTR):HINTERNET;stdcall;
  TWsSend=function(hWebSocket:HINTERNET;eBufferType:WINHTTP_WEB_SOCKET_BUFFER_TYPE;pvBuffer:Pointer;dwBufferLength:DWORD):DWORD;stdcall;
  TWsReceive=function(hWebSocket:HINTERNET;pvBuffer:Pointer;dwBufferLength:DWORD;pdwBytesRead:LPDWORD;peBufferType:PWINHTTP_WEB_SOCKET_BUFFER_TYPE):DWORD;stdcall;
var WsUpgrade:TWsUpgrade=nil;WsSend:TWsSend=nil;WsReceive:TWsReceive=nil;
procedure LoadWebSockets;
var H:HMODULE;
begin
  H:=LoadLibrary('winhttp.dll');if H=0 then Exit;
  Pointer(WsUpgrade):=GetProcAddress(H,'WinHttpWebSocketCompleteUpgrade');
  Pointer(WsSend):=GetProcAddress(H,'WinHttpWebSocketSend');
  Pointer(WsReceive):=GetProcAddress(H,'WinHttpWebSocketReceive');
end;
procedure Check(OK:Boolean);
begin if not OK then raise Exception.Create('Windows network error '+IntToStr(GetLastError));end;
var SharedSession:HINTERNET=nil;SharedLock:TRTLCriticalSection;
// One WinHTTP session for all API calls: WinHTTP keeps TCP/TLS connections alive per session,
// so only the first request to a server pays the handshake instead of every request.
function GetSharedSession:HINTERNET;
var Flag:DWORD;
begin
  EnterCriticalSection(SharedLock);
  try
    if SharedSession=nil then begin
      SharedSession:=WinHttpOpen('GrimHollow/0.5',WINHTTP_ACCESS_TYPE_NO_PROXY,nil,nil,0);Check(SharedSession<>nil);
      Check(HttpTimeouts(SharedSession,3000,3000,3000,3000));
      // Best effort, older Windows simply refuse: gzip answers (Windows 8.1+) and HTTP/2, which
      // carries parallel requests over one TLS connection (Windows 10).
      Flag:=3;WinHttpSetOption(SharedSession,118,@Flag,SizeOf(Flag));
      Flag:=1;WinHttpSetOption(SharedSession,133,@Flag,SizeOf(Flag));
    end;
    Result:=SharedSession;
  finally LeaveCriticalSection(SharedLock);end;
end;
procedure OpenRequest(const Url,Method:string;Upgrade,Shared:Boolean;out Session,Connection,Request:HINTERNET;Redirects:Boolean=False);
var U:TURI;Port:Word;Flags,Policy:DWORD;Host,Path,Verb:UnicodeString;
begin
  Session:=nil;Connection:=nil;Request:=nil;U:=ParseURI(Url,False);
  if (U.Host='') or (U.Username<>'') or (U.Password<>'') then raise Exception.Create('Invalid server URL');
  Flags:=0;Port:=U.Port;if U.Protocol='https' then begin Flags:=WINHTTP_FLAG_SECURE;if Port=0 then Port:=443;end
  else if U.Protocol='http' then begin if Port=0 then Port:=80;end else raise Exception.Create('Unsupported URL protocol');
  Host:=UTF8Decode(U.Host);Path:=UTF8Decode(U.Path+U.Document);if Path='' then Path:='/';if U.Params<>'' then Path:=Path+'?'+UTF8Decode(U.Params);Verb:=UTF8Decode(Method);
  if Shared then Session:=GetSharedSession
  else begin
    Session:=WinHttpOpen('GrimHollow/0.5',WINHTTP_ACCESS_TYPE_NO_PROXY,nil,nil,0);Check(Session<>nil);
    Check(HttpTimeouts(Session,3000,3000,3000,3000));
  end;
  Connection:=WinHttpConnect(Session,PWideChar(Host),Port,0);Check(Connection<>nil);
  Request:=WinHttpOpenRequest(Connection,PWideChar(Verb),PWideChar(Path),nil,nil,nil,Flags);Check(Request<>nil);
  if Redirects then Policy:=WINHTTP_OPTION_REDIRECT_POLICY_DISALLOW_HTTPS_TO_HTTP else Policy:=WINHTTP_OPTION_REDIRECT_POLICY_NEVER;Check(WinHttpSetOption(Request,WINHTTP_OPTION_REDIRECT_POLICY,@Policy,SizeOf(Policy)));
  // Default Windows chain, expiry and hostname checks stay enabled. No ignore-certificate flags.
  if Upgrade then Check(WinHttpSetOption(Request,WINHTTP_OPTION_UPGRADE_TO_WEB_SOCKET,nil,0));
end;
function NativeHttps(const Url,Token,Body,Method:string;out Status:Integer):string;
var Session,Connection,Request:HINTERNET;Headers:UnicodeString;Buffer:array[0..8191] of Byte;N,Code,Size:DWORD;Part:RawByteString;
begin
  Session:=nil;Connection:=nil;Request:=nil;Result:='';
  try
    OpenRequest(Url,Method,False,True,Session,Connection,Request);
    Headers:='Accept: application/json'+#13#10+'Content-Type: application/json'+#13#10;
    if Token<>'' then Headers:=Headers+'Authorization: Bearer '+UTF8Decode(Token)+#13#10;
    Check(WinHttpSendRequest(Request,PWideChar(Headers),Length(Headers),Pointer(Body),Length(Body),Length(Body),0));
    Check(WinHttpReceiveResponse(Request,nil));Size:=SizeOf(Code);
    Check(WinHttpQueryHeaders(Request,WINHTTP_QUERY_STATUS_CODE or WINHTTP_QUERY_FLAG_NUMBER,nil,@Code,@Size,nil));Status:=Code;
    repeat
      N:=0;Check(WinHttpReadData(Request,@Buffer[0],SizeOf(Buffer),@N));
      if Length(Result)+N>2097152 then raise Exception.Create('Response too large');
      SetString(Part,PAnsiChar(@Buffer[0]),N);Result:=Result+Part;
    until N=0;
  finally if Request<>nil then WinHttpCloseHandle(Request);if Connection<>nil then WinHttpCloseHandle(Connection);end;
end;
function NativeDownload(const Url,Accept:string;MaxBytes:Integer;out Status:Integer):RawByteString;
var Session,Connection,Request:HINTERNET;Headers:UnicodeString;Buffer:array[0..65535] of Byte;N,Code,Size:DWORD;Part:RawByteString;
begin
  Result:='';Status:=0;Session:=nil;Connection:=nil;Request:=nil;
  if Copy(Url,1,8)<>'https://' then raise Exception.Create('Updates are downloaded over HTTPS only');
  try
    OpenRequest(Url,'GET',False,False,Session,Connection,Request,True);
    Check(HttpTimeouts(Request,10000,10000,10000,30000));
    Headers:='Accept: '+UTF8Decode(Accept)+#13#10;
    Check(WinHttpSendRequest(Request,PWideChar(Headers),Length(Headers),nil,0,0,0));
    Check(WinHttpReceiveResponse(Request,nil));Size:=SizeOf(Code);
    Check(WinHttpQueryHeaders(Request,WINHTTP_QUERY_STATUS_CODE or WINHTTP_QUERY_FLAG_NUMBER,nil,@Code,@Size,nil));Status:=Code;
    repeat
      N:=0;Check(WinHttpReadData(Request,@Buffer[0],SizeOf(Buffer),@N));
      if Length(Result)+Int64(N)>MaxBytes then raise Exception.Create('Download too large');
      SetString(Part,PAnsiChar(@Buffer[0]),N);Result:=Result+Part;
    until N=0;
  finally
    if Request<>nil then WinHttpCloseHandle(Request);if Connection<>nil then WinHttpCloseHandle(Connection);if Session<>nil then WinHttpCloseHandle(Session);
  end;
end;
{$ELSE}
function NativeHttps(const Url,Token,Body,Method:string;out Status:Integer):string;
begin Status:=0;Result:='';raise Exception.Create('This Linux build supports local HTTP; native TLS adapter is not included.');end;
function NativeDownload(const Url,Accept:string;MaxBytes:Integer;out Status:Integer):RawByteString;
begin Status:=0;Result:='';raise Exception.Create('Updates need HTTPS, which this Linux build does not include.');end;
{$ENDIF}
function SupportsNativeNetwork:Boolean;
begin {$IFDEF WINDOWS}Result:=True;{$ELSE}Result:=False;{$ENDIF}end;
function SupportsWorldStream:Boolean;
begin {$IFDEF WINDOWS}Result:=Assigned(WsUpgrade) and Assigned(WsSend) and Assigned(WsReceive);{$ELSE}Result:=False;{$ENDIF}end;
constructor TWorldConnection.Create(const Url,Ticket:string;Callback:TWorldMessage);
begin inherited Create(True);FUrl:=Url;FTicket:=Ticket;FCallback:=Callback;InitCriticalSection(FLock);Start;end;
procedure TWorldConnection.Deliver;
begin if not Terminated then FCallback(FMessage,FError);end;
// Called from the UI thread while Execute blocks in receive: WinHTTP allows one send and one
// receive in flight at a time, and FLock serialises this send with the keep-alive ping.
function TWorldConnection.SendText(const Payload:RawByteString):Boolean;
begin
  Result:=False;
  {$IFDEF WINDOWS}
  EnterCriticalSection(FLock);
  try
    if (FSocket<>nil) and not Terminated then
      Result:=WsSend(FSocket,WINHTTP_WEB_SOCKET_UTF8_MESSAGE_BUFFER_TYPE,Pointer(Payload),Length(Payload))=0;
  finally LeaveCriticalSection(FLock);end;
  {$ENDIF}
end;
procedure TWorldConnection.Stop;
var H:Pointer;
begin
  Terminate;EnterCriticalSection(FLock);H:=FSocket;FSocket:=nil;LeaveCriticalSection(FLock);
  {$IFDEF WINDOWS}if H<>nil then WinHttpCloseHandle(H);{$ENDIF}
end;
destructor TWorldConnection.Destroy;
begin Stop;WaitFor;DoneCriticalSection(FLock);inherited Destroy;end;
procedure TWorldConnection.Execute;
{$IFDEF WINDOWS}
var Session,Connection,Request,Socket:HINTERNET;Buffer:array[0..16383] of Byte;
  Code,N:DWORD;BufferType:WINHTTP_WEB_SOCKET_BUFFER_TYPE;Part,Payload:RawByteString;LastPing:QWord;OwnHandle:Boolean;
{$ENDIF}
begin
  {$IFDEF WINDOWS}
  Session:=nil;Connection:=nil;Request:=nil;Socket:=nil;
  try
    try
      OpenRequest(FUrl,'GET',True,False,Session,Connection,Request);
      Check(WinHttpSendRequest(Request,nil,0,nil,0,0,0));Check(WinHttpReceiveResponse(Request,nil));
      Socket:=WsUpgrade(Request,0);Check(Socket<>nil);
      EnterCriticalSection(FLock);FSocket:=Socket;LeaveCriticalSection(FLock);
      // lean: the server may leave out an unchanged map and the other members' private fields.
      Payload:='{"v":1,"type":"hello","ticket":"'+FTicket+'","caps":["lean"]}';
      Code:=WsSend(Socket,WINHTTP_WEB_SOCKET_UTF8_MESSAGE_BUFFER_TYPE,Pointer(Payload),Length(Payload));
      if Code<>0 then raise Exception.Create('WebSocket hello failed '+IntToStr(Code));
      LastPing:=GetTickCount64;FMessage:='';
      while not Terminated do begin
        if GetTickCount64-LastPing>5000 then begin
          Payload:='{"v":1,"type":"ping"}';
          EnterCriticalSection(FLock);
          try Code:=WsSend(Socket,WINHTTP_WEB_SOCKET_UTF8_MESSAGE_BUFFER_TYPE,Pointer(Payload),Length(Payload));
          finally LeaveCriticalSection(FLock);end;
          if Code<>0 then raise Exception.Create('WebSocket ping failed');LastPing:=GetTickCount64;
        end;
        N:=0;Code:=WsReceive(Socket,@Buffer[0],SizeOf(Buffer),@N,@BufferType);
        if Terminated then Break;
        if Code=12002 then Continue;
        if Code<>0 then raise Exception.Create('WebSocket receive failed '+IntToStr(Code));
        if BufferType=WINHTTP_WEB_SOCKET_CLOSE_BUFFER_TYPE then raise Exception.Create('WebSocket closed');
        if not (BufferType in [WINHTTP_WEB_SOCKET_UTF8_MESSAGE_BUFFER_TYPE,WINHTTP_WEB_SOCKET_UTF8_FRAGMENT_BUFFER_TYPE]) then raise Exception.Create('Invalid WebSocket frame');
        if Length(FMessage)+N>2097152 then raise Exception.Create('WebSocket snapshot too large');
        SetString(Part,PAnsiChar(@Buffer[0]),N);FMessage:=FMessage+Part;
        if BufferType=WINHTTP_WEB_SOCKET_UTF8_MESSAGE_BUFFER_TYPE then begin Synchronize(@Deliver);FMessage:='';end;
      end;
    except on E:Exception do if not Terminated then begin FError:=E.Message;FMessage:='';Synchronize(@Deliver);end;end;
  finally
    EnterCriticalSection(FLock);OwnHandle:=FSocket<>nil;FSocket:=nil;LeaveCriticalSection(FLock);
    if OwnHandle then WinHttpCloseHandle(Socket);
    if Request<>nil then WinHttpCloseHandle(Request);if Connection<>nil then WinHttpCloseHandle(Connection);if Session<>nil then WinHttpCloseHandle(Session);
  end;
  {$ENDIF}
end;
{$IFDEF WINDOWS}
initialization
  InitCriticalSection(SharedLock);LoadWebSockets;
finalization
  if SharedSession<>nil then WinHttpCloseHandle(SharedSession);
  DoneCriticalSection(SharedLock);
{$ENDIF}
end.
