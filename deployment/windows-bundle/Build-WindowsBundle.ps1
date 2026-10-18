[CmdletBinding()]
param(
    [ValidateSet('FOH', 'BOH', 'Both')]
    [string] $Edition = 'Both',

    # Laravel API source (a clean checkout; vendor may include dev packages,
    # they are removed with composer install --no-dev).
    [string] $ApiSource = (Join-Path $PSScriptRoot '..\..\api'),

    # Compiled React build ("npm run build"), built for the origin root.
    [string] $WebBuildSource = (Join-Path $PSScriptRoot '..\..\..\ServeNestPOSFront\build'),

    [Parameter(Mandatory = $true)]
    [string] $NginxRuntimeSource,

    # Portable PHP with matching php.exe, php-cgi.exe and ext\*.dll.
    [Parameter(Mandatory = $true)]
    [string] $PhpRuntimeSource,

    [Parameter(Mandatory = $true)]
    [string] $NssmRuntimeSource,

    # Portable MariaDB: bin\mysqld.exe, bin\mysql.exe, bin\mysql_install_db.exe.
    [Parameter(Mandatory = $true)]
    [string] $DatabaseRuntimeSource,

    # A verified phpMyAdmin release including its vendor directory.
    [Parameter(Mandatory = $true)]
    [string] $PhpMyAdminSource,

    # PEM bundle installed as the bundled PHP's trusted CA list. Defaults to
    # the PHP runtime's own extras\ssl\cacert.pem, then Git for Windows'.
    [string] $CaBundlePath = '',

    [string] $ComposerPath = '',

    [string] $Version = '',

    [switch] $CompileInstaller,

    [string] $InnoCompilerPath = 'C:\Program Files (x86)\Inno Setup 6\ISCC.exe',

    # Outside an editor workspace avoids file indexers locking the vendor tree.
    [string] $StageDirectory = (Join-Path $PSScriptRoot 'build\stage'),

    [string] $OutputDirectory = (Join-Path $PSScriptRoot 'build\output')
)

$ErrorActionPreference = 'Stop'

function Resolve-Directory([string] $Path, [string] $Label) {
    if (-not $Path -or -not (Test-Path -LiteralPath $Path -PathType Container)) {
        throw "$Label was not found: $Path"
    }
    return (Resolve-Path -LiteralPath $Path).Path.TrimEnd('\')
}

function Assert-Files([string] $Root, [string[]] $RelativePaths, [string] $Label) {
    foreach ($relative in $RelativePaths) {
        if (-not (Test-Path -LiteralPath (Join-Path $Root $relative) -PathType Leaf)) {
            throw "$Label is missing $relative under $Root"
        }
    }
}

# Only a folder this builder created (it carries the marker) is ever deleted.
function Reset-SafeDirectory([string] $Path) {
    $full = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    $marker = Join-Path $full '.servenest-stage'
    if (Test-Path -LiteralPath $full) {
        $isEmpty = -not (Get-ChildItem -LiteralPath $full -Force | Select-Object -First 1)
        if (-not $isEmpty -and -not (Test-Path -LiteralPath $marker -PathType Leaf)) {
            throw "Refusing to reset $full`: it is not empty and was not created by this builder."
        }
        # Editors and indexers briefly lock files in a freshly staged tree.
        for ($attempt = 1; Test-Path -LiteralPath $full; $attempt++) {
            try {
                Remove-Item -LiteralPath $full -Recurse -Force -ErrorAction Stop
            } catch {
                if ($attempt -ge 5) { throw "Could not clear $full; close programs using it and retry. $($_.Exception.Message)" }
                Start-Sleep -Seconds 3
            }
        }
    }
    New-Item -ItemType Directory -Force -Path $full | Out-Null
    [IO.File]::WriteAllText($marker, 'Created by Build-WindowsBundle.ps1; safe to delete.')
}

# Windows PowerShell 5.1 turns a native program's redirected stderr (composer
# progress, ISCC messages) into terminating errors under 'Stop'; rely on the
# exit code instead.
function Invoke-Native([scriptblock] $Command) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $Command 2>&1 | ForEach-Object { Write-Host "  $_" }
        return $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
}

