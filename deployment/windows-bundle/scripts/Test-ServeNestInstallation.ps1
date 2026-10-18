[CmdletBinding()]
param(
    # Defaults to the installation that contains this script.
    [string] $InstallRoot = '',

    [ValidateSet('auto', 'edge', 'cloud', 'till')]
    [Alias('Profile')]
    [string] $InstallProfile = 'auto'
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ServeNest.Common.ps1')

function Invoke-Curl([string] $Uri, [string[]] $Options = @()) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& curl.exe --silent --show-error --noproxy '*' --connect-timeout 5 --max-time 30 `
            --write-out '\n%{http_code}' @Options $Uri 2>&1 | ForEach-Object { [string] $_ })
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    $status = if ($output.Count -gt 0) { $output[-1].Trim() } else { '' }
    $body = if ($output.Count -gt 1) { ($output[0..($output.Count - 2)]) -join "`n" } else { '' }
    return [pscustomobject]@{ ExitCode = $exitCode; Status = $status; Body = $body }
}

function Assert-Http([string] $Uri, [string] $Pattern = '', [string[]] $Options = @()) {
    $response = Invoke-Curl $Uri $Options
    if ($response.ExitCode -ne 0) {
        throw "$Uri could not be reached (curl exit $($response.ExitCode)): $($response.Body)"
    }
    if ($response.Status -notmatch '^[23]\d\d$') {
        throw "$Uri returned HTTP $($response.Status)."
    }
    if ($Pattern -and $response.Body -notmatch $Pattern) {
        throw "$Uri did not contain the expected installation marker."
    }
    Write-Host "OK $($response.Status) $Uri"
    return $response.Body
}

# The SPA is built for the origin root. A wrong base path serves index.html
# but 404s its bundle, which shows as a blank page.
function Assert-SinglePageApplication([string] $Origin, [string] $DeploymentMode, [string] $BackendMode, [string[]] $Options) {
    $index = Assert-Http "$Origin/" '<div id="root"></div>' $Options
    $bundle = [regex]::Match($index, 'src="(?<path>/static/js/main\.[^"]+\.js)"')
    if (-not $bundle.Success) {
        throw "The installed web build does not load /static/js/main.*.js from the origin root; it was built for a different base path."
    }
    [void] (Assert-Http ($Origin + $bundle.Groups['path'].Value) '' $Options)
    [void] (Assert-Http "$Origin/runtime-config.js" ('deployment_mode:\s*"' + $DeploymentMode + '"') $Options)
    [void] (Assert-Http "$Origin/runtime-config.js" ('backend_mode:\s*"' + $BackendMode + '"') $Options)
    [void] (Assert-Http "$Origin/up" '' $Options)
}

if (-not $InstallRoot) {
    $InstallRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
}
$root = [IO.Path]::GetFullPath($InstallRoot).TrimEnd('\')
$metadataPath = Join-Path $root 'config\installation.json'
$metadata = if (Test-Path -LiteralPath $metadataPath -PathType Leaf) {
    [IO.File]::ReadAllText($metadataPath) | ConvertFrom-Json
} else {
    $null
}
if ($InstallProfile -eq 'auto') {
    if (-not $metadata) { throw "Installation metadata is missing: $metadataPath" }
    $InstallProfile = ([string] $metadata.profile).ToLowerInvariant()
}

if ($InstallProfile -eq 'till') {
    $tillUrl = [string] $metadata.till_url
    if (-not $tillUrl) { throw 'The till installation has no recorded POS server URL.' }
    [void] (Assert-Http $tillUrl '<div id="root"></div>')
    Write-Host 'ServeNest till shortcut target is reachable.'
    exit 0
}

$layout = Get-ServeNestLayout (Get-ServeNestEditionForProfile $InstallProfile)
$api = Join-Path $root 'api'
$web = Join-Path $root $layout.WebDirectory
$uiPort = if ($metadata -and $metadata.ui_port) { [int] $metadata.ui_port } else { $layout.DefaultUiPort }
$phpIni = Join-Path $layout.PhpRoot 'php.ini'
foreach ($file in @(
    (Join-Path $api 'artisan'),
    (Join-Path $api '.env'),
    (Join-Path $root 'config\nginx.conf'),
    (Join-Path $layout.NginxRoot 'nginx.exe'),
    $layout.Nssm,
    (Join-Path $layout.PhpRoot 'php-cgi.exe'),
    (Join-Path $layout.PhpRoot 'php.exe'),
    $phpIni,
    (Join-Path $layout.PhpMyAdminRoot 'index.php'),
    (Join-Path $layout.PhpMyAdminRoot 'vendor\autoload.php'),
    (Join-Path $layout.PhpMyAdminRoot 'config.inc.php'),
    (Join-Path $web 'index.html'),
    (Join-Path $web 'runtime-config.js')
)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Missing installation file: $file" }
}
foreach ($directory in @((Join-Path $root 'assets'), (Join-Path $api 'assets'), (Join-Path $root 'run\php-ini-scan'))) {
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) { throw "Missing installation directory: $directory" }
}

