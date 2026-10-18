[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $InstallRoot,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 65535)]
    [int] $Port,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9_]+$')]
    [string] $DatabaseName,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9_]+$')]
    [string] $ApplicationUser,

    [Parameter(Mandatory = $true)]
    [string] $ApplicationPassword,

    [Parameter(Mandatory = $true)]
    [string] $AdministratorPassword,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^ServeNest(FOH|BOH)Database$')]
    [string] $ServiceName
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ServeNest.Common.ps1')

function Escape-SqlString([string] $Value) {
    return $Value.Replace('\', '\\').Replace("'", "''")
}

function Install-DatabaseService([string] $Executable, [string] $Name, [string] $DefaultsFile) {
    & $Executable '--install' $Name "--defaults-file=$DefaultsFile"
    if ($LASTEXITCODE -ne 0) {
        throw 'MariaDB service registration failed.'
    }
}

function Wait-ServiceRegistrationRemoved([string] $Name, [int] $Seconds = 15) {
    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    do {
        if (-not (Get-ServiceRegistration $Name)) { return }
        Start-Sleep -Milliseconds 250
    } while ([DateTime]::UtcNow -lt $deadline)
    throw "Windows service $Name remained registered after removal."
}

function Set-ServiceStartupMode([string] $Name, [string] $StartMode) {
    switch ($StartMode) {
        'Auto' { Set-Service -Name $Name -StartupType Automatic }
        'Disabled' { Set-Service -Name $Name -StartupType Disabled }
        default { Set-Service -Name $Name -StartupType Manual }
    }
}

function Restore-DatabaseServiceRegistration(
    [string] $Executable,
    [string] $Name,
    [AllowNull()]
    [string] $OriginalDefaultsFile,
    [string] $OriginalStartMode,
    [bool] $OriginalWasRunning
) {
    $current = Get-ServiceRegistration $Name
    $currentIsOriginal = $false
    if ($current) {
        $currentIdentity = Get-ServiceCommandIdentity $current.PathName
        if (-not (Test-SamePath $currentIdentity.Executable $Executable)) {
            throw "Windows service name $Name was claimed by another executable during rollback."
        }
        $currentIsOriginal = if ($OriginalDefaultsFile) {
            Test-SamePath $currentIdentity.DefaultsFile $OriginalDefaultsFile
        } else {
            -not $currentIdentity.DefaultsFile
        }
    }

    if (-not $currentIsOriginal) {
        if ($current) {
            Stop-ServiceAndWait $Name
            & $Executable '--remove' $Name
            if ($LASTEXITCODE -ne 0) {
                throw 'The failed replacement database service could not be removed during rollback.'
            }
            Wait-ServiceRegistrationRemoved $Name
        }
        if ($OriginalDefaultsFile) {
            Install-DatabaseService $Executable $Name $OriginalDefaultsFile
        } else {
            & $Executable '--install' $Name
            if ($LASTEXITCODE -ne 0) {
                throw 'The original database service registration could not be restored.'
            }
        }
    }

    $restored = Get-ServiceRegistration $Name
    $restoredIdentity = if ($restored) { Get-ServiceCommandIdentity $restored.PathName } else { $null }
    $restoredDefaultsMatch = if ($OriginalDefaultsFile -and $restoredIdentity) {
        Test-SamePath $restoredIdentity.DefaultsFile $OriginalDefaultsFile
    } elseif ($restoredIdentity) {
        -not $restoredIdentity.DefaultsFile
    } else {
        $false
    }
    if ((-not $restored) -or
        (-not (Test-SamePath $restoredIdentity.Executable $Executable)) -or
        (-not $restoredDefaultsMatch)) {
        throw 'The previous database service registration could not be verified after rollback.'
    }

    Set-ServiceStartupMode $Name $OriginalStartMode
    if ($OriginalWasRunning) {
        Start-ServiceAndWait $Name
    } else {
        Stop-ServiceAndWait $Name
    }
}

function Remove-InstallerStagingDirectory([string] $Path, [string] $Parent) {
    if (-not $Path -or -not (Test-Path -LiteralPath $Path -PathType Container)) { return }
    $resolvedParent = [IO.Path]::GetFullPath($Parent).TrimEnd('\') + '\'
    $resolvedPath = [IO.Path]::GetFullPath($Path)
    $leaf = Split-Path $resolvedPath -Leaf
    if ((-not $resolvedPath.StartsWith($resolvedParent, [StringComparison]::OrdinalIgnoreCase)) -or
        ($leaf -notmatch '^\.database-bootstrap-[0-9a-f]{32}$')) {
        throw "Refusing to remove an unverified database staging directory: $resolvedPath"
    }
    Remove-Item -LiteralPath $resolvedPath -Recurse -Force
}

function Invoke-RootProvisioning([string] $Client, [int] $TcpPort, [string] $Sql, [AllowNull()] [string] $Password) {
    if ($null -eq $Password) {
        Remove-Item -LiteralPath Env:MYSQL_PWD -ErrorAction SilentlyContinue
    } else {
        $env:MYSQL_PWD = $Password
    }
    $Sql | & $Client '--protocol=tcp' '--host=127.0.0.1' "--port=$TcpPort" '--user=root' '--batch'
    return $LASTEXITCODE -eq 0
}

function Assert-LoopbackListener([int] $TcpPort, [string] $Name) {
    $registration = Get-ServiceRegistration $Name
    $serviceProcessId = if ($registration) { [int] $registration.ProcessId } else { 0 }
    $listeners = @(Get-NetTCPConnection -LocalPort $TcpPort -State Listen -ErrorAction SilentlyContinue)
    $unsafe = @($listeners | Where-Object {
        $_.LocalAddress -notin @('127.0.0.1', '::1') -or [int] $_.OwningProcess -ne $serviceProcessId
    })
    if ((-not $registration) -or $serviceProcessId -le 0 -or $listeners.Count -eq 0 -or $unsafe.Count -gt 0) {
        Stop-Service -Name $Name -Force -ErrorAction SilentlyContinue
        throw "The database service does not exclusively own a loopback listener on TCP port $TcpPort."
    }
}

Assert-Administrator 'Database initialization'
foreach ($pair in @(
    @{ Name = 'ApplicationPassword'; Value = $ApplicationPassword },
    @{ Name = 'AdministratorPassword'; Value = $AdministratorPassword }
)) {
    if ([string]::IsNullOrWhiteSpace($pair.Value) -or $pair.Value -match "[`r`n]") {
        throw "$($pair.Name) must be a non-empty single-line value."
    }
}

$root = [IO.Path]::GetFullPath($InstallRoot)
$databaseRoot = Join-Path $root 'runtime\database'
$bin = Join-Path $databaseRoot 'bin'
$mysqld = Join-Path $bin 'mysqld.exe'
$mysql = @((Join-Path $bin 'mariadb.exe'), (Join-Path $bin 'mysql.exe')) |
    Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
$installDb = @((Join-Path $bin 'mariadb-install-db.exe'), (Join-Path $bin 'mysql_install_db.exe')) |
    Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
if (-not (Test-Path -LiteralPath $mysqld -PathType Leaf) -or -not $mysql) {
    throw 'The bundled database runtime must contain bin\mysqld.exe and bin\mariadb.exe or bin\mysql.exe.'
}

$dataParent = Join-Path $root 'data'
$dataPath = Join-Path $dataParent 'database'
$configPath = Join-Path $root 'config\database.ini'
$secretsPath = Join-Path $root 'secrets'
New-Item -ItemType Directory -Force -Path $dataParent, (Split-Path $configPath), $secretsPath | Out-Null
Set-RestrictedDirectoryAcl $dataParent
if (-not (Test-Path -LiteralPath $dataPath -PathType Container)) {
    New-Item -ItemType Directory -Path $dataPath | Out-Null
}

$dataInitialized = (Get-ChildItem -LiteralPath $dataPath -Force -ErrorAction SilentlyContinue | Measure-Object).Count -gt 0
$newDatabase = -not $dataInitialized
$adminSecretPath = Join-Path $secretsPath 'database-admin.txt'
$bootstrapStatePath = Join-Path $secretsPath 'database-bootstrap.json'
$bootstrapRecovery = $false
if (-not (Test-Path -LiteralPath $adminSecretPath -PathType Leaf)) {
    if (-not (Test-Path -LiteralPath $bootstrapStatePath -PathType Leaf)) {
        throw 'Database bootstrap has no protected recovery state; refusing an unrecoverable initialization.'
    }
    Set-RestrictedFileAcl $bootstrapStatePath
    try {
        $bootstrapState = [IO.File]::ReadAllText($bootstrapStatePath) | ConvertFrom-Json
    } catch {
        throw 'The protected database bootstrap recovery state is unreadable.'
    }
    if ([int] $bootstrapState.schema_version -ne 2 -or
        (-not ($bootstrapState.created_for_empty_database -is [bool])) -or
        $bootstrapState.created_for_empty_database -ne $true -or
        [int] $bootstrapState.database_port -ne $Port -or
        [string] $bootstrapState.database_name -cne $DatabaseName -or
        [string] $bootstrapState.database_user -cne $ApplicationUser -or
        [string] $bootstrapState.application_password -cne $ApplicationPassword -or
        [string] $bootstrapState.administrator_password -cne $AdministratorPassword) {
        throw 'The protected database bootstrap recovery state does not match this initialization request.'
    }
    $bootstrapRecovery = -not $newDatabase
}

# Validate ownership before writing configuration, initializing data, stopping
# a live service, or changing any service registration.
$registration = Get-ServiceRegistration $ServiceName
$registeredCommand = $null
$originalStartMode = $null
$originalWasRunning = $false
if ($registration) {
    $registeredCommand = Get-ServiceCommandIdentity $registration.PathName
    if (-not (Test-SamePath $registeredCommand.Executable $mysqld)) {
        throw "Windows service name $ServiceName is owned by another database installation."
    }
    $originalStartMode = [string] $registration.StartMode
    $originalWasRunning = (Get-Service -Name $ServiceName -ErrorAction Stop).Status -eq 'Running'
}

$iniRoot = $databaseRoot.Replace('\', '/')
$iniData = $dataPath.Replace('\', '/')
$ini = @"
[client]
port=$Port
host=127.0.0.1

[mysqld]
port=$Port
bind-address=127.0.0.1
basedir=$iniRoot
datadir=$iniData
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
skip-name-resolve
max_allowed_packet=100M
"@
Write-Utf8File $configPath $ini
Set-RestrictedFileAcl $configPath

if (-not $dataInitialized) {
    $stagingPath = Join-Path $dataParent ('.database-bootstrap-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $stagingPath | Out-Null
    Set-RestrictedDirectoryAcl $stagingPath
    try {
        if ($installDb) {
            # Initialize files only. Service registration is deliberately
            # separate and always uses the generated loopback-only defaults.
            & $installDb "--datadir=$stagingPath" "--port=$Port" '--silent'
            if ($LASTEXITCODE -ne 0) { throw 'MariaDB initialization failed.' }
        } else {
            & $mysqld "--defaults-file=$configPath" "--datadir=$stagingPath" '--initialize-insecure'
            if ($LASTEXITCODE -ne 0) { throw 'MySQL initialization failed.' }
        }
        if ((Get-ChildItem -LiteralPath $stagingPath -Force -ErrorAction Stop | Measure-Object).Count -eq 0) {
            throw 'Database initialization produced no data files.'
        }
        if ((Get-ChildItem -LiteralPath $dataPath -Force -ErrorAction Stop | Measure-Object).Count -ne 0) {
            throw 'The target database directory changed during initialization; refusing to replace it.'
        }
        Remove-Item -LiteralPath $dataPath -Force
        Move-Item -LiteralPath $stagingPath -Destination $dataPath
        $stagingPath = $null
        $dataInitialized = $true
    } finally {
        if ($stagingPath) {
            Remove-InstallerStagingDirectory $stagingPath $dataParent
        }
    }
}

$databaseServiceReady = $false
if ($registration -and -not (Test-SamePath $registeredCommand.DefaultsFile $configPath)) {
    try {
        Stop-ServiceAndWait $ServiceName
        & $mysqld '--remove' $ServiceName
        if ($LASTEXITCODE -ne 0) {
            throw 'The existing ServeNest database service could not be removed for safe re-registration.'
        }
        Wait-ServiceRegistrationRemoved $ServiceName
        Install-DatabaseService $mysqld $ServiceName $configPath
        $newRegistration = Get-ServiceRegistration $ServiceName
        $newIdentity = if ($newRegistration) { Get-ServiceCommandIdentity $newRegistration.PathName } else { $null }
        if ((-not $newRegistration) -or
            (-not (Test-SamePath $newIdentity.Executable $mysqld)) -or
            (-not (Test-SamePath $newIdentity.DefaultsFile $configPath))) {
            throw 'The replacement database service did not retain the generated defaults file.'
        }
        Set-Service -Name $ServiceName -StartupType Automatic
        Start-ServiceAndWait $ServiceName
        Wait-TcpPort $Port
        Assert-LoopbackListener $Port $ServiceName
        $databaseServiceReady = $true
    } catch {
        $reconfigurationFailure = $_
        try {
            Restore-DatabaseServiceRegistration `
                -Executable $mysqld `
                -Name $ServiceName `
                -OriginalDefaultsFile $registeredCommand.DefaultsFile `
                -OriginalStartMode $originalStartMode `
                -OriginalWasRunning $originalWasRunning
        } catch {
            throw "Database service re-registration failed ($($reconfigurationFailure.Exception.Message)); rollback also failed: $($_.Exception.Message) Manual recovery is required before retrying."
        }
        throw "Database service re-registration failed ($($reconfigurationFailure.Exception.Message)); its previous registration and running state were restored."
    }
} elseif (-not $registration) {
    Install-DatabaseService $mysqld $ServiceName $configPath
} else {
    Stop-ServiceAndWait $ServiceName
}

if (-not $databaseServiceReady) {
    $registration = Get-ServiceRegistration $ServiceName
    $registeredCommand = if ($registration) { Get-ServiceCommandIdentity $registration.PathName } else { $null }
    if ((-not $registration) -or
        (-not (Test-SamePath $registeredCommand.Executable $mysqld)) -or
        (-not (Test-SamePath $registeredCommand.DefaultsFile $configPath))) {
        throw 'The ServeNest database service is not registered with its generated defaults file.'
    }
    Set-Service -Name $ServiceName -StartupType Automatic
    Start-ServiceAndWait $ServiceName
    Wait-TcpPort $Port
    Assert-LoopbackListener $Port $ServiceName
}

$databaseEscaped = $DatabaseName.Replace('`', '``')
$userEscaped = $ApplicationUser.Replace("'", "''")
$appPasswordEscaped = Escape-SqlString $ApplicationPassword
$adminPasswordEscaped = Escape-SqlString $AdministratorPassword
$isFresh = $newDatabase -or $bootstrapRecovery

$sql = @"
CREATE DATABASE IF NOT EXISTS ``$databaseEscaped`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$userEscaped'@'127.0.0.1' IDENTIFIED BY '$appPasswordEscaped';
ALTER USER '$userEscaped'@'127.0.0.1' IDENTIFIED BY '$appPasswordEscaped';
GRANT ALL PRIVILEGES ON ``$databaseEscaped``.* TO '$userEscaped'@'127.0.0.1';
FLUSH PRIVILEGES;
"@
if ($isFresh) {
    $sql += "`nALTER USER 'root'@'localhost' IDENTIFIED BY '$adminPasswordEscaped';`n"
}

$hadPreviousPassword = Test-Path -LiteralPath Env:MYSQL_PWD
$previousPassword = $env:MYSQL_PWD
try {
    if ($bootstrapRecovery) {
        $provisioned = Invoke-RootProvisioning $mysql $Port $sql $AdministratorPassword
        if (-not $provisioned) {
            $provisioned = Invoke-RootProvisioning $mysql $Port $sql $null
        }
    } elseif ($newDatabase) {
        $provisioned = Invoke-RootProvisioning $mysql $Port $sql $null
    } else {
        $provisioned = Invoke-RootProvisioning $mysql $Port $sql $AdministratorPassword
    }
    if (-not $provisioned) { throw 'Database/user provisioning failed.' }
} finally {
    if ($hadPreviousPassword) {
        $env:MYSQL_PWD = $previousPassword
    } else {
        Remove-Item -LiteralPath Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
}

if (-not (Test-Path -LiteralPath $adminSecretPath)) {
    Write-Utf8File $adminSecretPath "Service=$ServiceName`r`nHost=127.0.0.1`r`nPort=$Port`r`nUser=root`r`nPassword=$AdministratorPassword`r`n"
}
Set-RestrictedFileAcl $adminSecretPath

$bootstrapState = $null
$ApplicationPassword = $null
$AdministratorPassword = $null

Write-Host "Database service $ServiceName is ready on 127.0.0.1:$Port."
