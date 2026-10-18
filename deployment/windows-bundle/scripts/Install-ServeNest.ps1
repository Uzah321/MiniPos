[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $ConfigurationPath
)

# Invoked by ServeNest-FOH-Setup.exe and ServeNest-BOH-Setup.exe after the
# payload is extracted. Profiles:
#   edge  - shop POS server (FOH): database, API, Back Office sync, Front Office
#   till  - additional browser till (FOH): no local services
#   cloud - Central Back Office (BOH): database, API, scheduler, Back Office,
#           licence issuer and the endpoint shop POS servers sync with
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ServeNest.Common.ps1')

function Get-OptionalProperty([object] $Value, [string] $Name) {
    $property = $Value.PSObject.Properties[$Name]
    if (-not $property -or $null -eq $property.Value) { return '' }
    return ([string] $property.Value).Trim()
}

function Get-RequiredProperty([object] $Value, [string] $Name, [string] $Label = '') {
    $text = Get-OptionalProperty $Value $Name
    if (-not $text) {
        throw "Installer configuration is missing $(if ($Label) { $Label } else { "'$Name'" })."
    }
    return $text
}

function Get-PortProperty([object] $Value, [string] $Name, [int] $Default) {
    $text = Get-OptionalProperty $Value $Name
    if (-not $text) { return $Default }
    $parsed = 0
    if (-not [int]::TryParse($text, [ref] $parsed) -or $parsed -lt 1 -or $parsed -gt 65535) {
        throw "Installer configuration '$Name' is not a valid TCP port."
    }
    return $parsed
}

function Get-BooleanProperty([object] $Value, [string] $Name, [bool] $Default) {
    $property = $Value.PSObject.Properties[$Name]
    if (-not $property -or $null -eq $property.Value) { return $Default }
    if ($property.Value -is [bool]) { return $property.Value }
    return ([string] $property.Value) -in @('1', 'true', 'yes')
}

function Assert-HttpsOrigin([string] $Value, [string] $Label) {
    $uri = $null
    if ((-not [Uri]::TryCreate($Value, [UriKind]::Absolute, [ref] $uri)) -or
        $uri.Scheme -ne 'https' -or $uri.PathAndQuery -ne '/' -or $uri.Fragment -or $uri.UserInfo) {
        throw "$Label must be an HTTPS address such as https://backoffice.example.com, without a path."
    }
    return $uri.GetLeftPart([UriPartial]::Authority)
}

function Assert-PemFile([string] $Path, [string] $Label, [string] $Pattern, [switch] $PublicOnly) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "$Label was not found: $Path" }
    $text = [IO.File]::ReadAllText($Path)
    if ($text -notmatch $Pattern -or ($PublicOnly -and $text -match 'PRIVATE KEY')) {
        throw "$Label is not a valid PEM file."
    }
}

# Runs a bundled helper script with the portable PHP before php.ini exists:
# ignore any host php.ini and load only the bundled OpenSSL extension.
function Invoke-BundledPhpHelper([string[]] $Arguments) {
    & $script:Php '-n' '-d' ('extension_dir=' + (Join-Path $script:Layout.PhpRoot 'ext')) '-d' 'extension=openssl' @Arguments
    return $LASTEXITCODE
}

function Invoke-Artisan([string[]] $Arguments, [string] $FailureMessage) {
    & $script:Php '-c' $script:PhpIni 'artisan' @Arguments '--no-interaction'
    if ($LASTEXITCODE -ne 0) { throw $FailureMessage }
}

function Read-InstallationMetadata([string] $Root) {
    $path = Join-Path $Root 'config\installation.json'
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { return $null }
    try {
        return [IO.File]::ReadAllText($path) | ConvertFrom-Json
    } catch {
        throw 'Existing ServeNest installation metadata is unreadable; refusing an in-place repair.'
    }
}

function Get-ExistingProfile($Metadata, [string] $Environment) {
    $profiles = [Collections.Generic.List[string]]::new()
    if ($Metadata) {
        $recorded = ([string] $Metadata.profile).ToLowerInvariant()
        if ($recorded -notin @('edge', 'cloud', 'till')) {
            throw 'Existing ServeNest installation metadata contains an unsupported profile.'
        }
        $profiles.Add($recorded)
    }
    $mode = Get-DotEnvValue $Environment 'SERVENEST_DEPLOYMENT_MODE'
    if (-not $mode) { $mode = Get-DotEnvValue $Environment 'SALEXA_DEPLOYMENT_MODE' }
    if ($mode) {
        if ($mode -notin @('edge', 'cloud') -or (Get-DotEnvValue $Environment 'SERVENEST_SYNC_LOCAL_HUB') -eq 'true') {
            throw "This folder holds a '$mode' installation, which this installer cannot repair. Install into a new folder."
        }
        $profiles.Add($mode)
    }
    $unique = @($profiles | Select-Object -Unique)
    if ($unique.Count -gt 1) {
        throw 'Existing installation metadata conflicts with the API environment; repair it before reinstalling.'
    }
    if ($unique.Count -eq 1) { return $unique[0] }
    return $null
}

function Get-ExistingDatabaseIdentity([string] $Environment) {
    if (-not $Environment) { return $null }
    $values = @{}
    foreach ($name in @('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD')) {
        $values[$name] = Get-DotEnvValue $Environment $name
        if (-not $values[$name]) {
            throw "The existing API environment has no reusable $name value; refusing an unsafe credential rotation."
        }
    }
    return [pscustomobject]@{
        Host = $values.DB_HOST
        Port = [int] $values.DB_PORT
        Name = $values.DB_DATABASE
        User = $values.DB_USERNAME
        Password = $values.DB_PASSWORD
    }
}

function Read-ShopConnection([string] $Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf) -or
        [IO.Path]::GetExtension($Path) -ine '.servenest-foh') {
        throw 'Select the .servenest-foh shop connection file exported by the ServeNest Back Office installer.'
    }
    try {
        $document = [IO.File]::ReadAllText($Path) | ConvertFrom-Json
    } catch {
        throw 'The shop connection file is not valid JSON.'
    }
    if ([int] $document.schema_version -ne 1 -or [string] $document.kind -ne 'servenest-shop-connection') {
        throw 'The shop connection file has an unsupported format.'
    }
    $trust = [string] $document.tls_trust_pem
    if ($trust -and ($trust -notmatch '-----BEGIN CERTIFICATE-----' -or $trust -match 'PRIVATE KEY')) {
        throw 'The shop connection file contains an invalid trust certificate.'
    }
    $publicKey = [string] $document.license.public_key
    if ($publicKey -notmatch '-----BEGIN PUBLIC KEY-----' -or $publicKey -match 'PRIVATE KEY') {
        throw 'The shop connection file contains an invalid licence public key.'
    }
    return [pscustomobject]@{
        BackOfficeUrl = Assert-HttpsOrigin ([string] $document.back_office_url) 'The Back Office address in the shop connection file'
        TrustPem = $trust
        LicenseAlgorithm = [string] $document.license.algorithm
        LicenseKeyId = [string] $document.license.key_id
        LicensePublicKeyPem = $publicKey
        LookupKeyId = [string] $document.login_code_lookup.key_id
        LookupKey = [string] $document.login_code_lookup.key
    }
}

function ConvertFrom-PemEnvironmentValue([string] $Value) {
    if ($Value -notmatch '^base64:(?<value>[A-Za-z0-9+/=]+)$') { return $null }
    return [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($Matches['value']))
}

