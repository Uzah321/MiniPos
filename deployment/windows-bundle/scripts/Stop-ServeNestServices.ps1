[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $InstallRoot,

    [Parameter(Mandatory = $true)]
    [ValidateSet('FOH', 'BOH')]
    [string] $Edition,

    # Receives the names of services that were running, one per line, so the
    # installer can restart them if the repair fails.
    [string] $StateFile = '',

    [switch] $IncludeDatabase
)

# Setup runs this before it replaces program files on a repair: running
# services lock php-cgi.exe, nginx.exe and mysqld.exe. Only services that
# provably belong to InstallRoot are stopped.
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ServeNest.Common.ps1')

Assert-Administrator 'Stopping ServeNest services'
$root = [IO.Path]::GetFullPath($InstallRoot).TrimEnd('\')
$layout = Get-ServeNestLayout $Edition

$names = @(Get-ServeNestApplicationServices $layout)
$owned = [Collections.Generic.List[string]]::new()
foreach ($name in $names) {
    if (Assert-OwnedApplicationService $layout $root $name) { $owned.Add($name) }
}
if ($IncludeDatabase -and (Assert-OwnedDatabaseService $layout $root)) {
    $owned.Add($layout.DatabaseService)
}

$running = [Collections.Generic.List[string]]::new()
try {
    foreach ($name in $owned) {
        $service = Get-Service -Name $name -ErrorAction Stop
        if ($service.Status -ne 'Stopped') {
            # Record first so a failure while stopping this or a later service
            # still restores everything that was running.
            $running.Add($name)
            Stop-ServiceAndWait $name
        }
    }
} catch {
    $failure = $_
    foreach ($name in @($running.ToArray())) {
        try { Start-ServiceAndWait $name } catch { }
    }
    throw "Could not stop the $($layout.Product) services: $($failure.Exception.Message)"
}

if ($StateFile) {
    Write-Utf8File $StateFile (($running.ToArray()) -join "`r`n")
}
Write-Host "Stopped $($running.Count) running $($layout.Product) service(s)."
