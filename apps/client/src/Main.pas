unit Main;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Classes, SysUtils, Forms, Controls, Graphics, StdCtrls, ExtCtrls, Dialogs,
  LCLType, LMessages, ComCtrls, fpjson, jsonparser, GameProtocol, GameTransport, GameTheme, GameData, GameRenderer, TownWindow, NativeNetwork, FieldGuide;

type
  TGameMap = class(TCustomControl)
  published
    property OnPaint;
    property OnMouseDown;
    property OnKeyDown;
  end;
  TGameForm = class;
  TGameForm = class(TForm)
  private
    FMap: TGameMap;
    FPanel: TWinControl;
    FTabs:TPageControl;
    FAccountTab,FTripTab,FBagTab,FSpellTab,FHeroTab:TTabSheet;
    FHeroes,FClass,FChannel,FBiome:TComboBox;
    FBag,FSpells:TListBox;
    FChat:TMemo;
    FChatInput:TEdit;
    FItemInfo,FSpellInfo,FHeroInfo:TLabel;
    FSheet,FCharacters:TJSONData;
    FChatCursor:string;
    FChatRequestContext,FChatSentBody:string;
    FPollCounter:Integer;
    FAwaitingStream:QWord;
    FHealth,FMana,FExperience:TProgressBar;
    FServer, FEmail, FPassword, FName, FCode: TEdit;
    FStatus, FStats, FHelp: TLabel;
    FTimer: TTimer;
    FSnapshot: TJSONData;
    FToken, FHero, FExpedition: string;
    FBusy, FSmoke: Boolean;
    FSmokePolls: Integer;
    FPendingAction, FPendingDirection, FPendingButton: string;
    FSmokeMoveSent, FSmokeStartQueued: Boolean;
    FSmokeStartX: Integer;
    FSmokeStage:Integer;
    FSmokeKeys,FSmokeActions:Integer;
    FWorld:TWorldConnection;
    FStreamSnapshot:string;
    FStreamSeen:Boolean;
    FReconnectAt:QWord;
    FSessionUntil:QWord;
    FWsInFlight:Boolean;
    FWsSentAt:QWord;
    // Local clock model of the server tick, and the client-side movement prediction.
    FSnapshotAt:QWord;
    FLocalReady:Int64;
    FLastAction,FLastDirection:string;
    FPathX,FPathY:array[0..4] of Integer;
    FPathCount:Integer;
    FPredUntil:QWord;
    function StreamLive:Boolean;
    function EstimatedTick:Int64;
    function CanAct:Boolean;
    procedure Predict(const Direction:string);
    procedure ApplyPrediction;
    procedure WorldMessage(const Payload,ErrorText:string);
    procedure FocusGame;
    procedure ResizeLayout(Sender:TObject);
    function ChatContext:string;
    procedure SelectHero(Sender:TObject);
    procedure SelectItem(Sender:TObject);
    procedure SelectSpell(Sender:TObject);
    procedure ChannelChanged(Sender:TObject);
    procedure ChatKey(Sender:TObject; var Key:Word; Shift:TShiftState);
    procedure RefreshSheet;
    procedure ShowSheet;
    procedure ReadChat;
    procedure DispatchPending;
    procedure ButtonClick(Sender: TObject);
    procedure Draw(Sender: TObject);
    procedure Poll(Sender: TObject);
    procedure KeyInput(Sender: TObject; var Key: Word; Shift: TShiftState);
    procedure Closing(Sender: TObject; var CanClose: Boolean);
    procedure Shown(Sender: TObject);
    procedure BeginSmoke(Data: PtrInt);
    procedure SmokeResult(const ResultText: string; Failed: Boolean);
    procedure MapMouse(Sender: TObject; Button: TMouseButton; Shift: TShiftState; X,Y: Integer);
    procedure Send(const Method, Path, Body, Kind: string);
    procedure Received(const Kind, Response, Error: string);
    procedure Action(const ActionName, Direction: string);
    procedure AddButton(const ACaption, AName: string; ATop: Integer);
    function AddEdit(const LabelText, Initial: string; ATop: Integer): TEdit;
  public
    constructor Create(AOwner: TComponent); override;
    destructor Destroy; override;
  end;

var GameForm: TGameForm;
implementation

function TGameForm.AddEdit(const LabelText, Initial: string; ATop: Integer): TEdit;
var L: TLabel;
begin
  L:=TLabel.Create(Self); L.Parent:=FPanel; L.SetBounds(16,ATop,280,20); L.Caption:=LabelText;
  L.Font.Color:=GoldColor;
  Result:=TEdit.Create(Self); Result.Parent:=FPanel; Result.Font.Color:=clBlack;
  Result.SetBounds(16,ATop+22,330,28); Result.Text:=Initial;
end;

procedure TGameForm.AddButton(const ACaption, AName: string; ATop: Integer);
var B: TButton;
begin
  B:=TButton.Create(Self); B.Parent:=FPanel; B.SetBounds(16,ATop,330,30);
  B.Font.Color:=clBlack; B.Caption:=ACaption; B.Name:=AName; B.OnClick:=@ButtonClick; B.TabStop:=False;
end;

{$I GameLayout.inc}
{$I GamePanels.inc}

destructor TGameForm.Destroy;
begin FWorld.Free;FSnapshot.Free; FSheet.Free; FCharacters.Free; inherited Destroy; end;

function TGameForm.StreamLive:Boolean;
begin Result:=not FSmoke and FStreamSeen and (FWorld<>nil) and not FWorld.Finished; end;

function TGameForm.EstimatedTick:Int64;
var Elapsed:QWord;
begin
  Result:=0;if FSnapshot=nil then Exit;
  Result:=StrToInt64Def(JStr(FSnapshot,'world.tick'),0);
  // The server only pushes a snapshot when the view changes; between pushes the tick advances at 10 Hz.
  Elapsed:=GetTickCount64-FSnapshotAt;if Elapsed>2000 then Elapsed:=2000;
  Result:=Result+Int64(Elapsed div 100);
end;