# Robocopy exit codes below 8 mean success.
function Copy-Tree([string] $Source, [string] $Destination, [string[]] $ExcludeDirectories = @(), [string[]] $ExcludeFiles = @()) {
    $arguments = @($Source, $Destination, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
    if ($ExcludeDirectories.Count) { $arguments += '/XD'; $arguments += $ExcludeDirectories }
    if ($ExcludeFiles.Count) { $arguments += '/XF'; $arguments += $ExcludeFiles }
    & robocopy.exe @arguments | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "Copying $Source failed (robocopy exit $LASTEXITCODE)." }
    $global:LASTEXITCODE = 0
}

$bundleRoot = $PSScriptRoot
if (-not $Version) { $Version = ([IO.File]::ReadAllText((Join-Path $bundleRoot 'VERSION'))).Trim() }
if ($Version -notmatch '^\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?$') { throw "Invalid version: $Version" }
$editions = if ($Edition -eq 'Both') { @('FOH', 'BOH') } else { @($Edition) }

# ------------------------------------------------------- validate inputs ---
$api = Resolve-Directory $ApiSource 'The API source'
Assert-Files $api @('artisan', 'composer.json', 'composer.lock', 'public\index.php') 'The API source'

$web = Resolve-Directory $WebBuildSource 'The web build'
Assert-Files $web @('index.html', 'runtime-config.js') 'The web build'
$index = [IO.File]::ReadAllText((Join-Path $web 'index.html'))
if ($index -notmatch 'src="/static/js/main\.[^"]+\.js"' -or $index -notmatch 'src="/runtime-config\.js"') {
    throw 'The web build must be compiled for the origin root ("homepage": "/"): index.html must load /static/js/main.*.js and /runtime-config.js.'
}
if ([IO.File]::ReadAllText((Join-Path $web 'runtime-config.js')) -notmatch '__SERVENEST_RUNTIME_CONFIG__') {
    throw 'The web build runtime-config.js does not define window.__SERVENEST_RUNTIME_CONFIG__.'
}

$nginx = Resolve-Directory $NginxRuntimeSource 'The nginx runtime'
Assert-Files $nginx @('nginx.exe', 'conf\mime.types', 'conf\fastcgi_params') 'The nginx runtime'

$nssm = Resolve-Directory $NssmRuntimeSource 'The NSSM runtime'
$nssmExecutable = @((Join-Path $nssm 'win64\nssm.exe'), (Join-Path $nssm 'nssm.exe')) |
    Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
if (-not $nssmExecutable) { throw "The NSSM runtime has neither win64\nssm.exe nor nssm.exe: $nssm" }

$php = Resolve-Directory $PhpRuntimeSource 'The PHP runtime'
Assert-Files $php @('php.exe', 'php-cgi.exe') 'The PHP runtime'

$database = Resolve-Directory $DatabaseRuntimeSource 'The database runtime'
Assert-Files $database @('bin\mysqld.exe') 'The database runtime'
if (-not (@('bin\mariadb.exe', 'bin\mysql.exe') | Where-Object { Test-Path -LiteralPath (Join-Path $database $_) })) {
    throw 'The database runtime needs bin\mariadb.exe or bin\mysql.exe.'
}
if (-not (@('bin\mariadb-install-db.exe', 'bin\mysql_install_db.exe') | Where-Object { Test-Path -LiteralPath (Join-Path $database $_) })) {
    throw 'The database runtime needs bin\mariadb-install-db.exe or bin\mysql_install_db.exe.'
}

$phpMyAdmin = Resolve-Directory $PhpMyAdminSource 'phpMyAdmin'
Assert-Files $phpMyAdmin @('index.php', 'vendor\autoload.php') 'phpMyAdmin'

