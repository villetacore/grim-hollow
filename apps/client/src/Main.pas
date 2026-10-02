unit Main;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Classes, SysUtils, Forms, Controls, Graphics, StdCtrls, ExtCtrls, Dialogs,
  LCLType, LMessages, ComCtrls, fpjson, jsonparser, GameProtocol, GameTransport, GameTheme, GameData, GameRenderer, GameArt, TownWindow, NativeNetwork, FieldGuide, URIParser, ClientUpdate, PlazaRender, Math;

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
    // Independent request lanes: chat and the town square never hold up game actions.
    FBusy, FChatBusy, FPlazaBusy, FSmoke: Boolean;
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
    // World commands sent and not yet answered; two may be on the way at once.
    FWsInFlight:Integer;
    // The last explored map the stream sent: lean snapshots leave an unchanged map out.
    FStreamMap:string;
    FWsSentAt:QWord;
    // Local clock model of the server tick, and the client-side movement prediction.
    FSnapshotAt:QWord;
    FLocalReady:Int64;
    FLastAction,FLastDirection:string;
    FPathX,FPathY:array[0..4] of Integer;
    FPathCount:Integer;
    FPredUntil:QWord;
    // Fog of war memory, chosen target and the direction the hero faces.
    FView:TMapView;
    FExploreKey,FTarget,FFacing:string;
    FMode:TComboBox;
    FBagSpecs:TStringList;
    FManualUpdate:Boolean;
    // Creation choices, the mentor picker and the areas sent by the server.
    FOrigin,FMentor:TComboBox;
    FBiomeKeys:TStringList;
    // The walkable town square: static map, latest live view, the predicted own cell,
    // steps not yet confirmed, the last keeper's words and a service to open.
    FPlazaTown,FPlaza:TJSONData;
    FPlazaX,FPlazaY:Integer;
    FPlazaOutbox:TStringList;
    FPlazaInFlight:Boolean;
    FPlazaStepAt,FPlazaSentAt,FPlazaPollAt,FTalkUntil:QWord;
    FPlazaHeld,FPlazaAsk,FTalk,FTalkNpc,FTalkService:string;
    FPlazaView:TPlazaView;
    // The live square over the world WebSocket: steps answered without HTTP round trips.
    FPlazaWorld:TWorldConnection;
    FPlazaLive:Boolean;
    FPlazaPending,FPlazaSeq:Integer;
    FPlazaReconnectAt:QWord;
    procedure PlazaMessage(const Payload,ErrorText:string);
    procedure ApplyPlaza(D:TJSONData);
    procedure ShowTalk(T:TJSONData);
    function TalkOpens(Npc:TJSONData):Boolean;
    function PlazaSend(const ActionName,Key,Value:string):Boolean;
    function WorldUrl:string;
    function PlazaActive:Boolean;
    procedure PlazaStep(const Direction:string);
    procedure PlazaAct(const ActionName,Target:string);
    procedure FlushPlaza;
    procedure ResetPlaza;
    procedure OpenTown(Tab:Integer);
    procedure OpenService(const Service:string);
    procedure UpdateDone(const Info:TUpdateInfo;Installed:Boolean;const Message:string);
    procedure SelfUpdateTest;
    function Hostiles(W:TJSONData):TJSONArray;
    function PickTarget(W:TJSONData;Range:Integer;Adjacent:Boolean):string;
    procedure CycleTarget;
    procedure DrawListItem(Control:TWinControl;Index:Integer;ARect:TRect;State:TOwnerDrawState);
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
begin FWorld.Free;FPlazaWorld.Free;FSnapshot.Free; FSheet.Free; FCharacters.Free; FBagSpecs.Free; FBiomeKeys.Free;
  FPlazaTown.Free; FPlaza.Free; FPlazaOutbox.Free; inherited Destroy; end;

{ The square is live while a hero stands in town (the smoke test keeps the plain town scene). }
function TGameForm.PlazaActive:Boolean;
begin Result:=not FSmoke and (FToken<>'') and (FHero<>'') and (FExpedition='') and (FSheet<>nil); end;

{ The world WebSocket address: /ws behind the HTTPS edge, port 8082 on a local or LAN server. }
function TGameForm.WorldUrl:string;
begin
  if Copy(FServer.Text,1,8)='https://' then Result:=FServer.Text+'/ws'
  else Result:='http://'+ParseURI(FServer.Text).Host+':8082';
end;

procedure TGameForm.ResetPlaza;
begin
  FreeAndNil(FPlaza);FPlazaX:=-1;FPlazaY:=-1;FPlazaOutbox.Clear;FPlazaInFlight:=False;
  FPlazaHeld:='';FPlazaAsk:='';FTalk:='';FTalkNpc:='';FTalkService:='';
  // Stop, not free: this may run inside the stream's own callback. Poll frees it once finished.
  if FPlazaWorld<>nil then FPlazaWorld.Stop;
  FPlazaLive:=False;FPlazaPending:=0;
end;

