; Shared [Setup], payload and uninstall sections for ServeNest-FOH.iss and
; ServeNest-BOH.iss. The including script defines Edition, EditionName,
; AppGuid and WebDir. Build-WindowsBundle.ps1 passes AppVersion, StageDir
; and OutputDir on the ISCC command line.

#ifndef AppVersion
  #define AppVersion "0.0.0-dev"
#endif
#ifndef StageDir
  #define StageDir AddBackslash(SourcePath) + "build\stage"
#endif
#ifndef OutputDir
  #define OutputDir AddBackslash(SourcePath) + "build\output"
#endif
#define SetupIcon AddBackslash(SourcePath) + "branding\servenest.ico"
#define RuntimeName "ServeNest" + Edition
; The install folder is Administrators/SYSTEM-only, so anything a cashier's
; shortcut must read (icon, .url target) lives in ProgramData instead.
#define PublicDir "{commonappdata}\ServeNest\" + Edition

[Setup]
AppId={#AppGuid}
AppName=ServeNest {#EditionName}
AppVersion={#AppVersion}
AppVerName=ServeNest {#EditionName} {#AppVersion}
AppPublisher=ServeNest
DefaultDirName=C:\ServeNest\{#Edition}
DisableProgramGroupPage=yes
DisableDirPage=no
DirExistsWarning=no
UsePreviousAppDir=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0.17763
WizardStyle=modern
Compression=lzma2/max
SolidCompression=yes
LZMAUseSeparateProcess=yes
OutputDir={#OutputDir}
OutputBaseFilename=ServeNest-{#Edition}-Setup-{#AppVersion}
SetupIconFile={#SetupIcon}
UninstallDisplayIcon={#PublicDir}\servenest.ico
UninstallDisplayName=ServeNest {#EditionName}
; Services lock the runtime executables; Setup stops only its own services
; (Stop-ServeNestServices.ps1) instead of letting Restart Manager close them.
CloseApplications=no
RestartApplications=no
SetupLogging=yes

[Files]
; Every profile, including an additional till, records its bundle and keeps
; the installer scripts for repair, health checks and uninstall.
Source: "{#StageDir}\BUNDLE-{#Edition}.json"; DestDir: "{app}"; DestName: "BUNDLE.json"; Flags: ignoreversion
Source: "{#StageDir}\SHA256SUMS.txt"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#StageDir}\app\installer\*"; DestDir: "{app}\installer"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\app\docs\*"; DestDir: "{app}\docs"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\app\branding\*"; DestDir: "{app}\branding"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\app\branding\servenest.ico"; DestDir: "{#PublicDir}"; Flags: ignoreversion

; Server payload: Laravel API, web build, database and the dedicated runtimes.
Source: "{#StageDir}\app\api\*"; DestDir: "{app}\api"; Check: IsServerProfile; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\web\*"; DestDir: "{app}\{#WebDir}"; Check: IsServerProfile; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\runtime\database\*"; DestDir: "{app}\runtime\database"; Check: IsServerProfile; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\runtime\nginx\*"; DestDir: "C:\nginx\{#RuntimeName}"; Check: IsServerProfile; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\runtime\nssm\*"; DestDir: "C:\nssm\{#RuntimeName}"; Check: IsServerProfile; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\runtime\php\*"; DestDir: "C:\php\{#RuntimeName}"; Check: IsServerProfile; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\runtime\phpmyadmin\*"; DestDir: "C:\phpMyAdmin\{#RuntimeName}"; Check: IsServerProfile; Flags: ignoreversion recursesubdirs createallsubdirs

; Run from {tmp} before extraction to stop this installation's services.
Source: "{#StageDir}\app\installer\scripts\ServeNest.Common.ps1"; Flags: dontcopy
Source: "{#StageDir}\app\installer\scripts\Stop-ServeNestServices.ps1"; Flags: dontcopy

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File ""{app}\installer\scripts\Uninstall-ServeNestServices.ps1"" -InstallRoot ""{app}"""; Flags: runhidden waituntilterminated; RunOnceId: "ServeNestRemoveServices"

[UninstallDelete]
; Generated runtime configuration. Data, secrets, .env and backups under
; {app} are deliberately kept.
Type: files; Name: "C:\php\{#RuntimeName}\php.ini"
Type: files; Name: "C:\phpMyAdmin\{#RuntimeName}\config.inc.php"
Type: dirifempty; Name: "C:\php\{#RuntimeName}"
Type: dirifempty; Name: "C:\phpMyAdmin\{#RuntimeName}"
Type: dirifempty; Name: "C:\nginx\{#RuntimeName}"
Type: dirifempty; Name: "C:\nssm\{#RuntimeName}"
