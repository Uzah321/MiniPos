[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('edge', 'cloud')]
    [Alias('Profile')]
    [string] $InstallProfile,

    [Parameter(Mandatory = $true)]
    [string] $ApiDirectory,

    [Parameter(Mandatory = $true)]
    [string] $TemplateDirectory,

    [Parameter(Mandatory = $true)]
    [string] $SiteName,

    # The origin browsers use for this installation's UI and API.
    [Parameter(Mandatory = $true)]
    [string] $AppOrigin,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 65535)]
    [int] $DatabasePort,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9_]+$')]
    [string] $DatabaseName,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9_]+$')]
    [string] $DatabaseUser,

    [Parameter(Mandatory = $true)]
    [string] $DatabasePassword,

    [ValidateSet('openssl-sha256')]
    [string] $LicenseAlgorithm = 'openssl-sha256',

    [Parameter(Mandatory = $true)]
    [string] $LicenseKeyId,

    [Parameter(Mandatory = $true)]
    [string] $LicensePublicKeyPath,

    # Central Back Office only: the issuer key kept on this server.
    [string] $LicensePrivateKeyPath = '',

    [Parameter(Mandatory = $true)]
    [string] $LoginCodeLookupKeyId,

    [Parameter(Mandatory = $true)]
    [string] $LoginCodeLookupKey,

    # Shop POS server only: the Central Back Office HTTPS origin it syncs with.
    [string] $BackOfficeUrl = '',

    [string] $SyncCaBundlePath = '',

    [string] $PlatformAdminIds = '',

    [switch] $ValidateOnly
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ServeNest.Common.ps1')

function Assert-SingleLine([string] $Name, [string] $Value) {
    if ([string]::IsNullOrWhiteSpace($Value) -or $Value -match "[`r`n]") {
        throw "$Name must be a non-empty single-line value."
    }
}

function Assert-Origin([string] $Name, [string] $Value, [string[]] $Schemes) {
    $uri = $null
    if (
        (-not [Uri]::TryCreate($Value, [UriKind]::Absolute, [ref] $uri)) -or
        ($uri.Scheme -notin $Schemes) -or
        ($uri.PathAndQuery -ne '/') -or
        $uri.Fragment -or
        $uri.UserInfo
    ) {
        throw "$Name must be an $(($Schemes | ForEach-Object { $_.ToUpperInvariant() }) -join '/') origin without a path, query or credentials."
    }
    return $uri
}

