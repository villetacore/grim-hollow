unit ClientUpdate;
{ Self-update from GitHub Releases. The release archive is verified against the release's
  SHA256SUMS.txt, then the running executable is renamed aside and replaced; the old copy is
  removed on the next start. Windows only: the Linux build has no HTTPS stack. }
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Classes,SysUtils;
{$I GameVersion.inc}
const
  ReleaseApi='https://api.github.com/repos/villetacore/grim-hollow/releases/latest';
  ReleasePage='https://github.com/villetacore/grim-hollow/releases/latest';
type
  TUpdateInfo=record
    Available:Boolean;
    Version,AssetName,AssetUrl,SumsUrl,PageUrl,Error:string;
  end;
  TUpdateEvent=procedure(const Info:TUpdateInfo;Installed:Boolean;const Message:string) of object;
  { Runs a check (and optionally the install) off the UI thread. }
  TUpdateThread=class(TThread)
  private
    FInstall:Boolean;FInfo:TUpdateInfo;FInstalled:Boolean;FMessage:string;FDone:TUpdateEvent;
    procedure Deliver;
  protected
    procedure Execute;override;
  public
    constructor Create(Install:Boolean;const Info:TUpdateInfo;Done:TUpdateEvent);
  end;
function CompareVersions(const A,B:string):Integer;
function PlatformAsset:string;
function CheckForUpdate:TUpdateInfo;
function InstallUpdate(const Info:TUpdateInfo;out Message:string):Boolean;
procedure RemoveOldBinary;
procedure RestartClient;
implementation
uses fpjson,jsonparser,zipper,NativeNetwork{$IFDEF WINDOWS},Windows,ShellApi{$ENDIF};

function CompareVersions(const A,B:string):Integer;
var PA,PB:TStringArray;I,X,Y:Integer;
begin
  PA:=A.TrimLeft(['v']).Split(['.','-']);PB:=B.TrimLeft(['v']).Split(['.','-']);Result:=0;
  for I:=0 to 2 do begin
    X:=0;Y:=0;if I<Length(PA) then X:=StrToIntDef(PA[I],0);if I<Length(PB) then Y:=StrToIntDef(PB[I],0);
    if X<>Y then begin if X<Y then Result:=-1 else Result:=1;Exit;end;
  end;
end;

function PlatformAsset:string;
begin
  {$IFDEF CPUX86_64}Result:='windows-x64';{$ELSE}Result:='windows-x86-legacy';{$ENDIF}
  {$IFDEF UNIX}Result:='linux-x64';{$ENDIF}
end;

function CheckForUpdate:TUpdateInfo;
var D,Assets,A:TJSONData;Status,I:Integer;Name:string;
begin
  Result:=Default(TUpdateInfo);Result.PageUrl:=ReleasePage;
  try
    D:=GetJSON(NativeDownload(ReleaseApi,'application/vnd.github+json',1048576,Status));
    try
      if Status<>200 then raise Exception.Create('GitHub HTTP '+IntToStr(Status));
      Name:=D.FindPath('tag_name').AsString;Result.Version:=Name.TrimLeft(['v']);
      if D.FindPath('html_url')<>nil then Result.PageUrl:=D.FindPath('html_url').AsString;
      Assets:=D.FindPath('assets');
      for I:=0 to Assets.Count-1 do begin A:=Assets.Items[I];Name:=A.FindPath('name').AsString;
        if Name='SHA256SUMS.txt' then Result.SumsUrl:=A.FindPath('browser_download_url').AsString
        else if Name='GrimHollow-'+Result.Version+'-'+PlatformAsset+'.zip' then begin
          Result.AssetName:=Name;Result.AssetUrl:=A.FindPath('browser_download_url').AsString;end;
      end;
      Result.Available:=CompareVersions(Result.Version,ClientVersion)>0;
    finally D.Free;end;
  except on E:Exception do begin Result.Available:=False;Result.Error:=E.Message;end;end;
end;

