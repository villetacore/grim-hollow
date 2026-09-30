unit TownWindow;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Classes,SysUtils,Forms,Controls,Graphics,StdCtrls,ExtCtrls,ComCtrls,Dialogs,fpjson,jsonparser,
  GameTransport,GameData,GameTheme,GameProtocol;
type
  TTownForm=class(TForm)
  private
    FBase,FToken,FHero:string;
    FBusy:Boolean;
    FData:TJSONData;
    FTabs:TPageControl;
    FStatus,FOverview,FBounty:TLabel;
    FBag,FMarket,FRecipes,FInvites,FTalents:TListBox;
    FItemIds,FMarketIds,FRecipeIds,FInviteIds,FTalentIds:TStringList;
    FPrice,FName:TEdit;
    FSocial,FJournal:TMemo;
    procedure Click(Sender:TObject);
    procedure Received(const Kind,Response,Error:string);
    procedure Request(const Body:string);
    procedure Closing(Sender:TObject;var CanClose:Boolean);
    procedure Populate;
    function ListBox(Host:TWinControl;X,Y,W,H:Integer):TListBox;
  public
    LastError:string;
    constructor CreateTown(AOwner:TComponent;const Base,Token,Hero:string);
    destructor Destroy;override;
  end;
implementation
function TTownForm.ListBox(Host:TWinControl;X,Y,W,H:Integer):TListBox;
begin
  Result:=TListBox.Create(Self);Result.Parent:=Host;Result.SetBounds(X,Y,W,H);
  Result.Color:=TownColor;Result.Font.Color:=GoldColor;
end;
constructor TTownForm.CreateTown(AOwner:TComponent;const Base,Token,Hero:string);
var Tabs:TPageControl;Forge,Market,Social,Chronicle:TTabSheet;
  function Page(const TitleText:string):TTabSheet;
  begin Result:=TTabSheet.Create(Self);Result.PageControl:=Tabs;Result.Caption:=TitleText;Result.Color:=PanelColor;end;