function TGameForm.CanAct:Boolean;
var Ready:Int64;
begin
  Ready:=StrToInt64Def(JStr(FSnapshot,'world.self.ready_at'),0);
  if FLocalReady>Ready then Ready:=FLocalReady;
  // The server buffers commands that arrive up to 3 ticks early, so send slightly ahead of time.
  Result:=EstimatedTick>=Ready-2;
end;

{ Moves the own hero at once; the next snapshots either confirm the step or cancel the prediction. }
procedure TGameForm.Predict(const Direction:string);
var W,Map,E:TJSONData;X,Y,NX,NY,I:Integer;Row:string;
begin
  if (FSnapshot=nil) or (FPathCount>=4) then Exit;
  W:=FSnapshot.FindPath('world');Map:=W.FindPath('map');X:=JInt(W,'self.x');Y:=JInt(W,'self.y');NX:=X;NY:=Y;
  case Direction of 'north':Dec(NY);'south':Inc(NY);'west':Dec(NX);'east':Inc(NX);else Exit;end;
  if (NX<0) or (NY<0) or (NX>31) or (NY>31) then Exit;
  Row:=Map.Items[NY].AsString;if Row[NX+1]<>'.' then Exit;
  for I:=0 to W.FindPath('enemies').Count-1 do begin E:=W.FindPath('enemies').Items[I];
    if (JInt(E,'x')=NX) and (JInt(E,'y')=NY) then Exit;end;
  for I:=0 to W.FindPath('players').Count-1 do begin E:=W.FindPath('players').Items[I];
    if (JStr(E,'id')<>FHero) and (JInt(E,'x')=NX) and (JInt(E,'y')=NY) and (E.FindPath('outcome').JSONType=jtNull) then Exit;end;
  if FPathCount=0 then begin FPathX[0]:=X;FPathY[0]:=Y;FPathCount:=1;end;
  FPathX[FPathCount]:=NX;FPathY[FPathCount]:=NY;Inc(FPathCount);FPredUntil:=GetTickCount64+1500;
  TJSONObject(W.FindPath('self')).Integers['x']:=NX;TJSONObject(W.FindPath('self')).Integers['y']:=NY;
  for I:=0 to W.FindPath('players').Count-1 do begin E:=W.FindPath('players').Items[I];
    if JStr(E,'id')=FHero then begin TJSONObject(E).Integers['x']:=NX;TJSONObject(E).Integers['y']:=NY;end;end;
  FMap.Invalidate;
end;

procedure TGameForm.ApplyPrediction;
var W,E:TJSONData;X,Y,I,Last:Integer;Pending:Boolean;
begin
  if (FPathCount=0) or (FSnapshot=nil) then Exit;
  if GetTickCount64>FPredUntil then begin FPathCount:=0;Exit;end;
  W:=FSnapshot.FindPath('world');X:=JInt(W,'self.x');Y:=JInt(W,'self.y');Last:=FPathCount-1;
  // Called for each fresh server snapshot: the final predicted cell confirms the path.
  if (X=FPathX[Last]) and (Y=FPathY[Last]) then begin FPathCount:=0;Exit;end;
  Pending:=False;
  for I:=0 to Last-1 do if (X=FPathX[I]) and (Y=FPathY[I]) then Pending:=True;
  if not Pending then begin FPathCount:=0;Exit;end;
  TJSONObject(W.FindPath('self')).Integers['x']:=FPathX[Last];TJSONObject(W.FindPath('self')).Integers['y']:=FPathY[Last];
  for I:=0 to W.FindPath('players').Count-1 do begin E:=W.FindPath('players').Items[I];
    if JStr(E,'id')=FHero then begin TJSONObject(E).Integers['x']:=FPathX[Last];TJSONObject(E).Integers['y']:=FPathY[Last];end;end;
end;

function NearbyHint(W:TJSONData):string;
var Objects,O:TJSONData;I,X,Y:Integer;
begin
  Result:='Esc — фокус на карте. F — открыть сундук, испить из родника, коснуться алтаря.';
  Objects:=W.FindPath('objects');if Objects=nil then Exit;X:=JInt(W,'self.x');Y:=JInt(W,'self.y');
  for I:=0 to Objects.Count-1 do begin O:=Objects.Items[I];
    if (JStr(O,'type')<>'trap') and not JBool(O,'used') and (Abs(JInt(O,'x')-X)+Abs(JInt(O,'y')-Y)<=1) then begin
      Result:='Рядом: '+GameWord(JStr(O,'type'))+'. Нажмите F, чтобы использовать.';Exit;end;
  end;
end;

function ActionCooldown(const ActionName:string):Integer;
begin
  case ActionName of
    'move':Result:=2;'attack':Result:=6;'bash':Result:=12;'guard','potion':Result:=10;
    'cast':Result:=5;'revive':Result:=30;'interact':Result:=4;
  else Result:=0;end;
end;

procedure TGameForm.WorldMessage(const Payload,ErrorText:string);
var D:TJSONData;Fresh,Kind:string;Rejected:Boolean;
begin
  if ErrorText<>'' then begin FReconnectAt:=GetTickCount64+3000;FWsInFlight:=False;Exit;end;
  D:=nil;Rejected:=False;
  try D:=GetJSON(Payload);Kind:=JStr(D,'type');
    if (Kind='snapshot') and (JStr(D,'instance_id')=FExpedition) then begin
      FStreamSnapshot:=Payload;FStreamSeen:=True;
    end else if (Kind='command_result') and FWsInFlight then begin
      FWsInFlight:=False;
      if JStr(D,'status')='rejected' then begin
        Rejected:=True;FPathCount:=0;FLocalReady:=0;
        // An early arrival is not an error: silently retry once the hero is ready.
        if JStr(D,'reason')='cooldown' then begin
          FLocalReady:=EstimatedTick+3;
          if FPendingAction='' then begin FPendingAction:=FLastAction;FPendingDirection:=FLastDirection;end;
        end else FStatus.Caption:=FriendlyError(JStr(D,'reason'));
      end else FAwaitingStream:=GetTickCount64;
    end else if (Kind='error') and FWsInFlight then begin
      FWsInFlight:=False;FPendingAction:='';FStatus.Caption:='Сервер отклонил действие.';
    end else if Kind='server_paused' then
      FStatus.Caption:='Мир на сервере временно приостановлен. Подождите…';
  finally D.Free;end;
  // Apply the pushed state immediately instead of waiting for the next poll-timer tick.
  if not FSmoke and not FBusy and (FStreamSnapshot<>'') then begin
    Fresh:=FStreamSnapshot;FStreamSnapshot:='';Received('poll',Fresh,'');
  end else if Rejected then DispatchPending;
