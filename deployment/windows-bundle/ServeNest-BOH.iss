; ServeNest Back Office installer: the Central Back Office (licence issuer,
; companies, brands, shops, tills, menus) that shop POS servers pair with
; and synchronize against over HTTPS.
; Build with Build-WindowsBundle.ps1 -Edition BOH -CompileInstaller.

#define Edition "BOH"
#define EditionName "Back Office"
#define AppGuid "{{454C05AA-ED61-44BF-B7A9-46A951D9F625}"
#define WebDir "web\back"

#include "include\ServeNestSetup.iss"

[INI]
Filename: "{#PublicDir}\ServeNest Back Office.url"; Section: "InternetShortcut"; Key: "URL"; String: "{code:GetBackOfficeUrl}"
Filename: "{#PublicDir}\ServeNest Back Office.url"; Section: "InternetShortcut"; Key: "IconFile"; String: "{#PublicDir}\servenest.ico"
Filename: "{#PublicDir}\ServeNest Back Office.url"; Section: "InternetShortcut"; Key: "IconIndex"; String: "0"

[Icons]
Name: "{commondesktop}\ServeNest Back Office"; Filename: "{#PublicDir}\ServeNest Back Office.url"; IconFilename: "{#PublicDir}\servenest.ico"
Name: "{commonprograms}\ServeNest\ServeNest Back Office"; Filename: "{#PublicDir}\ServeNest Back Office.url"; IconFilename: "{#PublicDir}\servenest.ico"

[UninstallDelete]
Type: files; Name: "{#PublicDir}\ServeNest Back Office.url"

[Run]
Filename: "{#PublicDir}\ServeNest Back Office.url"; Description: "Open the ServeNest Back Office now"; Flags: postinstall shellexec nowait skipifsilent runasoriginaluser; Check: InstallSucceeded

[Code]
#include "include\ServeNestHelpers.pas"

var
  SitePage: TInputQueryWizardPage;
  TlsPage: TInputOptionWizardPage;
  TrustCheckBox: TNewCheckBox;
  CertificatePage: TInputFileWizardPage;
  ProxyPage: TInputQueryWizardPage;
  DatabasePage: TInputQueryWizardPage;
  CompanyPage: TInputQueryWizardPage;
  AdminPage: TInputQueryWizardPage;
  LicenseKeyPage: TInputFileWizardPage;
  HandOffPage: TInputFileWizardPage;

function TlsMode: String;
begin
  case TlsPage.SelectedValueIndex of
    1: Result := 'certificate';
    2: Result := 'proxy';
  else
    Result := 'self_signed';
  end;
end;

function GetBackOfficeUrl(Param: String): String;
begin
  if TlsMode = 'proxy' then
  begin
    Result := Trim(ProxyPage.Values[0]);
    if Copy(Result, Length(Result), 1) <> '/' then
      Result := Result + '/';
  end
  else if Trim(SitePage.Values[2]) = '443' then
    Result := 'https://' + Trim(SitePage.Values[1]) + '/'
  else
    Result := 'https://' + Trim(SitePage.Values[1]) + ':' + Trim(SitePage.Values[2]) + '/';
end;

function AdministratorPasswordEntered: Boolean;
begin
  Result := AdminPage.Values[2] <> '';
end;

procedure TlsModeClicked(Sender: TObject);
begin
  TrustCheckBox.Enabled := TlsPage.SelectedValueIndex = 0;
end;

