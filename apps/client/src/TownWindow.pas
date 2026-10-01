unit TownWindow;
{$mode objfpc}{$H+}{$codepage utf8}
interface
uses Classes,SysUtils,Types,Math,Forms,Controls,Graphics,StdCtrls,ExtCtrls,ComCtrls,Dialogs,LCLType,fpjson,jsonparser,
  GameTransport,GameData,GameTheme,GameProtocol,GameArt;
type
  TTownForm=class(TForm)
  private
    FBase,FToken,FHero:string;
    FBusy:Boolean;
    FData:TJSONData;
    FTabs:TPageControl;
    FStatus,FOverview,FBounty,FCost,FItemCost:TLabel;
    FBag,FMarket,FRecipes,FInvites,FTalents:TListBox;
    FItemIds,FMarketIds,FRecipeIds,FInviteIds,FTalentIds,FAffixIds:TStringList;
    // Icon descriptors for the owner-drawn lists, index-aligned with their items.
    FBagSpecs,FRecipeSpecs,FMarketSpecs:TStringList;
    FPrice,FName,FTier:TEdit;
    FAffix,FMaterial,FRune,FReagentA,FReagentB:TComboBox;
    FMaterialIds,FRuneIds,FReagentIds:TStringList;
    FReagents:TListBox;
    FElixir:TLabel;
    FSocial,FJournal:TMemo;
    procedure ShowElixir(Sender:TObject);
    function ReagentName(const Key:string):string;
    procedure Click(Sender:TObject);
    procedure Received(const Kind,Response,Error:string);
    procedure Request(const Body:string);
    procedure Closing(Sender:TObject;var CanClose:Boolean);
    procedure Populate;
    procedure ShowRecipe(Sender:TObject);
    procedure ShowItem(Sender:TObject);
    procedure DrawItem(Control:TWinControl;Index:Integer;ARect:TRect;State:TOwnerDrawState);
    function ListBox(Host:TWinControl;X,Y,W,H:Integer;Icons:Boolean=False):TListBox;
  public
    LastError:string;
    constructor CreateTown(AOwner:TComponent;const Base,Token,Hero:string;StartTab:Integer=0);
    destructor Destroy;override;
  end;
implementation
{ "урон +4, броня +3" from a stats object. }
function AspectText(Stats:TJSONData):string;
var I:Integer;
begin
  Result:='';if Stats=nil then Exit;
  for I:=0 to Stats.Count-1 do begin if Result<>'' then Result:=Result+', ';
    Result:=Result+GameWord(TJSONObject(Stats).Names[I])+' +'+Stats.Items[I].AsString;end;
end;
function CostText(C:TJSONData):string;
begin
  Result:=JStr(C,'gold')+' кр.';
  if JInt(C,'materials')>0 then Result:=Result+' + '+JStr(C,'materials')+' мат.';
  if JInt(C,'essence')>0 then Result:=Result+' + '+JStr(C,'essence')+' эсс.';
end;
function TTownForm.ListBox(Host:TWinControl;X,Y,W,H:Integer;Icons:Boolean):TListBox;
begin
  Result:=TListBox.Create(Self);Result.Parent:=Host;Result.SetBounds(X,Y,W,H);
  Result.Color:=TownColor;Result.Font.Color:=GoldColor;
  if Icons then begin Result.Style:=lbOwnerDrawFixed;Result.ItemHeight:=28;Result.OnDrawItem:=@DrawItem;end;
end;
constructor TTownForm.CreateTown(AOwner:TComponent;const Base,Token,Hero:string;StartTab:Integer);
var Tabs:TPageControl;Forge,Market,Social,Chronicle,Alchemy:TTabSheet;
  function Combo(Host:TWinControl;X,Y,W:Integer):TComboBox;
  begin Result:=TComboBox.Create(Self);Result.Parent:=Host;Result.Style:=csDropDownList;Result.SetBounds(X,Y,W,28);Result.Font.Color:=clBlack;Result.DropDownCount:=12;end;
  function Page(const TitleText:string):TTabSheet;
  begin Result:=TTabSheet.Create(Self);Result.PageControl:=Tabs;Result.Caption:=TitleText;Result.Color:=PanelColor;end;