{ What a keeper's service is called in the dialog hint; empty when talking opens nothing. }
function ServiceTitle(const Service:string):string;
begin
  case Service of
    'forge':Result:='кузница и аптекарь';'market':Result:='торговая доска';'guild':Result:='гильдия и друзья';
    'bounty':Result:='доска контрактов';'temple':Result:='таланты и сброс';'alchemy':Result:='алхимия';
    'mentor':Result:='выбор наставника';'gate':Result:='собрать поход';'arena':Result:='дуэль на арене';'library':Result:='справочник';
  else Result:='';end;
end;

{ A keeper's answer stays on screen; F (or a click) on the same keeper then opens its service. }
procedure TGameForm.ShowTalk(T:TJSONData);
begin
  FTalkNpc:=JStr(T,'npc');FTalkService:=JStr(T,'service');FTalkUntil:=GetTickCount64+15000;
  FTalk:=JStr(T,'name')+': '+JStr(T,'line');
  if ServiceTitle(FTalkService)<>'' then FTalk:=FTalk+#10+'[F] или щелчок по жителю — '+ServiceTitle(FTalkService)+'.   [Esc] — закрыть.'
  else FTalk:=FTalk+#10+'[F] — спросить ещё.   [Esc] — закрыть.';
  FMap.Invalidate;
end;

{ The second F on a keeper who has just spoken opens what the keeper offers. }
function TGameForm.TalkOpens(Npc:TJSONData):Boolean;
var Service:string;
begin
  Result:=(Npc<>nil) and (FTalk<>'') and (FTalkNpc=JStr(Npc,'id')) and (ServiceTitle(FTalkService)<>'') and (GetTickCount64<FTalkUntil);
  if not Result then Exit;
  Service:=FTalkService;FTalk:='';FTalkNpc:='';FTalkService:='';FMap.Invalidate;
  OpenService(Service);
end;

{ One message on the live square; False when the stream is not up (HTTP takes over). }
function TGameForm.PlazaSend(const ActionName,Key,Value:string):Boolean;
var D:TJSONObject;
begin
  Result:=False;
  if not FPlazaLive or (FPlazaWorld=nil) or FPlazaWorld.Finished then Exit;
  Inc(FPlazaSeq);
  D:=TJSONObject.Create(['v',1,'type','plaza','action',ActionName,Key,Value,'seq',FPlazaSeq]);
  try Result:=FPlazaWorld.SendText(D.AsJSON);finally D.Free;end;
  if not Result then FPlazaLive:=False;
end;

{ Moves the own hero at once and sends the step; the server confirms or snaps back. }
procedure TGameForm.PlazaStep(const Direction:string);
var NX,NY:Integer;Npc:TJSONData;
begin
  if (FPlazaTown=nil) or (FPlaza=nil) or (FPlazaX<0) then Exit;
  if GetTickCount64-FPlazaStepAt<120 then begin FPlazaHeld:=Direction;Exit;end;
  FPlazaHeld:='';NX:=FPlazaX;NY:=FPlazaY;
  case Direction of 'north':Dec(NY);'south':Inc(NY);'west':Dec(NX);'east':Inc(NX);else Exit;end;
  if not PlazaWalkable(FPlazaTown,FPlaza,NX,NY) then Exit;
  // Over the stream every step leaves at once; only a runaway backlog waits.
  if FPlazaLive and (FPlazaPending>=8) then begin FPlazaHeld:=Direction;Exit;end;
  FPlazaX:=NX;FPlazaY:=NY;FPlazaStepAt:=GetTickCount64;
  if PlazaSend('move','direction',Direction) then Inc(FPlazaPending)
  else begin if FPlazaOutbox.Count<6 then FPlazaOutbox.Add(Direction);FlushPlaza;end;
  // Walking away ends the conversation.
  if FTalkNpc<>'' then begin Npc:=NearestNpc(FPlaza,FPlazaX,FPlazaY,3);
    if (Npc=nil) or (JStr(Npc,'id')<>FTalkNpc) then begin FTalk:='';FTalkNpc:='';FTalkService:='';end;end;
  FMap.Invalidate;
end;

{ HTTP fallback: sends the oldest queued step, never two closer than the server's pace allows. }
procedure TGameForm.FlushPlaza;
var D:TJSONObject;Direction:string;
begin
  if FPlazaBusy or FPlazaInFlight or (FPlazaOutbox.Count=0) or (GetTickCount64-FPlazaSentAt<90) then Exit;
  Direction:=FPlazaOutbox[0];FPlazaOutbox.Delete(0);
  FPlazaInFlight:=True;FPlazaSentAt:=GetTickCount64;
  D:=TJSONObject.Create(['action','move','direction',Direction]);
  try Send('POST','characters/'+FHero+'/plaza',D.AsJSON,'plaza_move');finally D.Free;end;
  if not FPlazaBusy then FPlazaInFlight:=False;
end;

{ Talking and emotes. Over HTTP a talk waits for the queued steps, so that the server judges
  the distance from the same cell the player sees. }
procedure TGameForm.PlazaAct(const ActionName,Target:string);
var D:TJSONObject;
begin
  if PlazaSend(ActionName,'target',Target) then begin FPlazaAsk:='';Exit;end;
  if FPlazaBusy or FPlazaInFlight or (FPlazaOutbox.Count>0) then begin FPlazaAsk:=ActionName+'|'+Target;Exit;end;
  FPlazaAsk:='';
  D:=TJSONObject.Create(['action',ActionName,'target',Target]);
  try Send('POST','characters/'+FHero+'/plaza',D.AsJSON,'plaza_'+ActionName);finally D.Free;end;
end;

{ A view of the square, from HTTP or the stream: the own cell follows the server only when no
  step of ours is still on the way. Takes ownership of D. }
procedure TGameForm.ApplyPlaza(D:TJSONData);
var Npcs,Keepers,Walkers:TJSONData;List:TJSONArray;I:Integer;
begin
  if D.FindPath('map')<>nil then begin FPlazaTown.Free;FPlazaTown:=D.Clone;end;
  // Live views carry only the walkers; the keepers come from the full view.
  Walkers:=D.FindPath('walkers');Npcs:=D.FindPath('npcs');
  if (Npcs=nil) and (Walkers<>nil) and (FPlazaTown<>nil) then begin
    List:=TJSONArray.Create;Keepers:=FPlazaTown.FindPath('npcs');
    if Keepers<>nil then for I:=0 to Keepers.Count-1 do if JStr(Keepers.Items[I],'service')<>'' then List.Add(Keepers.Items[I].Clone);
    for I:=0 to Walkers.Count-1 do List.Add(Walkers.Items[I].Clone);
    TJSONObject(D).Add('npcs',List);
  end;
  if (FPlazaX<0) or ((FPlazaOutbox.Count=0) and not FPlazaInFlight and (FPlazaPending=0) and (GetTickCount64-FPlazaStepAt>400)) then begin
    FPlazaX:=JInt(D,'self.x');FPlazaY:=JInt(D,'self.y');end;
  FPlaza.Free;FPlaza:=D;FPlazaPollAt:=GetTickCount64;
  FMap.Invalidate;
end;

procedure TGameForm.PlazaMessage(const Payload,ErrorText:string);
var D:TJSONData;Kind:string;
begin
  if ErrorText<>'' then begin FPlazaLive:=False;FPlazaPending:=0;FPlazaReconnectAt:=GetTickCount64+3000;Exit;end;
  if not PlazaActive then Exit;
  D:=GetJSON(Payload);
  try
    Kind:=JStr(D,'type');
    if Kind='plaza' then begin
      FPlazaLive:=FPlazaLive or (D.FindPath('map')<>nil);
      ApplyPlaza(D);D:=nil;
    end else if Kind='plaza_result' then begin
      if JStr(D,'action')='move' then begin
        if FPlazaPending>0 then Dec(FPlazaPending);
        // A refused step, or a settled path that ended elsewhere, snaps to the server's cell.
        if (JStr(D,'result')<>'ok') or ((FPlazaPending=0) and ((JInt(D,'x')<>FPlazaX) or (JInt(D,'y')<>FPlazaY))) then begin
          FPlazaX:=JInt(D,'x');FPlazaY:=JInt(D,'y');FMap.Invalidate;end;
      end else if JStr(D,'action')='talk' then begin
        if D.FindPath('talk')<>nil then ShowTalk(D.FindPath('talk'))
        else if JStr(D,'result')='too_far' then FStatus.Caption:='Подойдите ближе, чтобы поговорить.'
        else FStatus.Caption:=FriendlyError(JStr(D,'result'));
      end;
    end else if (Kind='error') and (JStr(D,'code')='in_expedition') then begin
      ResetPlaza;RefreshSheet;
    end;
  finally D.Free;end;
end;

procedure TGameForm.OpenTown(Tab:Integer);
var Town:TTownForm;
begin
  if FHero='' then begin FTabs.ActivePage:=FAccountTab;FStatus.Caption:='Сначала войдите и выберите героя.';Exit;end;
  if FExpedition<>'' then begin FStatus.Caption:='Городские службы доступны после возвращения из похода.';Exit;end;
  FTimer.Enabled:=False;Town:=TTownForm.CreateTown(Self,FServer.Text,FToken,FHero,Tab);
  try Town.ShowModal;if FSmoke and (Town.LastError<>'') then SmokeResult('PASCAL_SMOKE_FAILED town '+Town.LastError,True);
  finally Town.Free;FTimer.Enabled:=True;end;
  RefreshSheet;
end;

{ What a keeper's conversation opens. }
procedure TGameForm.OpenService(const Service:string);
begin
  case Service of
    'forge':OpenTown(0);'market':OpenTown(1);'guild':OpenTown(2);'bounty','temple':OpenTown(3);'alchemy':OpenTown(4);
    'mentor':begin FTabs.ActivePage:=FHeroTab;FStatus.Caption:='Выберите наставника во вкладке «Герой» (с '+JStr(FSheet,'mentor_level','10')+' уровня).';end;
    'gate':begin FTabs.ActivePage:=FTripTab;FMode.ItemIndex:=0;FStatus.Caption:='Выберите область и нажмите «Подготовить». В группе до '+JStr(FSheet,'max_party','8')+' героев.';end;
    'arena':begin FTabs.ActivePage:=FTripTab;FMode.ItemIndex:=1;FStatus.Caption:='Режим дуэли выбран: «Подготовить», затем передайте код сопернику.';end;
    'library':ShowFieldGuide(Self);
  end;
end;

{ Everything the hero may strike: monsters, plus the other duelists in a duel. Caller frees. }
function TGameForm.Hostiles(W:TJSONData):TJSONArray;
var I:Integer;E:TJSONData;
begin
  Result:=TJSONArray.Create;
  for I:=0 to W.FindPath('enemies').Count-1 do Result.Add(W.FindPath('enemies').Items[I].Clone);
  if JStr(W,'mode')='duel' then for I:=0 to W.FindPath('players').Count-1 do begin E:=W.FindPath('players').Items[I];
    if (JStr(E,'id')<>FHero) and (JInt(E,'hp')>0) and (E.FindPath('outcome').JSONType=jtNull) then Result.Add(E.Clone);end;
end;

{ The chosen target when it is still visible and in range, otherwise the nearest hostile. }
function TGameForm.PickTarget(W:TJSONData;Range:Integer;Adjacent:Boolean):string;
var List:TJSONArray;I,D,Best:Integer;E:TJSONData;
begin
  Result:='';Best:=1000;List:=Hostiles(W);
  try
    for I:=0 to List.Count-1 do begin E:=List.Items[I];
      D:=Abs(JInt(E,'x')-JInt(W,'self.x'))+Abs(JInt(E,'y')-JInt(W,'self.y'));
      if (D>Range) or (Adjacent and (D<>1)) then Continue;
      if JStr(E,'id')=FTarget then begin Result:=FTarget;Exit;end;
      if D<Best then begin Best:=D;Result:=JStr(E,'id');end;
    end;
  finally List.Free;end;
end;

procedure TGameForm.CycleTarget;
var W:TJSONData;List:TJSONArray;I,NextIndex:Integer;
begin
  if (FSnapshot=nil) or (FExpedition='') then Exit;
  W:=FSnapshot.FindPath('world');List:=Hostiles(W);
  try
    if List.Count=0 then begin FTarget:='';FStatus.Caption:='Врагов в поле зрения нет.';Exit;end;
    NextIndex:=0;
    for I:=0 to List.Count-1 do if JStr(List.Items[I],'id')=FTarget then NextIndex:=(I+1) mod List.Count;
    FTarget:=JStr(List.Items[NextIndex],'id');
    FStatus.Caption:='Цель: '+JStr(List.Items[NextIndex],'name')+' — '+JStr(List.Items[NextIndex],'hp')+'/'+JStr(List.Items[NextIndex],'max_hp',JStr(List.Items[NextIndex],'hp'))+' HP.';
  finally List.Free;end;
  FMap.Invalidate;
end;

procedure TGameForm.DrawListItem(Control:TWinControl;Index:Integer;ARect:TRect;State:TOwnerDrawState);
var Box:TListBox;Spec:string;
begin
  Box:=Control as TListBox;Spec:='';
  if (Box=FBag) and (FBagSpecs<>nil) and (Index<FBagSpecs.Count) then Spec:=FBagSpecs[Index];
  if Box=FSpells then Spec:='s|'+SpellKey(Index);
  DrawIconRow(Box.Canvas,ARect,Box.Items[Index],Spec,odSelected in State);
end;

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

{ Plain HTTP is allowed to loopback and to private LAN servers (old PCs on a home network). }
function LanAddress(const Base:string):Boolean;
var Host:string;Parts:TStringArray;Second:Integer;
begin
  Result:=False;if Copy(Base,1,7)<>'http://' then Exit;
  Host:=ParseURI(Base).Host;Parts:=Host.Split('.');if Length(Parts)<>4 then Exit;
  Second:=StrToIntDef(Parts[1],-1);
  Result:=(Parts[0]='10') or ((Parts[0]='192') and (Parts[1]='168')) or ((Parts[0]='172') and (Second>=16) and (Second<=31));
end;

function OutcomeText(const Outcome:string):string;
begin
  case Outcome of
    'extracted':Result:='вы вернулись с добычей';'defeated':Result:='поражение';'abandoned':Result:='поход покинут';
    'victory':Result:='ПОБЕДА в дуэли';'draw':Result:='ничья';
  else Result:=Outcome;end;
end;

function ActionCooldown(const ActionName:string):Integer;
begin
  case ActionName of
    'move':Result:=2;'attack':Result:=6;'bash':Result:=12;'guard','potion':Result:=10;
    'cast':Result:=5;'revive':Result:=30;'interact':Result:=4;
  else Result:=0;end;
end;

procedure TGameForm.WorldMessage(const Payload,ErrorText:string);
var D,W:TJSONData;Fresh,Kind:string;Rejected:Boolean;
begin
  if ErrorText<>'' then begin FReconnectAt:=GetTickCount64+3000;FWsInFlight:=0;FStreamMap:='';Exit;end;
  D:=nil;Rejected:=False;
  try D:=GetJSON(Payload);Kind:=JStr(D,'type');
    if (Kind='snapshot') and (JStr(D,'instance_id')=FExpedition) then begin
      // Lean snapshots leave out an explored map that has not changed since the last one.
      W:=D.FindPath('world');
      if (W<>nil) and (W.FindPath('map')<>nil) then begin FStreamMap:=W.FindPath('map').AsJSON;FStreamSnapshot:=Payload;end
      else if (W<>nil) and (FStreamMap<>'') then begin TJSONObject(W).Add('map',GetJSON(FStreamMap));FStreamSnapshot:=D.AsJSON;end
      else FStreamSnapshot:='';
      FStreamSeen:=FStreamSeen or (FStreamSnapshot<>'');
    end else if (Kind='command_result') and (FWsInFlight>0) then begin
      Dec(FWsInFlight);
      if JStr(D,'status')='rejected' then begin
        Rejected:=True;FPathCount:=0;FLocalReady:=0;
        // An early arrival is not an error: silently retry once the hero is ready.
        if JStr(D,'reason')='cooldown' then begin
          FLocalReady:=EstimatedTick+3;
          if FPendingAction='' then begin FPendingAction:=FLastAction;FPendingDirection:=FLastDirection;end;
        end else FStatus.Caption:=FriendlyError(JStr(D,'reason'));
      end else FAwaitingStream:=GetTickCount64;
    end else if (Kind='error') and (FWsInFlight>0) then begin
      Dec(FWsInFlight);FPendingAction:='';FStatus.Caption:='Сервер отклонил действие.';
    end else if Kind='server_paused' then
      FStatus.Caption:='Мир на сервере временно приостановлен. Подождите…';
  finally D.Free;end;
  // Apply the pushed state immediately instead of waiting for the next poll-timer tick.
  if not FSmoke and not FBusy and (FStreamSnapshot<>'') then begin
    Fresh:=FStreamSnapshot;FStreamSnapshot:='';Received('poll',Fresh,'');
  end else if Rejected then DispatchPending;
end;

procedure TGameForm.Shown(Sender: TObject);
begin
  ResizeLayout(nil);FTimer.Enabled:=True;RemoveOldBinary;
  if ParamStr(1)='--self-update-test' then begin SelfUpdateTest;Exit;end;
  // A quiet check in the background; the player decides whether to install.
  if not FSmoke and SupportsNativeNetwork and (ParamStr(1)<>'--no-update-check') then TUpdateThread.Create(False,Default(TUpdateInfo),@UpdateDone);
end;

procedure TGameForm.UpdateDone(const Info:TUpdateInfo;Installed:Boolean;const Message:string);
begin
  if Installed then begin
    MessageDlg('Обновление установлено',Message+' Клиент перезапустится.',mtInformation,[mbOK],0);
    RestartClient;Application.Terminate;Exit;
  end;
  if Message<>'' then begin FStatus.Caption:='Обновление не установлено: '+Message;FManualUpdate:=False;Exit;end;
  if Info.Available then begin
    if FExpedition<>'' then begin FStatus.Caption:='Доступна версия '+Info.Version+'. Обновите клиент после похода: Аккаунт → «Проверить обновления».';Exit;end;
    if MessageDlg('Доступно обновление','Вышла версия '+Info.Version+' (у вас '+ClientVersion+'). Скачать и установить сейчас? Клиент перезапустится.',
      mtConfirmation,[mbYes,mbNo],0)=mrYes then begin
      FStatus.Caption:='Загрузка обновления '+Info.Version+'…';TUpdateThread.Create(True,Info,@UpdateDone);
    end;
  end else if FManualUpdate then begin
    if Info.Error<>'' then FStatus.Caption:='Не удалось проверить обновления: '+Info.Error
    else FStatus.Caption:='У вас последняя версия клиента ('+ClientVersion+').';
  end;
  FManualUpdate:=False;
end;

{ Non-interactive end-to-end check of the updater, used to test a release. }
procedure TGameForm.SelfUpdateTest;
var Info:TUpdateInfo;Message,Report:string;OK:Boolean;
begin
  Info:=CheckForUpdate;OK:=False;Message:='';
  if Info.Available then OK:=InstallUpdate(Info,Message);
  Report:='UPDATE_TEST current='+ClientVersion+' latest='+Info.Version+' available='+BoolToStr(Info.Available,True)+
    ' asset='+Info.AssetName+' installed='+BoolToStr(OK,True)+' error='+Info.Error+' message='+Message;
  with TStringList.Create do try Text:=Report;SaveToFile(ExtractFilePath(ParamStr(0))+'update-test.txt');finally Free;end;
  Halt(Ord(not OK));
end;

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
var CX,CY,I:Integer;List:TJSONArray;E:TJSONData;
begin
  if PlazaActive and (FPlazaView.S>0) then begin
    FocusGame;
    CX:=FPlazaView.LeftCell+(X-FPlazaView.OX) div FPlazaView.S;CY:=FPlazaView.TopCell+(Y-FPlazaView.OY) div FPlazaView.S;
    E:=NearestNpc(FPlaza,CX,CY,0);
    if E<>nil then begin
      if Abs(JInt(E,'x')-FPlazaX)+Abs(JInt(E,'y')-FPlazaY)>2 then FStatus.Caption:=JStr(E,'name')+': подойдите ближе, чтобы поговорить.'
      else if not TalkOpens(E) then PlazaAct('talk',JStr(E,'id'));
    end;
    Exit;
  end;
  if (FExpedition='') and (FHero<>'') then begin ButtonClick(FindComponent('TownButton'));Exit;end;
  FocusGame;
  if (FSnapshot=nil) or (FView.S=0) or (X<FView.OX) or (Y<FView.OY) then Exit;
  CX:=FView.LeftCell+(X-FView.OX) div FView.S;CY:=FView.TopCell+(Y-FView.OY) div FView.S;
  if (CX>=FView.LeftCell+FView.Columns) or (CY>=FView.TopCell+FView.Rows) then Exit;
  List:=Hostiles(FSnapshot.FindPath('world'));
  try
    FTarget:='';
    for I:=0 to List.Count-1 do begin E:=List.Items[I];
      if (JInt(E,'x')=CX) and (JInt(E,'y')=CY) then begin
        FTarget:=JStr(E,'id');FStatus.Caption:='Цель: '+JStr(E,'name')+' — '+JStr(E,'hp')+'/'+JStr(E,'max_hp',JStr(E,'hp'))+' HP.';
        // Right click strikes at once.
        if Button=mbRight then Action('attack','');
      end;
    end;
  finally List.Free;end;
  FMap.Invalidate;
end;

procedure TGameForm.FocusGame;
begin
  if FMap.CanFocus then FMap.SetFocus;
end;

procedure TGameForm.DispatchPending;
var ButtonName, ActionName, Direction: string; W: TJSONData;
begin
  if FBusy or (FWsInFlight>=2) then Exit;
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
begin CanClose:=not (FBusy or FChatBusy or FPlazaBusy); if not CanClose then FStatus.Caption:='Завершается сетевой запрос. Повторите закрытие через несколько секунд.'; end;

procedure TGameForm.Send(const Method, Path, Body, Kind: string);
var Base: string;
begin
  if ((Copy(Kind,1,4)='chat') and FChatBusy) or ((Copy(Kind,1,5)='plaza') and FPlazaBusy) or
    ((Copy(Kind,1,4)<>'chat') and (Copy(Kind,1,5)<>'plaza') and FBusy) then Exit;
  Base:=Trim(FServer.Text);
  // This prototype intentionally permits plain HTTP only on loopback.
  if not (SupportsNativeNetwork and (Copy(Base,1,8)='https://')) and
    (Base<>'http://127.0.0.1:8080') and (Base<>'http://localhost:8080') and not LanAddress(Base) and
    not (FSmoke and (Base='http://api:8000')) then begin
    FStatus.Caption:='Используйте локальный сервер :8080, сервер в домашней сети (http://192.168.x.x:8080) или HTTPS-сервер.'; Exit;
  end;
  // Chat and the town square run on their own lanes, so they never delay game actions.
  if Copy(Kind,1,4)='chat' then FChatBusy:=True
  else if Copy(Kind,1,5)='plaza' then FPlazaBusy:=True
  else begin FBusy:=True;FHeroes.Enabled:=False;end;
  TRequestThread.Create(@Received,Base+'/api/v1/'+Path,FToken,Body,Method,Kind);
end;

procedure TGameForm.ButtonClick(Sender: TObject);
var ButtonName, Kind, Value: string; D: TJSONObject;
begin
  ButtonName:=(Sender as TButton).Name;
  if ButtonName='GuideButton' then begin ShowFieldGuide(Self);Exit;end;
  if ButtonName='UpdateButton' then begin
    if not SupportsNativeNetwork then begin FStatus.Caption:='Автообновление есть в Windows-клиенте. Новые версии: '+ReleasePage;Exit;end;
    FManualUpdate:=True;FStatus.Caption:='Проверяем обновления…';TUpdateThread.Create(False,Default(TUpdateInfo),@UpdateDone);Exit;
  end;
  if FBusy then begin
    FPendingButton:=ButtonName;
    FStatus.Caption:='Действие принято. Ожидаем ответ сервера…';
    Exit;
  end;
  if ButtonName='TownButton' then begin OpenTown(0);Exit;
  end else if ButtonName='MentorButton' then begin
    if (FSheet=nil) or (FMentor.ItemIndex<0) then Exit;
    if FExpedition<>'' then begin FStatus.Caption:='Наставника выбирают в городе.';Exit;end;
    D:=TJSONObject.Create(['operation_id',NewCommandId,'action','mentor','target',ClassKeys[FMentor.ItemIndex]]);
    try Send('POST','characters/'+FHero+'/town',D.AsJSON,'mentor');finally D.Free;end;Exit;
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
    Value:=ClassKeys[Max(0,FClass.ItemIndex)];
    D:=TJSONObject.Create(['name',FName.Text,'class_id',Value,'origin',OriginKeys[Max(0,FOrigin.ItemIndex)]]);
    try Send('POST','characters',D.AsJSON,'hero'); finally D.Free; end;
  end else if ButtonName='ExpeditionButton' then begin
    if FHero='' then begin FStatus.Caption:='Сначала создайте героя или войдите.'; Exit; end;
    D:=TJSONObject.Create(['character_id',FHero]);
    if (FBiome.ItemIndex>=0) and (FBiome.ItemIndex<FBiomeKeys.Count) then Value:=FBiomeKeys[FBiome.ItemIndex] else Value:='mines';
    D.Add('biome',Value);
    if (FMode<>nil) and (FMode.ItemIndex=1) and (Trim(FCode.Text)='') then D.Add('mode','duel');
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
var D, Items, W: TJSONData; I, Selected:Integer; Fresh:string;
begin
  if Copy(Kind,1,4)='chat' then FChatBusy:=False
  else if Copy(Kind,1,5)='plaza' then FPlazaBusy:=False
  else begin FBusy:=False;FHeroes.Enabled:=FExpedition='';end;
  if Kind='poll' then FAwaitingStream:=0;
  if (Error<>'') and (Copy(Kind,1,5)='plaza') and (Pos('HTTP 401',Error)=0) then begin
    FPlazaInFlight:=False;
    if Kind='plaza_talk' then FStatus.Caption:=FriendlyError(Error);
    // An older server or a hero on the way out: the HTTP square stays, the stream is retried later.
    if Kind='plaza_ticket' then FPlazaReconnectAt:=GetTickCount64+30000;
    if Kind='plaza_move' then begin FPlazaOutbox.Clear;if FPlaza<>nil then begin FPlazaX:=JInt(FPlaza,'self.x');FPlazaY:=JInt(FPlaza,'self.y');end;end;
    if Pos('in_expedition',Error)>0 then begin ResetPlaza;RefreshSheet;end;
    Exit;
  end;
  if Error<>'' then begin
    FPendingAction:=''; FPendingButton:='';
    FStatus.Caption:=FriendlyError(Error);
    if Kind='ticket' then FReconnectAt:=GetTickCount64+10000;
    if Kind='refresh' then FSessionUntil:=GetTickCount64+90000;
    if Pos('HTTP 401',Error)>0 then begin
      FExpedition:='';FToken:='';FHero:='';FHeroes.Enabled:=True;ResetPlaza;
      FreeAndNil(FSnapshot);FreeAndNil(FSheet);FMap.Invalidate;
      FServer.Enabled:=True;FEmail.Enabled:=True;FPassword.Enabled:=True;FName.Enabled:=True;FCode.ReadOnly:=False;
      FTabs.ActivePage:=FAccountTab;
    end;
    if FSmoke then SmokeResult('PASCAL_SMOKE_FAILED '+Error,True);
    Exit;
  end;
  if Kind='logout' then begin
    FToken:='';FHero:='';FHeroes.Clear;FChat.Clear;FBag.Clear;FSpells.Clear;ResetPlaza;
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
    if Kind='plaza_ticket' then begin
      if PlazaActive and (FPlazaWorld=nil) then begin
        FPlazaLive:=False;FPlazaPending:=0;FPlazaWorld:=TWorldConnection.Create(WorldUrl,JStr(D,'ticket'),@PlazaMessage);end;
    end else if Copy(Kind,1,5)='plaza' then begin
      if Kind='plaza_move' then FPlazaInFlight:=False;
      if (Kind='plaza_move') and (JStr(D,'result')<>'ok') then begin
        // The server refused the step: snap to its position and forget the queued ones.
        FPlazaOutbox.Clear;if not FPlazaLive then FPlazaX:=-1;
      end;
      if D.FindPath('talk')<>nil then ShowTalk(D.FindPath('talk'));
      // Once the stream is live, a late HTTP answer must not roll the square back.
      if not FPlazaLive or (FPlazaTown=nil) then begin ApplyPlaza(D);D:=nil;end;
    end else if Kind='mentor' then begin
      FStatus.Caption:='Наставник выбран: его черты и школы магии теперь ваши.';RefreshSheet;
    end else if Kind='refresh' then begin
      FToken:=JStr(D,'access_token');FSessionUntil:=GetTickCount64+QWord(JInt(D,'expires_in',28800))*1000;
      FreeAndNil(FWorld);FStreamSeen:=False;FStreamSnapshot:='';FWsInFlight:=0;
      // Streams are bound to the old session: reopen the square with a fresh ticket.
      if FPlazaWorld<>nil then FPlazaWorld.Stop;FPlazaLive:=False;FPlazaPending:=0;
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
        FreeAndNil(FWorld);FStreamSeen:=False;FStreamMap:='';FWsInFlight:=0;
        FWorld:=TWorldConnection.Create(WorldUrl,JStr(D,'ticket'),@WorldMessage);
      end;
    end else if Kind='expedition' then begin
      FExpedition:=D.FindPath('id').AsString; FCode.Text:=D.FindPath('join_code').AsString;ResetPlaza;
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
      // A new floor (or run) starts with an unexplored map.
      if FExploreKey<>FExpedition+':'+JStr(W,'floor')+':'+JStr(W,'biome') then begin
        FExploreKey:=FExpedition+':'+JStr(W,'floor')+':'+JStr(W,'biome');ClearExplored(FView);FTarget:='';end;
      Explore(FView,W.FindPath('map'));
      FCode.Text:=JStr(FSnapshot,'join_code');
      FHealth.Max:=JInt(W,'self.max_hp',100);FHealth.Position:=JInt(W,'self.hp');
      FMana.Max:=JInt(W,'self.max_mana',60);FMana.Position:=JInt(W,'self.mana',60);
      if FTabs.ActivePage=FSpellTab then SelectSpell(nil);
      FServer.Enabled:=False; FEmail.Enabled:=False; FPassword.Enabled:=False;
      FName.Enabled:=False; FCode.ReadOnly:=True;
      if FSnapshot.FindPath('lobby').AsBoolean then
        FStatus.Caption:='Подготовка группы: движение пока выключено. Лидер должен нажать «Начать».';
      FStats.Caption:=JStr(W,'self.name')+' | Ур. '+JStr(W,'self.level','1')+' | Глубина '+JStr(W,'floor')+' (враги ур. '+JStr(W,'depth_level','1')+')'+
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
        FStatus.Caption:='Результат: '+OutcomeText(W.FindPath('self.outcome').AsString)+'. Можно начать новый поход или дуэль.';
        FExpedition:=''; FCode.Clear;
        FHeroes.Enabled:=True;if FChannel.ItemIndex=1 then begin FChannel.ItemIndex:=0;ChannelChanged(nil);end;
        FPendingAction:=''; FServer.Enabled:=True; FEmail.Enabled:=True;
        FPassword.Enabled:=True; FName.Enabled:=True; FCode.ReadOnly:=False;
        RefreshSheet;
      end;
      FMap.Invalidate;
      if FSmoke then begin
        Inc(FSmokePolls);
        if FSmokeMoveSent and (W.FindPath('self.x').AsInteger=FSmokeStartX+1) and (not SupportsWorldStream or FStreamSeen) then begin
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
  Inc(FPollCounter);
  // The town square and chat run on their own lanes, even while a game request is out.
  if (FPlazaWorld<>nil) and (FPlazaWorld.Finished or not PlazaActive) then begin
    FreeAndNil(FPlazaWorld);FPlazaLive:=False;FPlazaPending:=0;
  end;
  if PlazaActive then begin
    if (FPlazaHeld<>'') and (GetTickCount64-FPlazaStepAt>=120) then PlazaStep(FPlazaHeld);
    if (FTalk<>'') and (GetTickCount64>FTalkUntil) then begin FTalk:='';FTalkNpc:='';FTalkService:='';FMap.Invalidate;end;
    if SupportsWorldStream and (FPlazaWorld=nil) and (GetTickCount64>=FPlazaReconnectAt) and not FPlazaBusy then begin
      FPlazaReconnectAt:=GetTickCount64+3000;D:=TJSONObject.Create(['character_id',FHero]);
      try Send('POST','world/tickets',D.AsJSON,'plaza_ticket');finally D.Free;end;
    end;
    if FPlazaAsk<>'' then begin Payload:=FPlazaAsk;PlazaAct(Copy(Payload,1,Pos('|',Payload)-1),Copy(Payload,Pos('|',Payload)+1,40));end;
    // Without the stream the square is polled over HTTP, a few times a second.
    if not FPlazaLive then begin
      FlushPlaza;
      if not FPlazaBusy and (FPlazaTown=nil) then Send('GET','characters/'+FHero+'/plaza?full=1','','plaza_full')
      else if not FPlazaBusy and (FPlazaOutbox.Count=0) and not FPlazaInFlight and (GetTickCount64-FPlazaPollAt>=300) then begin
        FPlazaPollAt:=GetTickCount64;Send('GET','characters/'+FHero+'/plaza','','plaza');end;
    end;
  end;
  if not FSmoke and (FHero<>'') and (FPollCounter mod 20=0) then ReadChat;
  if FBusy then Exit;
  // A WebSocket command whose result never arrived must not block input: drop the count and
  // let the HTTP fallback below refresh the state.
  if (FWsInFlight>0) and (GetTickCount64-FWsSentAt>3000) then begin
    FWsInFlight:=0;FAwaitingStream:=GetTickCount64-2000;
  end;
  // Held keys are dispatched from the local tick estimate, not only when a snapshot arrives.
  if (FPendingAction<>'') and (FWsInFlight<2) then begin DispatchPending;if FBusy then Exit;end;
  if (FPathCount>0) and (GetTickCount64>FPredUntil) then FPathCount:=0;
  if (FExpedition<>'') and (FSnapshot<>nil) then FMap.Invalidate;
  if (FToken<>'') and (FSessionUntil>0) and (GetTickCount64+60000>=FSessionUntil) then begin
    Send('POST','auth/refresh','{}','refresh');Exit;
  end;
  if (FWorld<>nil) and ((FExpedition='') or FWorld.Finished) then begin
    FreeAndNil(FWorld);FStreamSnapshot:='';FStreamSeen:=False;FWsInFlight:=0;FStreamMap:='';
  end;
  if SupportsWorldStream and (FExpedition<>'') and (FWorld=nil) and (GetTickCount64>=FReconnectAt) then begin
    D:=TJSONObject.Create(['character_id',FHero,'expedition_id',FExpedition]);
    try Send('POST','world/tickets',D.AsJSON,'ticket');finally D.Free;end;Exit;
  end;
  if not FSmoke and (FStreamSnapshot<>'') then begin
    Payload:=FStreamSnapshot;FStreamSnapshot:='';Received('poll',Payload,'');
    if FBusy then Exit;
  end;
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
var D, P: TJSONObject; W, Players, E: TJSONData; I, Distance, BestDistance, Range: Integer; Target, CommandId, Kind: string; Sent: Boolean;
begin
  if FSmoke then Inc(FSmokeActions);
  if (FExpedition='') or (FSnapshot=nil) then Exit;
  if FSnapshot.FindPath('lobby').AsBoolean then begin
    FStatus.Caption:='Сначала нажмите «Начать» — сейчас группа ещё в подготовке.'; Exit;
  end;
  if ActionName='move' then FFacing:=Direction;
  W:=FSnapshot.FindPath('world');
  // Up to two commands may be on the way: the server buffers one that arrives early.
  if FBusy or (FWsInFlight>=2) or not CanAct then begin
    FPendingAction:=ActionName; FPendingDirection:=Direction; Exit;
  end;
  P:=TJSONObject.Create(['action',ActionName]);
  if ActionName='revive' then begin
    Players:=W.FindPath('players');Target:='';
    for I:=0 to Players.Count-1 do begin E:=Players.Items[I];
      if (JInt(E,'hp')=0) and (Abs(JInt(E,'x')-JInt(W,'self.x'))+Abs(JInt(E,'y')-JInt(W,'self.y'))<=1) then begin Target:=JStr(E,'id');Break;end;
    end;
    if Target='' then begin P.Free;FStatus.Caption:='Рядом нет павшего союзника.';Exit;end;P.Add('target_id',Target);
  end;
  if ActionName='cast' then begin
    P.Add('spell_id',Direction);Kind:='';
    if FSheet<>nil then begin Kind:=JStr(FSheet,'spells.'+Direction+'.kind');Range:=JInt(FSheet,'spells.'+Direction+'.range',6);end else Range:=6;
    if (Direction='mend') and HasTrait(W.FindPath('self'),'ally_heal') then begin
      Players:=W.FindPath('players');Target:=FHero;BestDistance:=101;
      for I:=0 to Players.Count-1 do begin E:=Players.Items[I];
        if (JInt(E,'hp')>0) and (Abs(JInt(E,'x')-JInt(W,'self.x'))+Abs(JInt(E,'y')-JInt(W,'self.y'))<=3) then begin
          Distance:=JInt(E,'hp')*100 div JInt(E,'max_hp',100);
          if Distance<BestDistance then begin BestDistance:=Distance;Target:=JStr(E,'id');end;
        end;
      end;P.Add('target_id',Target);
    end;
    // Aimed spells hit the chosen target; line spells and the blink follow the hero's facing.
    if (Kind='bolt') or (Kind='meteor') or (Kind='drain') or (Kind='chain') then begin
      Target:=PickTarget(W,Range,False);
      if (Target='') and (Kind<>'chain') then begin P.Free;FStatus.Caption:='Нет цели в радиусе заклинания ('+IntToStr(Range)+').';Exit;end;
      if Target<>'' then P.Add('target_id',Target);
    end;
    if (Kind='wave') or (Kind='blink') then P.Add('direction',FFacing);
  end else if Direction<>'' then P.Add('direction',Direction);
  if (ActionName='attack') or (ActionName='bash') then begin
    if (ActionName='attack') and HasTrait(W.FindPath('self'),'ranged') then Target:=PickTarget(W,5,False)
    else Target:=PickTarget(W,1,True);
    if Target='' then begin P.Free; FStatus.Caption:='Нет противника в радиусе атаки. Подойдите вплотную или выберите цель.'; Exit; end;
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
    if Sent then begin P.Free; Inc(FWsInFlight); FWsSentAt:=GetTickCount64; Exit; end;
  end;
  D:=TJSONObject.Create(['character_id',FHero,'command_id',CommandId]); D.Add('payload',P);
  try Send('POST','expeditions/'+FExpedition+'/commands',D.AsJSON,'command'); finally D.Free; end;
end;

procedure TGameForm.KeyInput(Sender: TObject; var Key: Word; Shift: TShiftState);
begin
  if FSmoke then Inc(FSmokeKeys);
  if Key=VK_F1 then begin ShowFieldGuide(Self);Key:=0;Exit;end;
  if Key=VK_ESCAPE then begin
    if PlazaActive and (FTalk<>'') then begin FTalk:='';FTalkNpc:='';FTalkService:='';FMap.Invalidate;end;
    FocusGame;Key:=0;Exit;end;
  if (ActiveControl is TEdit) and (ActiveControl<>FCode) then Exit;
  if (ActiveControl is TComboBox) or (ActiveControl is TMemo) or (ActiveControl is TListBox) then Exit;
  if (ActiveControl=FCode) and not FCode.ReadOnly then Exit;
  if PlazaActive then begin
    case Key of
      VK_W,VK_UP:PlazaStep('north');VK_S,VK_DOWN:PlazaStep('south');VK_A,VK_LEFT:PlazaStep('west');VK_D,VK_RIGHT:PlazaStep('east');
      VK_F,VK_SPACE,VK_E,VK_RETURN:begin
        // The first F talks; the second, while the keeper's words are shown, opens the service.
        if NearestNpc(FPlaza,FPlazaX,FPlazaY,2)=nil then FStatus.Caption:='Рядом никого нет. Подойдите к жителю города.'
        else if not TalkOpens(NearestNpc(FPlaza,FPlazaX,FPlazaY,2)) then PlazaAct('talk',JStr(NearestNpc(FPlaza,FPlazaX,FPlazaY,2),'id'));end;
      VK_1:PlazaAct('emote','wave');VK_2:PlazaAct('emote','bow');VK_3:PlazaAct('emote','cheer');VK_4:PlazaAct('emote','dance');VK_5:PlazaAct('emote','sit');
      VK_T:ButtonClick(FindComponent('TownButton'));
    else Exit;end;
    Key:=0;Exit;
  end;
  case Key of
    VK_W,VK_UP: Action('move','north'); VK_S,VK_DOWN: Action('move','south');
    VK_A,VK_LEFT: Action('move','west'); VK_D,VK_RIGHT: Action('move','east');
    VK_SPACE: Action('attack',''); VK_1: Action('bash',''); VK_2: Action('guard','');
    VK_3: Action('potion',''); VK_E: Action('descend',''); VK_X: Action('extract','');
    VK_4: Action('cast','firebolt');VK_5: Action('cast','mend');VK_6: Action('cast','frost');VK_7: Action('cast','nova');
    VK_8: Action('cast','venom');VK_9: Action('cast','chain');VK_0: Action('cast','barrier');VK_Q: Action('cast','meteor');
    VK_Z: Action('cast','blink');VK_C: Action('cast','fire_wave');VK_V: Action('cast','whirlwind');VK_G: Action('cast','drain_life');
    VK_B: Action('cast','blood_rage');VK_N: Action('cast','entangle');VK_M: Action('cast','bone_spear');VK_H: Action('cast','sanctuary');
    VK_TAB: CycleTarget;
    VK_F: Action('interact','');
    VK_T:ButtonClick(FindComponent('TownButton'));
    VK_R:Action('revive','');
  else Exit; end;
  Key:=0;
end;

procedure TGameForm.Draw(Sender:TObject);
begin
  if PlazaActive and (FPlazaTown<>nil) and (FPlaza<>nil) and (FPlazaX>=0) then begin
    RenderPlaza(FMap.Canvas,FMap.Width,FMap.Height,FPlaza,FPlazaTown,FHero,FTalk,FPlazaX,FPlazaY,GetTickCount64,FPlazaView);Exit;end;
  FPlazaView.S:=0;
  FView.Hero:=FHero;FView.Expedition:=FExpedition;FView.Target:=FTarget;FView.Sheet:=FSheet;FView.EstTick:=EstimatedTick;
  RenderMap(FMap.Canvas,FMap.Width,FMap.Height,FSnapshot,FView);
end;
end.
