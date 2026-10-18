{ Shared Inno Setup event functions, included at the end of each edition's
  code section after the edition defines CreateEditionPages,
  ShouldSkipEditionPage, ValidateEditionPage, PrefillEditionPages,
  BuildConfigurationJson, EditionReadyMemo and EditionFinishedText.
  (Inno treats any line starting with a bracket as a section header, even
  inside a comment.) }

procedure InitializeWizard;
begin
  CreateEditionPages;
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := ShouldSkipEditionPage(PageID);
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Problem: String;
begin
  Result := True;
  if CurPageID = wpSelectDir then
  begin
    Problem := CheckInstallDirectory;
    if Problem <> '' then
    begin
      Result := ShowValidationError(Problem);
      Exit;
    end;
    PrefillEditionPages;
  end;
  Result := ValidateEditionPage(CurPageID);
end;

function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo,
  MemoComponentsInfo, MemoGroupInfo, MemoTasksInfo: String): String;
begin
  Result := MemoDirInfo + NewLine + NewLine + EditionReadyMemo(NewLine, Space);
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  Result := ProtectExtractionDirectory(NormalizedInstallRoot(WizardDirValue));
  if (Result = '') and IsServerProfile then
    Result := ProtectExtractionDirectory('C:\nginx\{#RuntimeName}');
  if (Result = '') and IsServerProfile then
    Result := ProtectExtractionDirectory('C:\nssm\{#RuntimeName}');
  if (Result = '') and IsServerProfile then
    Result := ProtectExtractionDirectory('C:\php\{#RuntimeName}');
  if (Result = '') and IsServerProfile then
    Result := ProtectExtractionDirectory('C:\phpMyAdmin\{#RuntimeName}');
  if Result = '' then
    Result := StopRunningServices;
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then
    RunInstallScript(BuildConfigurationJson);
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if CurPageID = wpFinished then
  begin
    if InstallFailed then
    begin
      WizardForm.FinishedHeadingLabel.Caption := 'ServeNest setup did not finish';
      WizardForm.FinishedLabel.Caption := InstallFailureText;
    end else
      WizardForm.FinishedLabel.Caption := EditionFinishedText;
  end;
end;

function GetCustomSetupExitCode: Integer;
begin
  if InstallFailed then
    Result := 1
  else
    Result := 0;
end;