end;

procedure TGameForm.Shown(Sender: TObject);
begin ResizeLayout(nil);FTimer.Enabled:=True; end;

procedure TGameForm.ResizeLayout(Sender:TObject);
var W:Integer; B:TButton;
begin
  if FChatInput=nil then Exit;
  W:=FChatInput.Parent.ClientWidth;
  FStatus.Width:=W-24;
  FChat.SetBounds(12,38,W-324,88);FHelp.SetBounds(W-298,40,286,48);
  B:=TButton(FindComponent('ReportButton'));if B<>nil then B.SetBounds(W-298,94,286,30);
  B:=TButton(FindComponent('ChatButton'));B.SetBounds(W-158,132,146,30);
  FChatInput.SetBounds(132,136,W-302,26);
  FStats.Width:=ClientWidth-36;
  B:=TButton(FindComponent('TownButton'));if B<>nil then B.Left:=ClientWidth-200;
  B:=TButton(FindComponent('GuideButton'));if B<>nil then B.Left:=ClientWidth-360;
end;

procedure TGameForm.BeginSmoke(Data: PtrInt);
begin FTimer.Enabled:=True; end;

procedure TGameForm.SmokeResult(const ResultText: string; Failed: Boolean);
var ArtifactDir: string; I:Integer; OldPage:TTabSheet; B:TButton;
begin
  FTimer.Enabled:=False;
  if ParamStr(1)='--smoke-local' then ArtifactDir:=ExtractFilePath(Application.ExeName)+'test-artifacts'
  else ArtifactDir:='/artifacts';
  ForceDirectories(ArtifactDir);
  with TStringList.Create do try Text:=ResultText; SaveToFile(ArtifactDir+'/result.txt'); finally Free; end;
  {$IFDEF UNIX}WriteLn(ResultText);{$ENDIF}
  if Failed then begin
    with GetFormImage do try SaveToFile(ArtifactDir+'/failure.bmp');finally Free;end;
    Halt(1);
  end;
  B:=TButton(FindComponent('ChatButton'));
  if (B.Left+B.Width>B.Parent.ClientWidth) or (FChatInput.Left+FChatInput.Width>B.Left) then
    SmokeResult('PASCAL_SMOKE_FAILED chat layout outside client area',True);
  with GetFormImage do try SaveToFile(ArtifactDir+'/pascal-client.bmp'); finally Free; end;
  OldPage:=FTabs.ActivePage;
  for I:=0 to FTabs.PageCount-1 do begin
    FTabs.ActivePageIndex:=I;Repaint;
    with GetFormImage do try SaveToFile(ArtifactDir+'/panel-'+IntToStr(I)+'.bmp');finally Free;end;
  end;
  FTabs.ActivePage:=OldPage;
  Width:=Constraints.MinWidth;Height:=Constraints.MinHeight;ResizeLayout(nil);Repaint;
  if (B.Left+B.Width>B.Parent.ClientWidth) or (FChatInput.Left+FChatInput.Width>B.Left) then
    SmokeResult('PASCAL_SMOKE_FAILED minimum-size chat layout',True);
  with GetFormImage do try SaveToFile(ArtifactDir+'/minimum-size.bmp');finally Free;end;
  Application.Terminate;
end;

procedure TGameForm.MapMouse(Sender: TObject; Button: TMouseButton; Shift: TShiftState; X,Y: Integer);
begin
  if (FExpedition='') and (FHero<>'') then ButtonClick(FindComponent('TownButton')) else FocusGame;
end;

procedure TGameForm.FocusGame;
begin
  if FMap.CanFocus then FMap.SetFocus;
end;

procedure TGameForm.DispatchPending;
var ButtonName, ActionName, Direction: string; W: TJSONData;
begin
  if FBusy or FWsInFlight then Exit;
  if FPendingButton<>'' then begin
    ButtonName:=FPendingButton; FPendingButton:='';
    ButtonClick(FindComponent(ButtonName)); Exit;
  end;
  if (FPendingAction='') or (FSnapshot=nil) or (FExpedition='') then Exit;
  if FSnapshot.FindPath('lobby').AsBoolean then Exit;
  W:=FSnapshot.FindPath('world');
  if (W=nil) or not CanAct then Exit;
  ActionName:=FPendingAction; Direction:=FPendingDirection; FPendingAction:='';
  Action(ActionName,Direction);
end;

procedure TGameForm.Closing(Sender: TObject; var CanClose: Boolean);
begin CanClose:=not FBusy; if FBusy then FStatus.Caption:='Завершается сетевой запрос. Повторите закрытие через несколько секунд.'; end;

procedure TGameForm.Send(const Method, Path, Body, Kind: string);
var Base: string;
begin
  if FBusy then Exit;
  Base:=Trim(FServer.Text);
  // This prototype intentionally permits plain HTTP only on loopback.
  if not (SupportsNativeNetwork and (Copy(Base,1,8)='https://')) and
    (Base<>'http://127.0.0.1:8080') and (Base<>'http://localhost:8080') and
    not (FSmoke and (Base='http://api:8000')) then begin
    FStatus.Caption:='Используйте локальный сервер :8080 или HTTPS-сервер (Windows).'; Exit;
  end;
  FBusy:=True; TRequestThread.Create(@Received,Base+'/api/v1/'+Path,FToken,Body,Method,Kind);
  FHeroes.Enabled:=False;
end;