procedure CreateEditionPages;
begin
  SitePage := CreateInputQueryPage(wpSelectDir,
    'Back Office address',
    'How browsers and shop POS servers reach this Back Office.',
    'Shops synchronize only over HTTPS. Use a DNS name or a fixed IP address that every shop can reach and that will not change.');
  SitePage.Add('Back Office name (for example Simbisa Back Office):', False);
  SitePage.Add('Network address (DNS name or IP address):', False);
  SitePage.Add('HTTPS port:', False);
  SitePage.Values[0] := 'ServeNest Back Office';
  SitePage.Values[1] := DefaultNetworkName;
  SitePage.Values[2] := '8082';

  TlsPage := CreateInputOptionPage(SitePage.ID,
    'HTTPS certificate',
    'How this Back Office proves its identity to browsers and shops.',
    'Shop POS servers always verify the certificate; the shop connection file carries it when it is not from a public certificate authority.',
    True, False);
  TlsPage.Add('Create a self-signed certificate for this address (private networks and testing)');
  TlsPage.Add('Use my certificate and private key (PEM files)');
  TlsPage.Add('An HTTPS reverse proxy in front of this server provides the certificate');
  TlsPage.SelectedValueIndex := 0;
  TlsPage.CheckListBox.Height := ScaleY(72);
  TlsPage.CheckListBox.OnClickCheck := @TlsModeClicked;
  TrustCheckBox := TNewCheckBox.Create(TlsPage);
  TrustCheckBox.Parent := TlsPage.Surface;
  TrustCheckBox.Left := TlsPage.CheckListBox.Left;
  TrustCheckBox.Top := TlsPage.CheckListBox.Top + TlsPage.CheckListBox.Height + ScaleY(12);
  TrustCheckBox.Width := TlsPage.SurfaceWidth;
  TrustCheckBox.Height := ScaleY(17);
  TrustCheckBox.Caption := 'Trust the self-signed certificate on this computer (for browsers on this server)';
  TrustCheckBox.Checked := True;

  CertificatePage := CreateInputFilePage(TlsPage.ID,
    'Your certificate',
    'The HTTPS certificate and private key for this address.',
    'The certificate must name the network address entered earlier. The private key must be unencrypted; it is ' +
    'stored in the protected secrets folder. Add the issuing CA certificate only if it is not a public authority.');
  CertificatePage.Add('Certificate (PEM, with any intermediate certificates):', 'PEM files (*.pem;*.crt;*.cer)|*.pem;*.crt;*.cer|All files|*.*', '.pem');
  CertificatePage.Add('Private key (PEM, unencrypted):', 'PEM files (*.pem;*.key)|*.pem;*.key|All files|*.*', '.pem');
  CertificatePage.Add('CA certificate for shops to trust (optional, PEM):', 'PEM files (*.pem;*.crt;*.cer)|*.pem;*.crt;*.cer|All files|*.*', '.pem');

  ProxyPage := CreateInputQueryPage(CertificatePage.ID,
    'Reverse proxy',
    'The public HTTPS address of the reverse proxy.',
    'Browsers and shops use this address. Configure the proxy to forward it to this server''s port over a private network.');
  ProxyPage.Add('Public HTTPS address (for example https://backoffice.example.com):', False);
  ProxyPage.Values[0] := 'https://';

  DatabasePage := CreateInputQueryPage(ProxyPage.ID,
    'Back Office database',
    'The database installed on this server.',
    'The defaults suit almost every installation. The database only listens on this computer.');
  DatabasePage.Add('Database port:', False);
  DatabasePage.Add('Database name:', False);
  DatabasePage.Add('Database user:', False);
  DatabasePage.Values[0] := '3308';
  DatabasePage.Values[1] := 'servenest_boh';
  DatabasePage.Values[2] := 'servenest_boh';

  CompanyPage := CreateInputQueryPage(DatabasePage.ID,
    'Operator company',
    'The company that runs this Back Office.',
    'The platform administrator belongs to this company. Further companies, brands, shops and tills are added in the Back Office.');
  CompanyPage.Add('Company name:', False);
  CompanyPage.Add('Company code (letters, numbers and dashes):', False);
  CompanyPage.Add('Head-office city:', False);
  CompanyPage.Add('Country:', False);
  CompanyPage.Add('Country code (two letters, for example ZW):', False);
  CompanyPage.Add('Currency code (three letters, for example USD):', False);
  CompanyPage.Values[2] := 'Harare';
  CompanyPage.Values[3] := 'Zimbabwe';
  CompanyPage.Values[4] := 'ZW';
  CompanyPage.Values[5] := 'USD';

  AdminPage := CreateInputQueryPage(CompanyPage.ID,
    'Platform administrator',
    'The first Back Office administrator.',
    'This account manages every company, licence and shop. The password needs at least 12 characters with ' +
    'upper-case, lower-case, number and symbol characters. It is never written to a log.');
  AdminPage.Add('Full name:', False);
  AdminPage.Add('Username:', False);
  AdminPage.Add('Password:', True);
  AdminPage.Add('Confirm password:', True);

  LicenseKeyPage := CreateInputFilePage(AdminPage.ID,
    'Licence signing key',
    'The key that signs company licences.',
    'Leave this empty to create a new key (recommended for a new Back Office). Select an existing unencrypted ' +
    'private key only when moving a Central Back Office to this server, so its licences stay valid.');
  LicenseKeyPage.Add('Existing licence signing private key (optional, PEM):', 'PEM files (*.pem;*.key)|*.pem;*.key|All files|*.*', '.pem');

  HandOffPage := CreateInputFilePage(LicenseKeyPage.ID,
    'Shop connection file',
    'What each shop POS server needs to connect to this Back Office.',
    'Setup saves a shop connection file with this Back Office''s address, certificate, licence public key and the shared ' +
    'sign-in key. Copy it to each shop POS server for ServeNest FOH Setup, then delete the copy. A master copy stays ' +
    'in the protected secrets folder.');
  HandOffPage.Add('Save a copy to:', 'ServeNest shop connection (*.servenest-foh)|*.servenest-foh', '.servenest-foh');
  HandOffPage.IsSaveButton[0] := True;
  HandOffPage.Values[0] := ExpandConstant('{userdesktop}\ServeNest-Shop-Connection.servenest-foh');
