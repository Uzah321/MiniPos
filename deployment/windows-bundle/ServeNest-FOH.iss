; ServeNest Front of House installer.
;   Shop POS server - shop database, API, Back Office sync and Front Office
;   Additional till - an Edge app shortcut to an existing shop POS server
; Build with Build-WindowsBundle.ps1 -Edition FOH -CompileInstaller.

#define Edition "FOH"
#define EditionName "Front of House"
#define AppGuid "{{04616C88-464A-48F2-BBD3-1407BDC23EBA}"
#define WebDir "web\front"

#include "include\ServeNestSetup.iss"

[Dirs]
; One Edge profile per computer keeps a till's browser activation across
; restarts and for every Windows user who signs in to that till.
Name: "{commonappdata}\ServeNest\FOH\EdgeProfile"; Permissions: users-modify

[INI]
Filename: "{#PublicDir}\ServeNest POS.url"; Section: "InternetShortcut"; Key: "URL"; String: "{code:GetPosUrl}"
Filename: "{#PublicDir}\ServeNest POS.url"; Section: "InternetShortcut"; Key: "IconFile"; String: "{#PublicDir}\servenest.ico"
Filename: "{#PublicDir}\ServeNest POS.url"; Section: "InternetShortcut"; Key: "IconIndex"; String: "0"

[Icons]
Name: "{commondesktop}\ServeNest POS"; Filename: "{code:GetEdgeExecutable}"; Parameters: "{code:GetPosShortcutParameters}"; IconFilename: "{#PublicDir}\servenest.ico"; Comment: "Open the ServeNest Front Office"; Check: HasEdge
Name: "{commonprograms}\ServeNest\ServeNest POS"; Filename: "{code:GetEdgeExecutable}"; Parameters: "{code:GetPosShortcutParameters}"; IconFilename: "{#PublicDir}\servenest.ico"; Comment: "Open the ServeNest Front Office"; Check: HasEdge
Name: "{commondesktop}\ServeNest POS"; Filename: "{#PublicDir}\ServeNest POS.url"; IconFilename: "{#PublicDir}\servenest.ico"; Check: not HasEdge
Name: "{commonprograms}\ServeNest\ServeNest POS"; Filename: "{#PublicDir}\ServeNest POS.url"; IconFilename: "{#PublicDir}\servenest.ico"; Check: not HasEdge

[UninstallDelete]
Type: files; Name: "{#PublicDir}\ServeNest POS.url"

[Run]
Filename: "{code:GetEdgeExecutable}"; Parameters: "{code:GetPosShortcutParameters}"; Description: "Open ServeNest POS now"; Flags: postinstall nowait skipifsilent runasoriginaluser; Check: InstallSucceeded and HasEdge
Filename: "{#PublicDir}\ServeNest POS.url"; Description: "Open ServeNest POS now"; Flags: postinstall shellexec nowait skipifsilent runasoriginaluser; Check: InstallSucceeded and not HasEdge

[Code]
#include "include\ServeNestHelpers.pas"

var
  ProfilePage: TInputOptionWizardPage;
  TillPage: TInputQueryWizardPage;
  SitePage: TInputQueryWizardPage;
  ConnectionPage: TInputFileWizardPage;
  DatabasePage: TInputQueryWizardPage;

function GetPosUrl(Param: String): String;
begin
  if TillProfileSelected then
    Result := Trim(TillPage.Values[1])
  else
    Result := 'http://127.0.0.1:' + Trim(SitePage.Values[2]) + '/';
end;

function GetPosShortcutParameters(Param: String): String;
begin
  Result := '--app=' + GetPosUrl('') +
    ' --user-data-dir="' + ExpandConstant('{commonappdata}\ServeNest\FOH\EdgeProfile') + '"' +
    ' --no-first-run --no-default-browser-check --start-maximized';
end;

function TillFrontOfficeUrl: String;
begin
  Result := 'http://' + Trim(SitePage.Values[1]) + ':' + Trim(SitePage.Values[2]) + '/';
end;