procedure TGameForm.ButtonClick(Sender: TObject);
var ButtonName, Kind, Value: string; D: TJSONObject; Town:TTownForm;
begin
  ButtonName:=(Sender as TButton).Name;
  if ButtonName='GuideButton' then begin ShowFieldGuide(Self);Exit;end;
  if FBusy then begin
    FPendingButton:=ButtonName;
    FStatus.Caption:='Действие принято. Ожидаем ответ сервера…';
    Exit;
  end;
  if ButtonName='TownButton' then begin
    if FHero='' then begin FTabs.ActivePage:=FAccountTab;FStatus.Caption:='Сначала войдите и выберите героя.';Exit;end;
    if FExpedition<>'' then begin FStatus.Caption:='Городские службы доступны после возвращения из похода.';Exit;end;
    FTimer.Enabled:=False;Town:=TTownForm.CreateTown(Self,FServer.Text,FToken,FHero);
    try Town.ShowModal;if FSmoke and (Town.LastError<>'') then SmokeResult('PASCAL_SMOKE_FAILED town '+Town.LastError,True);
    finally Town.Free;FTimer.Enabled:=True;end;
    RefreshSheet;Exit;
  end else if ButtonName='ReportButton' then begin
    if FToken='' then Exit;
    Value:='';Kind:='';
    if not InputQuery('Жалоба на сообщение','Номер сообщения после # в чате:',Value) then Exit;
    if not InputQuery('Жалоба на сообщение','Причина:',Kind) then Exit;
    D:=TJSONObject.Create(['message_id',StrToIntDef(Value,0),'reason',Kind]);
    try Send('POST','chat/reports',D.AsJSON,'report');finally D.Free;end;Exit;
  end else if ButtonName='ChatButton' then begin
    if (FHero='') or (Trim(FChatInput.Text)='') then Exit;
    case FChannel.ItemIndex of 1:Value:='party';2:Value:='guild';else Value:='global';end;
    if (Value='party') and (FExpedition='') then begin FStatus.Caption:='Чат группы доступен после подготовки похода.';Exit;end;
    FChatSentBody:=FChatInput.Text;
    D:=TJSONObject.Create(['character_id',FHero,'channel',Value,'message_id',NewCommandId,'body',FChatInput.Text]);
    try Send('POST','chat',D.AsJSON,'chat_sent');finally D.Free;end;Exit;
  end else if ButtonName='LogoutButton' then begin
    if FExpedition<>'' then begin FStatus.Caption:='Сначала завершите поход.';Exit;end;
    Send('POST','auth/logout','','logout');Exit;
  end else if ButtonName='RefreshButton' then begin RefreshSheet;Exit;
  end else if (ButtonName='EquipButton') or (ButtonName='UnequipButton') or (ButtonName='SellButton') or
    (ButtonName='StrengthButton') or (ButtonName='VitalityButton') or (ButtonName='IntellectButton') then begin
    if FSheet=nil then Exit;
    if FExpedition<>'' then begin FStatus.Caption:='Снаряжение и очки развития меняются в городе, до подготовки похода.';Exit;end;
    D:=TJSONObject.Create(['operation_id',NewCommandId]);
    try
      if (ButtonName='StrengthButton') or (ButtonName='VitalityButton') or (ButtonName='IntellectButton') then begin
        if ButtonName='StrengthButton' then Value:='strength' else if ButtonName='VitalityButton' then Value:='vitality' else Value:='intellect';
        D.Add('action','train');D.Add('attribute',Value);
      end else begin
        if FBag.ItemIndex<0 then Exit;
        if ButtonName='EquipButton' then Value:='equip' else if ButtonName='UnequipButton' then Value:='unequip' else Value:='sell';
        if (Value='sell') and (MessageDlg('Продать предмет?',FItemInfo.Caption,mtConfirmation,[mbYes,mbNo],0)<>mrYes) then Exit;
        D.Add('action',Value);D.Add('item_id',JStr(FSheet.FindPath('items').Items[FBag.ItemIndex],'id'));
      end;
      Send('POST','characters/'+FHero+'/manage',D.AsJSON,'sheet');
    finally D.Free;end;Exit;
  end else if ButtonName='CastButton' then begin Action('cast',SpellKey(FSpells.ItemIndex));FocusGame;Exit;
  end else if (ButtonName='RegisterButton') or (ButtonName='LoginButton') then begin
    if FExpedition<>'' then begin FStatus.Caption:='Сначала завершите поход.';Exit;end;
    if ButtonName='RegisterButton' then Kind:='register' else Kind:='login';
    D:=TJSONObject.Create(['email',FEmail.Text,'password',FPassword.Text]);
    try Send('POST','auth/'+Kind,D.AsJSON,'auth'); finally D.Free; end;
  end else if ButtonName='HeroButton' then begin
    if FExpedition<>'' then begin FStatus.Caption:='Сначала завершите поход.';Exit;end;
    case FClass.ItemIndex of 1:Value:='arcanist';2:Value:='ranger';3:Value:='warden';else Value:='guardian';end;
    D:=TJSONObject.Create(['name',FName.Text,'class_id',Value]);
    try Send('POST','characters',D.AsJSON,'hero'); finally D.Free; end;
  end else if ButtonName='ExpeditionButton' then begin
    if FHero='' then begin FStatus.Caption:='Сначала создайте героя или войдите.'; Exit; end;
    D:=TJSONObject.Create(['character_id',FHero]);
    case FBiome.ItemIndex of 1:Value:='monastery';2:Value:='roots';else Value:='mines';end;
    D.Add('biome',Value);
    if Trim(FCode.Text)<>'' then D.Add('join_code',Trim(FCode.Text));
    try Send('POST','expeditions',D.AsJSON,'expedition'); finally D.Free; end;
  end else if ButtonName='StartButton' then begin
    if FExpedition='' then Exit;
    D:=TJSONObject.Create(['character_id',FHero]);
    try Send('POST','expeditions/'+FExpedition+'/start',D.AsJSON,'start'); finally D.Free; end;
  end else if ButtonName='LeaveButton' then begin
    if FExpedition='' then Exit;
    if MessageDlg('Покинуть экспедицию?', 'Добыча текущего забега будет потеряна. Если вы лидер лобби, подготовка всей группы отменится.', mtConfirmation,[mbYes,mbNo],0)<>mrYes then Exit;
    D:=TJSONObject.Create(['character_id',FHero]);
    try Send('POST','expeditions/'+FExpedition+'/leave',D.AsJSON,'leave'); finally D.Free; end;
  end else if ButtonName='AttackButton' then Action('attack','')
  else if ButtonName='BashButton' then Action('bash','')
  else if ButtonName='GuardButton' then Action('guard','')
  else if ButtonName='PotionButton' then Action('potion','')
  else if ButtonName='ReviveButton' then Action('revive','')
  else if ButtonName='InteractButton' then Action('interact','')
  else if ButtonName='DescendButton' then Action('descend','')
  else if ButtonName='ExtractButton' then Action('extract','');
  if FExpedition<>'' then FocusGame;