end;

function ShouldSkipEditionPage(PageID: Integer): Boolean;
begin
  Result := False;
  if PageID = CertificatePage.ID then
    Result := TlsMode <> 'certificate'
  else if PageID = ProxyPage.ID then
    Result := TlsMode <> 'proxy'
  else if (PageID = DatabasePage.ID) or (PageID = CompanyPage.ID) or (PageID = LicenseKeyPage.ID) then
    { A repair keeps its database, operator company and signing key. }
    Result := IsRepair;
end;

procedure PrefillEditionPages;
var
  Value: String;
begin
  if not IsRepair then
  begin
    AdminPage.SubCaptionLabel.Caption := 'This account manages every company, licence and shop. The password needs at least ' +
      '12 characters with upper-case, lower-case, number and symbol characters. It is never written to a log.';
    Exit;
  end;
  AdminPage.SubCaptionLabel.Caption := 'Leave the password empty to keep the current platform administrator. ' +
    'Enter the administrator''s name, username and a new password only to reset a forgotten password.';
  Value := MetadataValue('site_name');
  if Value <> '' then
    SitePage.Values[0] := Value;
  Value := MetadataValue('site_host');
  if Value <> '' then
    SitePage.Values[1] := Value;
  Value := MetadataValue('ui_port');
  if Value <> '' then
    SitePage.Values[2] := Value;
  Value := MetadataValue('tls_mode');
  if Value = 'certificate' then
    TlsPage.SelectedValueIndex := 1
  else if Value = 'proxy' then
    TlsPage.SelectedValueIndex := 2
  else if Value = 'self_signed' then
    TlsPage.SelectedValueIndex := 0;
  TrustCheckBox.Enabled := TlsPage.SelectedValueIndex = 0;
  Value := MetadataValue('public_url');
  if Value <> '' then
    ProxyPage.Values[0] := Value;
  Value := MetadataValue('database_port');
  if Value <> '' then
    DatabasePage.Values[0] := Value;
  Value := MetadataValue('database_name');
  if Value <> '' then
    DatabasePage.Values[1] := Value;
  Value := MetadataValue('database_user');
  if Value <> '' then
    DatabasePage.Values[2] := Value;
end;

function IsValidCompanyCode(const Code: String): Boolean;
begin
  Result := (Length(Code) >= 2) and (Length(Code) <= 40) and ConsistsOf(Code, '-_')
    and IsAsciiLetterOrDigit(Code[1]);
end;

function IsLetters(const Value: String; const Count: Integer): Boolean;
begin
  Result := (Length(Value) = Count) and ConsistsOf(Value, '') and not HasCharacterBetween(Value, '0', '9');
end;

function ValidateEditionPage(PageID: Integer): Boolean;
var
  KeepCertificate: Boolean;
