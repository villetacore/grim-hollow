program Screenshots;
{ Renders README images with the real client renderer: scene snapshots produced by
  tools/screenshots/scenes.php, a bestiary of every creature and the gear/skill icon sets.
  Usage: screenshots <scene-dir> <output-dir> }
{$mode objfpc}{$H+}{$codepage utf8}
uses Interfaces, Forms, Classes, SysUtils, Graphics, Types, fpjson, jsonparser, GameArt, GameRenderer, GameData, PlazaRender;
const
  Creatures:array[0..43] of string=('scavenger','archer','spitter','brute','rat_swarm','ghoul_miner','acolyte','bell_wraith',
    'gargoyle','flagellant','root_crawler','thorn_archer','spore_bloater','rift_hound','skeleton','bone_archer','lich_acolyte',
    'crypt_knight','frost_wolf','ice_golem','rime_witch','yeti','ash_knight','cinder_mage','hellhound','magma_brute',
    'bog_lurker','leech_swarm','mire_witch','toad_brute','wisp','void_stalker','star_spawn','mind_flayer','void_golem','comet_wisp',
    'warden','prior','rift_heart','bone_king','frost_queen','ash_tyrant','mire_mother','void_king');
  Bosses=36;
  Biomes:array[0..7] of string=('mines','monastery','roots','catacombs','glacier','citadel','swamp','abyss');
  Bases:array[0..35] of string=('blade','axe','hammer','dagger','mace','staff','wand','bow','crossbow','greatsword','spear','scythe',
    'claws','totem','sickle','robe','jerkin','mail','plate','shield','tome','orb','quiver','ring','signet','amulet','talisman',
    'helm','hood','circlet','gauntlets','wraps','bracers','boots','greaves','sandals');
  Heroes:array[0..7] of string=('guardian','arcanist','ranger','warden','berserker','assassin','necromancer','druid');
  Tiers:array[0..5] of Integer=(1,3,6,10,15,25);
var OutDir:string;

procedure Save(B:TBitmap;const Name:string);
begin B.SaveToFile(IncludeTrailingPathDelimiter(OutDir)+Name+'.bmp');WriteLn(Name);end;

procedure Scene(const FileName,Name:string);
var B:TBitmap;D:TJSONData;View:TMapView;I:Integer;Text:TStringList;
begin
  Text:=TStringList.Create;try Text.LoadFromFile(FileName);D:=GetJSON(Text.Text);finally Text.Free;end;
  B:=TBitmap.Create;
  try
    B.SetSize(1200,780);
    View:=Default(TMapView);View.Hero:=JStr(D,'hero');View.Expedition:='readme';View.Target:=JStr(D,'target');
    View.Sheet:=D.FindPath('sheet');View.EstTick:=JInt(D,'est_tick');
    for I:=0 to 31 do View.Explored[I]:=D.FindPath('explored').Items[I].AsString;
    RenderMap(B.Canvas,B.Width,B.Height,D.FindPath('snapshot'),View);
    Save(B,Name);
  finally B.Free;D.Free;end;
end;

procedure Bestiary;
var B:TBitmap;I,X,Y:Integer;
begin
  B:=TBitmap.Create;
  try
    B.SetSize(8*96+16,((Length(Creatures)+7) div 8+1)*96+16);B.Canvas.Brush.Color:=$001A1E20;B.Canvas.FillRect(Rect(0,0,B.Width,B.Height));
    for I:=0 to High(Creatures) do begin X:=8+(I mod 8)*96;Y:=8+(I div 8)*96;
      Stone(B.Canvas,X+8,Y+8,80,I,False,Biomes[I div 6 mod Length(Biomes)]);
      Figure(B.Canvas,X+8,Y+8,80,Creatures[I],False,False,40+I*7);
      EnemyMarks(B.Canvas,X+8,Y+8,80,30+I*2,100,I mod 5=1,I>=Bosses);
    end;
    // The eight hero classes along the bottom row.
    for I:=0 to High(Heroes) do begin X:=8+I*96;Y:=8+((Length(Creatures)+7) div 8)*96;
      Stone(B.Canvas,X+8,Y+8,80,I,False,'monastery');Figure(B.Canvas,X+8,Y+8,80,Heroes[I],True,False,0,False);end;
    Save(B,'bestiary');
  finally B.Free;end;
