program GrimHollow;
{$mode objfpc}{$H+}
uses
  {$IFDEF UNIX}cthreads,{$ENDIF}
  Interfaces, Forms, Main;
begin
  RequireDerivedFormResource := False;
  Application.Initialize;
  Application.CreateForm(TGameForm, GameForm);
  Application.Run;
end.