begin
  Result := True;
  if PageID = SitePage.ID then
  begin
    if (Trim(SitePage.Values[0]) = '') or (Length(SitePage.Values[0]) > 120) or (Pos('"', SitePage.Values[0]) > 0) then
      Result := ShowValidationError('Enter a Back Office name of up to 120 characters, without double quotes.')
    else if not IsValidHost(Trim(SitePage.Values[1])) then
      Result := ShowValidationError('Enter this server''s DNS name or IP address only, without https:// or a path.')
    else if not IsValidPort(Trim(SitePage.Values[2])) then
      Result := ShowValidationError('Enter an HTTPS port between 1 and 65535.');
  end
  else if PageID = CertificatePage.ID then
  begin
    KeepCertificate := IsRepair and (MetadataValue('tls_mode') = 'certificate')
      and (Trim(CertificatePage.Values[0]) = '') and (Trim(CertificatePage.Values[1]) = '');
    if not KeepCertificate then
    begin
      if not FileExists(Trim(CertificatePage.Values[0])) then
        Result := ShowValidationError('Select the HTTPS certificate PEM file.')
      else if not FileExists(Trim(CertificatePage.Values[1])) then
        Result := ShowValidationError('Select the HTTPS private key PEM file.');
    end;
    if Result and (Trim(CertificatePage.Values[2]) <> '') and not FileExists(Trim(CertificatePage.Values[2])) then
      Result := ShowValidationError('The selected CA certificate file does not exist.');
  end
  else if PageID = ProxyPage.ID then
  begin
    if not IsHttpUrl(Trim(ProxyPage.Values[0]), True, True) then
      Result := ShowValidationError('Enter the proxy''s public HTTPS address without a path, for example https://backoffice.example.com.');
  end
  else if PageID = DatabasePage.ID then
  begin
    if not IsValidPort(Trim(DatabasePage.Values[0])) then
      Result := ShowValidationError('Enter a database port between 1 and 65535.')
    else if Trim(DatabasePage.Values[0]) = Trim(SitePage.Values[2]) then
      Result := ShowValidationError('The database and HTTPS ports must be different.')
    else if not IsValidIdentifier(Trim(DatabasePage.Values[1]), 64) then
      Result := ShowValidationError('The database name may contain only letters, numbers and underscores.')
    else if not IsValidIdentifier(Trim(DatabasePage.Values[2]), 32) then
      Result := ShowValidationError('The database user may contain only letters, numbers and underscores (up to 32).');
  end
  else if PageID = CompanyPage.ID then
  begin
    if (Trim(CompanyPage.Values[0]) = '') or (Length(CompanyPage.Values[0]) > 255) then
      Result := ShowValidationError('Enter the company name.')
    else if not IsValidCompanyCode(Trim(CompanyPage.Values[1])) then
      Result := ShowValidationError('Enter a company code of 2-40 letters, numbers, dashes or underscores, starting with a letter or number.')
    else if Trim(CompanyPage.Values[2]) = '' then
      Result := ShowValidationError('Enter the head-office city.')
    else if Trim(CompanyPage.Values[3]) = '' then
      Result := ShowValidationError('Enter the country.')
    else if not IsLetters(Trim(CompanyPage.Values[4]), 2) then
      Result := ShowValidationError('Enter the two-letter country code, for example ZW.')
    else if not IsLetters(Trim(CompanyPage.Values[5]), 3) then
      Result := ShowValidationError('Enter the three-letter currency code, for example USD.');
  end
  else if PageID = AdminPage.ID then
  begin
    if IsRepair and not AdministratorPasswordEntered then
      Exit;
    if Trim(AdminPage.Values[0]) = '' then
      Result := ShowValidationError('Enter the administrator''s full name.')
    else if (Length(Trim(AdminPage.Values[1])) < 3) or not ConsistsOf(Trim(AdminPage.Values[1]), '._@-') then
      Result := ShowValidationError('Enter a username of at least 3 letters, numbers, dots, dashes, underscores or @.')
    else if not IsStrongPassword(AdminPage.Values[2], Trim(AdminPage.Values[1])) then
      Result := ShowValidationError('The password needs 12-128 characters with upper-case, lower-case, number and symbol characters, and must differ from the username.')
    else if AdminPage.Values[2] <> AdminPage.Values[3] then
      Result := ShowValidationError('The two passwords do not match.');
  end
  else if PageID = LicenseKeyPage.ID then
  begin
    if (Trim(LicenseKeyPage.Values[0]) <> '') and not FileExists(Trim(LicenseKeyPage.Values[0])) then
      Result := ShowValidationError('The selected licence signing key file does not exist.');
  end;
end;