function Escape-DotEnvQuoted([string] $Value) {
    return $Value.Replace('\', '\\').Replace('"', '\"')
}

function ConvertTo-PemEnvironmentValue([string] $Path) {
    return 'base64:' + [Convert]::ToBase64String([IO.File]::ReadAllBytes($Path))
}

Assert-SingleLine 'SiteName' $SiteName
Assert-SingleLine 'DatabasePassword' $DatabasePassword
if ($SiteName.Contains('"') -or $SiteName.Length -gt 120) {
    throw 'SiteName must be at most 120 characters and contain no double quotes.'
}
if ($LicenseKeyId -notmatch '^[A-Za-z0-9._-]{1,64}$') {
    throw 'The licence key ID may contain only letters, numbers, dots, dashes and underscores.'
}
if ($LoginCodeLookupKeyId -notmatch '^[A-Za-z0-9._-]{1,32}$') {
    throw 'The login-code lookup key ID is invalid.'
}
$lookupMatch = [regex]::Match($LoginCodeLookupKey, '^base64:(?<value>[A-Za-z0-9+/]+={0,2})$')
$lookupBytes = $null
if ($lookupMatch.Success) {
    try { $lookupBytes = [Convert]::FromBase64String($lookupMatch.Groups['value'].Value) } catch { $lookupBytes = $null }
}
if (-not $lookupBytes -or $lookupBytes.Length -lt 32) {
    throw 'The login-code lookup key must be a base64: value of at least 32 random bytes.'
}

$appUri = Assert-Origin 'AppOrigin' $AppOrigin @('http', 'https')
if ($InstallProfile -eq 'cloud' -and $appUri.Scheme -ne 'https') {
    throw 'The Central Back Office must be reached over HTTPS: shop POS servers refuse plain-HTTP synchronization in production.'
}

if (-not (Test-Path -LiteralPath $LicensePublicKeyPath -PathType Leaf)) {
    throw "The licence public key was not found: $LicensePublicKeyPath"
}
$publicKeyText = [IO.File]::ReadAllText($LicensePublicKeyPath)
if ($publicKeyText -notmatch '-----BEGIN PUBLIC KEY-----' -or $publicKeyText -match 'PRIVATE KEY') {
    throw 'The licence public key file must contain only a PEM public key.'
}

if ($InstallProfile -eq 'edge') {
    if ($LicensePrivateKeyPath) {
        throw 'Refusing to place a licence issuer private key on a shop POS server.'
    }
    [void] (Assert-Origin 'BackOfficeUrl' $BackOfficeUrl @('https'))
    if ($SyncCaBundlePath) {
        if (-not (Test-Path -LiteralPath $SyncCaBundlePath -PathType Leaf)) {
            throw 'The Back Office trust certificate was not found.'
        }
        $caText = [IO.File]::ReadAllText($SyncCaBundlePath)
        if ($caText -notmatch '-----BEGIN CERTIFICATE-----' -or $caText -match 'PRIVATE KEY') {
            throw 'The Back Office trust file must contain only public PEM certificates.'
        }
    }
} else {
    if (-not $LicensePrivateKeyPath -or -not (Test-Path -LiteralPath $LicensePrivateKeyPath -PathType Leaf)) {
        throw 'The Central Back Office requires its licence signing private key.'
    }
    $privateKeyText = [IO.File]::ReadAllText($LicensePrivateKeyPath)
    if ($privateKeyText -notmatch '-----BEGIN (?:RSA |EC )?PRIVATE KEY-----') {
        throw 'The licence signing key must be an unencrypted PEM private key.'
    }
    $privateKeyText = $null
    if ($PlatformAdminIds -and $PlatformAdminIds -notmatch '^[0-9a-fA-F-]{36}(,[0-9a-fA-F-]{36})*$') {
        throw 'PlatformAdminIds must be a comma-separated list of UUIDs.'
    }
}

$apiPath = [IO.Path]::GetFullPath($ApiDirectory)
if (-not (Test-Path -LiteralPath (Join-Path $apiPath 'artisan') -PathType Leaf)) {
    throw "Laravel artisan was not found under $apiPath"
}
$templatePath = Join-Path $TemplateDirectory "$InstallProfile.env.template"
if (-not (Test-Path -LiteralPath $templatePath -PathType Leaf)) {
    throw "Environment template was not found: $templatePath"
}

$envPath = Join-Path $apiPath '.env'
$existingEnvironment = if (Test-Path -LiteralPath $envPath -PathType Leaf) {
    [IO.File]::ReadAllText($envPath)
} else {
    ''
}
$existingMode = Get-DotEnvValue $existingEnvironment 'SERVENEST_DEPLOYMENT_MODE'
if (-not $existingMode) { $existingMode = Get-DotEnvValue $existingEnvironment 'SALEXA_DEPLOYMENT_MODE' }
if ($existingMode -and $existingMode -ne $InstallProfile) {
    throw "Refusing to change an existing $existingMode database into $InstallProfile mode. Use a documented migration instead."
}
if ((Get-DotEnvValue $existingEnvironment 'SERVENEST_SYNC_LOCAL_HUB') -eq 'true') {
    throw 'Refusing to convert a local Back Office hub into a Central Back Office in place.'
}

# Rotating APP_KEY would make encrypted sessions, device credentials and
# sync credentials unreadable, so a repair always keeps it.
$applicationKey = Get-DotEnvValue $existingEnvironment 'APP_KEY'
if ($applicationKey -notmatch '^base64:[A-Za-z0-9+/=]+$') {
    $applicationKey = New-RandomKey 32
}

$publicKey = ConvertTo-PemEnvironmentValue $LicensePublicKeyPath
$replacements = [ordered]@{
    '{{SITE_NAME}}' = Escape-DotEnvQuoted $SiteName
    '{{APP_KEY}}' = $applicationKey
    '{{APP_ORIGIN}}' = $AppOrigin.TrimEnd('/')
    '{{DATABASE_PORT}}' = [string] $DatabasePort
    '{{DATABASE_NAME}}' = $DatabaseName
    '{{DATABASE_USER}}' = $DatabaseUser
    '{{DATABASE_PASSWORD}}' = Escape-DotEnvQuoted $DatabasePassword
    '{{CACHE_PREFIX}}' = ('servenest_' + $InstallProfile + '_' + $DatabaseName).ToLowerInvariant()
    '{{STATEFUL_DOMAINS}}' = $appUri.Authority
    '{{LICENSE_ALGORITHM}}' = $LicenseAlgorithm
    '{{LICENSE_KEY_ID}}' = $LicenseKeyId
    '{{LICENSE_PUBLIC_KEY}}' = $publicKey
    '{{LICENSE_PUBLIC_KEYS}}' = '{"' + $LicenseKeyId + '":"' + $publicKey + '"}'
    '{{LOGIN_CODE_LOOKUP_KEY_ID}}' = $LoginCodeLookupKeyId
    '{{LOGIN_CODE_LOOKUP_KEY}}' = $LoginCodeLookupKey
    '{{BACK_OFFICE_URL}}' = $BackOfficeUrl.TrimEnd('/')
    '{{SYNC_CA_BUNDLE}}' = Escape-DotEnvQuoted $SyncCaBundlePath
    '{{LICENSE_PRIVATE_KEY}}' = if ($InstallProfile -eq 'cloud') { ConvertTo-PemEnvironmentValue $LicensePrivateKeyPath } else { '' }
    '{{PLATFORM_ADMIN_IDS}}' = $PlatformAdminIds.ToLowerInvariant()
}

$contents = [IO.File]::ReadAllText($templatePath)
foreach ($entry in $replacements.GetEnumerator()) {
    $contents = $contents.Replace($entry.Key, $entry.Value)
}
if ($contents -match '\{\{[A-Z0-9_]+\}\}') {
    throw "The generated environment still contains an unresolved token: $($Matches[0])"
}
if ($InstallProfile -eq 'edge' -and $contents -match '(?m)^LICENSE_SIGNATURE_PRIVATE_KEY=.+$') {
    throw 'Refusing to write a licence issuer private key to a shop POS server.'
}

if ($ValidateOnly) {
    Write-Host "Validated $InstallProfile environment inputs without changing the installation."
    return
}

Write-Utf8File $envPath $contents
$contents = $null
Set-RestrictedFileAcl $envPath

Write-Host "Wrote the $InstallProfile environment: $envPath"