{$IFDEF WINDOWS}
const PROV_RSA_AES=24;CRYPT_VERIFYCONTEXT=DWORD($F0000000);CALG_SHA_256=$0000800C;HP_HASHVAL=2;
function CryptAcquireContextW(var Prov:ULONG_PTR;Container,Provider:PWideChar;ProvType,Flags:DWORD):BOOL;stdcall;external 'advapi32.dll';
function CryptCreateHash(Prov:ULONG_PTR;Algorithm:DWORD;Key:ULONG_PTR;Flags:DWORD;var Hash:ULONG_PTR):BOOL;stdcall;external 'advapi32.dll';
function CryptHashData(Hash:ULONG_PTR;Data:Pointer;Len,Flags:DWORD):BOOL;stdcall;external 'advapi32.dll';
function CryptGetHashParam(Hash:ULONG_PTR;Param:DWORD;Data:Pointer;var Len:DWORD;Flags:DWORD):BOOL;stdcall;external 'advapi32.dll';
function CryptDestroyHash(Hash:ULONG_PTR):BOOL;stdcall;external 'advapi32.dll';
function CryptReleaseContext(Prov:ULONG_PTR;Flags:DWORD):BOOL;stdcall;external 'advapi32.dll';

function Sha256Hex(const Data:RawByteString):string;
var Prov,Hash:ULONG_PTR;Digest:array[0..31] of Byte;Len:DWORD;I:Integer;
begin
  Result:='';Prov:=0;Hash:=0;
  if not CryptAcquireContextW(Prov,nil,nil,PROV_RSA_AES,CRYPT_VERIFYCONTEXT) then raise Exception.Create('SHA-256 is not available');
  try
    if not CryptCreateHash(Prov,CALG_SHA_256,0,0,Hash) then raise Exception.Create('SHA-256 is not available');
    try
      if (Length(Data)>0) and not CryptHashData(Hash,Pointer(Data),Length(Data),0) then raise Exception.Create('Hashing failed');
      Len:=SizeOf(Digest);if not CryptGetHashParam(Hash,HP_HASHVAL,@Digest[0],Len,0) then raise Exception.Create('Hashing failed');
      for I:=0 to 31 do Result:=Result+LowerCase(IntToHex(Digest[I],2));
    finally CryptDestroyHash(Hash);end;
  finally CryptReleaseContext(Prov,0);end;
end;
{$ENDIF}

function FindFile(const Dir,Name:string):string;
var R:TSearchRec;
begin
  Result:='';
  if FileExists(IncludeTrailingPathDelimiter(Dir)+Name) then Exit(IncludeTrailingPathDelimiter(Dir)+Name);
  if FindFirst(IncludeTrailingPathDelimiter(Dir)+'*',faDirectory,R)=0 then
    try
      repeat
        if ((R.Attr and faDirectory)<>0) and (R.Name<>'.') and (R.Name<>'..') then begin
          Result:=FindFile(IncludeTrailingPathDelimiter(Dir)+R.Name,Name);if Result<>'' then Exit;end;
      until FindNext(R)<>0;
    finally SysUtils.FindClose(R);end;
end;

procedure RemoveTree(const Dir:string);
var R:TSearchRec;Path:string;
begin
  if not DirectoryExists(Dir) then Exit;
  if FindFirst(IncludeTrailingPathDelimiter(Dir)+'*',faAnyFile or faDirectory,R)=0 then
    try
      repeat
        if (R.Name='.') or (R.Name='..') then Continue;Path:=IncludeTrailingPathDelimiter(Dir)+R.Name;
        if (R.Attr and faDirectory)<>0 then RemoveTree(Path) else SysUtils.DeleteFile(Path);
      until FindNext(R)<>0;
    finally SysUtils.FindClose(R);end;
  SysUtils.RemoveDir(Dir);
end;