end;

procedure TGameForm.Received(const Kind, Response, Error: string);
var D, Items, W: TJSONData; I, Selected:Integer; SocketUrl, Fresh:string;
begin
  FBusy:=False;
  if Kind='poll' then FAwaitingStream:=0;
  FHeroes.Enabled:=FExpedition='';
  if Error<>'' then begin
    FPendingAction:=''; FPendingButton:='';
    FStatus.Caption:=FriendlyError(Error);
    if Kind='ticket' then FReconnectAt:=GetTickCount64+10000;
    if Kind='refresh' then FSessionUntil:=GetTickCount64+90000;
    if Pos('HTTP 401',Error)>0 then begin
      FExpedition:='';FToken:='';FHero:='';FHeroes.Enabled:=True;
      FreeAndNil(FSnapshot);FreeAndNil(FSheet);FMap.Invalidate;
      FServer.Enabled:=True;FEmail.Enabled:=True;FPassword.Enabled:=True;FName.Enabled:=True;FCode.ReadOnly:=False;
      FTabs.ActivePage:=FAccountTab;
    end;
    if FSmoke then SmokeResult('PASCAL_SMOKE_FAILED '+Error,True);
    Exit;
  end;
  if Kind='logout' then begin
    FToken:='';FHero:='';FHeroes.Clear;FChat.Clear;FBag.Clear;FSpells.Clear;
    FreeAndNil(FSheet);FreeAndNil(FCharacters);FStats.Caption:='Вы вышли из аккаунта.';
    FreeAndNil(FSnapshot);FMap.Invalidate;FPendingAction:='';FPendingButton:='';
    FItemInfo.Caption:='Выберите персонажа.';FSpellInfo.Caption:='Выберите персонажа.';
    FHealth.Position:=0;FMana.Position:=0;FExperience.Position:=0;
    FHeroInfo.Caption:='Выберите персонажа.';FStatus.Caption:='Войдите в аккаунт.';Exit;
  end;
  if Response='' then Exit;
  D:=nil;
  try
    D:=GetJSON(Response);
    if Kind='refresh' then begin
      FToken:=JStr(D,'access_token');FSessionUntil:=GetTickCount64+QWord(JInt(D,'expires_in',28800))*1000;
      FreeAndNil(FWorld);FStreamSeen:=False;FStreamSnapshot:='';FWsInFlight:=False;
    end else if Kind='auth' then begin
      FPendingAction:=''; FPendingButton:='';
      FToken:=D.FindPath('access_token').AsString; FPassword.Clear;
      FSessionUntil:=GetTickCount64+QWord(JInt(D,'expires_in',28800))*1000;
      FExpedition:=''; FHero:=''; FreeAndNil(FSnapshot);
      FreeAndNil(FSheet);FChat.Clear;FChatCursor:='0';
      Send('GET','characters','','heroes');
    end else if Kind='heroes' then begin
      Items:=D.FindPath('items');
      FCharacters.Free;FCharacters:=D.Clone;FHeroes.Clear;Selected:=0;
      for I:=0 to Items.Count-1 do begin
        FHeroes.Items.Add(JStr(Items.Items[I],'name')+' / '+JStr(Items.Items[I],'class_id'));
        if JStr(Items.Items[I],'id')=FHero then Selected:=I;
      end;
      if Items.Count>0 then begin
        FHeroes.ItemIndex:=Selected;SelectHero(nil);
        FStatus.Caption:='Герой выбран. Проверьте вещи и подготовьте поход.';
      end else begin
        FStatus.Caption:='Создайте первого героя.';
        if FSmoke then ButtonClick(FindComponent('HeroButton'));
      end;
    end else if Kind='hero' then begin
      FHero:=D.FindPath('id').AsString; FStatus.Caption:='Герой создан. Проверьте снаряжение.';
      Send('GET','characters','','heroes');
    end else if Kind='sheet' then begin
      FSheet.Free;FSheet:=D;D:=nil;
      FExpedition:=JStr(FSheet,'active_expedition');
      ShowSheet;
      if FCharacters<>nil then for I:=0 to FCharacters.FindPath('items').Count-1 do begin
        Items:=FCharacters.FindPath('items').Items[I];
        if JStr(Items,'id')=FHero then begin
          TJSONObject(Items).Delete('active_expedition');
          if FExpedition='' then TJSONObject(Items).Add('active_expedition',TJSONNull.Create)
          else TJSONObject(Items).Add('active_expedition',FExpedition);
        end;
      end;
      FHeroes.Enabled:=FExpedition='';
      if FSmoke and (FSmokeStage=0) then begin
        FSmokeStage:=-1;ButtonClick(FindComponent('TownButton'));
      end else if FSmoke and (FSmokeStage=-1) then begin
        for I:=0 to FSheet.FindPath('items').Count-1 do
          if JStr(FSheet.FindPath('items').Items[I],'definition')='ember_ring' then FBag.ItemIndex:=I;
        FSmokeStage:=1;ButtonClick(FindComponent('EquipButton'));
      end else if FSmoke and (FSmokeStage=1) then begin
        if JStr(FSheet,'stats.equipment.ring')<>'ember_ring' then SmokeResult('PASCAL_SMOKE_FAILED equip ring',True);
        FSmokeStage:=2;FChatInput.Text:='Pascal smoke '+FHero;ButtonClick(FindComponent('ChatButton'));
      end;
    end else if Kind='report' then begin
      FStatus.Caption:='Жалоба зарегистрирована для модератора.';
    end else if Kind='chat_sent' then begin
      if FChatInput.Text=FChatSentBody then FChatInput.Clear;ReadChat;
    end else if Kind='chat' then begin
      if FChatRequestContext=ChatContext then begin
        Items:=D.FindPath('items');
        for I:=0 to Items.Count-1 do FChat.Lines.Add('#'+JStr(Items.Items[I],'id')+' '+JStr(Items.Items[I],'name')+': '+JStr(Items.Items[I],'body'));
        while FChat.Lines.Count>200 do FChat.Lines.Delete(0);
        FChatCursor:=JStr(D,'cursor','0');FChat.SelStart:=Length(FChat.Text);
        if FSmoke and (FSmokeStage=2) then begin
          if Pos('Pascal smoke '+FHero,FChat.Text)=0 then SmokeResult('PASCAL_SMOKE_FAILED chat message missing',True);
          FSmokeStage:=3;ButtonClick(FindComponent('ExpeditionButton'));
        end;
      end;
    end else if Kind='ticket' then begin
      if FExpedition<>'' then begin
        if Copy(FServer.Text,1,8)='https://' then SocketUrl:=FServer.Text+'/ws'
        else SocketUrl:='http://127.0.0.1:8082';
        FreeAndNil(FWorld);FStreamSeen:=False;FWorld:=TWorldConnection.Create(SocketUrl,JStr(D,'ticket'),@WorldMessage);
      end;
    end else if Kind='expedition' then begin
      FExpedition:=D.FindPath('id').AsString; FCode.Text:=D.FindPath('join_code').AsString;
      FHeroes.Enabled:=False;if FChannel.ItemIndex=1 then ChannelChanged(nil);
      FTabs.ActivePage:=FTripTab;
      FStatus.Caption:='Код группы: '+FCode.Text+'. Передайте его второму игроку, затем начните.';
      FocusGame;
      // The smoke test starts via the same button while a lobby poll is in flight.
    end else if Kind='poll' then begin
      if FSnapshot=nil then FocusGame;
      ValidateSnapshot(D); FSnapshot.Free; FSnapshot:=D; D:=nil;FSnapshotAt:=GetTickCount64;
      ApplyPrediction;
      W:=FSnapshot.FindPath('world');
      FCode.Text:=JStr(FSnapshot,'join_code');
      FHealth.Max:=JInt(W,'self.max_hp',100);FHealth.Position:=JInt(W,'self.hp');
      FMana.Max:=JInt(W,'self.max_mana',60);FMana.Position:=JInt(W,'self.mana',60);
      if FTabs.ActivePage=FSpellTab then SelectSpell(nil);
      FServer.Enabled:=False; FEmail.Enabled:=False; FPassword.Enabled:=False;
      FName.Enabled:=False; FCode.ReadOnly:=True;
      if FSnapshot.FindPath('lobby').AsBoolean then
        FStatus.Caption:='Подготовка группы: движение пока выключено. Лидер должен нажать «Начать».';
      FStats.Caption:=JStr(W,'self.name')+' | Ур. '+JStr(W,'self.level','1')+' | Этаж '+JStr(W,'floor')+'/'+JStr(W,'last_floor','3')+
        ' | HP '+JStr(W,'self.hp')+'/'+JStr(W,'self.max_hp')+' | MP '+JStr(W,'self.mana','60')+
        ' | Зелья '+JStr(W,'self.potions')+' | Добыча: '+JStr(W,'self.gold')+' крон, '+JStr(W,'self.xp')+' XP';
      if W.FindPath('self.loot')<>nil then FStats.Caption:=FStats.Caption+' | Трофеи: '+IntToStr(W.FindPath('self.loot').Count);
      if JInt(W,'self.essence')>0 then FStats.Caption:=FStats.Caption+' | Эссенция: '+JStr(W,'self.essence');
      if JInt(W,'self.poison_until')>JInt(W,'tick') then FStats.Caption:=FStats.Caption+' | ОТРАВЛЕН';
      if JInt(W,'self.might_until')>JInt(W,'tick') then FStats.Caption:=FStats.Caption+' | ЯРОСТЬ';
      if JInt(W,'self.shield_until')>JInt(W,'tick') then FStats.Caption:=FStats.Caption+' | ЗАЩИТА';
      if not FSnapshot.FindPath('lobby').AsBoolean then FHelp.Caption:=NearbyHint(W);
      if (JInt(W,'self.hp')=0) and (W.FindPath('self.outcome').JSONType=jtNull) then
        FStatus.Caption:='Вы выведены из боя. Союзник рядом может помочь [R] в течение 20 секунд. Затем остаётся святилище на переходе.';
      if W.FindPath('self.outcome').JSONType<>jtNull then begin
        FStatus.Caption:='Результат: '+W.FindPath('self.outcome').AsString+'. Можно начать новую экспедицию.';
        FExpedition:=''; FCode.Clear;
        FHeroes.Enabled:=True;if FChannel.ItemIndex=1 then begin FChannel.ItemIndex:=0;ChannelChanged(nil);end;
        FPendingAction:=''; FServer.Enabled:=True; FEmail.Enabled:=True;
        FPassword.Enabled:=True; FName.Enabled:=True; FCode.ReadOnly:=False;
        RefreshSheet;
      end;
      FMap.Invalidate;
      if FSmoke then begin
        Inc(FSmokePolls);
        if FSmokeMoveSent and (W.FindPath('self.x').AsInteger=FSmokeStartX+1) and (not SupportsNativeNetwork or FStreamSeen) then begin
          SmokeResult('PASCAL_SMOKE_OK town=true native_ws='+BoolToStr(FStreamSeen,True)+' equipment=true chat=true start_queued=true keyboard=east queued_during_poll=true x='+W.FindPath('self.x').AsString,False);
        end else if FSmokePolls>40 then begin
          SmokeResult('PASCAL_SMOKE_FAILED keyboard movement not confirmed; lobby='+JStr(FSnapshot,'lobby')+
            '; sent='+BoolToStr(FSmokeMoveSent,True)+'; start='+BoolToStr(FSmokeStartQueued,True)+
            '; x='+JStr(W,'self.x')+'; keys='+IntToStr(FSmokeKeys)+'; actions='+IntToStr(FSmokeActions)+
            '; pending='+FPendingButton+'/'+FPendingAction+'; status='+FStatus.Caption,True);
        end;
      end;
    end else if Kind='command' then begin
      if D.FindPath('status').AsString='rejected' then begin
        FPathCount:=0;FLocalReady:=0;
        if D.FindPath('reason').AsString='cooldown' then begin
          FLocalReady:=EstimatedTick+3;
          if FPendingAction='' then begin FPendingAction:=FLastAction;FPendingDirection:=FLastDirection;end;
        end else FStatus.Caption:=FriendlyError(D.FindPath('reason').AsString);
      end;
    end else if Kind='start' then begin
      FocusGame;
      FStatus.Caption:='Игра началась! Стрелки или WASD — движение. Ваш герой — в светлой рамке. Найдите золотой выход.';
    end else if Kind='leave' then begin
      FExpedition:=''; FPendingAction:=''; FCode.Clear; FreeAndNil(FSnapshot); FMap.Invalidate;
      FHeroes.Enabled:=True;if FChannel.ItemIndex=1 then begin FChannel.ItemIndex:=0;ChannelChanged(nil);end;
      FServer.Enabled:=True; FEmail.Enabled:=True; FPassword.Enabled:=True; FName.Enabled:=True; FCode.ReadOnly:=False;
      FStatus.Caption:='Вы покинули экспедицию.';
      RefreshSheet;
    end;
  except on E: Exception do begin
    FStatus.Caption:='Ошибка протокола: '+E.Message;
    if FSmoke then SmokeResult('PASCAL_SMOKE_FAILED '+E.Message,True);
  end; end;
  D.Free;
  if (Kind='command') and (FExpedition<>'') and not FBusy then begin
    if not FSmoke and FStreamSeen and (FWorld<>nil) and not FWorld.Finished then begin
      // The world stream pushes the post-command state within one tick, so a second HTTPS
      // round trip is unnecessary. Wait for it (Poll falls back to HTTP if it does not arrive).
      if FStreamSnapshot<>'' then begin Fresh:=FStreamSnapshot;FStreamSnapshot:='';Received('poll',Fresh,'');end
      else FAwaitingStream:=GetTickCount64;
    end else Send('GET','expeditions/'+FExpedition+'?character_id='+FHero,'','poll');
  end else DispatchPending;