begin
  inherited CreateNew(AOwner,1);Caption:='Пепельный предел — городские службы';Width:=980;Height:=700;
  BorderStyle:=bsDialog;Position:=poOwnerFormCenter;Color:=PanelColor;Font.Name:='Tahoma';Font.Size:=10;Font.Color:=GoldColor;
  FBase:=Base;FToken:=Token;FHero:=Hero;OnCloseQuery:=@Closing;
  FItemIds:=TStringList.Create;FMarketIds:=TStringList.Create;FRecipeIds:=TStringList.Create;FInviteIds:=TStringList.Create;FTalentIds:=TStringList.Create;
  FOverview:=LabelAt(Self,Self,'ПЕПЕЛЬНЫЙ ПРЕДЕЛ',20,12,930,60);FOverview.Font.Size:=12;
  Tabs:=TPageControl.Create(Self);Tabs.Parent:=Self;Tabs.SetBounds(12,82,940,510);Tabs.Font.Color:=clBlack;
  FTabs:=Tabs;
  Forge:=Page('Кузница и аптекарь');Market:=Page('Торговая доска');Social:=Page('Гильдия и друзья');Chronicle:=Page('Хроника и контракты');
  LabelAt(Self,Forge,'Рюкзак: выберите снятую вещь для разбора',16,12,420,30);
  FBag:=ListBox(Forge,16,46,420,278);
  ButtonAt(Self,Forge,'Разобрать в материалы','Salvage',16,338,205,@Click);
  ButtonAt(Self,Forge,'Зелье в запас: 8 крон','Supply',230,338,206,@Click);
  LabelAt(Self,Forge,'Рецепты кузнеца',466,12,420,30);FRecipes:=ListBox(Forge,466,46,420,278);
  ButtonAt(Self,Forge,'Изготовить выбранное','Craft',466,338,420,@Click);
  ButtonAt(Self,Forge,'Перегонка: 10 мат. + 20 кр. = 1 эссенция','Distill',16,376,420,@Click);
  LabelAt(Self,Forge,'Разбор даёт материалы по уровню вещи (вещи от 5 ур. — ещё и эссенцию). Эссенция выпадает с боссов и матёрых врагов и нужна для редких рецептов. Созданные вещи можно продать на рынке до первого надевания. В поход берутся до 3 дополнительных зелий.',16,414,870,72);
  LabelAt(Self,Market,'Открытые предложения / комиссия продавца 5%',16,12,850,30);FMarket:=ListBox(Market,16,46,870,226);
  ButtonAt(Self,Market,'Купить выбранный лот','Buy',16,288,420,@Click);ButtonAt(Self,Market,'Снять свой лот','Cancel',466,288,420,@Click);
  LabelAt(Self,Market,'Продажа: выберите вещь во вкладке кузницы, затем укажите цену.',16,340,870,30);
  FPrice:=TEdit.Create(Self);FPrice.Parent:=Market;FPrice.SetBounds(16,376,180,30);FPrice.Text:='20';FPrice.Font.Color:=clBlack;
  ButtonAt(Self,Market,'Выставить выбранную вещь','List',212,374,674,@Click);
  LabelAt(Self,Market,'Надетые и привязанные предметы не продаются игрокам. Предмет снимается с рюкзака до покупки или отмены лота.',16,426,870,44);
  FSocial:=TMemo.Create(Self);FSocial.Parent:=Social;FSocial.SetBounds(16,14,540,216);FSocial.ReadOnly:=True;FSocial.Color:=TownColor;FSocial.Font.Color:=GoldColor;FSocial.ScrollBars:=ssVertical;
  FInvites:=ListBox(Social,572,14,314,160);ButtonAt(Self,Social,'Принять приглашение','GuildAccept',572,190,314,@Click);
  LabelAt(Self,Social,'Имя персонажа для приглашения / имя новой гильдии',16,244,850,28);
  FName:=TEdit.Create(Self);FName.Parent:=Social;FName.SetBounds(16,278,870,30);FName.Font.Color:=clBlack;FName.MaxLength:=32;
  ButtonAt(Self,Social,'Создать гильдию','GuildCreate',16,324,280,@Click);ButtonAt(Self,Social,'Пригласить в гильдию','GuildInvite',310,324,280,@Click);ButtonAt(Self,Social,'Покинуть гильдию','GuildLeave',604,324,282,@Click);
  ButtonAt(Self,Social,'Предложить дружбу','FriendRequest',16,368,280,@Click);ButtonAt(Self,Social,'Принять дружбу','FriendAccept',310,368,280,@Click);ButtonAt(Self,Social,'Удалить / отклонить','FriendRemove',604,368,282,@Click);
  LabelAt(Self,Social,'Глава приглашает по имени персонажа. При выходе главы роль получает старший участник. Дружба объединяет аккаунты. Гильдейский чат доступен в главном окне.',16,418,870,56);
  FJournal:=TMemo.Create(Self);FJournal.Parent:=Chronicle;FJournal.SetBounds(16,16,870,140);FJournal.ReadOnly:=True;FJournal.ScrollBars:=ssVertical;FJournal.Color:=TownColor;FJournal.Font.Color:=GoldColor;
  FBounty:=LabelAt(Self,Chronicle,'Доска контрактов: возьмите заказ на врагов, эвакуируйтесь с трофеями и получите награду.',16,164,870,44);
  ButtonAt(Self,Chronicle,'Взять контракт','BountyTake',16,212,280,@Click);ButtonAt(Self,Chronicle,'Получить награду','BountyClaim',310,212,280,@Click);
  ButtonAt(Self,Chronicle,'Отказаться','BountyDrop',604,212,282,@Click);
  FTalents:=ListBox(Chronicle,16,254,870,88);ButtonAt(Self,Chronicle,'Развить выбранный талант','Talent',16,356,870,@Click);
  ButtonAt(Self,Chronicle,'Сбросить очки и таланты (бесплатно)','Respec',16,404,870,@Click);
  FStatus:=LabelAt(Self,Self,'Загрузка...',20,608,720,50);ButtonAt(Self,Self,'Обновить','RefreshTown',778,606,170,@Click);
  Tabs.ActivePage:=Forge;Request('');
end;
destructor TTownForm.Destroy;
begin FData.Free;FItemIds.Free;FMarketIds.Free;FRecipeIds.Free;FInviteIds.Free;FTalentIds.Free;inherited Destroy;end;
procedure TTownForm.Closing(Sender:TObject;var CanClose:Boolean);
begin CanClose:=not FBusy;if FBusy then FStatus.Caption:='Дождитесь завершения запроса.';end;
procedure TTownForm.Request(const Body:string);
var Method:string;
begin
  if FBusy then Exit;FBusy:=True;if Body='' then Method:='GET' else Method:='POST';
  TRequestThread.Create(@Received,FBase+'/api/v1/characters/'+FHero+'/town',FToken,Body,Method,'town');