begin
  inherited CreateNew(AOwner,1);Caption:='Пепельный предел — городские службы';Width:=980;Height:=760;
  BorderStyle:=bsDialog;Position:=poOwnerFormCenter;Color:=PanelColor;Font.Name:='Tahoma';Font.Size:=10;Font.Color:=GoldColor;
  FBase:=Base;FToken:=Token;FHero:=Hero;OnCloseQuery:=@Closing;
  FItemIds:=TStringList.Create;FMarketIds:=TStringList.Create;FRecipeIds:=TStringList.Create;FInviteIds:=TStringList.Create;FTalentIds:=TStringList.Create;
  FAffixIds:=TStringList.Create;FBagSpecs:=TStringList.Create;FRecipeSpecs:=TStringList.Create;FMarketSpecs:=TStringList.Create;
  FMaterialIds:=TStringList.Create;FRuneIds:=TStringList.Create;FReagentIds:=TStringList.Create;
  FOverview:=LabelAt(Self,Self,'ПЕПЕЛЬНЫЙ ПРЕДЕЛ',20,12,930,60);FOverview.Font.Size:=12;
  Tabs:=TPageControl.Create(Self);Tabs.Parent:=Self;Tabs.SetBounds(12,82,940,570);Tabs.Font.Color:=clBlack;
  FTabs:=Tabs;
  Forge:=Page('Кузница и аптекарь');Market:=Page('Торговая доска');Social:=Page('Гильдия, друзья, лидеры');Chronicle:=Page('Хроника и контракты');
  Alchemy:=Page('Алхимия');
  LabelAt(Self,Forge,'Рюкзак: снятые вещи можно разобрать, улучшить или зачаровать',16,8,420,26);
  FBag:=ListBox(Forge,16,34,420,238,True);FBag.OnClick:=@ShowItem;
  ButtonAt(Self,Forge,'Разобрать','Salvage',16,280,205,@Click);
  ButtonAt(Self,Forge,'Улучшить (+1 уровень)','Upgrade',230,280,206,@Click);
  ButtonAt(Self,Forge,'Зачаровать (свойство справа)','Enchant',16,316,205,@Click);
  ButtonAt(Self,Forge,'Зелье в запас: 8 крон','Supply',230,316,206,@Click);
  ButtonAt(Self,Forge,'Перегонка: 10 мат. + 20 кр. = 1 эссенция','Distill',16,352,420,@Click);
  ButtonAt(Self,Forge,'Нанести руну (выбор руны справа)','Inscribe',16,388,420,@Click);
  FItemCost:=LabelAt(Self,Forge,'Выберите вещь, чтобы увидеть цену улучшения, зачарования и руны.',16,426,420,110);
  LabelAt(Self,Forge,'Кузница: любая основа любого уровня и именные рецепты',466,8,420,26);
  FRecipes:=ListBox(Forge,466,34,420,238,True);FRecipes.OnClick:=@ShowRecipe;
  LabelAt(Self,Forge,'Уровень',466,284,64,24);
  FTier:=TEdit.Create(Self);FTier.Parent:=Forge;FTier.SetBounds(530,280,64,28);FTier.Text:='1';FTier.Font.Color:=clBlack;FTier.OnChange:=@ShowRecipe;
  FAffix:=TComboBox.Create(Self);FAffix.Parent:=Forge;FAffix.Style:=csDropDownList;FAffix.SetBounds(604,280,282,28);FAffix.Font.Color:=clBlack;FAffix.OnChange:=@ShowRecipe;
  FMaterial:=Combo(Forge,466,316,205);FMaterial.OnChange:=@ShowRecipe;
  FRune:=Combo(Forge,681,316,205);FRune.OnChange:=@ShowRecipe;
  ButtonAt(Self,Forge,'Выковать выбранное','Craft',466,352,420,@Click);
  FCost:=LabelAt(Self,Forge,'',466,388,420,150);
  LabelAt(Self,Market,'Открытые предложения / комиссия продавца 5%',16,12,850,30);FMarket:=ListBox(Market,16,46,870,226,True);
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
  LabelAt(Self,Social,'Глава приглашает по имени персонажа. При выходе главы роль получает старший участник. Слева — лидеры глубины и рейтинг дуэлей.',16,418,870,56);
  FJournal:=TMemo.Create(Self);FJournal.Parent:=Chronicle;FJournal.SetBounds(16,16,870,140);FJournal.ReadOnly:=True;FJournal.ScrollBars:=ssVertical;FJournal.Color:=TownColor;FJournal.Font.Color:=GoldColor;
  FBounty:=LabelAt(Self,Chronicle,'Доска контрактов: возьмите заказ на врагов, эвакуируйтесь с трофеями и получите награду.',16,164,870,44);
  ButtonAt(Self,Chronicle,'Взять контракт','BountyTake',16,212,280,@Click);ButtonAt(Self,Chronicle,'Получить награду','BountyClaim',310,212,280,@Click);
  ButtonAt(Self,Chronicle,'Отказаться','BountyDrop',604,212,282,@Click);
  FTalents:=ListBox(Chronicle,16,254,870,88);ButtonAt(Self,Chronicle,'Развить выбранный талант','Talent',16,356,870,@Click);
  ButtonAt(Self,Chronicle,'Сбросить очки и таланты (бесплатно)','Respec',16,404,870,@Click);
  LabelAt(Self,Alchemy,'Реагенты: выпадают из чудовищ и сундуков. Каждый вид врага даёт свой, химера — оба.',16,8,420,40);
  FReagents:=ListBox(Alchemy,16,50,420,300);
  LabelAt(Self,Alchemy,'Эликсир из двух реагентов: свойства складываются, одинаковые — вдвое крепче, особые пары дают реакцию. Действует один поход.',466,8,420,60);
  FReagentA:=Combo(Alchemy,466,72,420);FReagentA.OnChange:=@ShowElixir;
  FReagentB:=Combo(Alchemy,466,108,420);FReagentB.OnChange:=@ShowElixir;
  FElixir:=LabelAt(Self,Alchemy,'',466,144,420,150);
  ButtonAt(Self,Alchemy,'Сварить эликсир','Brew',466,300,420,@Click);
  LabelAt(Self,Alchemy,'Реагенты нужны и кузнице: металлы (сталь, мифрил, обсидиан…) и руны требуют своих реагентов. '+
    'Три вещи одного металла дают бонус комплекта, пять — двойной.',16,366,870,60);
  FStatus:=LabelAt(Self,Self,'Загрузка...',20,668,720,50);ButtonAt(Self,Self,'Обновить','RefreshTown',778,666,170,@Click);
  if (StartTab>=0) and (StartTab<Tabs.PageCount) then Tabs.ActivePageIndex:=StartTab else Tabs.ActivePage:=Forge;
  Request('');