function Protect-FromApache([string] $Root) {
    # A root under XAMPP's htdocs is a naming convention only; Apache must not
    # publish it. This file is a second line of defence, never the only one.
    if ($Root.IndexOf('\htdocs\', [StringComparison]::OrdinalIgnoreCase) -lt 0) { return }
    $path = Join-Path $Root '.htaccess'
    $marker = '# ServeNest installer: deny Apache access to this installation.'
    if ((Test-Path -LiteralPath $path -PathType Leaf) -and
        -not ([IO.File]::ReadAllText($path)).StartsWith($marker, [StringComparison]::Ordinal)) {
        throw "An unmanaged Apache .htaccess already exists at $path; refusing to replace it."
    }
    Write-Utf8File $path ("$marker`r`n<IfModule mod_authz_core.c>`r`n    Require all denied`r`n</IfModule>`r`n" +
        "<IfModule mod_access_compat.c>`r`n    Deny from all`r`n</IfModule>`r`n")
    Set-RestrictedFileAcl $path
}

function Get-SelfSignedNames([string] $SiteHost) {
    $names = [Collections.Generic.List[string]]::new()
    foreach ($candidate in @($SiteHost, $env:COMPUTERNAME, 'localhost', '127.0.0.1')) {
        if ($candidate -and $candidate -match '^[A-Za-z0-9.-]+$' -and -not $names.Contains($candidate)) {
            $names.Add($candidate)
        }
    }
    $computer = Get-CimInstance -ClassName Win32_ComputerSystem -ErrorAction SilentlyContinue
    if ($computer -and $computer.PartOfDomain -and $computer.Domain -match '^[A-Za-z0-9.-]+$') {
        $fqdn = "$($env:COMPUTERNAME).$($computer.Domain)"
        if (-not $names.Contains($fqdn)) { $names.Add($fqdn) }
    }
    return $names.ToArray()
}

function Assert-PlatformAdministratorInput($Admin, [bool] $Rotation) {
    if (-not $Rotation) {
        if ($Admin.company_code -notmatch '^[A-Za-z0-9][A-Za-z0-9_-]{1,39}$') {
            throw 'The company code must be 2-40 letters, numbers, dashes or underscores.'
        }
        if ($Admin.country_code -notmatch '^[A-Za-z]{2}$' -or $Admin.currency_code -notmatch '^[A-Za-z]{3}$') {
            throw 'Enter a two-letter country code and a three-letter currency code.'
        }
    }
    if ($Admin.username -notmatch '^[A-Za-z0-9._@-]{3,255}$') {
        throw 'The administrator username may contain only letters, numbers, dots, dashes, underscores and @.'
    }
    $password = $Admin.password
    if ($password.Length -lt 12 -or $password.Length -gt 128 -or
        $password -cnotmatch '[A-Z]' -or $password -cnotmatch '[a-z]' -or
        $password -notmatch '[0-9]' -or $password -notmatch '[^A-Za-z0-9]' -or
        $password.Equals($Admin.username, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The administrator password must be 12-128 characters with upper-case, lower-case, number and symbol characters.'
    }
}

# ---------------------------------------------------------------- inputs ---
Assert-Administrator 'ServeNest installation'
if (-not (Test-Path -LiteralPath $ConfigurationPath -PathType Leaf)) {
    throw "Installer configuration was not found: $ConfigurationPath"
}
Set-RestrictedFileAcl $ConfigurationPath
$configuration = $null
try {
    $rawConfiguration = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $ConfigurationPath))
    if ($rawConfiguration -match '-----BEGIN [A-Z ]*PRIVATE KEY-----') {
        throw 'Key material must be selected as a file, never embedded in the installer configuration.'
    }
    $configuration = $rawConfiguration | ConvertFrom-Json
    $rawConfiguration = $null
} finally {
    try {
        Remove-SensitiveFile $ConfigurationPath
    } catch {
        throw 'The protected installer configuration could not be securely removed after reading.'
    }
}

$installProfile = (Get-RequiredProperty $configuration 'profile').ToLowerInvariant()
$edition = Get-ServeNestEditionForProfile $installProfile
$layout = Get-ServeNestLayout $edition
$script:Layout = $layout
$installRoot = [IO.Path]::GetFullPath((Get-RequiredProperty $configuration 'install_root')).TrimEnd('\')

$bundlePath = Join-Path $installRoot 'BUNDLE.json'
$bundle = if (Test-Path -LiteralPath $bundlePath -PathType Leaf) { [IO.File]::ReadAllText($bundlePath) | ConvertFrom-Json } else { $null }
if (-not $bundle -or [string] $bundle.product -ne 'ServeNest' -or [string] $bundle.edition -ne $edition) {
    throw "The extracted bundle in $installRoot is not a ServeNest $edition bundle."
}
if (Test-Path -LiteralPath (Join-Path $installRoot 'apps\api') -PathType Container) {
    throw 'This folder uses the older apps\api layout. Install ServeNest into a new folder and migrate its data separately.'
}

$logDirectory = Join-Path $installRoot 'logs\installer'
Protect-AdministratorDirectory $logDirectory
$errorLog = Join-Path $logDirectory 'last-install-error.log'
if (Test-Path -LiteralPath $errorLog -PathType Leaf) { Remove-SensitiveFile $errorLog }
Start-Transcript -LiteralPath (Join-Path $logDirectory ("install-{0}.log" -f (Get-Date -Format 'yyyyMMdd-HHmmss'))) -Force | Out-Null

$servicesToRestore = [Collections.Generic.List[string]]::new()
$previouslyRunningFile = Get-OptionalProperty $configuration 'previously_running_services_file'
if ($previouslyRunningFile -and (Test-Path -LiteralPath $previouslyRunningFile -PathType Leaf)) {
    foreach ($name in [IO.File]::ReadAllLines($previouslyRunningFile)) {
        if ($name.Trim()) { $servicesToRestore.Add($name.Trim()) }
    }
}
$stagingDirectory = Join-Path $installRoot ('run\installer-' + [Guid]::NewGuid().ToString('N'))

try {
    $siteName = Get-RequiredProperty $configuration 'site_name' 'the site name'
    if ($siteName.Length -gt 120 -or $siteName.Contains('"') -or $siteName -match "[`r`n]") {
        throw 'The site name must be at most 120 characters on one line, without double quotes.'
    }
    $metadata = Read-InstallationMetadata $installRoot
    # Salexa 4.x metadata has no edition; its database service and data must
    # not be adopted by a second, ServeNest-named database service.
    if ($metadata -and [string] $metadata.edition -ne $edition) {
        throw "This folder holds a different installation ($(if ($metadata.edition) { "ServeNest $($metadata.edition)" } else { 'not ServeNest' })). Install into a new folder."
    }

    if ($installProfile -eq 'till') {
        $tillUrl = Get-RequiredProperty $configuration 'till_url' 'the POS server address'
        $uri = $null
        if (-not [Uri]::TryCreate($tillUrl, [UriKind]::Absolute, [ref] $uri) -or $uri.Scheme -notin @('http', 'https')) {
            throw 'The POS server address must be an absolute http:// or https:// URL.'
        }
        if ($metadata -and [string] $metadata.profile -ne 'till') {
            throw "Refusing to turn the existing $($metadata.profile) installation into an additional till."
        }
        $tillMetadata = [ordered]@{
            profile = 'till'
            edition = $edition
            version = [string] $bundle.version
            site_name = $siteName
            till_url = $tillUrl
            installed_at = [DateTime]::UtcNow.ToString('o')
        }
        Write-Utf8File (Join-Path $installRoot 'config\installation.json') ($tillMetadata | ConvertTo-Json)
        Write-Host "Additional till configured for $tillUrl. Activate this browser against its own till in the Front Office."
        Stop-Transcript | Out-Null
        exit 0
    }

    # ----------------------------------------------- server profile inputs ---
    $environmentPath = Join-Path $installRoot 'api\.env'
    $existingEnvironment = if (Test-Path -LiteralPath $environmentPath -PathType Leaf) {
        [IO.File]::ReadAllText($environmentPath)
    } else {
        ''
    }
    $existingProfile = Get-ExistingProfile $metadata $existingEnvironment
    if ($existingProfile -and $existingProfile -ne $installProfile) {
        throw "Refusing to change the existing $existingProfile installation into $installProfile mode. Use a different folder."
    }

    $siteHost = Get-RequiredProperty $configuration 'site_host' "this computer's network address"
    if ($siteHost -notmatch '^[A-Za-z0-9.-]{1,253}$') {
        throw "This computer's network address must be a DNS name or IPv4 address, without http:// or a path."
    }
    $uiPort = Get-PortProperty $configuration 'ui_port' $layout.DefaultUiPort
    $phpPort = Get-PortProperty $configuration 'php_port' $layout.DefaultPhpPort
    $databasePort = Get-PortProperty $configuration 'database_port' $layout.DefaultDatabasePort
    $databaseName = Get-RequiredProperty $configuration 'database_name' 'the database name'
    $databaseUser = Get-RequiredProperty $configuration 'database_user' 'the database user'
    if ($databaseName -notmatch '^[A-Za-z0-9_]{1,64}$' -or $databaseUser -notmatch '^[A-Za-z0-9_]{1,32}$') {
        throw 'The database name and user may contain only letters, numbers and underscores.'
    }
    if ($phpPort + $layout.PhpWorkerCount - 1 -gt 65535) { throw 'The PHP worker ports exceed 65535.' }
    $phpPorts = @(0..($layout.PhpWorkerCount - 1) | ForEach-Object { $phpPort + $_ })
    $requiredPorts = @($uiPort, $databasePort, $layout.PhpMyAdminPort) + $phpPorts
    if (($requiredPorts | Select-Object -Unique).Count -ne $requiredPorts.Count) {
        throw "The web ($uiPort), database ($databasePort), phpMyAdmin ($($layout.PhpMyAdminPort)) and PHP ($($phpPorts -join ', ')) ports must all be different."
    }

    $existingDatabase = Get-ExistingDatabaseIdentity $existingEnvironment
    if ($existingDatabase -and (
        $existingDatabase.Host -ne '127.0.0.1' -or $existingDatabase.Port -ne $databasePort -or
        $existingDatabase.Name -cne $databaseName -or $existingDatabase.User -cne $databaseUser)) {
        throw "Repair database settings must match the existing database (port $($existingDatabase.Port), $($existingDatabase.Name), user $($existingDatabase.User))."
    }

    $api = Join-Path $installRoot 'api'
    $scripts = Join-Path $installRoot 'installer\scripts'
    $templates = Join-Path $installRoot 'installer\templates'
    $script:Php = Join-Path $layout.PhpRoot 'php.exe'
    $script:PhpIni = Join-Path $layout.PhpRoot 'php.ini'
    foreach ($required in @(
        (Join-Path $api 'artisan'),
        $script:Php,
        (Join-Path $layout.PhpRoot 'php-cgi.exe'),
        (Join-Path $layout.NginxRoot 'nginx.exe'),
        $layout.Nssm,
        (Join-Path $layout.PhpMyAdminRoot 'index.php'),
        (Join-Path $layout.PhpMyAdminRoot 'vendor\autoload.php'),
        (Join-Path $installRoot 'runtime\database\bin\mysqld.exe'),
        (Join-Path $installRoot "$($layout.WebDirectory)\index.html"),
        (Join-Path $scripts 'Configure-ServeNestEnvironment.ps1'),
        (Join-Path $scripts 'Initialize-ServeNestDatabase.ps1'),
        (Join-Path $scripts 'Install-ServeNestServices.ps1'),
        (Join-Path $scripts 'Test-ServeNestInstallation.ps1')
    )) {
        if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
            throw "Required bundle file is missing: $required"
        }
    }
    Protect-AdministratorDirectory $stagingDirectory

    $configDirectory = Join-Path $installRoot 'config'
    $tlsDirectory = Join-Path $configDirectory 'tls'
    $secretsDirectory = Join-Path $installRoot 'secrets'
    $licensePublicPath = Join-Path $configDirectory 'license-public.pem'
    $licensePrivatePath = Join-Path $secretsDirectory 'license-private.pem'
    $stagedLicensePublic = Join-Path $stagingDirectory 'license-public.pem'
    $stagedLicensePrivate = Join-Path $stagingDirectory 'license-private.pem'

    # ------------------------------------------------- shop POS server ---
    $backOfficeUrl = ''
    $syncTrustPem = ''
    if ($installProfile -eq 'edge') {
        $appOrigin = "http://${siteHost}:$uiPort"
        $connectionFile = Get-OptionalProperty $configuration 'connection_file'
        if ($connectionFile) {
            $connection = Read-ShopConnection $connectionFile
        } elseif ($existingProfile -eq 'edge') {
            # A repair without a new connection file keeps the paired values.
            $existingPublic = if (Test-Path -LiteralPath $licensePublicPath -PathType Leaf) {
                [IO.File]::ReadAllText($licensePublicPath)
            } else {
                ConvertFrom-PemEnvironmentValue (Get-DotEnvValue $existingEnvironment 'LICENSE_SIGNATURE_PUBLIC_KEY')
            }
            $existingTrust = Join-Path $configDirectory 'back-office-ca.pem'
            $existingUrl = Get-DotEnvValue $existingEnvironment 'SERVENEST_SYNC_CLOUD_URL'
            if (-not $existingUrl) { $existingUrl = Get-DotEnvValue $existingEnvironment 'SALEXA_SYNC_CLOUD_URL' }
            $connection = [pscustomobject]@{
                BackOfficeUrl = Assert-HttpsOrigin $existingUrl 'The existing Back Office address'
                TrustPem = if (Test-Path -LiteralPath $existingTrust -PathType Leaf) { [IO.File]::ReadAllText($existingTrust) } else { '' }
                LicenseAlgorithm = Get-DotEnvValue $existingEnvironment 'LICENSE_SIGNATURE_ALGORITHM'
                LicenseKeyId = Get-DotEnvValue $existingEnvironment 'LICENSE_SIGNATURE_KEY_ID'
                LicensePublicKeyPem = [string] $existingPublic
                LookupKeyId = Get-DotEnvValue $existingEnvironment 'AUTH_LOGIN_CODE_LOOKUP_KEY_ID'
                LookupKey = Get-DotEnvValue $existingEnvironment 'AUTH_LOGIN_CODE_LOOKUP_KEY'
            }
            if (-not $connection.LookupKey -or $connection.LicensePublicKeyPem -notmatch 'BEGIN PUBLIC KEY') {
                throw 'This shop POS server has no complete Back Office connection yet. Select the shop connection file exported by the Back Office installer.'
            }
        } else {
            throw 'Select the shop connection file (.servenest-foh) exported by the ServeNest Back Office installer.'
        }
        $override = Get-OptionalProperty $configuration 'back_office_url_override'
        $backOfficeUrl = if ($override) { Assert-HttpsOrigin $override 'The Back Office address' } else { $connection.BackOfficeUrl }
        $syncTrustPem = $connection.TrustPem
        $licenseAlgorithm = if ($connection.LicenseAlgorithm) { $connection.LicenseAlgorithm } else { 'openssl-sha256' }
        $licenseKeyId = $connection.LicenseKeyId
        $lookupKeyId = $connection.LookupKeyId
        $lookupKey = $connection.LookupKey
        Write-Utf8File $stagedLicensePublic $connection.LicensePublicKeyPem
        $platformAdminIds = ''
    }

    # ---------------------------------------------- Central Back Office ---
    $tlsMode = ''
    $sourceCertificate = ''
    $sourcePrivateKey = ''
    $sourceShopTrust = ''
    $adminInput = $null
    $rotateAdministrator = $false
    if ($installProfile -eq 'cloud') {
        $tlsMode = (Get-OptionalProperty $configuration 'tls_mode').ToLowerInvariant()
        if (-not $tlsMode) { $tlsMode = 'self_signed' }
        if ($tlsMode -notin @('self_signed', 'certificate', 'proxy')) { throw "Unsupported TLS mode: $tlsMode" }
        $installedCertificate = Join-Path $tlsDirectory 'back-office-cert.pem'
        $installedPrivateKey = Join-Path $secretsDirectory 'back-office-key.pem'
        $installedShopTrust = Join-Path $tlsDirectory 'shop-trust.pem'
        $previousTlsMode = if ($metadata) { [string] $metadata.tls_mode } else { '' }
        $checkTlsPair = Join-Path $scripts 'Check-TlsPair.php'

        if ($tlsMode -eq 'proxy') {
            $appOrigin = Assert-HttpsOrigin (Get-RequiredProperty $configuration 'public_url' 'the public HTTPS address') 'The public HTTPS address'
        } else {
            $appOrigin = if ($uiPort -eq 443) { "https://$siteHost" } else { "https://${siteHost}:$uiPort" }
        }

        if ($tlsMode -eq 'self_signed') {
            $keepCertificate = $previousTlsMode -eq 'self_signed' -and
                [string] $metadata.site_host -eq $siteHost -and
                (Test-Path -LiteralPath $installedCertificate -PathType Leaf) -and
                (Test-Path -LiteralPath $installedPrivateKey -PathType Leaf) -and
                (Invoke-BundledPhpHelper @($checkTlsPair, $installedCertificate, $installedPrivateKey, '30')) -eq 0
            if (-not $keepCertificate) {
                $sourceCertificate = Join-Path $stagingDirectory 'back-office-cert.pem'
                $sourcePrivateKey = Join-Path $stagingDirectory 'back-office-key.pem'
                $names = Get-SelfSignedNames $siteHost
                $exitCode = Invoke-BundledPhpHelper (@((Join-Path $scripts 'New-SelfSignedTls.php'), $sourceCertificate, $sourcePrivateKey, $siteHost) + $names)
                if ($exitCode -ne 0) { throw "The self-signed Back Office certificate could not be created (code $exitCode)." }
                Write-Host "Created a self-signed certificate for: $($names -join ', ')"
            }
        } elseif ($tlsMode -eq 'certificate') {
            $sourceCertificate = Get-OptionalProperty $configuration 'tls_certificate_path'
            $sourcePrivateKey = Get-OptionalProperty $configuration 'tls_private_key_path'
            if (-not $sourceCertificate -and -not $sourcePrivateKey -and $previousTlsMode -eq 'certificate') {
                $sourceCertificate = $installedCertificate
                $sourcePrivateKey = $installedPrivateKey
            }
            if (-not $sourceCertificate -or -not $sourcePrivateKey) {
                throw 'Select both the HTTPS certificate and its private key PEM files.'
            }
            Assert-PemFile $sourceCertificate 'The HTTPS certificate' '-----BEGIN CERTIFICATE-----' -PublicOnly
            Assert-PemFile $sourcePrivateKey 'The HTTPS private key' '-----BEGIN (?:RSA |EC )?PRIVATE KEY-----'
            if (Test-SamePath $sourceCertificate $sourcePrivateKey) {
                throw 'The HTTPS certificate and private key must be different files.'
            }
            $exitCode = Invoke-BundledPhpHelper @($checkTlsPair, $sourceCertificate, $sourcePrivateKey)
            if ($exitCode -ne 0) {
                throw 'The HTTPS certificate and private key do not match, are encrypted, or are outside their validity dates.'
            }
            $sourceShopTrust = Get-OptionalProperty $configuration 'tls_trust_certificate_path'
            if ($sourceShopTrust) {
                Assert-PemFile $sourceShopTrust 'The CA certificate for shops' '-----BEGIN CERTIFICATE-----' -PublicOnly
            } elseif ($previousTlsMode -eq 'certificate' -and (Test-Path -LiteralPath $installedShopTrust -PathType Leaf)) {
                $sourceShopTrust = $installedShopTrust
            }
        }

        # Licence issuer key pair and the login-code lookup key shared with shops.
        $licenseAlgorithm = 'openssl-sha256'
        $existingPrivate = ConvertFrom-PemEnvironmentValue (Get-DotEnvValue $existingEnvironment 'LICENSE_SIGNATURE_PRIVATE_KEY')
        $existingPublic = ConvertFrom-PemEnvironmentValue (Get-DotEnvValue $existingEnvironment 'LICENSE_SIGNATURE_PUBLIC_KEY')
        if ($existingProfile -eq 'cloud' -and $existingPrivate -and $existingPublic) {
            Write-Utf8File $stagedLicensePrivate $existingPrivate
            Write-Utf8File $stagedLicensePublic $existingPublic
            $licenseKeyId = Get-DotEnvValue $existingEnvironment 'LICENSE_SIGNATURE_KEY_ID'
            $lookupKeyId = Get-DotEnvValue $existingEnvironment 'AUTH_LOGIN_CODE_LOOKUP_KEY_ID'
            $lookupKey = Get-DotEnvValue $existingEnvironment 'AUTH_LOGIN_CODE_LOOKUP_KEY'
        } else {
            $suppliedKey = Get-OptionalProperty $configuration 'license_private_key_path'
            $helperArguments = @((Join-Path $scripts 'New-LicenseKeyPair.php'), $stagedLicensePrivate, $stagedLicensePublic)
            if ($suppliedKey) { $helperArguments += $suppliedKey }
            $exitCode = Invoke-BundledPhpHelper $helperArguments
            if ($exitCode -ne 0) {
                throw $(if ($suppliedKey) {
                    'The supplied licence signing key is not an unencrypted RSA (2048+ bit) or EC PEM private key.'
                } else {
                    "The licence signing key pair could not be generated (code $exitCode)."
                })
            }
            $licenseKeyId = 'servenest-' + (Get-Date -Format 'yyyyMMdd') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 6)
            $lookupKeyId = 'v1'
            $lookupKey = New-RandomKey 32
        }

        # First platform administrator (fresh install) or a deliberate
        # password reset of the recorded administrator (repair).
        $platformAdminIds = Get-DotEnvValue $existingEnvironment 'PLATFORM_ADMIN_POS_USER_IDS'
        # Read verbatim: a password is never trimmed.
        $adminPasswordProperty = $configuration.PSObject.Properties['admin_password']
        $adminPassword = if ($adminPasswordProperty -and $null -ne $adminPasswordProperty.Value) { [string] $adminPasswordProperty.Value } else { '' }
        $configuration.PSObject.Properties.Remove('admin_password')
        $adminPasswordProperty = $null
        if ($adminPassword -or -not $platformAdminIds) {
            if (-not $adminPassword) { throw 'Enter the platform administrator password.' }
            # Only a completed installation (it recorded its metadata) has a
            # platform administrator to reset. A retried first install keeps
            # the UUID already written to .env and uses --ensure, which
            # creates the administrator or sets the same one again.
            $rotateAdministrator = [bool] $metadata -and [bool] $platformAdminIds
            $adminInput = [pscustomobject]@{
                id = if ($platformAdminIds) { ($platformAdminIds -split ',')[0].Trim() } else { [Guid]::NewGuid().ToString() }
                name = Get-RequiredProperty $configuration 'admin_name' "the administrator's name"
                username = Get-RequiredProperty $configuration 'admin_username' 'the administrator username'
                password = $adminPassword
                company_name = ''
                company_code = ''
                company_city = ''
                country_code = ''
                country_name = ''
                currency_code = ''
                timezone = 'Africa/Harare'
            }
            if (-not $rotateAdministrator) {
                # The first administrator's home company and country.
                $adminInput.company_name = Get-RequiredProperty $configuration 'company_name' 'the company name'
                $adminInput.company_code = (Get-RequiredProperty $configuration 'company_code' 'the company code').ToUpperInvariant()
                $adminInput.company_city = Get-RequiredProperty $configuration 'company_city' 'the head-office city'
                $adminInput.country_code = (Get-RequiredProperty $configuration 'country_code' 'the country code').ToUpperInvariant()
                $adminInput.country_name = Get-RequiredProperty $configuration 'country_name' 'the country name'
                $adminInput.currency_code = (Get-RequiredProperty $configuration 'currency_code' 'the currency code').ToUpperInvariant()
                $timezone = Get-OptionalProperty $configuration 'timezone'
                if ($timezone) { $adminInput.timezone = $timezone }
                if (-not $platformAdminIds) { $platformAdminIds = $adminInput.id }
            }
            Assert-PlatformAdministratorInput $adminInput $rotateAdministrator
        }
        $adminPassword = $null
    }

    # ------------------------------------------- database credentials ---
    $applicationPassword = if ($existingDatabase) { $existingDatabase.Password } else { $null }
    $databaseAdministratorSecret = Join-Path $secretsDirectory 'database-admin.txt'
    $databaseBootstrapState = Join-Path $secretsDirectory 'database-bootstrap.json'
    $bootstrapStateWasPresent = Test-Path -LiteralPath $databaseBootstrapState -PathType Leaf
    $databaseDataPath = Join-Path $installRoot 'data\database'
    $databaseDataWasNonEmpty = (Test-Path -LiteralPath $databaseDataPath -PathType Container) -and
        ((Get-ChildItem -LiteralPath $databaseDataPath -Force -ErrorAction Stop | Measure-Object).Count -gt 0)
    if ($databaseDataWasNonEmpty -and
        -not (Test-Path -LiteralPath $databaseAdministratorSecret -PathType Leaf) -and
        -not $bootstrapStateWasPresent) {
        throw 'Existing database data has neither an administrator recovery secret nor an interrupted-install record; refusing to claim it.'
    }
    $administratorPassword = $null
    if (Test-Path -LiteralPath $databaseAdministratorSecret -PathType Leaf) {
        Set-RestrictedFileAcl $databaseAdministratorSecret
        $passwordMatch = [regex]::Match([IO.File]::ReadAllText($databaseAdministratorSecret), '(?m)^Password=(?<value>[^\r\n]+)$')
        if (-not $passwordMatch.Success) {
            throw 'The existing database recovery secret is invalid; refusing to rotate credentials blindly.'
        }
        $administratorPassword = $passwordMatch.Groups['value'].Value
    }
    $bootstrapApplicationPassword = $null
    $bootstrapAdministratorPassword = $null
    if ($bootstrapStateWasPresent) {
        Set-RestrictedFileAcl $databaseBootstrapState
        try {
            $bootstrapState = [IO.File]::ReadAllText($databaseBootstrapState) | ConvertFrom-Json
        } catch {
            throw 'The protected database bootstrap recovery state is unreadable.'
        }
        if ([int] $bootstrapState.schema_version -ne 2 -or $bootstrapState.created_for_empty_database -ne $true -or
            [int] $bootstrapState.database_port -ne $databasePort -or
            [string] $bootstrapState.database_name -cne $databaseName -or
            [string] $bootstrapState.database_user -cne $databaseUser) {
            throw 'The protected database bootstrap recovery state belongs to different database settings.'
        }
        $bootstrapApplicationPassword = [string] $bootstrapState.application_password
        $bootstrapAdministratorPassword = [string] $bootstrapState.administrator_password
        if (-not $bootstrapApplicationPassword -or -not $bootstrapAdministratorPassword) {
            throw 'The protected database bootstrap recovery state is incomplete.'
        }
        if ($applicationPassword -and $applicationPassword -cne $bootstrapApplicationPassword) {
            throw 'The API environment conflicts with the protected database bootstrap recovery state.'
        }
        if ($administratorPassword -and $administratorPassword -cne $bootstrapAdministratorPassword) {
            throw 'The database recovery secret conflicts with the protected bootstrap recovery state.'
        }
        $applicationPassword = $bootstrapApplicationPassword
        $administratorPassword = $bootstrapAdministratorPassword
    }
    if (-not $applicationPassword) { $applicationPassword = New-RandomPassword }
    if (-not $administratorPassword) { $administratorPassword = New-RandomPassword 44 }
    $requiresBootstrapState = (-not (Test-Path -LiteralPath $databaseAdministratorSecret -PathType Leaf)) -or
        (-not $existingDatabase) -or $bootstrapStateWasPresent

    # Validate every value that will be written to .env before stopping a live
    # service or touching the database.
    $environmentArguments = @{
        InstallProfile = $installProfile
        ApiDirectory = $api
        TemplateDirectory = Join-Path $templates 'env'
        SiteName = $siteName
        AppOrigin = $appOrigin
        DatabasePort = $databasePort
        DatabaseName = $databaseName
        DatabaseUser = $databaseUser
        DatabasePassword = $applicationPassword
        LicenseAlgorithm = $licenseAlgorithm
        LicenseKeyId = $licenseKeyId
        LicensePublicKeyPath = $stagedLicensePublic
        LoginCodeLookupKeyId = $lookupKeyId
        LoginCodeLookupKey = $lookupKey
        PlatformAdminIds = [string] $platformAdminIds
    }
    if ($installProfile -eq 'edge') {
        $environmentArguments.BackOfficeUrl = $backOfficeUrl
        if ($syncTrustPem) {
            $stagedTrust = Join-Path $stagingDirectory 'back-office-ca.pem'
            Write-Utf8File $stagedTrust $syncTrustPem
            $environmentArguments.SyncCaBundlePath = $stagedTrust
        }
    } else {
        $environmentArguments.LicensePrivateKeyPath = $stagedLicensePrivate
    }
    & (Join-Path $scripts 'Configure-ServeNestEnvironment.ps1') @environmentArguments -ValidateOnly

    # ------------------------------------------------- stop and prepare ---
    foreach ($name in (Get-ServeNestApplicationServices $layout)) {
        if (Assert-OwnedApplicationService $layout $installRoot $name) {
            $service = Get-Service -Name $name -ErrorAction Stop
            if ($service.Status -ne 'Stopped') {
                if (-not $servicesToRestore.Contains($name)) { $servicesToRestore.Add($name) }
                Stop-ServiceAndWait $name
            }
        }
    }
    foreach ($port in @($uiPort, $layout.PhpMyAdminPort) + $phpPorts) { Assert-PortAvailable $port }
    Assert-PortAvailable $databasePort @($layout.DatabaseService)

    foreach ($directory in @(
        # Product, category and logo uploads resolve to root\assets, the only
        # directory nginx publishes as /assets/.
        'assets',
        # Receipts and barcode documents stay application-local and are served
        # through authenticated /api/assets routes.
        'api\assets',
        'api\bootstrap\cache',
        'api\storage\app\public',
        'api\storage\app\private\zimra\devices',
        'api\storage\framework\cache\data',
        'api\storage\framework\sessions',
        'api\storage\framework\views',
        'api\storage\logs',
        'backups',
        'config\tls',
        'data',
        'logs\nginx',
        'logs\services',
        'run\php-temp',
        'run\php-ini-scan',
        'run\phpmyadmin-temp',
        'run\nginx\client_body_temp',
        'run\nginx\proxy_temp',
        'run\nginx\fastcgi_temp',
        'run\nginx\uwsgi_temp',
        'run\nginx\scgi_temp',
        'secrets'
    )) {
        New-Item -ItemType Directory -Force -Path (Join-Path $installRoot $directory) | Out-Null
    }
    # nginx opens <prefix>\logs\error.log before it reads its configuration.
    New-Item -ItemType Directory -Force -Path (Join-Path $layout.NginxRoot 'logs') | Out-Null

    # LocalSystem services execute these trees, so a non-administrator must
    # not be able to replace code, read secrets or plant DLLs in them.
    Protect-AdministratorDirectory $installRoot
    foreach ($runtime in @($layout.NginxRoot, $layout.NssmRoot, $layout.PhpRoot, $layout.PhpMyAdminRoot)) {
        Protect-AdministratorDirectory $runtime
    }
    Protect-FromApache $installRoot

    Copy-Item -LiteralPath $stagedLicensePublic -Destination $licensePublicPath -Force
    if ($installProfile -eq 'edge') {
        $installedTrust = Join-Path $configDirectory 'back-office-ca.pem'
        if ($syncTrustPem) {
            Copy-Item -LiteralPath $environmentArguments.SyncCaBundlePath -Destination $installedTrust -Force
            $environmentArguments.SyncCaBundlePath = $installedTrust
        } elseif (Test-Path -LiteralPath $installedTrust -PathType Leaf) {
            # A public-CA Back Office needs no pinned certificate any more.
            Remove-SensitiveFile $installedTrust
        }
    } else {
        Copy-Item -LiteralPath $stagedLicensePrivate -Destination $licensePrivatePath -Force
        Set-RestrictedFileAcl $licensePrivatePath
        $environmentArguments.LicensePrivateKeyPath = $licensePrivatePath
        if ($tlsMode -ne 'proxy') {
            if ($sourceCertificate -and -not (Test-SamePath $sourceCertificate $installedCertificate)) {
                Copy-Item -LiteralPath $sourceCertificate -Destination $installedCertificate -Force
            }
            if ($sourcePrivateKey -and -not (Test-SamePath $sourcePrivateKey $installedPrivateKey)) {
                Copy-Item -LiteralPath $sourcePrivateKey -Destination $installedPrivateKey -Force
            }
            Set-RestrictedFileAcl $installedPrivateKey
            if ($tlsMode -eq 'self_signed') {
                Copy-Item -LiteralPath $installedCertificate -Destination $installedShopTrust -Force
            } elseif ($sourceShopTrust -and -not (Test-SamePath $sourceShopTrust $installedShopTrust)) {
                Copy-Item -LiteralPath $sourceShopTrust -Destination $installedShopTrust -Force
            } elseif (-not $sourceShopTrust -and (Test-Path -LiteralPath $installedShopTrust -PathType Leaf)) {
                Remove-SensitiveFile $installedShopTrust
            }
        }
    }
    $environmentArguments.LicensePublicKeyPath = $licensePublicPath

    # ------------------------------------------------ phpMyAdmin and PHP ---
    $phpMyAdminTemp = Join-Path $installRoot 'run\phpmyadmin-temp'
    $phpMyAdminConfig = Join-Path $layout.PhpMyAdminRoot 'config.inc.php'
    $pmaMarker = '// Generated by the ServeNest installer.'
    if (Test-Path -LiteralPath $phpMyAdminConfig -PathType Leaf) {
        $existingPma = [IO.File]::ReadAllText($phpMyAdminConfig)
        if (-not $existingPma.Contains($pmaMarker) -or
            -not $existingPma.Contains("`$cfg['Servers'][`$i]['port'] = '$databasePort';") -or
            -not $existingPma.Contains("`$cfg['Servers'][`$i]['host'] = '127.0.0.1';") -or
            -not $existingPma.Contains("`$cfg['Servers'][`$i]['auth_type'] = 'cookie';") -or
            -not $existingPma.Contains("`$cfg['Servers'][`$i]['AllowNoPassword'] = false;")) {
            throw 'The existing phpMyAdmin configuration is not owned by this installation or uses different database settings.'
        }
        $existingPma = $null
    } else {
        $pmaConfig = @"
<?php
$pmaMarker
`$cfg['blowfish_secret'] = '$(New-RandomPassword 48)';
`$i = 0;
`$i++;
`$cfg['Servers'][`$i]['auth_type'] = 'cookie';
`$cfg['Servers'][`$i]['host'] = '127.0.0.1';
`$cfg['Servers'][`$i]['port'] = '$databasePort';
`$cfg['Servers'][`$i]['connect_type'] = 'tcp';
`$cfg['Servers'][`$i]['AllowNoPassword'] = false;
`$cfg['TempDir'] = '$($phpMyAdminTemp.Replace('\', '/'))';
"@
        Write-Utf8File $phpMyAdminConfig $pmaConfig
        $pmaConfig = $null
    }
    Set-RestrictedFileAcl $phpMyAdminConfig

    Expand-ServeNestTemplate (Join-Path $templates 'php\php.ini.template') $script:PhpIni @{
        INSTALL_ROOT_PHP = $installRoot
        PHP_RUNTIME_ROOT = $layout.PhpRoot
    }
    $env:PHPRC = $layout.PhpRoot
    $env:PHP_INI_SCAN_DIR = Join-Path $installRoot 'run\php-ini-scan'
    foreach ($phpExecutable in @($script:Php, (Join-Path $layout.PhpRoot 'php-cgi.exe'))) {
        $previousPreference = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $moduleLines = @(& $phpExecutable '-c' $script:PhpIni '-m' 2>&1 | ForEach-Object { [string] $_ })
            $moduleExit = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $previousPreference
        }
        $startupWarnings = @($moduleLines | Where-Object {
            $_ -match '(?i)PHP Startup|Unable to (?:initialize|load)|Module compiled with|These options need to match'
        } | Select-Object -First 3)
        if ($moduleExit -ne 0 -or $moduleLines -notcontains 'pdo_mysql' -or $moduleLines -notcontains 'openssl' -or $startupWarnings.Count -gt 0) {
            $detail = if ($startupWarnings.Count -gt 0) { $startupWarnings -join ' ' } else { 'No PHP startup detail was returned.' }
            throw "Bundled $([IO.Path]::GetFileName($phpExecutable)) cannot load pdo_mysql and OpenSSL from its own php.ini (exit $moduleExit). $detail"
        }
    }

    # ----------------------------------------------------------- database ---
    if ($requiresBootstrapState -and -not $bootstrapStateWasPresent) {
        $newBootstrapState = [ordered]@{
            schema_version = 2
            created_for_empty_database = $true
            database_port = $databasePort
            database_name = $databaseName
            database_user = $databaseUser
            application_password = $applicationPassword
            administrator_password = $administratorPassword
        }
        Write-Utf8File $databaseBootstrapState ($newBootstrapState | ConvertTo-Json -Compress)
        Set-RestrictedFileAcl $databaseBootstrapState
        $newBootstrapState = $null
    }
    & (Join-Path $scripts 'Initialize-ServeNestDatabase.ps1') `
        -InstallRoot $installRoot `
        -Port $databasePort `
        -DatabaseName $databaseName `
        -ApplicationUser $databaseUser `
        -ApplicationPassword $applicationPassword `
        -AdministratorPassword $administratorPassword `
        -ServiceName $layout.DatabaseService

    & (Join-Path $scripts 'Configure-ServeNestEnvironment.ps1') @environmentArguments

    # ---------------------------------------------------- web front ends ---
    $nginxTemplate = if ($installProfile -eq 'edge') {
        'nginx-edge.conf.template'
    } elseif ($tlsMode -eq 'proxy') {
        'nginx-cloud-proxy.conf.template'
    } else {
        'nginx-cloud-https.conf.template'
    }
    $upstreamServers = ($phpPorts | ForEach-Object { "        server 127.0.0.1:$_;" }) -join "`r`n"
    $nginxValues = @{
        INSTALL_ROOT_NGINX = $installRoot.Replace('\', '/')
        NGINX_RUNTIME_NGINX = $layout.NginxRoot.Replace('\', '/')
        UI_PORT = $uiPort
        PHP_UPSTREAM_SERVERS = $upstreamServers
        TLS_CERTIFICATE_NGINX = (Join-Path $tlsDirectory 'back-office-cert.pem').Replace('\', '/')
        TLS_PRIVATE_KEY_NGINX = (Join-Path $secretsDirectory 'back-office-key.pem').Replace('\', '/')
        PHPMYADMIN_PORT = $layout.PhpMyAdminPort
        PHPMYADMIN_ROOT_NGINX = $layout.PhpMyAdminRoot.Replace('\', '/')
    }
    Expand-ServeNestTemplate (Join-Path $templates 'nginx\phpmyadmin-server.conf.template') (Join-Path $configDirectory 'phpmyadmin-nginx.conf') $nginxValues
    Expand-ServeNestTemplate (Join-Path $templates "nginx\$nginxTemplate") (Join-Path $configDirectory 'nginx.conf') $nginxValues

    $runtimeTemplate = if ($installProfile -eq 'edge') { 'runtime-config.front.js' } else { 'runtime-config.back.js' }
    Expand-ServeNestTemplate (Join-Path $templates "runtime\$runtimeTemplate") (Join-Path $installRoot "$($layout.WebDirectory)\runtime-config.js") @{}

    # ------------------------------------------------------------ Laravel ---
    $protectedAdministratorFile = $null
    Push-Location $api
    try {
        # A configuration cache from a previous version would make Artisan use
        # stale settings; the cache table may not exist yet, so only clear files.
        Invoke-Artisan @('config:clear') 'Laravel could not clear its configuration cache.'

        # Capture the real Artisan failure before the generic catch replaces
        # it. Windows PowerShell 5.1 turns redirected native stderr into
        # ErrorRecords, so use Continue only for this process.
        $previousPreference = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $migrationLines = @(& $script:Php '-c' $script:PhpIni 'artisan' 'migrate' '--force' '--no-interaction' 2>&1 |
                ForEach-Object { [string] $_ })
            $migrationExitCode = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $previousPreference
        }
        if ($migrationExitCode -ne 0) {
            $omitSqlContinuation = $false
            $safeLines = foreach ($line in $migrationLines) {
                $safeLine = [string] $line
                if ($omitSqlContinuation) {
                    if ([string]::IsNullOrWhiteSpace($safeLine)) { $omitSqlContinuation = $false; continue }
                    if ($safeLine -notmatch '^\s*In\s+\S.+\s+line\s+\d+:\s*$') { continue }
                    $omitSqlContinuation = $false
                }
                foreach ($secret in @($applicationPassword, $administratorPassword, $bootstrapApplicationPassword, $bootstrapAdministratorPassword)) {
                    if ($secret) { $safeLine = $safeLine.Replace([string] $secret, '[REDACTED]') }
                }
                # SQL can contain company or user data; the database error
                # before it identifies the failed migration.
                if ($safeLine -match '(?i)\bSQL:\s') {
                    $safeLine = [regex]::Replace($safeLine, '(?i)\bSQL:\s.*$', 'SQL: [OMITTED]')
                    $omitSqlContinuation = $true
                }
                $safeLine = [regex]::Replace($safeLine, '(?i)\b(APP_KEY|DB_PASSWORD|LICENSE_SIGNATURE_PRIVATE_KEY|AUTH_LOGIN_CODE_LOOKUP_KEY)\s*=\s*\S+', '$1=[REDACTED]')
                if ($safeLine.Length -gt 2000) { $safeLine = $safeLine.Substring(0, 2000) + ' [TRUNCATED]' }
                $safeLine
            }
            if (-not $safeLines) { $safeLines = @('(PHP returned no migration output.)') }
            $migrationErrorLog = Join-Path $logDirectory ('migration-error-{0}.log' -f (Get-Date -Format 'yyyyMMdd-HHmmss'))
            @("Laravel migration exit code: $migrationExitCode") + $safeLines | Set-Content -LiteralPath $migrationErrorLog -Encoding UTF8
            @("Laravel migration failure details: $migrationErrorLog", 'Recent redacted migration output:') +
                @($safeLines | Select-Object -Last 80) | Add-Content -LiteralPath $errorLog
            throw "Laravel database migrations failed. Review the redacted details in $migrationErrorLog."
        }
        Write-Host 'Laravel database migrations completed.'

        if ($installProfile -eq 'cloud') {
            if ($adminInput) {
                $provisioningDirectory = Join-Path $stagingDirectory 'provisioning'
                Protect-AdministratorDirectory $provisioningDirectory
                $protectedAdministratorFile = Join-Path $provisioningDirectory 'platform-admin.json'
                $credentials = [ordered]@{
                    schema_version = 1
                    user = [ordered]@{
                        id = $adminInput.id
                        name = $adminInput.name
                        username = $adminInput.username
                        password = $adminInput.password
                    }
                }
                if (-not $rotateAdministrator) {
                    $credentials.country = [ordered]@{
                        code = $adminInput.country_code
                        name = $adminInput.country_name
                        currency_code = $adminInput.currency_code
                        timezone = $adminInput.timezone
                    }
                    $credentials.company = [ordered]@{
                        code = $adminInput.company_code
                        name = $adminInput.company_name
                        city = $adminInput.company_city
                    }
                }
                Write-Utf8File $protectedAdministratorFile ($credentials | ConvertTo-Json -Depth 5 -Compress)
                Set-RestrictedFileAcl $protectedAdministratorFile
                $credentials = $null
                try {
                    $bootstrapArguments = @('servenest:bootstrap-platform-admin', $protectedAdministratorFile)
                    if ($rotateAdministrator) { $bootstrapArguments += '--rotate-existing' } else { $bootstrapArguments += '--ensure' }
                    Invoke-Artisan $bootstrapArguments 'The platform administrator could not be created. Review the protected Laravel log under api\storage\logs.'
                } finally {
                    $adminInput.password = $null
                    Remove-SensitiveFile $protectedAdministratorFile
                    $protectedAdministratorFile = $null
                }
            }
            # Reference data (permissions, units, discount types, settings).
            # Idempotent; simulation fixtures stay disabled in production.
            Invoke-Artisan @('db:seed', '--force') 'The Back Office reference data could not be seeded.'
        }

        & $script:Php '-c' $script:PhpIni 'artisan' 'storage:link' '--no-interaction'
        if ($LASTEXITCODE -ne 0 -and -not (Test-Path -LiteralPath (Join-Path $api 'public\storage'))) {
            throw 'Laravel could not link public storage.'
        }
        Invoke-Artisan @('optimize:clear') 'Laravel could not clear its caches.'
        Invoke-Artisan @('config:cache') 'Laravel could not cache its configuration.'
    } finally {
        Pop-Location
        if ($protectedAdministratorFile) { Remove-SensitiveFile $protectedAdministratorFile }
    }

    # ------------------------------------------------- services and network ---
    & (Join-Path $scripts 'Install-ServeNestServices.ps1') `
        -InstallRoot $installRoot `
        -InstallProfile $installProfile `
        -PhpPort $phpPort

    Get-NetFirewallRule -DisplayName $layout.FirewallRule -ErrorAction SilentlyContinue |
        Where-Object { $_.Group -eq $layout.FirewallGroup } |
        Remove-NetFirewallRule
    if ($installProfile -eq 'edge') {
        # LAN tills only. Shop PCs are often domain-joined, so the Domain
        # profile is included alongside Private.
        New-NetFirewallRule -DisplayName $layout.FirewallRule -Group $layout.FirewallGroup `
            -Direction Inbound -Action Allow -Protocol TCP -LocalPort $uiPort `
            -Profile Domain, Private -RemoteAddress LocalSubnet | Out-Null
    } else {
        # Shop POS servers connect from their own networks or a VPN.
        New-NetFirewallRule -DisplayName $layout.FirewallRule -Group $layout.FirewallGroup `
            -Direction Inbound -Action Allow -Protocol TCP -LocalPort $uiPort `
            -Profile Domain, Private | Out-Null
    }

    $trustedThumbprint = if ($metadata) { [string] $metadata.trusted_certificate_thumbprint } else { '' }
    if ($installProfile -eq 'cloud') {
        $trustLocally = $tlsMode -eq 'self_signed' -and (Get-BooleanProperty $configuration 'trust_certificate_locally' $true)
        $store = [Security.Cryptography.X509Certificates.X509Store]::new('Root', 'LocalMachine')
        $store.Open('ReadWrite')
        try {
            $current = if ($trustLocally) {
                [Security.Cryptography.X509Certificates.X509Certificate2]::new($installedCertificate)
            } else {
                $null
            }
            if ($trustedThumbprint -and (-not $current -or $current.Thumbprint -ne $trustedThumbprint)) {
                foreach ($old in $store.Certificates.Find('FindByThumbprint', $trustedThumbprint, $false)) { $store.Remove($old) }
                $trustedThumbprint = ''
            }
            if ($current) {
                $store.Add($current)
                $trustedThumbprint = $current.Thumbprint
            }
        } finally {
            $store.Close()
        }
    }

    # Recorded before the health check, which reads it.
    $installationMetadata = [ordered]@{
        profile = $installProfile
        edition = $edition
        version = [string] $bundle.version
        site_name = $siteName
        site_host = $siteHost
        ui_port = $uiPort
        php_port = $phpPort
        database_service = $layout.DatabaseService
        database_port = $databasePort
        database_name = $databaseName
        database_user = $databaseUser
        front_url = if ($installProfile -eq 'edge') { "$appOrigin/" } else { $null }
        back_office_url = if ($installProfile -eq 'edge') { $backOfficeUrl } else { $appOrigin }
        tls_mode = $tlsMode
        public_url = if ($tlsMode -eq 'proxy') { $appOrigin } else { $null }
        trusted_certificate_thumbprint = $trustedThumbprint
        installed_at = [DateTime]::UtcNow.ToString('o')
    }
    Write-Utf8File (Join-Path $configDirectory 'installation.json') ($installationMetadata | ConvertTo-Json)

    & (Join-Path $scripts 'Test-ServeNestInstallation.ps1') -InstallRoot $installRoot -InstallProfile $installProfile

    # -------------------------------------------- shop connection hand-off ---
    if ($installProfile -eq 'cloud') {
        $shopTrustPath = Join-Path $tlsDirectory 'shop-trust.pem'
        $connectionDocument = [ordered]@{
            schema_version = 1
            kind = 'servenest-shop-connection'
            back_office_name = $siteName
            back_office_url = $appOrigin
            tls_trust_pem = if ($tlsMode -ne 'proxy' -and (Test-Path -LiteralPath $shopTrustPath -PathType Leaf)) {
                [IO.File]::ReadAllText($shopTrustPath)
            } else {
                $null
            }
            license = [ordered]@{
                algorithm = $licenseAlgorithm
                key_id = $licenseKeyId
                public_key = [IO.File]::ReadAllText($licensePublicPath)
            }
            login_code_lookup = [ordered]@{
                key_id = $lookupKeyId
                key = $lookupKey
            }
            issued_at = [DateTime]::UtcNow.ToString('o')
        }
        $connectionJson = $connectionDocument | ConvertTo-Json -Depth 5
        $connectionPath = Join-Path $secretsDirectory 'shop-connection.servenest-foh'
        Write-Utf8File $connectionPath $connectionJson
        Set-RestrictedFileAcl $connectionPath

        $copyPath = Get-OptionalProperty $configuration 'shop_connection_copy_path'
        if ($copyPath) {
            # The operator copies this to each shop POS server. It carries the
            # login-code lookup key, so only the installing account can read it.
            Write-Utf8File $copyPath $connectionJson
            Set-RestrictedFileAcl $copyPath ([Security.Principal.WindowsIdentity]::GetCurrent().User.Value)
            Write-Host "Saved the shop connection file to $copyPath"
        }
        $connectionJson = $null
        $connectionDocument = $null
    }

    if (Test-Path -LiteralPath $databaseBootstrapState -PathType Leaf) {
        Remove-SensitiveFile $databaseBootstrapState
    }
    Write-Host "$($layout.Product) installation completed. Database administrator recovery details are under $secretsDirectory."
    Stop-Transcript | Out-Null
} catch {
    $installFailure = $_
    $installFailure | Out-String | Add-Content -LiteralPath $errorLog
    $rollbackFailure = $null
    foreach ($name in $servicesToRestore) {
        try {
            Start-ServiceAndWait $name
        } catch {
            $rollbackFailure = $_
            "Could not restart $name`: $($_.Exception.Message)" | Add-Content -LiteralPath $errorLog
        }
    }
    Stop-Transcript -ErrorAction SilentlyContinue | Out-Null
    if ($rollbackFailure) {
        throw "ServeNest installation failed ($($installFailure.Exception.Message)); some previously running services could not be restarted."
    }
    throw $installFailure
} finally {
    if (Test-Path -LiteralPath $stagingDirectory) {
        foreach ($file in Get-ChildItem -LiteralPath $stagingDirectory -Recurse -File -Force -ErrorAction SilentlyContinue) {
            Remove-SensitiveFile $file.FullName
        }
        Remove-Item -LiteralPath $stagingDirectory -Recurse -Force -ErrorAction SilentlyContinue
    }
    $configuration = $null
    $applicationPassword = $null
    $administratorPassword = $null
    $bootstrapApplicationPassword = $null
    $bootstrapAdministratorPassword = $null
    $lookupKey = $null
}
