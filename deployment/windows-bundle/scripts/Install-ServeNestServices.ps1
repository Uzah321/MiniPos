[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $InstallRoot,

    [Parameter(Mandatory = $true)]
    [ValidateSet('edge', 'cloud')]
    [Alias('Profile')]
    [string] $InstallProfile,

    # First FastCGI port of the PHP worker pool; workers use consecutive ports.
    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 65535)]
    [int] $PhpPort
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ServeNest.Common.ps1')

function Assert-Nssm([string] $Operation) {
    if ($LASTEXITCODE -ne 0) { throw "NSSM failed while $Operation." }
}

function Set-ServiceWithNssm(
    [string] $Name,
    [string] $DisplayName,
    [string] $Executable,
    [string] $Arguments,
    [string] $WorkingDirectory,
    [string[]] $Dependencies,
    [string[]] $Environment,
    [int] $RestartDelayMilliseconds = 3000
) {
    $nssm = $script:Layout.Nssm
    [void] (Assert-OwnedApplicationService $script:Layout $script:Root $Name)
    $existing = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if ($existing) {
        Stop-ServiceAndWait $Name
        & $nssm set $Name Application $Executable | Out-Null
        Assert-Nssm "updating the $Name executable"
        & $nssm set $Name AppParameters $Arguments | Out-Null
        Assert-Nssm "updating the $Name arguments"
    } else {
        & $nssm install $Name $Executable $Arguments | Out-Null
        Assert-Nssm "installing $Name"
    }

    foreach ($setting in @(
        @('AppDirectory', $WorkingDirectory),
        @('DisplayName', $DisplayName),
        @('Description', "$DisplayName ($($script:Layout.Product))"),
        @('Start', 'SERVICE_AUTO_START'),
        @('AppExit', 'Default', 'Restart'),
        @('AppRestartDelay', [string] $RestartDelayMilliseconds),
        @('AppStdout', (Join-Path $script:Logs "$Name.out.log")),
        @('AppStderr', (Join-Path $script:Logs "$Name.err.log")),
        @('AppRotateFiles', '1'),
        @('AppRotateOnline', '1'),
        @('AppRotateBytes', '10485760')
    )) {
        & $nssm set $Name @setting | Out-Null
        Assert-Nssm "setting $Name $($setting[0])"
    }
    if ($Environment.Count -gt 0) {
        & $nssm set $Name AppEnvironmentExtra @Environment | Out-Null
        Assert-Nssm "setting the $Name environment"
    }
    if ($Dependencies.Count -gt 0) {
        & $nssm set $Name DependOnService @Dependencies | Out-Null
        Assert-Nssm "setting $Name dependencies"
    }
}

Assert-Administrator 'Service installation'
$script:Root = [IO.Path]::GetFullPath($InstallRoot).TrimEnd('\')
$script:Layout = Get-ServeNestLayout (Get-ServeNestEditionForProfile $InstallProfile)
$script:Logs = Join-Path $script:Root 'logs\services'
New-Item -ItemType Directory -Force -Path $script:Logs | Out-Null

$layout = $script:Layout
$phpCgi = Join-Path $layout.PhpRoot 'php-cgi.exe'
$phpCli = Join-Path $layout.PhpRoot 'php.exe'
$phpIni = Join-Path $layout.PhpRoot 'php.ini'
$phpIniScan = Join-Path $script:Root 'run\php-ini-scan'
$nginx = Join-Path $layout.NginxRoot 'nginx.exe'
$api = Join-Path $script:Root 'api'
$nginxConfig = Join-Path $script:Root 'config\nginx.conf'
foreach ($required in @($layout.Nssm, $phpCgi, $phpCli, $phpIni, $nginx, (Join-Path $api 'artisan'), $nginxConfig)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "Required service file is missing: $required"
    }
}
if (-not (Test-Path -LiteralPath $phpIniScan -PathType Container)) {
    throw "Bundled PHP configuration scan directory is missing: $phpIniScan"
}

# Verify every name before stopping or changing any of them.
foreach ($name in (Get-ServeNestApplicationServices $layout)) {
    [void] (Assert-OwnedApplicationService $layout $script:Root $name)
}

$nginxPrefix = $layout.NginxRoot.Replace('\', '/').TrimEnd('/') + '/'
& $nginx '-p' $nginxPrefix '-t' '-c' $nginxConfig.Replace('\', '/')
if ($LASTEXITCODE -ne 0) { throw 'Nginx rejected the generated configuration.' }

# The bundled PHP must never read a WinGet/XAMPP php.ini or scanned extension
# list from the host.
$phpEnvironment = @(('PHPRC=' + $layout.PhpRoot), ('PHP_INI_SCAN_DIR=' + $phpIniScan))
$artisan = '-c "' + $phpIni + '" artisan'
$databaseDependency = @($layout.DatabaseService)

$port = $PhpPort
foreach ($name in $layout.PhpServices) {
    Set-ServiceWithNssm `
        -Name $name `
        -DisplayName "ServeNest $($layout.Edition) PHP worker ($port)" `
        -Executable $phpCgi `
        -Arguments ('-c "' + $phpIni + '" -b 127.0.0.1:' + $port) `
        -WorkingDirectory $api `
        -Dependencies $databaseDependency `
        -Environment ($phpEnvironment + 'PHP_FCGI_MAX_REQUESTS=10000') `
        -RestartDelayMilliseconds 0
    $port++
}

Set-ServiceWithNssm `
    -Name $layout.QueueService `
    -DisplayName "ServeNest $($layout.Edition) background jobs" `
    -Executable $phpCli `
    -Arguments "$artisan queue:work database --sleep=2 --tries=3 --timeout=90 --max-time=3600" `
    -WorkingDirectory $api `
    -Dependencies $databaseDependency `
    -Environment $phpEnvironment

if ($layout.SyncService) {
    Set-ServiceWithNssm `
        -Name $layout.SyncService `
        -DisplayName 'ServeNest FOH Back Office sync' `
        -Executable $phpCli `
        -Arguments "$artisan sync:edge-worker" `
        -WorkingDirectory $api `
        -Dependencies $databaseDependency `
        -Environment $phpEnvironment
}
if ($layout.SchedulerService) {
    Set-ServiceWithNssm `
        -Name $layout.SchedulerService `
        -DisplayName 'ServeNest BOH scheduler' `
        -Executable $phpCli `
        -Arguments "$artisan schedule:work" `
        -WorkingDirectory $api `
        -Dependencies $databaseDependency `
        -Environment $phpEnvironment
}

Set-ServiceWithNssm `
    -Name $layout.NginxService `
    -DisplayName "ServeNest $($layout.Edition) web server" `
    -Executable $nginx `
    -Arguments ('-p "' + $nginxPrefix + '" -c "' + $nginxConfig.Replace('\', '/') + '"') `
    -WorkingDirectory $layout.NginxRoot `
    -Dependencies $layout.PhpServices `
    -Environment @()

foreach ($name in @($layout.PhpServices) + @($layout.QueueService, $layout.SyncService, $layout.SchedulerService, $layout.NginxService)) {
    if ($name) { Start-ServiceAndWait $name }
}

Write-Host "Installed and started the $($layout.Product) services."