procedure CreateEditionPages;
begin
  ProfilePage := CreateInputOptionPage(wpWelcome,
    'What is this computer?',
    'Choose what Setup installs on this computer.',
    'Each shop has exactly one POS server. Every other till in the shop only needs a shortcut to it.',
    True, False);
  ProfilePage.Add('Shop POS server: the shop database, API, Back Office sync and Front Office');
  ProfilePage.Add('Additional till: a shortcut to the shop POS server (nothing else is installed)');
  ProfilePage.SelectedValueIndex := 0;
  if MetadataValue('profile') = 'till' then
    ProfilePage.SelectedValueIndex := 1;
  TillProfileSelected := ProfilePage.SelectedValueIndex = 1;

  TillPage := CreateInputQueryPage(wpSelectDir,
    'Additional till',
    'Where this till finds its shop POS server.',
    'Enter the Front Office address shown at the end of the shop POS server installation.');
  TillPage.Add('Till name (for example Till 2):', False);
  TillPage.Add('Shop POS server address (for example http://192.168.1.10:8083/):', False);
  TillPage.Values[0] := GetComputerNameString;
  TillPage.Values[1] := 'http://';

  SitePage := CreateInputQueryPage(wpSelectDir,
    'Shop POS server',
    'How tills in the shop reach this server.',
    'Tills open the Front Office at http://<address>:<port>/. Use a DNS name or a fixed IP address that will not change.');
  SitePage.Add('Shop name (for example Chicken Inn CBD):', False);
  SitePage.Add('This server''s network address (DNS name or IP address):', False);
  SitePage.Add('Front Office port:', False);
  SitePage.Values[1] := DefaultNetworkName;
  SitePage.Values[2] := '8083';

  ConnectionPage := CreateInputFilePage(SitePage.ID,
    'Back Office connection',
    'Connect this shop to the ServeNest Back Office.',
    'Select the shop connection file (.servenest-foh) saved by ServeNest Back Office Setup. It holds the ' +
    'Back Office address, its certificate and the shared sign-in key. Delete your copy after this installation.');
  ConnectionPage.Add('Shop connection file:',
    'ServeNest shop connection (*.servenest-foh)|*.servenest-foh|All files|*.*', '.servenest-foh');

  DatabasePage := CreateInputQueryPage(ConnectionPage.ID,
    'Shop database',
    'The database installed on this server.',
    'The defaults suit almost every shop. The database only listens on this computer.');
  DatabasePage.Add('Database port:', False);
  DatabasePage.Add('Database name:', False);
  DatabasePage.Add('Database user:', False);
  DatabasePage.Values[0] := '3307';
  DatabasePage.Values[1] := 'servenest_foh';
  DatabasePage.Values[2] := 'servenest_foh';
end;

function ShouldSkipEditionPage(PageID: Integer): Boolean;
begin
  Result := False;
  if PageID = TillPage.ID then
    Result := not TillProfileSelected
  else if (PageID = SitePage.ID) or (PageID = ConnectionPage.ID) then
    Result := TillProfileSelected
  else if PageID = DatabasePage.ID then
    { A repair must keep the existing database settings. }
    Result := TillProfileSelected or IsRepair;
end;

{ After the folder is confirmed: a repair starts from the recorded values. }
procedure PrefillEditionPages;
var
  Value: String;
begin
  if not IsRepair then
  begin
    ConnectionPage.SubCaptionLabel.Caption := 'Select the shop connection file (.servenest-foh) saved by ServeNest Back Office Setup. ' +
      'It holds the Back Office address, its certificate and the shared sign-in key. Delete your copy after this installation.';
    Exit;
  end;
  ConnectionPage.SubCaptionLabel.Caption := 'Leave this empty to keep the current Back Office connection, or select a newer ' +
    'shop connection file (.servenest-foh) if the Back Office address or certificate changed.';
  Value := MetadataValue('site_name');
  if Value <> '' then
  begin
    SitePage.Values[0] := Value;
    TillPage.Values[0] := Value;
  end;
  Value := MetadataValue('site_host');
  if Value <> '' then
    SitePage.Values[1] := Value;
  Value := MetadataValue('ui_port');
  if Value <> '' then
    SitePage.Values[2] := Value;
  Value := MetadataValue('database_port');
  if Value <> '' then
    DatabasePage.Values[0] := Value;
  Value := MetadataValue('database_name');
  if Value <> '' then
    DatabasePage.Values[1] := Value;
  Value := MetadataValue('database_user');
  if Value <> '' then
    DatabasePage.Values[2] := Value;
  Value := MetadataValue('till_url');
  if Value <> '' then
    TillPage.Values[1] := Value;