if (-not $CaBundlePath) {
    $CaBundlePath = @(
        (Join-Path $php 'extras\ssl\cacert.pem'),
        'C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt',
        'C:\Program Files\Git\mingw64\ssl\certs\ca-bundle.crt'
    ) | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
}
if (-not $CaBundlePath -or -not (Test-Path -LiteralPath $CaBundlePath -PathType Leaf) -or
    ([IO.File]::ReadAllText($CaBundlePath)) -notmatch '-----BEGIN CERTIFICATE-----') {
    throw 'Supply -CaBundlePath with a PEM CA bundle (for example curl''s cacert.pem); bundled PHP needs it to verify HTTPS.'
}

if (-not $ComposerPath) {
    $composerCommand = Get-Command composer -ErrorAction SilentlyContinue
    if ($composerCommand) {
        $candidate = Join-Path (Split-Path $composerCommand.Source) 'composer'
        $ComposerPath = @("$candidate.phar", $candidate) | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
    }
}
if (-not $ComposerPath -or -not (Test-Path -LiteralPath $ComposerPath -PathType Leaf)) {
    throw 'Composer was not found; pass -ComposerPath with the path to composer.phar.'
}

# Reject mixed thread-safe/non-thread-safe PHP files and missing extensions
# before anything is staged. Windows PowerShell is used explicitly because the
# validator is part of the shipped, Windows PowerShell 5.1 script set.
$windowsPowerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$validatorExit = Invoke-Native {
    & $windowsPowerShell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $bundleRoot 'scripts\Assert-PhpRuntimeCompatibility.ps1') `
        -RuntimeRoot $php -PhpIniTemplatePath (Join-Path $bundleRoot 'templates\php\php.ini.template')
}
if ($validatorExit -ne 0) { throw 'The PHP runtime failed the compatibility check.' }

if ($CompileInstaller -and -not (Test-Path -LiteralPath $InnoCompilerPath -PathType Leaf)) {
    throw "Inno Setup 6 was not found: $InnoCompilerPath"
}

# ------------------------------------------------------------------ stage ---
$stageRoot = [IO.Path]::GetFullPath($StageDirectory).TrimEnd('\')
Reset-SafeDirectory $stageRoot
$stageApi = Join-Path $stageRoot 'app\api'
Write-Host 'Staging the API...'
# Never ship environment files, databases, logs, uploads, generated
# documents, fiscal device certificates, QZ keys or backups.
Copy-Tree $api $stageApi `
    -ExcludeDirectories @(
        (Join-Path $api '.git'), (Join-Path $api '.idea'), (Join-Path $api '.junie'), (Join-Path $api 'node_modules'),
        (Join-Path $api 'tests'), (Join-Path $api 'tmp'), (Join-Path $api 'docs'), (Join-Path $api 'api'),
        (Join-Path $api 'zimra'), (Join-Path $api 'assets'), (Join-Path $api 'storage\app'),
        (Join-Path $api 'storage\logs'), (Join-Path $api 'storage\framework'), (Join-Path $api 'bootstrap\cache'),
        (Join-Path $api 'public\storage')
    ) `
    -ExcludeFiles @('.env', '.env.*', '.phpunit.result.cache', 'phpunit.xml', '*.log', '*.sql', '*.sqlite',
        '*.salexa-company', '*.servenest-foh', '*.pem', '*.key', '*.p12', '*.pfx')
foreach ($directory in @('assets', 'bootstrap\cache', 'storage\app\public', 'storage\app\private',
    'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $stageApi $directory) | Out-Null
}

Write-Host 'Staging the runtimes...'
$stagePhp = Join-Path $stageRoot 'runtime\php'
Copy-Tree $php $stagePhp -ExcludeDirectories @((Join-Path $php 'dev'), (Join-Path $php 'logs')) `
    -ExcludeFiles @('php.ini', 'php.ini-*', '*.log')
New-Item -ItemType Directory -Force -Path (Join-Path $stagePhp 'extras\ssl') | Out-Null
Copy-Item -LiteralPath $CaBundlePath -Destination (Join-Path $stagePhp 'extras\ssl\cacert.pem') -Force

Copy-Tree $nginx (Join-Path $stageRoot 'runtime\nginx') `
    -ExcludeDirectories @((Join-Path $nginx 'logs'), (Join-Path $nginx 'temp'), (Join-Path $nginx 'html'), (Join-Path $nginx 'contrib')) `
    -ExcludeFiles @('*.log', '*.pid')
