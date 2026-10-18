# Shared helpers for the ServeNest Windows installer scripts.
# Dot-source from a script in the same folder:
#   . (Join-Path $PSScriptRoot 'ServeNest.Common.ps1')

Set-StrictMode -Off

$script:SystemSid = 'S-1-5-18'
$script:AdministratorsSid = 'S-1-5-32-544'

function Get-ServeNestLayout {
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet('FOH', 'BOH')]
        [string] $Edition
    )

    # Each edition owns its service names and runtime folders, so a Front of
    # House server and a Back Office can share one test PC without either
    # installer touching the other's services.
    $prefix = "ServeNest$Edition"
    $isFoh = $Edition -eq 'FOH'
    # php-cgi on Windows serves one request at a time, so each edition runs a
    # small pool behind an nginx least_conn upstream. A long Back Office
    # request (a shop Day End) then cannot block every other caller.
    $phpWorkers = if ($isFoh) { 2 } else { 4 }
    return [pscustomobject]@{
        Edition = $Edition
        Product = if ($isFoh) { 'ServeNest Front of House' } else { 'ServeNest Back Office' }
        ServicePrefix = $prefix
        NginxRoot = "C:\nginx\$prefix"
        NssmRoot = "C:\nssm\$prefix"
        Nssm = "C:\nssm\$prefix\win64\nssm.exe"
        PhpRoot = "C:\php\$prefix"
        PhpMyAdminRoot = "C:\phpMyAdmin\$prefix"
        DefaultUiPort = if ($isFoh) { 8083 } else { 8082 }
        DefaultPhpPort = if ($isFoh) { 9301 } else { 9401 }
        DefaultDatabasePort = if ($isFoh) { 3307 } else { 3308 }
        PhpWorkerCount = $phpWorkers
        DatabaseService = "${prefix}Database"
        PhpServices = @(1..$phpWorkers | ForEach-Object { "${prefix}PHP$_" })
        QueueService = "${prefix}Queue"
        SyncService = if ($isFoh) { "${prefix}Sync" } else { $null }
        SchedulerService = if ($isFoh) { $null } else { "${prefix}Scheduler" }
        NginxService = "${prefix}Nginx"
        WebDirectory = if ($isFoh) { 'web\front' } else { 'web\back' }
        PhpMyAdminPort = if ($isFoh) { 8081 } else { 8084 }
        FirewallGroup = 'ServeNest'
        FirewallRule = if ($isFoh) { 'ServeNest POS Front Office' } else { 'ServeNest Back Office' }
    }
}

function Get-ServeNestEditionForProfile([string] $InstallProfile) {
    switch ($InstallProfile) {
        'edge' { return 'FOH' }
        'till' { return 'FOH' }
        'cloud' { return 'BOH' }
        default { throw "Unsupported ServeNest installation profile: $InstallProfile" }
    }
}

# Application services in stop order: the web front first, the database last
# (the database is handled separately by its own ownership rules).
function Get-ServeNestApplicationServices($Layout) {
    return @(
        @($Layout.NginxService, $Layout.SyncService, $Layout.SchedulerService, $Layout.QueueService) +
        $Layout.PhpServices
    ) | Where-Object { $_ }
}

function Assert-Administrator([string] $Activity = 'This ServeNest installer step') {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw "$Activity must run from an elevated process."
    }
}

function Get-ServiceRegistration([string] $Name) {
    $escaped = $Name.Replace("'", "''")
    return Get-CimInstance -ClassName Win32_Service -Filter "Name='$escaped'" -ErrorAction SilentlyContinue
}

function Get-ServiceCommandIdentity([string] $CommandLine) {
    $executableMatch = [regex]::Match([string] $CommandLine, '^\s*(?:"(?<quoted>[^"]+)"|(?<bare>\S+))')
    $defaultsMatch = [regex]::Match(
        [string] $CommandLine,
        '(?i)(?:^|\s)--defaults-file=(?:"(?<quoted>[^"]+)"|(?<bare>\S+))'
    )
    $executable = if ($executableMatch.Groups['quoted'].Success) {
        $executableMatch.Groups['quoted'].Value
    } else {
        $executableMatch.Groups['bare'].Value
    }
    $defaultsFile = if ($defaultsMatch.Groups['quoted'].Success) {
        $defaultsMatch.Groups['quoted'].Value
    } else {
        $defaultsMatch.Groups['bare'].Value
    }
    return [pscustomobject]@{
        Executable = if ($executable) { [IO.Path]::GetFullPath($executable) } else { $null }
        DefaultsFile = if ($defaultsFile) { [IO.Path]::GetFullPath($defaultsFile) } else { $null }
    }
}