end;

function ValidateEditionPage(PageID: Integer): Boolean;
var
  Recorded, ConnectionFile: String;
begin
  Result := True;
  if PageID = ProfilePage.ID then
  begin
    TillProfileSelected := ProfilePage.SelectedValueIndex = 1;
    { Switch between the two suggested folders unless another was chosen. }
    if TillProfileSelected and (CompareText(WizardForm.DirEdit.Text, 'C:\ServeNest\FOH') = 0) then
      WizardForm.DirEdit.Text := 'C:\ServeNest\Till'
    else if (not TillProfileSelected) and (CompareText(WizardForm.DirEdit.Text, 'C:\ServeNest\Till') = 0) then
      WizardForm.DirEdit.Text := 'C:\ServeNest\FOH';
  end
  else if PageID = wpSelectDir then
  begin
    Recorded := MetadataValue('profile');
    if (Recorded = 'till') and not TillProfileSelected then
      Result := ShowValidationError('This folder holds an additional till. Choose another folder for the shop POS server.')
    else if (Recorded = 'edge') and TillProfileSelected then
      Result := ShowValidationError('This folder holds the shop POS server. Choose another folder for an additional till.');
  end
  else if PageID = TillPage.ID then
  begin
    if (Trim(TillPage.Values[0]) = '') or (Pos('"', TillPage.Values[0]) > 0) then
      Result := ShowValidationError('Enter a till name without double quotes.')
    else if not IsHttpUrl(Trim(TillPage.Values[1]), False, False) then
      Result := ShowValidationError('Enter the shop POS server address, for example http://192.168.1.10:8083/.');
  end
  else if PageID = SitePage.ID then
  begin
    if (Trim(SitePage.Values[0]) = '') or (Length(SitePage.Values[0]) > 120) or (Pos('"', SitePage.Values[0]) > 0) then
      Result := ShowValidationError('Enter a shop name of up to 120 characters, without double quotes.')
    else if not IsValidHost(Trim(SitePage.Values[1])) then
      Result := ShowValidationError('Enter this server''s DNS name or IP address only, without http:// or a path.')
    else if not IsValidPort(Trim(SitePage.Values[2])) then
      Result := ShowValidationError('Enter a Front Office port between 1 and 65535.');
  end
  else if PageID = ConnectionPage.ID then
  begin
    ConnectionFile := Trim(ConnectionPage.Values[0]);
    if (ConnectionFile = '') and not IsRepair then
      Result := ShowValidationError('Select the shop connection file (.servenest-foh) saved by ServeNest Back Office Setup.')
    else if (ConnectionFile <> '') and not FileExists(ConnectionFile) then
      Result := ShowValidationError('The selected shop connection file does not exist.')
    else if (ConnectionFile <> '') and (CompareText(ExtractFileExt(ConnectionFile), '.servenest-foh') <> 0) then
      Result := ShowValidationError('Select a .servenest-foh shop connection file.');
  end
  else if PageID = DatabasePage.ID then
  begin
    if not IsValidPort(Trim(DatabasePage.Values[0])) then
      Result := ShowValidationError('Enter a database port between 1 and 65535.')
    else if Trim(DatabasePage.Values[0]) = Trim(SitePage.Values[2]) then
      Result := ShowValidationError('The database and Front Office ports must be different.')
    else if not IsValidIdentifier(Trim(DatabasePage.Values[1]), 64) then
      Result := ShowValidationError('The database name may contain only letters, numbers and underscores.')
    else if not IsValidIdentifier(Trim(DatabasePage.Values[2]), 32) then
      Result := ShowValidationError('The database user may contain only letters, numbers and underscores (up to 32).');
  end;