function InstallUpdate(const Info:TUpdateInfo;out Message:string):Boolean;
{$IFDEF WINDOWS}
var Archive,Sums,Line,Expected,Exe,Old,Stage,Fresh:string;Status:Integer;Lines:TStringList;Zip:TUnZipper;Stream:TFileStream;I:Integer;
begin
  Result:=False;Message:='';
  if (Info.AssetUrl='') or (Info.SumsUrl='') then begin Message:='В релизе нет сборки для этой системы. Скачайте вручную: '+Info.PageUrl;Exit;end;
  Exe:=ParamStr(0);Old:=Exe+'.old';Stage:=ExtractFilePath(Exe)+'update-tmp';
  try
    Sums:=NativeDownload(Info.SumsUrl,'application/octet-stream',1048576,Status);
    if Status<>200 then raise Exception.Create('Контрольные суммы недоступны (HTTP '+IntToStr(Status)+')');
    Expected:='';Lines:=TStringList.Create;
    try
      Lines.Text:=Sums;
      for I:=0 to Lines.Count-1 do begin Line:=Trim(Lines[I]);
        if Line.EndsWith('  '+Info.AssetName) then Expected:=LowerCase(Copy(Line,1,64));end;
    finally Lines.Free;end;
    if Length(Expected)<>64 then raise Exception.Create('Архив не найден в SHA256SUMS.txt');
    Archive:=NativeDownload(Info.AssetUrl,'application/octet-stream',104857600,Status);
    if Status<>200 then raise Exception.Create('Архив недоступен (HTTP '+IntToStr(Status)+')');
    if Sha256Hex(Archive)<>Expected then raise Exception.Create('Контрольная сумма архива не совпала — обновление отменено');
    RemoveTree(Stage);ForceDirectories(Stage);
    Stream:=TFileStream.Create(IncludeTrailingPathDelimiter(Stage)+Info.AssetName,fmCreate);
    try Stream.WriteBuffer(Pointer(Archive)^,Length(Archive));finally Stream.Free;end;
    Zip:=TUnZipper.Create;
    try Zip.FileName:=IncludeTrailingPathDelimiter(Stage)+Info.AssetName;Zip.OutputPath:=Stage;Zip.UnZipAllFiles;finally Zip.Free;end;
    Fresh:=FindFile(Stage,'GrimHollow.exe');
    if Fresh='' then raise Exception.Create('В архиве нет GrimHollow.exe');
    // A running executable can be renamed on Windows but not overwritten.
    if FileExists(Old) then SysUtils.DeleteFile(Old);
    if not RenameFile(Exe,Old) then raise Exception.Create('Нет прав на запись в папку игры. Скачайте обновление вручную: '+Info.PageUrl);
    if not CopyFile(PChar(Fresh),PChar(Exe),True) then begin
      RenameFile(Old,Exe);raise Exception.Create('Не удалось записать новый клиент; прежняя версия восстановлена');
    end;
    RemoveTree(Stage);
    Message:='Клиент обновлён до версии '+Info.Version+'.';Result:=True;
  except on E:Exception do begin Message:=E.Message;RemoveTree(Stage);end;end;
end;
{$ELSE}
begin Result:=False;Message:='Автообновление доступно в Windows-клиенте. Новая версия: '+Info.PageUrl;end;
{$ENDIF}

procedure RemoveOldBinary;
begin
  if FileExists(ParamStr(0)+'.old') then SysUtils.DeleteFile(ParamStr(0)+'.old');
  RemoveTree(ExtractFilePath(ParamStr(0))+'update-tmp');
end;

procedure RestartClient;
begin
  {$IFDEF WINDOWS}ShellExecuteW(0,'open',PWideChar(UnicodeString(ParamStr(0))),nil,PWideChar(UnicodeString(ExtractFilePath(ParamStr(0)))),SW_SHOWNORMAL);{$ENDIF}
end;

constructor TUpdateThread.Create(Install:Boolean;const Info:TUpdateInfo;Done:TUpdateEvent);
begin inherited Create(True);FreeOnTerminate:=True;FInstall:=Install;FInfo:=Info;FDone:=Done;Start;end;
procedure TUpdateThread.Execute;
begin
  if FInstall then FInstalled:=InstallUpdate(FInfo,FMessage) else FInfo:=CheckForUpdate;
  Synchronize(@Deliver);
end;
procedure TUpdateThread.Deliver;
begin FDone(FInfo,FInstalled,FMessage);end;
end.