New-Item -ItemType Directory -Force -Path (Join-Path $stageRoot 'runtime\nssm\win64') | Out-Null
Copy-Item -LiteralPath $nssmExecutable -Destination (Join-Path $stageRoot 'runtime\nssm\win64\nssm.exe')

# Database binaries and licences only: never a data directory or backup.
Copy-Tree $database (Join-Path $stageRoot 'runtime\database') `
    -ExcludeDirectories @('data', 'data*', 'backup', 'backup*', (Join-Path $database 'include'),
        (Join-Path $database 'mysql-test'), (Join-Path $database 'sql-bench')) `
    -ExcludeFiles @('*.err', '*.pid', '*.log', 'my.ini', 'my.cnf', '*.sql')

# phpMyAdmin without any machine configuration, temporary files or setup UI.
Copy-Tree $phpMyAdmin (Join-Path $stageRoot 'runtime\phpmyadmin') `
    -ExcludeDirectories @((Join-Path $phpMyAdmin 'tmp'), (Join-Path $phpMyAdmin 'setup'),
        (Join-Path $phpMyAdmin 'examples'), (Join-Path $phpMyAdmin 'doc'), (Join-Path $phpMyAdmin 'test')) `
    -ExcludeFiles @('config.inc.php', '*.log')

Write-Host 'Staging the web build and installer scripts...'
Copy-Tree $web (Join-Path $stageRoot 'web') -ExcludeFiles @('*.map')
Copy-Tree (Join-Path $bundleRoot 'scripts') (Join-Path $stageRoot 'app\installer\scripts')
Copy-Tree (Join-Path $bundleRoot 'templates') (Join-Path $stageRoot 'app\installer\templates')
Copy-Tree (Join-Path $bundleRoot 'docs') (Join-Path $stageRoot 'app\docs')
Copy-Tree (Join-Path $bundleRoot 'branding') (Join-Path $stageRoot 'app\branding')

# Production dependencies only, resolved by the bundled PHP so composer's
# platform checks match the customer runtime.
Write-Host 'Installing production dependencies...'
$phpArguments = @('-n', '-d', ('extension_dir=' + (Join-Path $stagePhp 'ext')), '-d', 'memory_limit=-1',
    '-d', ('openssl.cafile=' + $CaBundlePath), '-d', ('curl.cainfo=' + $CaBundlePath))
