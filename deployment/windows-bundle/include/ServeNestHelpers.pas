{ Shared [Code] helpers for ServeNest-FOH.iss and ServeNest-BOH.iss.
  Included at the top of each edition's [Code] section; the event functions
  are in ServeNestEvents.pas, included at the end. Wizard checks read the
  prospective folder from WizardDirValue and never expand the app constant
  before the directory page has been confirmed. }

var
  TillProfileSelected: Boolean;
  InstallFailed: Boolean;
  InstallFailureText: String;
  PreviouslyRunningFile: String;

function JsonEscape(const Value: String): String;
var
  I: Integer;
  C: Char;
begin
  Result := '';
  for I := 1 to Length(Value) do
  begin
    C := Value[I];
    if C = '"' then
      Result := Result + '\"'
    else if C = '\' then
      Result := Result + '\\'
    else if C = #9 then
      Result := Result + '\t'
    else if C = #10 then
      Result := Result + '\n'
    else if C = #13 then
      Result := Result + '\r'
    else if Ord(C) < 32 then
      Result := Result + Format('\u%.4x', [Ord(C)])
    else
      Result := Result + C;
  end;
end;

function JsonPair(const Key, Value: String): String;
begin
  Result := '"' + Key + '": "' + JsonEscape(Value) + '"';
end;

function JsonBool(const Key: String; const Value: Boolean): String;
begin
  if Value then
    Result := '"' + Key + '": true'
  else
    Result := '"' + Key + '": false';
end;

function IsAsciiLetterOrDigit(const C: Char): Boolean;
begin
  Result := ((C >= 'a') and (C <= 'z')) or ((C >= 'A') and (C <= 'Z')) or ((C >= '0') and (C <= '9'));
end;

{ True when Value is non-empty and every character is an ASCII letter, a
  digit or one of Extra. }
function ConsistsOf(const Value, Extra: String): Boolean;
var
  I: Integer;
begin
  Result := Length(Value) > 0;
  for I := 1 to Length(Value) do
    if not IsAsciiLetterOrDigit(Value[I]) and (Pos(Value[I], Extra) = 0) then
    begin
      Result := False;
      Exit;
    end;
end;

function HasCharacterBetween(const Value: String; const Low, High: Char): Boolean;
var
  I: Integer;
begin
  Result := False;
  for I := 1 to Length(Value) do
    if (Value[I] >= Low) and (Value[I] <= High) then
    begin
      Result := True;
      Exit;
    end;
end;

function IsValidPort(const Value: String): Boolean;
var
  Port: Integer;
begin
  Port := StrToIntDef(Value, 0);
  Result := ConsistsOf(Value, '') and (Length(Value) <= 5) and (Port >= 1) and (Port <= 65535)
    and not HasCharacterBetween(Value, 'A', 'z');
end;

function IsValidHost(const Value: String): Boolean;
begin
  Result := (Length(Value) <= 253) and ConsistsOf(Value, '.-');
end;

function IsValidIdentifier(const Value: String; const MaxLength: Integer): Boolean;
begin
  Result := (Length(Value) <= MaxLength) and ConsistsOf(Value, '_');
end;

{ Mirrors the default Back Office credential policy. }
function IsStrongPassword(const Password, Username: String): Boolean;
var
  I: Integer;
  HasSymbol: Boolean;
begin
  HasSymbol := False;
  for I := 1 to Length(Password) do
    if not IsAsciiLetterOrDigit(Password[I]) then
      HasSymbol := True;
  Result := (Length(Password) >= 12) and (Length(Password) <= 128)
    and HasCharacterBetween(Password, 'A', 'Z') and HasCharacterBetween(Password, 'a', 'z')
    and HasCharacterBetween(Password, '0', '9') and HasSymbol
    and (CompareText(Password, Username) <> 0);
end;

function StartsText(const Prefix, Value: String): Boolean;
begin
  Result := CompareText(Copy(Value, 1, Length(Prefix)), Prefix) = 0;