function BuildConfigurationJson: String;
begin
  Result := '{' +
    JsonPair('profile', 'cloud') + ', ' +
    JsonPair('install_root', NormalizedInstallRoot(WizardDirValue)) + ', ' +
    JsonPair('site_name', Trim(SitePage.Values[0])) + ', ' +
    JsonPair('site_host', Trim(SitePage.Values[1])) + ', ' +
    JsonPair('ui_port', Trim(SitePage.Values[2])) + ', ' +
    JsonPair('tls_mode', TlsMode) + ', ' +
    JsonBool('trust_certificate_locally', TrustCheckBox.Checked) + ', ' +
    JsonPair('tls_certificate_path', Trim(CertificatePage.Values[0])) + ', ' +
    JsonPair('tls_private_key_path', Trim(CertificatePage.Values[1])) + ', ' +
    JsonPair('tls_trust_certificate_path', Trim(CertificatePage.Values[2])) + ', ' +
    JsonPair('public_url', Trim(ProxyPage.Values[0])) + ', ' +
    JsonPair('database_port', Trim(DatabasePage.Values[0])) + ', ' +
    JsonPair('database_name', Trim(DatabasePage.Values[1])) + ', ' +
    JsonPair('database_user', Trim(DatabasePage.Values[2])) + ', ' +
    JsonPair('company_name', Trim(CompanyPage.Values[0])) + ', ' +
    JsonPair('company_code', Trim(CompanyPage.Values[1])) + ', ' +
    JsonPair('company_city', Trim(CompanyPage.Values[2])) + ', ' +
    JsonPair('country_name', Trim(CompanyPage.Values[3])) + ', ' +
    JsonPair('country_code', Trim(CompanyPage.Values[4])) + ', ' +
    JsonPair('currency_code', Trim(CompanyPage.Values[5])) + ', ' +
    JsonPair('admin_name', Trim(AdminPage.Values[0])) + ', ' +
    JsonPair('admin_username', Trim(AdminPage.Values[1])) + ', ' +
    JsonPair('admin_password', AdminPage.Values[2]) + ', ' +
    JsonPair('license_private_key_path', Trim(LicenseKeyPage.Values[0])) + ', ' +
    JsonPair('shop_connection_copy_path', Trim(HandOffPage.Values[0])) + ', ' +
    JsonPair('previously_running_services_file', PreviouslyRunningFile) + '}';
end;

function EditionReadyMemo(const NewLine, Space: String): String;
var
  Tls, Administrator, SigningKey, HandOff: String;
begin
  if TlsMode = 'certificate' then
    Tls := 'your certificate'
  else if TlsMode = 'proxy' then
    Tls := 'provided by the reverse proxy'
  else if TrustCheckBox.Checked then
    Tls := 'self-signed, trusted on this computer'
  else
    Tls := 'self-signed';

  if IsRepair then
  begin
    if AdministratorPasswordEntered then
      Administrator := 'reset the password of ' + Trim(AdminPage.Values[1])
    else
      Administrator := 'unchanged';
    SigningKey := 'kept';
  end else
  begin
    Administrator := Trim(AdminPage.Values[1]) + ' (' + Trim(AdminPage.Values[0]) + ')';
    if Trim(LicenseKeyPage.Values[0]) = '' then
      SigningKey := 'create a new key'
    else
      SigningKey := 'use ' + Trim(LicenseKeyPage.Values[0]);
  end;
  HandOff := Trim(HandOffPage.Values[0]);
  if HandOff = '' then
    HandOff := 'secrets folder only';

  Result := 'Back Office:' + NewLine +
    Space + Trim(SitePage.Values[0]) + NewLine +
    Space + GetBackOfficeUrl('') + ' (HTTPS: ' + Tls + ')' + NewLine + NewLine;
  if not IsRepair then
    Result := Result + 'Operator company:' + NewLine +
      Space + Trim(CompanyPage.Values[0]) + ' (' + Uppercase(Trim(CompanyPage.Values[1])) + '), ' +
      Trim(CompanyPage.Values[2]) + ', ' + Trim(CompanyPage.Values[3]) + NewLine + NewLine;
  Result := Result + 'Platform administrator:' + NewLine + Space + Administrator + NewLine + NewLine +
    'Licence signing key:' + NewLine + Space + SigningKey + NewLine + NewLine +
    'Database:' + NewLine + Space + '127.0.0.1:' + Trim(DatabasePage.Values[0]) + ', ' + Trim(DatabasePage.Values[1]) + NewLine + NewLine +
    'Shop connection file:' + NewLine + Space + HandOff;
end;

function EditionFinishedText: String;
var
  HandOff: String;
begin
  HandOff := Trim(HandOffPage.Values[0]);
  if HandOff = '' then
    HandOff := ExpandConstant('{app}\secrets\shop-connection.servenest-foh');
  Result := 'The ServeNest Back Office is running at ' + GetBackOfficeUrl('') + #13#10#13#10 +
    'Copy the shop connection file (' + HandOff + ') to each shop POS server for ServeNest FOH Setup, then delete the copy. ' +
    'Back up ' + ExpandConstant('{app}\secrets') + ': it holds the licence signing key.';
end;

#include "include\ServeNestEvents.pas"
