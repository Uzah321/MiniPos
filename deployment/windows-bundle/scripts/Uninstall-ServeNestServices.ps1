[CmdletBinding(SupportsShouldProcess = $true)]
param(
    # Defaults to the installation that contains this script.
    [string] $InstallRoot = '',

    [ValidateSet('auto', 'edge', 'cloud', 'till')]
    [Alias('Profile')]
    [string] $InstallProfile = 'auto',

    [switch] $KeepDatabaseService
)

# Removes the Windows services, firewall rule and locally trusted certificate
# of one installation. Data, secrets, .env, storage and backups are preserved.
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ServeNest.Common.ps1')

Assert-Administrator 'Service removal'
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
    if (-not $metadata) {
        Write-Host 'No installation metadata was found; there are no recorded ServeNest services to remove.'
        exit 0
    }
    $InstallProfile = ([string] $metadata.profile).ToLowerInvariant()
}
if ($InstallProfile -eq 'till') {
    Write-Host 'An additional till has no local ServeNest services to remove.'
    exit 0
}

$layout = Get-ServeNestLayout (Get-ServeNestEditionForProfile $InstallProfile)
$applicationServices = @(Get-ServeNestApplicationServices $layout)

# Validate ownership of every target before stopping or deleting any service.
$owned = [Collections.Generic.List[string]]::new()
foreach ($name in $applicationServices) {
    if (Assert-OwnedApplicationService $layout $root $name) { $owned.Add($name) }
}
$ownsDatabase = (-not $KeepDatabaseService) -and [bool] (Assert-OwnedDatabaseService $layout $root)

foreach ($name in $owned) {
    if ($PSCmdlet.ShouldProcess($name, 'Stop and remove Windows service')) {
        Stop-ServiceAndWait $name
        & $layout.Nssm remove $name confirm | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "NSSM failed to remove $name." }
    }
}
if ($ownsDatabase -and $PSCmdlet.ShouldProcess($layout.DatabaseService, 'Stop and unregister the database service (data is kept)')) {
    Stop-ServiceAndWait $layout.DatabaseService
    & sc.exe delete $layout.DatabaseService | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Windows failed to remove $($layout.DatabaseService)." }
}

Get-NetFirewallRule -DisplayName $layout.FirewallRule -ErrorAction SilentlyContinue |
    Where-Object { $_.Group -eq $layout.FirewallGroup } |
    ForEach-Object {
        if ($PSCmdlet.ShouldProcess($_.DisplayName, 'Remove ServeNest firewall rule')) {
            Remove-NetFirewallRule -Name $_.Name
        }
    }

$thumbprint = if ($metadata) { [string] $metadata.trusted_certificate_thumbprint } else { '' }
if ($thumbprint -match '^[0-9A-Fa-f]{40}$' -and
    $PSCmdlet.ShouldProcess($thumbprint, 'Remove the locally trusted Back Office certificate')) {
    $store = [Security.Cryptography.X509Certificates.X509Store]::new('Root', 'LocalMachine')
    $store.Open('ReadWrite')
    try {
        foreach ($certificate in $store.Certificates.Find('FindByThumbprint', $thumbprint, $false)) {
            $store.Remove($certificate)
        }
    } finally {
        $store.Close()
    }
}

Write-Host "$($layout.Product) services were removed. Data, secrets, .env, storage and backups were preserved."