end;

{ An http(s) URL whose host part is valid. With RequireOrigin no path other
  than a trailing slash is allowed. }
function IsHttpUrl(const Value: String; const HttpsOnly, RequireOrigin: Boolean): Boolean;
var
  Rest: String;
  Slash: Integer;
begin
  Result := False;
  if StartsText('https://', Value) then
    Rest := Copy(Value, 9, Length(Value))
  else if (not HttpsOnly) and StartsText('http://', Value) then
    Rest := Copy(Value, 8, Length(Value))
  else
    Exit;
  Slash := Pos('/', Rest);
  if Slash > 0 then
  begin
    if RequireOrigin and (Slash <> Length(Rest)) then
      Exit;
    Rest := Copy(Rest, 1, Slash - 1);
  end;
  Result := ConsistsOf(Rest, '.-:');
end;

{ A DNS-style name for this computer: NAME.domain on a domain, else NAME. }
function DefaultNetworkName: String;
var
  Domain: String;
begin
  Result := GetComputerNameString;
  Domain := GetEnv('USERDNSDOMAIN');
  if (Domain <> '') and ConsistsOf(Domain, '.-') then
    Result := Result + '.' + Lowercase(Domain);
end;

function NormalizedInstallRoot(const Path: String): String;
begin
  Result := RemoveBackslashUnlessRoot(Trim(Path));
end;

function ProspectiveInstallPath(const SubPath: String): String;
begin
  Result := AddBackslash(NormalizedInstallRoot(WizardDirValue)) + SubPath;
end;

function ReadTextFile(const Path: String): String;
var
  Contents: AnsiString;
begin
  Result := '';
  if FileExists(Path) and LoadStringFromFile(Path, Contents) then
    Result := String(Contents);
end;

{ Minimal reader for the flat JSON written by the installer scripts:
  "key": "string" | number | true | false | null. }
function JsonValue(const Json, Key: String): String;
var
  P, I: Integer;
  S: String;
