[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $RuntimeRoot,

    [Parameter(Mandatory = $true)]
    [string] $PhpIniTemplatePath
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $RuntimeRoot -PathType Container)) {
    throw "PHP runtime directory was not found: $RuntimeRoot"
}
if (-not (Test-Path -LiteralPath $PhpIniTemplatePath -PathType Leaf)) {
    throw "PHP configuration template was not found: $PhpIniTemplatePath"
}

$runtime = (Resolve-Path -LiteralPath $RuntimeRoot).Path
$extensionDirectory = Join-Path $runtime 'ext'
if (-not (Test-Path -LiteralPath $extensionDirectory -PathType Container)) {
    throw "PHP extension directory was not found: $extensionDirectory"
}

$template = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $PhpIniTemplatePath).Path)
$phpSection = [regex]::Match($template, '(?ms)^\[PHP\]\s*(.*?)(?=^\[[^\r\n]+\]|\z)')
if (-not $phpSection.Success -or
    $phpSection.Groups[1].Value -notmatch '(?m)^\s*extension_dir\s*=' -or
    $phpSection.Groups[1].Value -notmatch '(?m)^\s*sys_temp_dir\s*=') {
    throw 'The generated PHP template must define extension_dir and sys_temp_dir in its active [PHP] section.'
}
$extensionLines = [regex]::Matches($template, '(?m)^[ \t]*extension[ \t]*=[ \t]*([^\r\n;#]+)')
if ($extensionLines.Count -eq 0) {
    throw 'PHP configuration template does not enable any extensions.'
}

$extensions = [Collections.Generic.List[string]]::new()
$requiredModules = [Collections.Generic.List[string]]::new()
foreach ($line in $extensionLines) {
    $extension = $line.Groups[1].Value.Trim().Trim('"', "'")
    if ($extension -notmatch '^(?:php_)?[A-Za-z0-9_]+(?:\.dll)?$') {
        throw "Unsupported PHP extension directive in the installer template: $($line.Value)"
    }
    $module = $extension -replace '^php_', '' -replace '\.dll$', ''
    if (-not $requiredModules.Contains($module)) {
        $extensions.Add($extension)
        $requiredModules.Add($module)
    }
}

function Invoke-PhpProbe([string] $Executable, [string[]] $Arguments, [bool] $IncludeDetails = $true) {
    # PHP can exit successfully even when a DLL could not be loaded. Capture
    # stderr and inspect the module inventory instead of trusting the exit code.
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& $Executable @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }

    $result = ($output | ForEach-Object { [string] $_ }) -join "`n"
    # php-cgi -i includes environment variables. Never copy phpinfo output
    # into build logs if an information probe fails.
    $details = if ($IncludeDetails) { "`n$result" } else { '' }
    if ($exitCode -ne 0) {
        throw "PHP runtime probe failed ($exitCode) for $Executable$details"
    }
    if ($result -match '(?i)PHP Startup|Unable to (?:initialize|load)|Module compiled with|These options need to match') {
        throw "PHP runtime has an incompatible or unloadable extension: $Executable$details"
    }
    return $result
}

function Get-PhpInfoValue([string] $Information, [string] $Name) {
    $escapedName = [regex]::Escape($Name)
    $plain = [regex]::Match($Information, "(?m)^$escapedName\s*=>\s*([^\r\n]+)")
    if ($plain.Success) {
        $value = $plain.Groups[1].Value.Trim()
        # php -i shows local and master values for INI settings. Return only
        # the local/effective value used by this process.
        return ($value -split '\s+=>\s+', 2)[0].Trim()
    }

    # php-cgi.exe formats -i as HTML, unlike php.exe. Read only the named
    # row; never print the full phpinfo output into build logs.
    $html = [regex]::Match(
        $Information,
        '<tr><td class="e">' + $escapedName + '\s*</td><td class="v">([^<]+)</td></tr>'
    )
    if ($html.Success) {
        return [Net.WebUtility]::HtmlDecode($html.Groups[1].Value).Trim()
    }
    return ''
}

$extensionArguments = @('-n', '-d', "extension_dir=$extensionDirectory")
foreach ($extension in $extensions) {
    $extensionArguments += @('-d', "extension=$extension")
}