end;

{ The configuration script runs as a separate process and has been seen to
  miss a connection file the wizard could read (for example on the user's
  Desktop). Hand it a copy in the same protected folder as its configuration
  file instead; Setup removes its temporary folder when it closes. }
function ProtectedConnectionFile: String;
var
  Source, Directory: String;
begin
  Source := Trim(ConnectionPage.Values[0]);
  Result := Source;
  if Source = '' then
    Exit;
  Directory := ExpandConstant('{tmp}\protected-config');
  if ProtectExtractionDirectory(Directory) <> '' then
    Exit;
  if FileCopy(Source, Directory + '\shop-connection.servenest-foh', False) then
    Result := Directory + '\shop-connection.servenest-foh'
  else
    Log('Could not copy the shop connection file ' + Source + '; passing the original path.');
end;

function BuildConfigurationJson: String;
begin
  if TillProfileSelected then
    Result := '{' +
      JsonPair('profile', 'till') + ', ' +
      JsonPair('install_root', NormalizedInstallRoot(WizardDirValue)) + ', ' +
      JsonPair('site_name', Trim(TillPage.Values[0])) + ', ' +
      JsonPair('till_url', Trim(TillPage.Values[1])) + '}'
  else
    Result := '{' +
      JsonPair('profile', 'edge') + ', ' +
      JsonPair('install_root', NormalizedInstallRoot(WizardDirValue)) + ', ' +
      JsonPair('site_name', Trim(SitePage.Values[0])) + ', ' +
      JsonPair('site_host', Trim(SitePage.Values[1])) + ', ' +
      JsonPair('ui_port', Trim(SitePage.Values[2])) + ', ' +
      JsonPair('database_port', Trim(DatabasePage.Values[0])) + ', ' +
      JsonPair('database_name', Trim(DatabasePage.Values[1])) + ', ' +
      JsonPair('database_user', Trim(DatabasePage.Values[2])) + ', ' +
      JsonPair('connection_file', ProtectedConnectionFile) + ', ' +
      JsonPair('previously_running_services_file', PreviouslyRunningFile) + '}';
end;

function EditionReadyMemo(const NewLine, Space: String): String;
var
  Connection: String;
begin
  if TillProfileSelected then
  begin
    Result := 'Additional till:' + NewLine +
      Space + Trim(TillPage.Values[0]) + NewLine +
      Space + 'Opens ' + Trim(TillPage.Values[1]);
    Exit;
  end;
  Connection := Trim(ConnectionPage.Values[0]);
  if Connection = '' then
    Connection := 'keep the current connection';
  Result := 'Shop POS server:' + NewLine +
    Space + Trim(SitePage.Values[0]) + NewLine +
    Space + 'Front Office for tills: ' + TillFrontOfficeUrl + NewLine + NewLine +
    'Back Office connection:' + NewLine + Space + Connection + NewLine + NewLine +
    'Database:' + NewLine +
    Space + '127.0.0.1:' + Trim(DatabasePage.Values[0]) + ', ' + Trim(DatabasePage.Values[1]);
end;

function EditionFinishedText: String;
begin
  if TillProfileSelected then
    Result := 'This till opens the shop POS server at ' + Trim(TillPage.Values[1]) + '.' + #13#10#13#10 +
      'Open ServeNest POS and activate this browser against its own till.'
  else
    Result := 'The shop POS server is running. Tills in the shop open ' + TillFrontOfficeUrl + #13#10#13#10 +
      'Next: in the Back Office, issue a pairing code for this shop (Deployment & Sync), enter it in ' +
      'ServeNest POS, then activate each till. Delete your copy of the shop connection file.';
end;

#include "include\ServeNestEvents.pas"