end;

procedure TGameForm.Poll(Sender: TObject);
var D:TJSONObject;Payload:string;
begin
  if FBusy then Exit;
  // A WebSocket command whose result never arrived must not block input: drop the flag and
  // let the HTTP fallback below refresh the state.
  if FWsInFlight and (GetTickCount64-FWsSentAt>3000) then begin
    FWsInFlight:=False;FAwaitingStream:=GetTickCount64-2000;
  end;
  // Held keys are dispatched from the local tick estimate, not only when a snapshot arrives.
  if (FPendingAction<>'') and not FWsInFlight then begin DispatchPending;if FBusy then Exit;end;
  if (FPathCount>0) and (GetTickCount64>FPredUntil) then FPathCount:=0;
  if (FToken<>'') and (FSessionUntil>0) and (GetTickCount64+60000>=FSessionUntil) then begin
    Send('POST','auth/refresh','{}','refresh');Exit;
  end;
  if (FWorld<>nil) and ((FExpedition='') or FWorld.Finished) then begin
    FreeAndNil(FWorld);FStreamSnapshot:='';FStreamSeen:=False;FWsInFlight:=False;
  end;
  if SupportsNativeNetwork and (FExpedition<>'') and (FWorld=nil) and (GetTickCount64>=FReconnectAt) then begin
    D:=TJSONObject.Create(['character_id',FHero,'expedition_id',FExpedition]);
    try Send('POST','world/tickets',D.AsJSON,'ticket');finally D.Free;end;Exit;
  end;
  if not FSmoke and (FStreamSnapshot<>'') then begin
    Payload:=FStreamSnapshot;FStreamSnapshot:='';Received('poll',Payload,'');
    if FBusy then Exit;
  end;
  Inc(FPollCounter);
  if not FSmoke and (FHero<>'') and (FPollCounter mod 20=0) then begin ReadChat;if FBusy then Exit;end;
  if FSmoke and (FToken='') then ButtonClick(FindComponent('RegisterButton'))
  else if FExpedition<>'' then begin
    if not FSmoke and FStreamSeen and (FWorld<>nil) and not FWorld.Finished and
      not ((FAwaitingStream>0) and (GetTickCount64-FAwaitingStream>1000)) then Exit;
    Send('GET','expeditions/'+FExpedition+'?character_id='+FHero,'','poll');
    if FSmoke and not FSmokeStartQueued and (FSnapshot<>nil) and FSnapshot.FindPath('lobby').AsBoolean then begin
      FSmokeStartQueued:=True; ButtonClick(FindComponent('StartButton'));
    end;
    if FSmoke and not FSmokeMoveSent and (FSnapshot<>nil) and not FSnapshot.FindPath('lobby').AsBoolean then begin
      if ActiveControl<>FMap then begin SmokeResult('PASCAL_SMOKE_FAILED map has no keyboard focus',True); Exit; end;
      FSmokeStartX:=FSnapshot.FindPath('world.self.x').AsInteger; FSmokeMoveSent:=True;
      // Widgetsets first dispatch CN_KEYDOWN (preview / OnKeyDown); LM_KEYDOWN
      // is only the remaining, unhandled-key phase and must not be injected alone.
      if FMap.Perform(CN_KEYDOWN,VK_RIGHT,0)=0 then FMap.Perform(LM_KEYDOWN,VK_RIGHT,0);
      if FMap.Perform(CN_KEYUP,VK_RIGHT,0)=0 then FMap.Perform(LM_KEYUP,VK_RIGHT,0);
    end;
  end;