begin
  Result := '';
  P := Pos('"' + Key + '"', Json);
  if P = 0 then
    Exit;
  S := Copy(Json, P + Length(Key) + 2, Length(Json));
  P := Pos(':', S);
  if P = 0 then
    Exit;
  S := TrimLeft(Copy(S, P + 1, Length(S)));
  if (Length(S) > 0) and (S[1] = '"') then
  begin
    I := 2;
    while I <= Length(S) do
    begin
      if S[I] = '"' then
        Exit;
      if (S[I] = '\') and (I < Length(S)) then
        I := I + 1;
      Result := Result + S[I];
      I := I + 1;
    end;
  end else
  begin
    I := 1;
    while (I <= Length(S)) and (Pos(S[I], ',}] ' + #9 + #13 + #10) = 0) do
    begin
      Result := Result + S[I];
      I := I + 1;
    end;
    if Result = 'null' then
      Result := '';
  end;
end;

function MetadataValue(const Key: String): String;
begin
  Result := JsonValue(ReadTextFile(ProspectiveInstallPath('config\installation.json')), Key);
end;

function IsRepair: Boolean;
begin
  Result := FileExists(ProspectiveInstallPath('config\installation.json'));
end;

function IsServerProfile: Boolean;
begin
  Result := not TillProfileSelected;
end;

function IsTillProfile: Boolean;
begin
  Result := TillProfileSelected;
end;

function IsDirectoryEmpty(const Path: String): Boolean;
var
  FindRec: TFindRec;
begin
  Result := True;
  if FindFirst(AddBackslash(Path) + '*', FindRec) then
  try
    repeat
      if (FindRec.Name <> '.') and (FindRec.Name <> '..') then
      begin
        Result := False;
        Exit;
      end;
    until not FindNext(FindRec);
  finally
    FindClose(FindRec);
  end;
end;

{ Returns a message when the chosen folder must not be used. A ServeNest
  installation never adopts another product's database or a source
  checkout; a Salexa POS 4.x folder needs a separate data migration. }
function CheckInstallDirectory: String;
var
  Directory, Bundle, Product: String;
begin
  Result := '';
  Directory := NormalizedInstallRoot(WizardDirValue);
  if Length(Directory) <= 3 then
  begin
    Result := 'Choose a folder, not the root of a drive.';
    Exit;
  end;
  if not ConsistsOf(Directory, ':\_-.') then
  begin
    Result := 'Use a folder path with only letters, numbers, dots, dashes and underscores (no spaces), for example C:\ServeNest\{#Edition}.';
    Exit;
  end;
  if not DirExists(Directory) or IsDirectoryEmpty(Directory) then
    Exit;

  Bundle := ReadTextFile(AddBackslash(Directory) + 'BUNDLE.json');
  if Bundle <> '' then
  begin
    Product := JsonValue(Bundle, 'product');
    if Product <> 'ServeNest' then
      Result := 'This folder holds a ' + Product + ' installation. ServeNest never takes over another product''s database; choose a new, empty folder.'
    else if JsonValue(Bundle, 'edition') <> '{#Edition}' then
      Result := 'This folder holds a ServeNest ' + JsonValue(Bundle, 'edition') + ' installation. Choose another folder.';
    Exit;
  end;
  if DirExists(AddBackslash(Directory) + '.git') or FileExists(AddBackslash(Directory) + 'artisan')
    or FileExists(AddBackslash(Directory) + 'api\artisan') then
    Result := 'This folder holds source code or a manual deployment. Choose a new, empty folder.'
  else
    Result := 'This folder is not empty and is not a ServeNest {#Edition} installation. Choose an empty folder.';
end;

{ Administrators and SYSTEM only, before any file is extracted into it: the
  services that run from these folders execute as LocalSystem. }
function ProtectExtractionDirectory(const Path: String): String;
var
  ResultCode: Integer;
begin
  Result := '';
  if not ForceDirectories(Path) then
  begin
    Result := 'Setup could not create ' + Path + '.';
    Exit;
  end;
  if not Exec(ExpandConstant('{sys}\icacls.exe'),
    '"' + Path + '" /inheritance:r /grant:r *S-1-5-18:(OI)(CI)F *S-1-5-32-544:(OI)(CI)F',
    '', SW_HIDE, ewWaitUntilTerminated, ResultCode) or (ResultCode <> 0) then
    Result := 'Setup could not restrict access to ' + Path + ' (icacls exit code ' + IntToStr(ResultCode) + ').';
end;

function PowerShellExecutable: String;
begin
  Result := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
end;

function RunPowerShellScript(const ScriptPath, Arguments: String; var ResultCode: Integer): Boolean;
begin
  Log('Running ' + ScriptPath);
  Result := Exec(PowerShellExecutable,
    '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + ScriptPath + '" ' + Arguments,
    '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Log('Exit code ' + IntToStr(ResultCode));
end;

{ A repair replaces executables that running services keep locked, so stop
  this installation's services first (ownership is verified by the script). }
function StopRunningServices: String;
var
  ResultCode: Integer;
begin
  Result := '';
  PreviouslyRunningFile := '';
  if not IsServerProfile or not IsRepair then
    Exit;
  ExtractTemporaryFile('ServeNest.Common.ps1');
  ExtractTemporaryFile('Stop-ServeNestServices.ps1');
  PreviouslyRunningFile := ExpandConstant('{tmp}\previously-running-services.txt');
  if not RunPowerShellScript(ExpandConstant('{tmp}\Stop-ServeNestServices.ps1'),
    '-InstallRoot "' + NormalizedInstallRoot(WizardDirValue) + '" -Edition {#Edition} -StateFile "' +
    PreviouslyRunningFile + '" -IncludeDatabase', ResultCode) or (ResultCode <> 0) then
    Result := 'Setup could not stop the running ServeNest {#Edition} services before updating them ' +
      '(exit code ' + IntToStr(ResultCode) + '). Stop them in services.msc and run Setup again.';
end;

function WriteProtectedConfiguration(const Json: String; var Path: String): String;
var
  Directory: String;
  Lines: TArrayOfString;
begin
  Directory := ExpandConstant('{tmp}\protected-config');
  Result := ProtectExtractionDirectory(Directory);
  if Result <> '' then
    Exit;
  Path := Directory + '\servenest-install-config.json';
  SetArrayLength(Lines, 1);
  Lines[0] := Json;
  if not SaveStringsToUTF8File(Path, Lines, False) then
    Result := 'Setup could not write its protected configuration file.';
end;

{ Post-install helpers: the app folder exists from here on. Everything above
  runs while the wizard is still collecting values. }

function ReadInstallFailure: String;
var
  Text: String;
  LineEnd: Integer;
begin
  Text := Trim(ReadTextFile(ExpandConstant('{app}\logs\installer\last-install-error.log')));
  { The first line carries the reason; the rest is diagnostic detail. }
  LineEnd := Pos(#10, Text);
  if LineEnd > 0 then
    Text := Trim(Copy(Text, 1, LineEnd - 1));
  if Text = '' then
    Text := 'The configuration script did not report a reason.';
  if Length(Text) > 700 then
    Text := Copy(Text, 1, 700) + '...';
  Result := 'ServeNest could not finish configuring this computer:' + #13#10#13#10 + Text + #13#10#13#10 +
    'Logs: ' + ExpandConstant('{app}\logs\installer') + #13#10 +
    'Fix the cause, then run this Setup again with the same folder and settings. Existing data is kept.';
end;

procedure RunInstallScript(const Json: String);
var
  ConfigPath, Failure: String;
  ResultCode: Integer;
begin
  Failure := WriteProtectedConfiguration(Json, ConfigPath);
  if Failure = '' then
  begin
    WizardForm.StatusLabel.Caption := 'Configuring ServeNest (database, API and services). This can take several minutes...';
    WizardForm.ProgressGauge.Style := npbstMarquee;
    try
      if not RunPowerShellScript(ExpandConstant('{app}\installer\scripts\Install-ServeNest.ps1'),
        '-ConfigurationPath "' + ConfigPath + '"', ResultCode) then
        Failure := 'Setup could not start Windows PowerShell: ' + SysErrorMessage(ResultCode)
      else if ResultCode <> 0 then
        Failure := ReadInstallFailure;
    finally
      WizardForm.ProgressGauge.Style := npbstNormal;
      { Install-ServeNest.ps1 erases it after reading; this covers a failed start. }
      if FileExists(ConfigPath) then
        DeleteFile(ConfigPath);
    end;
  end;
  if Failure <> '' then
  begin
    InstallFailed := True;
    InstallFailureText := Failure;
    Log('ServeNest configuration failed: ' + Failure);
    SuppressibleMsgBox(Failure, mbCriticalError, MB_OK, IDOK);
  end;
end;

function InstallSucceeded: Boolean;
begin
  Result := not InstallFailed;
end;

function EdgeExecutable: String;
begin
  Result := ExpandConstant('{commonpf32}\Microsoft\Edge\Application\msedge.exe');
  if not FileExists(Result) then
    Result := ExpandConstant('{commonpf64}\Microsoft\Edge\Application\msedge.exe');
  if not FileExists(Result) then
    Result := '';
end;

function HasEdge: Boolean;
begin
  Result := EdgeExecutable <> '';
end;

function GetEdgeExecutable(Param: String): String;
begin
  Result := EdgeExecutable;
end;

function ShowValidationError(const Message: String): Boolean;
begin
  SuppressibleMsgBox(Message, mbError, MB_OK, IDOK);
  Result := False;
end;
