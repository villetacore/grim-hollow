procedure TGameForm.RefreshSheet;
begin if FHero<>'' then Send('GET','characters/'+FHero,'','sheet'); end;

procedure TGameForm.SelectHero(Sender:TObject);
var C:TJSONData;
begin
  if (FCharacters=nil) or (FHeroes.ItemIndex<0) then Exit;
  if FBusy then begin FStatus.Caption:='Дождитесь ответа сервера и повторите выбор.';Exit;end;
  if FExpedition<>'' then begin FStatus.Caption:='Сначала завершите поход текущего героя.';Exit;end;
  C:=FCharacters.FindPath('items').Items[FHeroes.ItemIndex];FHero:=JStr(C,'id');
  // Fetch the selected hero afresh: cached list entries may refer to a finished run.
  FreeAndNil(FSheet);FreeAndNil(FSnapshot);FMap.Invalidate;
  FChatCursor:='0';FChat.Clear;RefreshSheet;
end;

procedure TGameForm.ShowSheet;
var Items,S:TJSONData;I,OldIndex,LevelValue:Integer;Prefix,NextValue:string;
begin
  if FSheet=nil then Exit;
  LevelValue:=JInt(FSheet,'level',1);NextValue:=JStr(FSheet,'next_level_xp','MAX');
  FHeroInfo.Caption:=JStr(FSheet,'name')+'  /  '+GameWord(JStr(FSheet,'class_id'))+LineEnding+
    'Уровень '+IntToStr(LevelValue)+'   Опыт: '+JStr(FSheet,'xp')+' / '+NextValue+LineEnding+
    'Золото в городе: '+JStr(FSheet,'gold')+LineEnding+LineEnding+
    'Сила: '+JStr(FSheet,'attributes.strength')+LineEnding+'Живучесть: '+JStr(FSheet,'attributes.vitality')+LineEnding+
    'Разум: '+JStr(FSheet,'attributes.intellect')+LineEnding+'Свободные очки: '+JStr(FSheet,'points')+LineEnding+LineEnding+
    'Здоровье: '+JStr(FSheet,'stats.max_hp')+'   Мана: '+JStr(FSheet,'stats.max_mana')+LineEnding+
    'Бонус урона: '+JStr(FSheet,'stats.damage_bonus')+'   Броня: '+JStr(FSheet,'stats.armor')+LineEnding+
    'Сила магии: '+JStr(FSheet,'stats.power');
  FExperience.Max:=JInt(FSheet,'next_level_xp',JInt(FSheet,'xp')+1)-JInt(FSheet,'level_xp');
  FExperience.Position:=JInt(FSheet,'xp')-JInt(FSheet,'level_xp');
  if FExpedition='' then begin
    FStats.Caption:=JStr(FSheet,'name')+' | Уровень '+IntToStr(LevelValue)+' | Город | '+JStr(FSheet,'gold')+' крон';
    FHealth.Max:=JInt(FSheet,'stats.max_hp',100);FHealth.Position:=FHealth.Max;
    FMana.Max:=JInt(FSheet,'stats.max_mana',60);FMana.Position:=FMana.Max;
  end;
  OldIndex:=FBag.ItemIndex;FBag.Clear;Items:=FSheet.FindPath('items');
  for I:=0 to Items.Count-1 do begin
    Prefix:='   ';if JStr(Items.Items[I],'equipped_slot')<>'' then Prefix:='* ';
    FBag.Items.Add(Prefix+JStr(Items.Items[I],'name'));
  end;
  if OldIndex>=FBag.Count then OldIndex:=FBag.Count-1;
  if (OldIndex<0) and (FBag.Count>0) then OldIndex:=0;FBag.ItemIndex:=OldIndex;SelectItem(nil);
  OldIndex:=FSpells.ItemIndex;FSpells.Clear;
  for I:=0 to 3 do begin
    S:=FSheet.FindPath('spells.'+SpellKey(I));Prefix:='';
    if LevelValue<JInt(S,'level') then Prefix:='[закрыто] ';
    FSpells.Items.Add(IntToStr(I+4)+'. '+Prefix+JStr(S,'name')+' (ур.'+JStr(S,'level')+')');
  end;
  if OldIndex<0 then OldIndex:=0;FSpells.ItemIndex:=OldIndex;SelectSpell(nil);
end;

procedure TGameForm.SelectItem(Sender:TObject);
begin
  if (FSheet=nil) or (FBag.ItemIndex<0) then begin FItemInfo.Caption:='Рюкзак пуст.';Exit;end;
  FItemInfo.Caption:=ItemSummary(FSheet.FindPath('items').Items[FBag.ItemIndex]);
end;

procedure TGameForm.SelectSpell(Sender:TObject);
var S:TJSONData; Remaining:Integer;
begin
  if (FSheet=nil) or (FSpells.ItemIndex<0) then Exit;
  S:=FSheet.FindPath('spells.'+SpellKey(FSpells.ItemIndex));
  FSpellInfo.Caption:=JStr(S,'name')+LineEnding+JStr(S,'description')+LineEnding+LineEnding+
    'Мана: '+JStr(S,'mana')+' | Уровень: '+JStr(S,'level')+LineEnding+
    'Перезарядка: '+FloatToStr(JInt(S,'cooldown')/10)+' сек.';
  if (FSnapshot<>nil) and (FExpedition<>'') then begin
    Remaining:=JInt(FSnapshot,'world.self.spell_ready.'+SpellKey(FSpells.ItemIndex))-JInt(FSnapshot,'world.tick');
    if Remaining>0 then FSpellInfo.Caption:=FSpellInfo.Caption+LineEnding+'До готовности: '+FormatFloat('0.0',Remaining/10)+' сек.'
    else FSpellInfo.Caption:=FSpellInfo.Caption+LineEnding+'Готово (при достаточной мане и уровне).';
  end;
end;

procedure TGameForm.ChannelChanged(Sender:TObject);
begin FChatCursor:='0';FChat.Clear;end;

procedure TGameForm.ReadChat;
var ChannelName:string;
begin
  if (FHero='') or ((FChannel.ItemIndex=1) and (FExpedition='')) then Exit;
  case FChannel.ItemIndex of 1:ChannelName:='party';2:ChannelName:='guild';else ChannelName:='global';end;
  FChatRequestContext:=ChatContext;
  Send('GET','chat?character_id='+FHero+'&channel='+ChannelName+'&after='+FChatCursor,'','chat');
end;

function TGameForm.ChatContext:string;
begin
  Result:=FHero+':'+IntToStr(FChannel.ItemIndex);
  if FChannel.ItemIndex=1 then Result:=Result+':'+FExpedition;
end;

procedure TGameForm.ChatKey(Sender:TObject;var Key:Word;Shift:TShiftState);
begin
  if Key=VK_RETURN then begin ButtonClick(FindComponent('ChatButton'));Key:=0;end;
  if Key=VK_ESCAPE then begin FocusGame;Key:=0;end;
end;