end;

procedure TGameForm.Action(const ActionName, Direction: string);
var D, P: TJSONObject; W, Enemies, E: TJSONData; I, X, Y, Distance, BestDistance: Integer; Target, CommandId: string; Sent: Boolean;
begin
  if FSmoke then Inc(FSmokeActions);
  if (FExpedition='') or (FSnapshot=nil) then Exit;
  if FSnapshot.FindPath('lobby').AsBoolean then begin
    FStatus.Caption:='Сначала нажмите «Начать» — сейчас группа ещё в подготовке.'; Exit;
  end;
  W:=FSnapshot.FindPath('world');
  if FBusy or FWsInFlight or not CanAct then begin
    FPendingAction:=ActionName; FPendingDirection:=Direction; Exit;
  end;
  P:=TJSONObject.Create(['action',ActionName]);
  if ActionName='revive' then begin
    Enemies:=W.FindPath('players');Target:='';
    for I:=0 to Enemies.Count-1 do begin E:=Enemies.Items[I];
      if (JInt(E,'hp')=0) and (Abs(JInt(E,'x')-JInt(W,'self.x'))+Abs(JInt(E,'y')-JInt(W,'self.y'))<=1) then begin Target:=JStr(E,'id');Break;end;
    end;
    if Target='' then begin P.Free;FStatus.Caption:='Рядом нет павшего союзника.';Exit;end;P.Add('target_id',Target);
  end;
  if ActionName='cast' then begin
    P.Add('spell_id',Direction);
    if (Direction='mend') and (JStr(W,'self.class_id')='warden') then begin
      Enemies:=W.FindPath('players');Target:=FHero;BestDistance:=101;
      for I:=0 to Enemies.Count-1 do begin E:=Enemies.Items[I];
        if (JInt(E,'hp')>0) and (Abs(JInt(E,'x')-JInt(W,'self.x'))+Abs(JInt(E,'y')-JInt(W,'self.y'))<=3) then begin
          Distance:=JInt(E,'hp')*100 div JInt(E,'max_hp',100);
          if Distance<BestDistance then begin BestDistance:=Distance;Target:=JStr(E,'id');end;
        end;
      end;P.Add('target_id',Target);
    end;
    if (Direction='firebolt') or (Direction='frost') or (Direction='venom') or (Direction='meteor') then begin
      Enemies:=W.FindPath('enemies');Target:='';BestDistance:=1000;
      for I:=0 to Enemies.Count-1 do begin
        E:=Enemies.Items[I];Distance:=Abs(JInt(E,'x')-JInt(W,'self.x'))+Abs(JInt(E,'y')-JInt(W,'self.y'));
        if Distance<BestDistance then begin BestDistance:=Distance;Target:=JStr(E,'id');end;
      end;
      P.Add('target_id',Target);
    end;
  end else if Direction<>'' then P.Add('direction',Direction);
  if (ActionName='attack') or (ActionName='bash') then begin
    W:=FSnapshot.FindPath('world'); X:=W.FindPath('self.x').AsInteger; Y:=W.FindPath('self.y').AsInteger;
    Enemies:=W.FindPath('enemies'); Target:='';
    for I:=0 to Enemies.Count-1 do begin
      E:=Enemies.Items[I];
      if Abs(E.FindPath('x').AsInteger-X)+Abs(E.FindPath('y').AsInteger-Y)=1 then begin Target:=E.FindPath('id').AsString; Break; end;
    end;
    if (Target='') and (ActionName='attack') and (JStr(W,'self.class_id')='ranger') then begin
      BestDistance:=6;
      for I:=0 to Enemies.Count-1 do begin E:=Enemies.Items[I];Distance:=Abs(JInt(E,'x')-X)+Abs(JInt(E,'y')-Y);
        if Distance<BestDistance then begin BestDistance:=Distance;Target:=JStr(E,'id');end;end;
    end;
    if Target='' then begin P.Free; FStatus.Caption:='Нет противника в радиусе атаки.'; Exit; end;
    P.Add('target_id',Target);
  end;
  CommandId:=NewCommandId;
  FLastAction:=ActionName;FLastDirection:=Direction;
  FLocalReady:=EstimatedTick+ActionCooldown(ActionName);
  if ActionName='move' then Predict(Direction);
  // Prefer the already open world WebSocket: no HTTP request, the result and the new state
  // arrive on the same connection. HTTPS stays as the fallback when the stream is not live.
  if StreamLive then begin
    D:=TJSONObject.Create(['v',1,'type','command','command_id',CommandId]); D.Add('payload',P.Clone);
    try Sent:=FWorld.SendText(D.AsJSON); finally D.Free; end;
    if Sent then begin P.Free; FWsInFlight:=True; FWsSentAt:=GetTickCount64; Exit; end;
  end;
  D:=TJSONObject.Create(['character_id',FHero,'command_id',CommandId]); D.Add('payload',P);
  try Send('POST','expeditions/'+FExpedition+'/commands',D.AsJSON,'command'); finally D.Free; end;