end;
destructor TTownForm.Destroy;
begin
  FData.Free;FItemIds.Free;FMarketIds.Free;FRecipeIds.Free;FInviteIds.Free;FTalentIds.Free;
  FAffixIds.Free;FBagSpecs.Free;FRecipeSpecs.Free;FMarketSpecs.Free;FMaterialIds.Free;FRuneIds.Free;FReagentIds.Free;inherited Destroy;
end;
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
procedure TTownForm.DrawItem(Control:TWinControl;Index:Integer;ARect:TRect;State:TOwnerDrawState);
var Box:TListBox;Spec:string;
begin
  Box:=Control as TListBox;Spec:='';
  if (Box=FBag) and (Index<FBagSpecs.Count) then Spec:=FBagSpecs[Index]
  else if (Box=FRecipes) and (Index<FRecipeSpecs.Count) then Spec:=FRecipeSpecs[Index]
  else if (Box=FMarket) and (Index<FMarketSpecs.Count) then Spec:=FMarketSpecs[Index];
  DrawIconRow(Box.Canvas,ARect,Box.Items[Index],Spec,odSelected in State);
end;
function ItemSpec(E:TJSONData):string;
begin Result:='i|'+JStr(E,'icon')+'|'+JStr(E,'tier','1')+'|'+BoolToStr(JBool(E,'unique'),'1','0');end;
function TTownForm.ReagentName(const Key:string):string;
begin Result:=JStr(FData,'alchemy.reagents.'+Key+'.name',Key);end;