foreach ($line in [regex]::Matches([IO.File]::ReadAllText((Join-Path $bundleRoot 'templates\php\php.ini.template')), '(?m)^extension=(\S+)')) {
    $phpArguments += @('-d', ('extension=' + $line.Groups[1].Value))
}
Push-Location $stageApi
try {
    $composerExit = Invoke-Native {
        & (Join-Path $stagePhp 'php.exe') @phpArguments $ComposerPath install --no-dev --no-scripts --no-interaction `
            --prefer-dist --optimize-autoloader --no-progress
    }
    if ($composerExit -ne 0) { throw "composer install --no-dev failed (exit $composerExit)." }
} finally {
    Pop-Location
}

# --------------------------------------------------------- safety checks ---
Write-Host 'Checking the staged payload...'
$forbidden = @(Get-ChildItem -LiteralPath $stageRoot -Recurse -File -Force | Where-Object {
    $_.Name -eq '.env' -or $_.Name -like '.env.*' -or
    $_.Extension -in @('.sql', '.sqlite', '.salexa-company', '.servenest-foh', '.p12', '.pfx')
})
if ($forbidden.Count) {
    throw "The staged payload contains files that must never ship:`n$(($forbidden | Select-Object -First 10 -ExpandProperty FullName) -join "`n")"
}
foreach ($empty in @('assets', 'storage\app', 'storage\logs', 'bootstrap\cache')) {
    $files = @(Get-ChildItem -LiteralPath (Join-Path $stageApi $empty) -Recurse -File -Force | Where-Object { $_.Name -ne '.gitignore' })
    if ($files.Count) { throw "The staged API must not contain files under $empty (found $($files[0].FullName))." }
}
$privateKeyScan = @(Get-ChildItem -LiteralPath $stageRoot -Recurse -File -Force | Where-Object {
    $_.FullName -notlike "$stageApi\vendor\*" -and $_.FullName -notlike "$stageRoot\runtime\*" -and $_.Length -lt 2MB
} | Where-Object {
    # A PEM header followed by base64 is key material; code that only
    # mentions the header (for validation) is not.
    [regex]::IsMatch([IO.File]::ReadAllText($_.FullName), '-----BEGIN [A-Z ]*PRIVATE KEY-----\r?\n[A-Za-z0-9+/=]{40,}')
})
if ($privateKeyScan.Count) {
    throw "The staged payload contains private key material: $($privateKeyScan[0].FullName)"
}

# Re-run the PHP check on the staged copy that customers will receive.
$validatorExit = Invoke-Native {
    & $windowsPowerShell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $bundleRoot 'scripts\Assert-PhpRuntimeCompatibility.ps1') `
        -RuntimeRoot $stagePhp -PhpIniTemplatePath (Join-Path $bundleRoot 'templates\php\php.ini.template')
}
if ($validatorExit -ne 0) { throw 'The staged PHP runtime failed the compatibility check.' }

# ------------------------------------------------------ bundle metadata ---
$builtAt = [DateTime]::UtcNow.ToString('o')
foreach ($bundleEdition in @('FOH', 'BOH')) {
    $metadata = [ordered]@{ product = 'ServeNest'; edition = $bundleEdition; version = $Version; built_at = $builtAt }
    [IO.File]::WriteAllText((Join-Path $stageRoot "BUNDLE-$bundleEdition.json"), ($metadata | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
}
$prefixLength = $stageRoot.TrimEnd('\').Length + 1
$checksums = Get-ChildItem -LiteralPath $stageRoot -Recurse -File -Force |
    Where-Object { $_.Name -ne '.servenest-stage' } |
    Sort-Object { $_.FullName.Substring($prefixLength) } -Culture ([Globalization.CultureInfo]::InvariantCulture) |
    ForEach-Object { '{0} *{1}' -f (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant(), $_.FullName.Substring($prefixLength).Replace('\', '/') }
[IO.File]::WriteAllLines((Join-Path $stageRoot 'SHA256SUMS.txt'), [string[]] $checksums, [Text.UTF8Encoding]::new($false))
Write-Host "Staged $($checksums.Count) files in $stageRoot"

# ---------------------------------------------------------------- compile ---
if ($CompileInstaller) {
    # Inno Setup cannot read paths beyond MAX_PATH (260); the deepest vendor
    # files are about 140 characters below the stage folder.
    $longestPath = (Get-ChildItem -LiteralPath $stageRoot -Recurse -File -Force |
        ForEach-Object { $_.FullName.Length } | Measure-Object -Maximum).Maximum
    if ($longestPath -ge 255) {
        throw "Staged paths reach $longestPath characters, beyond what Inno Setup can read. Rerun with a shorter -StageDirectory, for example C:\snb\stage."
    }
    New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
    foreach ($compileEdition in $editions) {
        Write-Host "Compiling ServeNest-$compileEdition-Setup-$Version.exe..."
        $isccExit = Invoke-Native {
            & $InnoCompilerPath /Q "/DStageDir=$stageRoot" "/DOutputDir=$OutputDirectory" "/DAppVersion=$Version" `
                (Join-Path $bundleRoot "ServeNest-$compileEdition.iss")
        }
        if ($isccExit -ne 0) { throw "Inno Setup failed to compile the $compileEdition installer (exit $isccExit)." }
        $setup = Join-Path $OutputDirectory "ServeNest-$compileEdition-Setup-$Version.exe"
        $hash = (Get-FileHash -LiteralPath $setup -Algorithm SHA256).Hash.ToLowerInvariant()
        Write-Host "  $setup"
        Write-Host "  SHA256 $hash"
    }
    Write-Host 'Unsigned installers are for controlled testing only; sign them with an Authenticode certificate for customers.'
}