function Test-SamePath([string] $First, [string] $Second) {
    if (-not $First -or -not $Second) { return $false }
    return [IO.Path]::GetFullPath($First).TrimEnd('\').Equals(
        [IO.Path]::GetFullPath($Second).TrimEnd('\'),
        [StringComparison]::OrdinalIgnoreCase
    )
}

# NSSM writes UTF-16LE when its output is redirected. Reading that through
# PowerShell's console decoding interleaves NUL characters into every value,
# which made [IO.Path]::GetFullPath throw "Illegal characters in path" during
# a repair. Decode the raw bytes instead of trusting the console encoding.
function ConvertFrom-NssmOutput([byte[]] $Bytes) {
    if (-not $Bytes -or $Bytes.Length -eq 0) { return '' }

    $offset = 0
    $utf16 = $false
    if ($Bytes.Length -ge 2 -and $Bytes[0] -eq 0xFF -and $Bytes[1] -eq 0xFE) {
        $offset = 2
        $utf16 = $true
    } elseif ($Bytes.Length -ge 2) {
        $oddZeros = 0
        for ($index = 1; $index -lt $Bytes.Length; $index += 2) {
            if ($Bytes[$index] -eq 0) { $oddZeros++ }
        }
        $utf16 = $oddZeros -gt 0 -and $oddZeros -ge [Math]::Floor($Bytes.Length / 4)
    }

    $text = if ($utf16) {
        [Text.Encoding]::Unicode.GetString($Bytes, $offset, $Bytes.Length - $offset)
    } else {
        [Text.Encoding]::Default.GetString($Bytes)
    }
    return $text.Replace([string] [char] 0, '').Replace([string] [char] 0xFEFF, '').Trim().Trim('"').Trim()
}

function Invoke-NssmGet([string] $Nssm, [string] $Name, [string] $Parameter) {
    $info = [Diagnostics.ProcessStartInfo]::new($Nssm)
    $info.Arguments = 'get "' + $Name + '" ' + $Parameter
    $info.UseShellExecute = $false
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.CreateNoWindow = $true
    $process = [Diagnostics.Process]::Start($info)
    try {
        $errorReader = $process.StandardError.ReadToEndAsync()
        $buffer = [IO.MemoryStream]::new()
        $process.StandardOutput.BaseStream.CopyTo($buffer)
        $process.WaitForExit()
        [void] $errorReader.Result
        if ($process.ExitCode -ne 0) { return $null }
        return ConvertFrom-NssmOutput $buffer.ToArray()
    } finally {
        $process.Dispose()
    }
}

function Get-ExpectedServiceApplication($Layout, [string] $Name) {
    if ($Name -eq $Layout.NginxService) { return Join-Path $Layout.NginxRoot 'nginx.exe' }
    if ($Name -in $Layout.PhpServices) { return Join-Path $Layout.PhpRoot 'php-cgi.exe' }
    return Join-Path $Layout.PhpRoot 'php.exe'
}

# Returns the registration of an existing service that provably belongs to
# this installation, $null when the service does not exist, and throws when
# the name is used by anything else. Nothing is stopped or changed here.
function Assert-OwnedApplicationService($Layout, [string] $Root, [string] $Name) {
    $registration = Get-ServiceRegistration $Name
    if (-not $registration) {
        if (Get-Service -Name $Name -ErrorAction SilentlyContinue) {
            throw "Windows service $Name could not be inspected; refusing to change it."
        }
        return $null
    }

    $identity = Get-ServiceCommandIdentity $registration.PathName
    if (-not (Test-SamePath $identity.Executable $Layout.Nssm)) {
        throw "Windows service $Name belongs to another installation; refusing to change it."
    }
    if (-not (Test-Path -LiteralPath $Layout.Nssm -PathType Leaf)) {
        throw "NSSM is missing from $($Layout.NssmRoot); refusing to change $Name without verifying it."
    }

    $application = Invoke-NssmGet $Layout.Nssm $Name 'Application'
    if (-not $application) {
        throw "Windows service $Name application could not be inspected; refusing to change it."
    }
    if (-not (Test-SamePath $application (Get-ExpectedServiceApplication $Layout $Name))) {
        throw "Windows service $Name runs a different application; refusing to change it."
    }

    $directory = Invoke-NssmGet $Layout.Nssm $Name 'AppDirectory'
    $expectedDirectory = if ($Name -eq $Layout.NginxService) { $Layout.NginxRoot } else { Join-Path $Root 'api' }
    if (-not (Test-SamePath $directory $expectedDirectory)) {
        throw "Windows service $Name is not bound to $Root; refusing to change it."
    }

    if ($Name -eq $Layout.NginxService) {
        $arguments = Invoke-NssmGet $Layout.Nssm $Name 'AppParameters'
        $expectedConfig = [IO.Path]::GetFullPath((Join-Path $Root 'config\nginx.conf')).Replace('\', '/')
        if (-not $arguments -or
            $arguments.Replace('\', '/').IndexOf($expectedConfig, [StringComparison]::OrdinalIgnoreCase) -lt 0) {
            throw "$Name does not use this installation's configuration; refusing to change it."
        }
    }
    return $registration
}

function Assert-OwnedDatabaseService($Layout, [string] $Root) {
    $registration = Get-ServiceRegistration $Layout.DatabaseService
    if (-not $registration) {
        if (Get-Service -Name $Layout.DatabaseService -ErrorAction SilentlyContinue) {
            throw "Windows service $($Layout.DatabaseService) could not be inspected; refusing to change it."
        }
        return $null
    }
    $identity = Get-ServiceCommandIdentity $registration.PathName
    if (-not (Test-SamePath $identity.Executable (Join-Path $Root 'runtime\database\bin\mysqld.exe'))) {
        throw "Windows service $($Layout.DatabaseService) is owned by another database installation."
    }
    if ($identity.DefaultsFile -and
        -not (Test-SamePath $identity.DefaultsFile (Join-Path $Root 'config\database.ini'))) {
        throw "Windows service $($Layout.DatabaseService) uses a defaults file outside $Root."
    }
    return $registration
}

function Stop-ServiceAndWait([string] $Name) {
    $service = Get-Service -Name $Name -ErrorAction Stop
    if ($service.Status -ne 'Stopped') {
        Stop-Service -Name $Name -Force -ErrorAction Stop
        $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(45))
    }
}

function Start-ServiceAndWait([string] $Name) {
    $service = Get-Service -Name $Name -ErrorAction Stop
    $service.Refresh()
    if ($service.Status -ne 'Running') {
        Start-Service -Name $Name -ErrorAction Stop
        $service.WaitForStatus('Running', [TimeSpan]::FromSeconds(45))
    }
}

function New-RestrictedRule([string] $Sid, [bool] $Directory) {
    $identity = [Security.Principal.SecurityIdentifier]::new($Sid)
    if ($Directory) {
        return [Security.AccessControl.FileSystemAccessRule]::new(
            $identity,
            [Security.AccessControl.FileSystemRights]::FullControl,
            ([Security.AccessControl.InheritanceFlags]::ContainerInherit -bor
                [Security.AccessControl.InheritanceFlags]::ObjectInherit),
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
    }
    return [Security.AccessControl.FileSystemAccessRule]::new(
        $identity,
        [Security.AccessControl.FileSystemRights]::FullControl,
        [Security.AccessControl.AccessControlType]::Allow
    )
}

function Assert-ExclusiveAcl([string] $Path, [string[]] $AllowedSids) {
    $verified = Get-Acl -LiteralPath $Path
    $rules = @($verified.GetAccessRules($true, $false, [Security.Principal.SecurityIdentifier]))
    $unexpected = @($rules | Where-Object {
        $_.IdentityReference.Value -notin $AllowedSids -or
        $_.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow
    })
    if (-not $verified.AreAccessRulesProtected -or $unexpected.Count -gt 0 -or $rules.Count -ne $AllowedSids.Count) {
        throw "Failed to establish an exclusive restricted ACL on $Path"
    }
}

# Administrators and SYSTEM only; optionally one extra account (the operator
# who must later copy a hand-off file).
function Set-RestrictedFileAcl([string] $Path, [string] $AdditionalSid = '') {
    $sids = @($script:SystemSid, $script:AdministratorsSid)
    if ($AdditionalSid -and $AdditionalSid -notin $sids) { $sids += $AdditionalSid }
    $acl = [Security.AccessControl.FileSecurity]::new()
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($sid in $sids) { $acl.AddAccessRule((New-RestrictedRule $sid $false)) }
    Set-Acl -LiteralPath $Path -AclObject $acl
    Assert-ExclusiveAcl $Path $sids
}

function Set-RestrictedDirectoryAcl([string] $Path) {
    $sids = @($script:SystemSid, $script:AdministratorsSid)
    $acl = [Security.AccessControl.DirectorySecurity]::new()
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($sid in $sids) { $acl.AddAccessRule((New-RestrictedRule $sid $true)) }
    Set-Acl -LiteralPath $Path -AclObject $acl
    Assert-ExclusiveAcl $Path $sids
}

function Protect-AdministratorDirectory([string] $Path) {
    New-Item -ItemType Directory -Force -Path $Path | Out-Null
    Set-RestrictedDirectoryAcl $Path
    if (Get-ChildItem -LiteralPath $Path -Force -ErrorAction Stop | Select-Object -First 1) {
        # Make every descendant inherit only the restricted root ACL. One
        # icacls pass is far faster than Set-Acl per file over vendor trees.
        $output = & icacls.exe (Join-Path $Path '*') /reset /T /C /Q 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "Failed to reset inherited permissions under ${Path}: $($output -join ' ')"
        }
    }
}

function Write-Utf8File([string] $Path, [string] $Contents) {
    New-Item -ItemType Directory -Force -Path (Split-Path $Path -Parent) | Out-Null
    [IO.File]::WriteAllText($Path, $Contents, [Text.UTF8Encoding]::new($false))
}

function Remove-SensitiveFile([string] $Path) {
    if (-not $Path -or -not (Test-Path -LiteralPath $Path -PathType Leaf)) { return }

    # Best-effort overwrite before deletion. SSD wear levelling can only be
    # addressed by whole-volume encryption, which remains a site requirement.
    $stream = $null
    try {
        $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Write, [IO.FileShare]::None)
        $remaining = $stream.Length
        $buffer = New-Object byte[] 65536
        while ($remaining -gt 0) {
            $count = [Math]::Min([int64] $buffer.Length, $remaining)
            $stream.Write($buffer, 0, [int] $count)
            $remaining -= $count
        }
        $stream.Flush($true)
        $stream.SetLength(0)
    } finally {
        if ($stream) { $stream.Dispose() }
    }
    Remove-Item -LiteralPath $Path -Force
}

function Expand-ServeNestTemplate([string] $Source, [string] $Destination, [hashtable] $Values) {
    $contents = [IO.File]::ReadAllText($Source)
    foreach ($entry in $Values.GetEnumerator()) {
        $contents = $contents.Replace('{{' + $entry.Key + '}}', [string] $entry.Value)
    }
    if ($contents -match '\{\{[A-Z0-9_]+\}\}') {
        throw "Template $Source still contains unresolved token $($Matches[0])."
    }
    Write-Utf8File $Destination $contents
}

function New-RandomPassword([int] $Length = 36) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%_-+'
    $builder = [Text.StringBuilder]::new($Length)
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    $buffer = [byte[]]::new(1)
    $limit = [Math]::Floor(256 / $alphabet.Length) * $alphabet.Length
    try {
        while ($builder.Length -lt $Length) {
            $generator.GetBytes($buffer)
            if ($buffer[0] -ge $limit) { continue }
            [void] $builder.Append($alphabet[$buffer[0] % $alphabet.Length])
        }
    } finally {
        $generator.Dispose()
    }
    return $builder.ToString()
}