{ Reagents a forging at this tier needs: as Catalog::reagentCost on the server. }
function ReagentText(Town:TTownForm;Data:TJSONData;Tier:Integer;const Material,Rune:string):string;
var Cost:TJSONData;I,Count:Integer;
begin
  Result:='';Count:=1+Tier div 5;
  if Material<>'' then begin Cost:=Data.FindPath('forge.materials.'+Material+'.cost');
    if Cost<>nil then for I:=0 to Cost.Count-1 do Result:=Result+', '+Town.ReagentName(TJSONObject(Cost).Names[I])+' ×'+IntToStr(Cost.Items[I].AsInteger*Count);end;
  if Rune<>'' then Result:=Result+', '+Town.ReagentName(JStr(Data,'forge.runes.'+Rune+'.cost'))+' ×'+IntToStr(Count);
  if Result<>'' then Result:='Реагенты: '+Copy(Result,3,MaxInt);
end;

procedure TTownForm.ShowRecipe(Sender:TObject);
var Id,Tier,Material,Rune:string;C,Base:TJSONData;Limit,Extra:Integer;
begin
  if (FData=nil) or (FRecipes.ItemIndex<0) then begin FCost.Caption:='Выберите основу или рецепт.';Exit;end;
  Id:=FRecipeIds[FRecipes.ItemIndex];Limit:=JInt(FData,'forge.limit',2);
  if Copy(Id,1,5)='base:' then begin
    Tier:=IntToStr(StrToIntDef(FTier.Text,1));Base:=FData.FindPath('forge.bases.'+Copy(Id,6,40));
    if (FAffix.ItemIndex>0) then C:=FData.FindPath('forge.costs.'+Tier+'.enchanted') else C:=FData.FindPath('forge.costs.'+Tier+'.plain');
    Material:='';Rune:='';Extra:=0;
    if FMaterial.ItemIndex>0 then begin Material:=FMaterialIds[FMaterial.ItemIndex];Inc(Extra,JInt(FData,'forge.materials.'+Material+'.essence'));end;
    if FRune.ItemIndex>0 then begin Rune:=FRuneIds[FRune.ItemIndex];Inc(Extra);end;
    FCost.Caption:=JStr(Base,'name')+' +'+Tier+' ('+GameWord(JStr(Base,'slot'))+')'+LineEnding+
      'Кузнец умеет ковать до +'+IntToStr(Limit)+' (растёт с уровнем и рекордом глубины).'+LineEnding;
    if C=nil then FCost.Caption:=FCost.Caption+'Этот уровень пока недоступен.'
    else begin
      FCost.Caption:=FCost.Caption+'Цена: '+CostText(C);
      if Extra>0 then FCost.Caption:=FCost.Caption+' + ещё '+IntToStr(Extra)+' эсс.';
      FCost.Caption:=FCost.Caption+LineEnding+ReagentText(Self,FData,StrToIntDef(Tier,1),Material,Rune)+LineEnding+
        'Металл умножает свойства основы, руна добавляет своё. 3 вещи одного металла — бонус комплекта.';
    end;
  end else begin
    C:=FData.FindPath('recipes.'+Copy(Id,7,40));
    FCost.Caption:=JStr(C,'name')+' — именная вещь, ур. '+JStr(C,'level')+LineEnding+'Цена: '+CostText(C)+LineEnding+
      'Урон +'+JStr(C,'damage')+'  Броня +'+JStr(C,'armor')+'  Магия +'+JStr(C,'power')+'  HP +'+JStr(C,'hp','0')+'  MP +'+JStr(C,'mana','0');
  end;