end;

procedure TGameForm.KeyInput(Sender: TObject; var Key: Word; Shift: TShiftState);
begin
  if FSmoke then Inc(FSmokeKeys);
  if Key=VK_F1 then begin ShowFieldGuide(Self);Key:=0;Exit;end;
  if Key=VK_ESCAPE then begin FocusGame;Key:=0;Exit;end;
  if (ActiveControl is TEdit) and (ActiveControl<>FCode) then Exit;
  if (ActiveControl is TComboBox) or (ActiveControl is TMemo) or (ActiveControl is TListBox) then Exit;
  if (ActiveControl=FCode) and not FCode.ReadOnly then Exit;
  case Key of
    VK_W,VK_UP: Action('move','north'); VK_S,VK_DOWN: Action('move','south');
    VK_A,VK_LEFT: Action('move','west'); VK_D,VK_RIGHT: Action('move','east');
    VK_SPACE: Action('attack',''); VK_1: Action('bash',''); VK_2: Action('guard','');
    VK_3: Action('potion',''); VK_E: Action('descend',''); VK_X: Action('extract','');
    VK_4: Action('cast','firebolt');VK_5: Action('cast','mend');VK_6: Action('cast','frost');VK_7: Action('cast','nova');
    VK_8: Action('cast','venom');VK_9: Action('cast','chain');VK_0: Action('cast','barrier');VK_Q: Action('cast','meteor');
    VK_F: Action('interact','');
    VK_T:ButtonClick(FindComponent('TownButton'));
    VK_R:Action('revive','');
  else Exit; end;
  Key:=0;
end;

procedure TGameForm.Draw(Sender:TObject);
begin RenderMap(FMap,FSnapshot,FHero,FExpedition); end;
end.