end;
procedure TTownForm.Received(const Kind,Response,Error:string);
var ArtifactDir:string;I:Integer;
begin
  FBusy:=False;if Error<>'' then begin LastError:=Error;FStatus.Caption:=FriendlyError(Error);
    if (ParamStr(1)='--smoke') or (ParamStr(1)='--smoke-local') then ModalResult:=mrCancel;Exit;end;
  try FData.Free;FData:=nil;FData:=GetJSON(Response);Populate;FStatus.Caption:='Городские сведения обновлены.';
    if (ParamStr(1)='--smoke') or (ParamStr(1)='--smoke-local') then begin
      if ParamStr(1)='--smoke-local' then ArtifactDir:=ExtractFilePath(Application.ExeName)+'test-artifacts' else ArtifactDir:='/artifacts';
      ForceDirectories(ArtifactDir);Repaint;with GetFormImage do try SaveToFile(ArtifactDir+'/town-services.bmp');finally Free;end;
      for I:=0 to FTabs.PageCount-1 do begin
        FTabs.ActivePageIndex:=I;Repaint;
        with GetFormImage do try SaveToFile(ArtifactDir+'/town-tab-'+IntToStr(I)+'.bmp');finally Free;end;
      end;
      ModalResult:=mrOK;
    end;
  except on E:Exception do begin LastError:=E.Message;FStatus.Caption:=E.Message;
    if (ParamStr(1)='--smoke') or (ParamStr(1)='--smoke-local') then ModalResult:=mrCancel;end;end;
end;
procedure TTownForm.Populate;
var A,E:TJSONData;I:Integer;LineText:string;
begin
  FOverview.Caption:=JStr(FData,'hero.name')+'   /   Кроны: '+JStr(FData,'hero.gold')+'   Материалы: '+JStr(FData,'hero.materials')+
    '   Эссенция: '+JStr(FData,'hero.essence','0')+'   Запас зелий: '+JStr(FData,'hero.supplies')+LineEnding+
    'Пройдено областей: '+JStr(FData,'hero.campaign')+'/6   /   Изготовлено предметов: '+JStr(FData,'hero.craft_xp');
  FBag.Clear;FItemIds.Clear;A:=FData.FindPath('hero.items');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];LineText:=JStr(E,'name');if JStr(E,'equipped_slot')<>'' then LineText:='* '+LineText;
    if E.FindPath('bound').AsBoolean then LineText:=LineText+' [привязан]';FBag.Items.Add(LineText);FItemIds.Add(JStr(E,'id'));end;
  FRecipes.Clear;FRecipeIds.Clear;A:=FData.FindPath('recipes');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];LineText:='ур.'+JStr(E,'level')+' '+JStr(E,'name')+' ('+GameWord(JStr(E,'slot'))+') / '+JStr(E,'gold')+' кр. + '+JStr(E,'materials')+' мат.';
    if JInt(E,'essence')>0 then LineText:=LineText+' + '+JStr(E,'essence')+' эсс.';FRecipes.Items.Add(LineText);FRecipeIds.Add(TJSONObject(A).Names[I]);end;
  if FRecipes.Count>0 then FRecipes.ItemIndex:=0;
  FMarket.Clear;FMarketIds.Clear;A:=FData.FindPath('market');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];FMarket.Items.Add(JStr(E,'name')+' — '+JStr(E,'price')+' кр. / '+JStr(E,'seller'));FMarketIds.Add(JStr(E,'id'));end;
  FSocial.Clear;FSocial.Lines.Add('Гильдия: '+JStr(FData,'guild.name','не состоите'));A:=FData.FindPath('members');
  for I:=0 to A.Count-1 do FSocial.Lines.Add('  '+JStr(A.Items[I],'name')+' / '+GameWord(JStr(A.Items[I],'class_id')));
  FSocial.Lines.Add('');FSocial.Lines.Add('Друзья и заявки:');A:=FData.FindPath('friends');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];LineText:=JStr(E,'name');
    if E.FindPath('accepted').AsBoolean then LineText:=LineText+' — друзья' else if E.FindPath('incoming').AsBoolean then LineText:=LineText+' — входящая заявка' else LineText:=LineText+' — ожидает ответа';FSocial.Lines.Add(LineText);end;
  FInvites.Clear;FInviteIds.Clear;A:=FData.FindPath('invites');
  for I:=0 to A.Count-1 do begin FInvites.Items.Add(JStr(A.Items[I],'name'));FInviteIds.Add(JStr(A.Items[I],'id'));end;
  FJournal.Clear;FSocial.SelStart:=0;
  FJournal.Lines.Add('Очки талантов: '+JStr(FData,'talent_points')+' (1 за каждые 3 уровня, до 3 рангов на направление).');
  FTalents.Clear;FTalentIds.Clear;A:=FData.FindPath('talents');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];FTalents.Items.Add(JStr(E,'name')+' '+JStr(E,'rank')+'/3 | +'+JStr(E,'amount')+' '+GameWord(JStr(E,'stat')));FTalentIds.Add(TJSONObject(A).Names[I]);end;
  if JInt(FData,'hero.campaign')>=6 then FJournal.Lines.Add('ПЕПЕЛЬНЫЙ ТИРАН ПАЛ. Все шесть областей очищены. Вы — легенда Пепельного предела; походы и контракты остаются доступны.')
  else if JInt(FData,'hero.campaign')>=3 then FJournal.Lines.Add('ТРИ ОГНЯ ВОЗВРАЩЕНЫ. Но глубже просыпаются катакомбы, ледник и цитадель. Победите босса последнего этажа, чтобы открыть следующую область.')
  else FJournal.Lines.Add('Кампания: победите босса последнего этажа текущей области и вернитесь с огнём. Первый успех даёт награду и открывает следующую область.');
  E:=FData.FindPath('bounty');
  if (E=nil) or (E.JSONType=jtNull) then FBounty.Caption:='Контракта нет. Возьмите заказ: победите нужных врагов в любой открытой области и эвакуируйтесь.'
  else FBounty.Caption:='Контракт: '+JStr(E,'name')+' ('+JStr(E,'area')+') — '+JStr(E,'have')+'/'+JStr(E,'need')+
    '. Награда: '+JStr(E,'gold')+' кр., '+JStr(E,'materials')+' мат., '+JStr(E,'essence')+' эсс. Засчитываются только эвакуированные походы.';
  FJournal.Lines.Add('');FJournal.Lines.Add('Последние экономические операции:');A:=FData.FindPath('ledger');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];FJournal.Lines.Add(JStr(E,'created_at')+' | '+GameWord(JStr(E,'reason'))+' | '+JStr(E,'gold_delta')+' кр. | '+JStr(E,'materials_delta')+' мат.');end;