$identities = @()
foreach ($name in @('php.exe', 'php-cgi.exe')) {
    $executable = Join-Path $runtime $name
    if (-not (Test-Path -LiteralPath $executable -PathType Leaf)) {
        throw "Required PHP executable was not found: $executable"
    }

    $information = Invoke-PhpProbe $executable @('-n', '-i') $false
    $build = Get-PhpInfoValue $information 'PHP Extension Build'
    $version = Get-PhpInfoValue $information 'PHP Version'
    $architecture = Get-PhpInfoValue $information 'Architecture'
    if (-not $build -or -not $version -or -not $architecture) {
        throw "Could not identify PHP build, version and architecture: $executable"
    }
    $identities += "$version/$architecture/$build"

    $modulesText = Invoke-PhpProbe $executable ($extensionArguments + @('-m'))
    $section = [regex]::Match($modulesText, '(?ms)^\[PHP Modules\]\s*(.*?)^\[Zend Modules\]')
    if (-not $section.Success) {
        throw "PHP did not return a valid module inventory: $executable`n$modulesText"
    }
    $loaded = @($section.Groups[1].Value -split '\r?\n' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    foreach ($module in $requiredModules) {
        if ($loaded -notcontains $module) {
            throw "PHP extension '$module' is not loadable by $name ($version/$architecture/$build). Check that php.exe, php-cgi.exe and ext/*.dll come from the same PHP distribution."
        }
    }
}

if ($identities[0] -ne $identities[1]) {
    throw "PHP CLI and CGI executables are from different builds: $($identities -join ' versus ')"
}

$probeRoot = Join-Path ([IO.Path]::GetTempPath()) ('servenest-php-config-check-' + [Guid]::NewGuid().ToString('N'))
$probeIni = Join-Path $probeRoot 'php.ini'
$originalPhprc = $env:PHPRC
$originalScanDirectory = $env:PHP_INI_SCAN_DIR
try {
    New-Item -ItemType Directory -Path (Join-Path $probeRoot 'run\php-temp') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $probeRoot 'php-ini-scan') -Force | Out-Null
    $renderedIni = $template.Replace('{{PHP_RUNTIME_ROOT}}', $runtime).Replace('{{INSTALL_ROOT_PHP}}', $probeRoot)
    if ($renderedIni -match '\{\{[A-Z_]+\}\}') {
        throw 'The generated PHP configuration template contains an unresolved placeholder.'
    }
    [IO.File]::WriteAllText($probeIni, $renderedIni, [Text.UTF8Encoding]::new($false))
    $env:PHPRC = $probeRoot
    $env:PHP_INI_SCAN_DIR = Join-Path $probeRoot 'php-ini-scan'

    $configuredPhpInfo = Invoke-PhpProbe `
        (Join-Path $runtime 'php.exe') `
        @('-c', $probeIni, '-i') $false
    $effectiveExtensionDirectory = Get-PhpInfoValue $configuredPhpInfo 'extension_dir'
    if (-not $effectiveExtensionDirectory.Trim().Replace('/', '\').TrimEnd('\').Equals(
        $extensionDirectory.Replace('/', '\').TrimEnd('\'),
        [StringComparison]::OrdinalIgnoreCase
    )) {
        throw "Generated php.ini uses '$effectiveExtensionDirectory' instead of the bundled extension directory '$extensionDirectory'."
    }
    foreach ($name in @('php.exe', 'php-cgi.exe')) {
        $modulesText = Invoke-PhpProbe (Join-Path $runtime $name) @('-c', $probeIni, '-m')
        $section = [regex]::Match($modulesText, '(?ms)^\[PHP Modules\]\s*(.*?)^\[Zend Modules\]')
        if (-not $section.Success) {
            throw "Generated php.ini did not return a valid module inventory for $name."
        }
        $loaded = @($section.Groups[1].Value -split '\r?\n' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
        foreach ($module in $requiredModules) {
            if ($loaded -notcontains $module) {
                throw "Generated php.ini cannot load '$module' with $name."
            }
        }
    }
} finally {
    $env:PHPRC = $originalPhprc
    $env:PHP_INI_SCAN_DIR = $originalScanDirectory
    $safeTemporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    if ([IO.Path]::GetFullPath($probeRoot).StartsWith($safeTemporaryRoot, [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $probeRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}

Write-Host "PHP runtime verified: $($identities[0]); $($requiredModules.Count) required extensions load in CLI and CGI."