end;

procedure Icons;
var B:TBitmap;I,J,X,Y:Integer;
begin
  B:=TBitmap.Create;
  try
    B.SetSize(Length(Bases)*46+16,(Length(Tiers)+2)*46+24);B.Canvas.Brush.Color:=$001A1E20;B.Canvas.FillRect(Rect(0,0,B.Width,B.Height));
    for J:=0 to High(Tiers) do for I:=0 to High(Bases) do begin X:=8+I*46;Y:=8+J*46;ItemIcon(B.Canvas,X,Y,40,Bases[I],Tiers[J],False);end;
    for I:=0 to High(BarSkills) do begin X:=8+I*46;Y:=16+Length(Tiers)*46;SpellIcon(B.Canvas,X,Y,40,BarSkills[I]);end;
    for I:=0 to 7 do ItemIcon(B.Canvas,8+(Length(BarSkills)+I)*46,16+Length(Tiers)*46,40,Bases[I*4],5,True);
    Save(B,'icons');
  finally B.Free;end;
end;

procedure Tiles;
var B:TBitmap;I,X:Integer;
const Objects:array[0..3] of string=('chest','fountain','shrine','trap');
begin
  B:=TBitmap.Create;
  try
    B.SetSize(Length(Biomes)*136+16,2*72+16);B.Canvas.Brush.Color:=$001A1E20;B.Canvas.FillRect(Rect(0,0,B.Width,B.Height));
    for I:=0 to High(Biomes) do begin X:=8+I*136;
      Stone(B.Canvas,X,8,64,I,True,Biomes[I]);Stone(B.Canvas,X+64,8,64,I*3,False,Biomes[I]);
      Stone(B.Canvas,X,80,64,I,True,Biomes[I],True);Stone(B.Canvas,X+64,80,64,I*3,False,Biomes[I]);
      DungeonObject(B.Canvas,X+64,80,64,Objects[I mod 4],I>=4);
    end;
    Save(B,'tiles');
  finally B.Free;end;
end;

procedure Town(const FileName,Name:string);
var B:TBitmap;D:TJSONData;View:TPlazaView;Text:TStringList;
begin
  Text:=TStringList.Create;try Text.LoadFromFile(FileName);D:=GetJSON(Text.Text);finally Text.Free;end;
  B:=TBitmap.Create;
  try
    B.SetSize(1200,860);View:=Default(TPlazaView);
    RenderPlaza(B.Canvas,B.Width,B.Height,D.FindPath('plaza'),D.FindPath('town'),JStr(D,'hero'),JStr(D,'talk'),
      JInt(D,'plaza.self.x'),JInt(D,'plaza.self.y'),1000000,View);
    Save(B,Name);
  finally B.Free;D.Free;end;
end;

begin
  Application.Initialize;
  if ParamCount<2 then begin WriteLn('usage: screenshots <scene-dir> <output-dir>');Halt(2);end;
  OutDir:=ParamStr(2);ForceDirectories(OutDir);
  Scene(IncludeTrailingPathDelimiter(ParamStr(1))+'deep.json','gameplay-depth');
  Scene(IncludeTrailingPathDelimiter(ParamStr(1))+'boss.json','gameplay-boss');
  Scene(IncludeTrailingPathDelimiter(ParamStr(1))+'duel.json','gameplay-duel');
  Town(IncludeTrailingPathDelimiter(ParamStr(1))+'plaza.json','town-square');
  Bestiary;Icons;Tiles;
end.