end;
procedure TTownForm.ShowItem(Sender:TObject);
var E:TJSONData;
begin
  if (FData=nil) or (FBag.ItemIndex<0) then Exit;
  E:=FData.FindPath('hero.items').Items[FBag.ItemIndex];
  FItemCost.Caption:=ItemSummary(E);
  if E.FindPath('upgrade_cost')<>nil then FItemCost.Caption:=FItemCost.Caption+LineEnding+
    'Улучшение: '+CostText(E.FindPath('upgrade_cost'))+'   Зачарование: '+CostText(E.FindPath('enchant_cost'))+LineEnding+
    'Руна: '+CostText(E.FindPath('inscribe_cost'))+' + реагент руны'
  else FItemCost.Caption:=FItemCost.Caption+LineEnding+'Именная вещь: не улучшается.';
end;
procedure TTownForm.Populate;
var A,E:TJSONData;I,Keep:Integer;LineText:string;
begin
  FOverview.Caption:=JStr(FData,'hero.name')+'   /   Кроны: '+JStr(FData,'hero.gold')+'   Материалы: '+JStr(FData,'hero.materials')+
    '   Эссенция: '+JStr(FData,'hero.essence','0')+'   Запас зелий: '+JStr(FData,'hero.supplies')+LineEnding+
    'Областей: '+JStr(FData,'hero.campaign')+'/6   Рекорд глубины: '+JStr(FData,'hero.best_depth','0')+
    '   Рейтинг дуэлей: '+JStr(FData,'hero.rating','1000')+'   Выковано: '+JStr(FData,'hero.craft_xp');
  Keep:=FBag.ItemIndex;FBag.Clear;FItemIds.Clear;FBagSpecs.Clear;A:=FData.FindPath('hero.items');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];LineText:=JStr(E,'name');if JStr(E,'equipped_slot')<>'' then LineText:='* '+LineText;
    if JBool(E,'bound') then LineText:=LineText+' [привязан]';FBag.Items.Add(LineText);FItemIds.Add(JStr(E,'id'));FBagSpecs.Add(ItemSpec(E));end;
  if Keep<FBag.Count then FBag.ItemIndex:=Keep;
  Keep:=FRecipes.ItemIndex;FRecipes.Clear;FRecipeIds.Clear;FRecipeSpecs.Clear;A:=FData.FindPath('forge.bases');
  if A<>nil then for I:=0 to A.Count-1 do begin E:=A.Items[I];
    FRecipes.Items.Add(JStr(E,'name')+' ('+GameWord(JStr(E,'slot'))+') — любой уровень до +'+JStr(FData,'forge.limit','2'));
    FRecipeIds.Add('base:'+TJSONObject(A).Names[I]);FRecipeSpecs.Add('i|'+TJSONObject(A).Names[I]+'|'+JStr(FData,'forge.limit','2')+'|0');end;
  A:=FData.FindPath('recipes');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];LineText:='ур.'+JStr(E,'level')+' '+JStr(E,'name')+' / '+CostText(E);
    FRecipes.Items.Add(LineText);FRecipeIds.Add('named:'+TJSONObject(A).Names[I]);FRecipeSpecs.Add('i|'+JStr(E,'icon')+'|'+IntToStr((JInt(E,'level')+2) div 3)+'|1');end;
  if Keep<0 then Keep:=0;if Keep<FRecipes.Count then FRecipes.ItemIndex:=Keep;
  if FAffix.Items.Count=0 then begin
    FAffix.Items.Add('без свойства');FAffixIds.Add('');A:=FData.FindPath('forge.affixes');
    if A<>nil then for I:=0 to A.Count-1 do begin FAffix.Items.Add('свойство: '+A.Items[I].AsString);FAffixIds.Add(TJSONObject(A).Names[I]);end;
    FAffix.ItemIndex:=0;FTier.Text:=JStr(FData,'forge.limit','1');
  end;
  if FMaterial.Items.Count=0 then begin
    FMaterial.Items.Add('металл: обычное железо');FMaterialIds.Add('');A:=FData.FindPath('forge.materials');
    if A<>nil then for I:=0 to A.Count-1 do begin FMaterial.Items.Add('металл: '+JStr(A.Items[I],'name'));FMaterialIds.Add(TJSONObject(A).Names[I]);end;
    FMaterial.ItemIndex:=0;
    FRune.Items.Add('без руны');FRuneIds.Add('');A:=FData.FindPath('forge.runes');
    if A<>nil then for I:=0 to A.Count-1 do begin FRune.Items.Add('руна: '+JStr(A.Items[I],'name'));FRuneIds.Add(TJSONObject(A).Names[I]);end;
    FRune.ItemIndex:=0;
    A:=FData.FindPath('alchemy.reagents');
    if A<>nil then for I:=0 to A.Count-1 do begin
      FReagentA.Items.Add(JStr(A.Items[I],'name'));FReagentB.Items.Add(JStr(A.Items[I],'name'));FReagentIds.Add(TJSONObject(A).Names[I]);end;
    if FReagentA.Items.Count>0 then begin FReagentA.ItemIndex:=0;FReagentB.ItemIndex:=0;end;
  end;
  FReagents.Clear;
  for I:=0 to FReagentIds.Count-1 do begin
    E:=FData.FindPath('alchemy.reagents.'+FReagentIds[I]);
    FReagents.Items.Add(ReagentName(FReagentIds[I])+': '+IntToStr(JInt(FData,'alchemy.have.'+FReagentIds[I]))+'   (даёт '+AspectText(E.FindPath('aspect'))+')');end;
  ShowRecipe(nil);ShowItem(nil);ShowElixir(nil);
  FMarket.Clear;FMarketIds.Clear;FMarketSpecs.Clear;A:=FData.FindPath('market');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];FMarket.Items.Add(JStr(E,'name')+' — '+JStr(E,'price')+' кр. / '+JStr(E,'seller'));FMarketIds.Add(JStr(E,'id'));FMarketSpecs.Add(ItemSpec(E));end;
  FSocial.Clear;FSocial.Lines.Add('Гильдия: '+JStr(FData,'guild.name','не состоите'));A:=FData.FindPath('members');
  for I:=0 to A.Count-1 do FSocial.Lines.Add('  '+JStr(A.Items[I],'name')+' / '+GameWord(JStr(A.Items[I],'class_id')));
  FSocial.Lines.Add('');FSocial.Lines.Add('Друзья и заявки:');A:=FData.FindPath('friends');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];LineText:=JStr(E,'name');
    if JBool(E,'accepted') then LineText:=LineText+' — друзья' else if JBool(E,'incoming') then LineText:=LineText+' — входящая заявка' else LineText:=LineText+' — ожидает ответа';FSocial.Lines.Add(LineText);end;
  FSocial.Lines.Add('');FSocial.Lines.Add('ЛИДЕРЫ ГЛУБИНЫ:');A:=FData.FindPath('leaders.depth');
  if A<>nil then for I:=0 to A.Count-1 do FSocial.Lines.Add('  '+IntToStr(I+1)+'. '+JStr(A.Items[I],'name')+' ('+GameWord(JStr(A.Items[I],'class_id'))+') — глубина '+JStr(A.Items[I],'best_depth'));
  FSocial.Lines.Add('');FSocial.Lines.Add('РЕЙТИНГ ДУЭЛЕЙ:');A:=FData.FindPath('leaders.duel');
  if A<>nil then for I:=0 to A.Count-1 do FSocial.Lines.Add('  '+IntToStr(I+1)+'. '+JStr(A.Items[I],'name')+' — '+JStr(A.Items[I],'rating')+
    ' ('+JStr(A.Items[I],'duel_wins')+'/'+JStr(A.Items[I],'duel_losses')+')');
  FInvites.Clear;FInviteIds.Clear;A:=FData.FindPath('invites');
  for I:=0 to A.Count-1 do begin FInvites.Items.Add(JStr(A.Items[I],'name'));FInviteIds.Add(JStr(A.Items[I],'id'));end;
  FJournal.Clear;FSocial.SelStart:=0;
  FJournal.Lines.Add('Очки талантов: '+JStr(FData,'talent_points')+' (1 за каждые 3 уровня, до 10 рангов на направление).');
  FTalents.Clear;FTalentIds.Clear;A:=FData.FindPath('talents');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];FTalents.Items.Add(JStr(E,'name')+' '+JStr(E,'rank')+'/10 | +'+JStr(E,'amount')+' '+GameWord(JStr(E,'stat')));FTalentIds.Add(TJSONObject(A).Names[I]);end;
  if JInt(FData,'hero.campaign')>=6 then FJournal.Lines.Add('Все шесть областей открыты. Подземелья бесконечны: ставьте рекорды глубины и сражайтесь на арене.')
  else if JInt(FData,'hero.campaign')>=3 then FJournal.Lines.Add('ТРИ ОГНЯ ВОЗВРАЩЕНЫ. Глубже просыпаются катакомбы, ледник и цитадель: первый босс области открывает следующую.')
  else FJournal.Lines.Add('Кампания: победите первого босса области и вернитесь с огнём, чтобы открыть следующую. Спускаться можно бесконечно.');
  E:=FData.FindPath('bounty');
  if (E=nil) or (E.JSONType=jtNull) then FBounty.Caption:='Контракта нет. Возьмите заказ: победите нужных врагов в любой открытой области и эвакуируйтесь.'
  else FBounty.Caption:='Контракт: '+JStr(E,'name')+' ('+JStr(E,'area')+') — '+JStr(E,'have')+'/'+JStr(E,'need')+
    '. Награда: '+JStr(E,'gold')+' кр., '+JStr(E,'materials')+' мат., '+JStr(E,'essence')+' эсс. Засчитываются только эвакуированные походы.';
  FJournal.Lines.Add('');FJournal.Lines.Add('Последние экономические операции:');A:=FData.FindPath('ledger');
  for I:=0 to A.Count-1 do begin E:=A.Items[I];FJournal.Lines.Add(JStr(E,'created_at')+' | '+GameWord(JStr(E,'reason'))+' | '+JStr(E,'gold_delta')+' кр. | '+JStr(E,'materials_delta')+' мат.');end;