$environment = [IO.File]::ReadAllText((Join-Path $api '.env'))
$expectedSigning = if ($InstallProfile -eq 'cloud') { 'issuer' } else { 'verify_only' }
if ((Get-DotEnvValue $environment 'SERVENEST_DEPLOYMENT_MODE') -ne $InstallProfile -or
    (Get-DotEnvValue $environment 'LICENSE_SIGNING_MODE') -ne $expectedSigning -or
    -not (Get-DotEnvValue $environment 'AUTH_LOGIN_CODE_LOOKUP_KEY')) {
    throw "The API environment is not a complete $InstallProfile configuration."
}
$environment = $null

# The services must use the bundled PHP configuration, never a host php.ini.
$env:PHPRC = $layout.PhpRoot
$env:PHP_INI_SCAN_DIR = Join-Path $root 'run\php-ini-scan'
foreach ($executable in @((Join-Path $layout.PhpRoot 'php.exe'), (Join-Path $layout.PhpRoot 'php-cgi.exe'))) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $modules = @(& $executable '-c' $phpIni '-m' 2>&1 | ForEach-Object { [string] $_ })
        $moduleExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($moduleExit -ne 0 -or $modules -notcontains 'pdo_mysql' -or $modules -notcontains 'openssl' -or
        @($modules | Where-Object { $_ -match '(?i)PHP Startup|Unable to (?:initialize|load)|Module compiled with' }).Count -gt 0) {
        throw "The bundled $([IO.Path]::GetFileName($executable)) cannot load pdo_mysql and OpenSSL from its own configuration."
    }
}

$previous = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try {
    Push-Location $api
    & (Join-Path $layout.PhpRoot 'php.exe') '-c' $phpIni 'artisan' 'migrate:status' '--no-ansi' 2>&1 | Out-Null
    $migrationExit = $LASTEXITCODE
} finally {
    Pop-Location
    $ErrorActionPreference = $previous
}
if ($migrationExit -ne 0) { throw 'The API cannot read its own database migration state.' }
Write-Host 'OK database migrations readable'

foreach ($name in @($layout.DatabaseService) + @(Get-ServeNestApplicationServices $layout)) {
    $service = Get-Service -Name $name -ErrorAction Stop
    $service.Refresh()
    if ($service.Status -ne 'Running') { throw "Service $name is $($service.Status), not Running." }
    if ($service.StartType -ne 'Automatic') { throw "Service $name does not start automatically after a reboot." }
    Write-Host "OK service $name"
}

if ($InstallProfile -eq 'edge') {
    Assert-SinglePageApplication "http://127.0.0.1:$uiPort" 'front-office' 'edge' @()
} else {
    $tlsMode = [string] $metadata.tls_mode
    if ($tlsMode -eq 'proxy') {
        Assert-SinglePageApplication "http://127.0.0.1:$uiPort" 'back-office' 'cloud' @()
    } else {
        $origin = [Uri] ([string] $metadata.back_office_url)
        $options = @('--resolve', ('{0}:{1}:127.0.0.1' -f $origin.Host, $origin.Port))
        $trust = if ($tlsMode -eq 'self_signed') {
            Join-Path $root 'config\tls\back-office-cert.pem'
        } else {
            Join-Path $root 'config\tls\shop-trust.pem'
        }
        if (Test-Path -LiteralPath $trust -PathType Leaf) { $options += @('--cacert', $trust) }
        Assert-SinglePageApplication $origin.GetLeftPart([UriPartial]::Authority) 'back-office' 'cloud' $options
    }
}

$pmaListeners = @(Get-NetTCPConnection -LocalPort $layout.PhpMyAdminPort -State Listen -ErrorAction SilentlyContinue)
if ($pmaListeners.Count -eq 0 -or @($pmaListeners | Where-Object { $_.LocalAddress -ne '127.0.0.1' }).Count -gt 0) {
    throw "phpMyAdmin must listen only on 127.0.0.1:$($layout.PhpMyAdminPort)."
}
[void] (Assert-Http "http://127.0.0.1:$($layout.PhpMyAdminPort)/" 'phpMyAdmin')

Write-Host "$($layout.Product) ($InstallProfile) installation is healthy."