end;
procedure TTownForm.Click(Sender:TObject);
var N,ActionName,TargetId:string;D:TJSONObject;
begin
  if FBusy then begin FStatus.Caption:='Дождитесь ответа сервера.';Exit;end;
  N:=TButton(Sender).Name;if N='RefreshTown' then begin Request('');Exit;end;
  ActionName:='';TargetId:='';
  case N of
    'Salvage','List':begin if FBag.ItemIndex<0 then begin FStatus.Caption:='Выберите вещь во вкладке кузницы.';Exit;end;TargetId:=FItemIds[FBag.ItemIndex];if N='List' then ActionName:='list' else ActionName:='salvage';end;
    'Craft':begin if FRecipes.ItemIndex<0 then Exit;TargetId:=FRecipeIds[FRecipes.ItemIndex];ActionName:='craft';end;
    'Buy','Cancel':begin if FMarket.ItemIndex<0 then Exit;TargetId:=FMarketIds[FMarket.ItemIndex];if N='Buy' then ActionName:='buy' else ActionName:='cancel';end;
    'Supply':ActionName:='supply';'Respec':ActionName:='respec';'Distill':ActionName:='distill';
    'BountyTake':ActionName:='bounty_take';'BountyClaim':ActionName:='bounty_claim';'BountyDrop':ActionName:='bounty_drop';
    'Talent':begin if FTalents.ItemIndex<0 then Exit;ActionName:='talent';TargetId:=FTalentIds[FTalents.ItemIndex];end;
    'GuildCreate':ActionName:='guild_create';'GuildInvite':ActionName:='guild_invite';'GuildLeave':ActionName:='guild_leave';
    'GuildAccept':begin if FInvites.ItemIndex<0 then Exit;ActionName:='guild_accept';TargetId:=FInviteIds[FInvites.ItemIndex];end;
    'FriendRequest':ActionName:='friend_request';'FriendAccept':ActionName:='friend_accept';'FriendRemove':ActionName:='friend_remove';
  end;
  if ActionName='' then Exit;
  if (N='Salvage') or (N='Buy') or (N='List') or (N='GuildLeave') or (N='BountyDrop') then
    if MessageDlg('Подтверждение',TButton(Sender).Caption+'?',mtConfirmation,[mbYes,mbNo],0)<>mrYes then Exit;
  D:=TJSONObject.Create(['operation_id',NewCommandId,'action',ActionName]);
  try if TargetId<>'' then D.Add('target',TargetId);
    if N='List' then D.Add('price',StrToIntDef(FPrice.Text,0));
    if (Pos('Guild',N)=1) or (Pos('Friend',N)=1) then D.Add('text',Trim(FName.Text));
    Request(D.AsJSON);
  finally D.Free;end;
end;
end.