end;
procedure TTownForm.ShowElixir(Sender:TObject);
var A,B,Key:string;E:TJSONData;
begin
  if (FData=nil) or (FReagentA.ItemIndex<0) or (FReagentB.ItemIndex<0) then Exit;
  A:=FReagentIds[FReagentA.ItemIndex];B:=FReagentIds[FReagentB.ItemIndex];
  if A<=B then Key:=A+'+'+B else Key:=B+'+'+A;
  E:=FData.FindPath('alchemy.elixirs.'+Key);
  FElixir.Caption:='';
  if E<>nil then begin
    FElixir.Caption:=JStr(E,'name')+LineEnding+AspectText(E.FindPath('stats'));
    if JBool(E,'reaction') then FElixir.Caption:=FElixir.Caption+LineEnding+'Особая реакция!';
  end;
  FElixir.Caption:=FElixir.Caption+LineEnding+'Цена: 1 + 1 реагент и '+JStr(FData,'alchemy.price','10')+' крон.';
  if JStr(FData,'alchemy.active.name')<>'' then FElixir.Caption:=FElixir.Caption+LineEnding+LineEnding+'Сейчас выпит: '+JStr(FData,'alchemy.active.name')+' (заменится новым).';
end;

procedure TTownForm.Click(Sender:TObject);
var N,ActionName,TargetId,Recipe,Affix:string;D:TJSONObject;
begin
  if FBusy then begin FStatus.Caption:='Дождитесь ответа сервера.';Exit;end;
  N:=TButton(Sender).Name;if N='RefreshTown' then begin Request('');Exit;end;
  ActionName:='';TargetId:='';Recipe:='';Affix:='';
  if FAffix.ItemIndex>0 then Affix:=FAffixIds[FAffix.ItemIndex];
  case N of
    'Brew':begin if (FReagentA.ItemIndex<0) or (FReagentB.ItemIndex<0) then Exit;ActionName:='brew';end;
    'Salvage','List','Upgrade','Enchant','Inscribe':begin if FBag.ItemIndex<0 then begin FStatus.Caption:='Выберите вещь во вкладке кузницы.';Exit;end;TargetId:=FItemIds[FBag.ItemIndex];
      case N of 'List':ActionName:='list';'Upgrade':ActionName:='upgrade';'Enchant':ActionName:='enchant';'Inscribe':ActionName:='inscribe';else ActionName:='salvage';end;
      if (N='Inscribe') and (FRune.ItemIndex<=0) then begin FStatus.Caption:='Выберите руну в списке справа.';Exit;end;
      if (N='Enchant') and (Affix='') then begin FStatus.Caption:='Выберите свойство в списке справа от уровня.';Exit;end;end;
    'Craft':begin if FRecipes.ItemIndex<0 then Exit;Recipe:=FRecipeIds[FRecipes.ItemIndex];
      if Copy(Recipe,1,5)='base:' then begin ActionName:='forge';TargetId:=Copy(Recipe,6,40);end else begin ActionName:='craft';TargetId:=Copy(Recipe,7,40);end;end;
    'Buy','Cancel':begin if FMarket.ItemIndex<0 then Exit;TargetId:=FMarketIds[FMarket.ItemIndex];if N='Buy' then ActionName:='buy' else ActionName:='cancel';end;
    'Supply':ActionName:='supply';'Respec':ActionName:='respec';'Distill':ActionName:='distill';
    'BountyTake':ActionName:='bounty_take';'BountyClaim':ActionName:='bounty_claim';'BountyDrop':ActionName:='bounty_drop';
    'Talent':begin if FTalents.ItemIndex<0 then Exit;ActionName:='talent';TargetId:=FTalentIds[FTalents.ItemIndex];end;
    'GuildCreate':ActionName:='guild_create';'GuildInvite':ActionName:='guild_invite';'GuildLeave':ActionName:='guild_leave';
    'GuildAccept':begin if FInvites.ItemIndex<0 then Exit;ActionName:='guild_accept';TargetId:=FInviteIds[FInvites.ItemIndex];end;
    'FriendRequest':ActionName:='friend_request';'FriendAccept':ActionName:='friend_accept';'FriendRemove':ActionName:='friend_remove';
  end;
  if ActionName='' then Exit;
  if (N='Salvage') or (N='Buy') or (N='List') or (N='GuildLeave') or (N='BountyDrop') or (N='Enchant') then
    if MessageDlg('Подтверждение',TButton(Sender).Caption+'?',mtConfirmation,[mbYes,mbNo],0)<>mrYes then Exit;
  D:=TJSONObject.Create(['operation_id',NewCommandId,'action',ActionName]);
  try if TargetId<>'' then D.Add('target',TargetId);
    if N='List' then D.Add('price',StrToIntDef(FPrice.Text,0));
    if ActionName='forge' then begin D.Add('tier',Max(1,StrToIntDef(FTier.Text,1)));if Affix<>'' then D.Add('text',Affix);end;
    if ActionName='enchant' then D.Add('text',Affix);
    if ActionName='inscribe' then D.Add('text',FRuneIds[FRune.ItemIndex]);
    if ActionName='brew' then D.Add('text',FReagentIds[FReagentA.ItemIndex]+'+'+FReagentIds[FReagentB.ItemIndex]);
    if ActionName='forge' then begin
      if FMaterial.ItemIndex>0 then D.Add('material',FMaterialIds[FMaterial.ItemIndex]);
      if FRune.ItemIndex>0 then D.Add('rune',FRuneIds[FRune.ItemIndex]);
    end;
    if (Pos('Guild',N)=1) or (Pos('Friend',N)=1) then D.Add('text',Trim(FName.Text));
    Request(D.AsJSON);
  finally D.Free;end;
end;
end.