function New-RandomKey([int] $ByteCount = 32) {
    $bytes = [byte[]]::new($ByteCount)
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }
    return 'base64:' + [Convert]::ToBase64String($bytes)
}

# Reads one value from .env text, removing the quoting Configure writes.
function Get-DotEnvValue([string] $Contents, [string] $Name) {
    if (-not $Contents) { return $null }
    $match = [regex]::Match($Contents, '(?m)^' + [regex]::Escape($Name) + '=(?<value>[^\r\n]*)$')
    if (-not $match.Success) { return $null }
    $value = $match.Groups['value'].Value.Trim()
    if ($value.Length -ge 2 -and $value[0] -eq '"' -and $value[$value.Length - 1] -eq '"') {
        $value = $value.Substring(1, $value.Length - 2).Replace('\"', '"').Replace('\\', '\')
    }
    return $value
}

function Wait-TcpPort([int] $Port, [int] $Seconds = 30) {
    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    do {
        $client = [Net.Sockets.TcpClient]::new()
        try {
            $attempt = $client.ConnectAsync('127.0.0.1', $Port)
            if ($attempt.Wait(500) -and $client.Connected) { return }
        } catch {
            # Retry until the deadline.
        } finally {
            $client.Dispose()
        }
        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)
    throw "Nothing opened 127.0.0.1:$Port within $Seconds seconds."
}

function Assert-PortAvailable([int] $Port, [string[]] $OwnedServices = @()) {
    $listeners = @(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
    foreach ($listener in $listeners) {
        $owned = $false
        foreach ($name in $OwnedServices) {
            $registration = Get-ServiceRegistration $name
            if ($registration -and [int] $registration.ProcessId -eq [int] $listener.OwningProcess) {
                $owned = $true
                break
            }
        }
        if (-not $owned) {
            $owner = Get-Process -Id $listener.OwningProcess -ErrorAction SilentlyContinue
            $detail = if ($owner) { " by $($owner.ProcessName) (PID $($owner.Id))" } else { '' }
            throw "TCP port $Port is already in use$detail. Stop that program or choose another port."
        }
    }
}
