[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $SearchBase,
    [string] $ApiUrl = 'https://localhost/ics_finalproject/ad_sync_api.php',
    [switch] $Send,
    [string] $RunId = ([guid]::NewGuid().ToString()),
    [Nullable[long]] $RequestId
)
$ErrorActionPreference = 'Stop'
$apiUri = [Uri]$ApiUrl
if (-not $apiUri.IsAbsoluteUri -or $apiUri.Scheme -ne 'https') { throw 'ApiUrl must be an absolute HTTPS URL.' }
if ($Send -and ([string]::IsNullOrWhiteSpace($env:AD_SYNC_TOKEN) -or $env:AD_SYNC_TOKEN.Length -lt 32)) { throw 'AD_SYNC_TOKEN must be at least 32 characters in the process environment.' }
try { Import-Module ActiveDirectory -ErrorAction Stop } catch { throw 'The ActiveDirectory PowerShell module is required. Install RSAT and run on an authorized domain joined host.' }
$computers = @(Get-ADComputer -SearchBase $SearchBase -SearchScope Subtree -Filter * -Properties Enabled,OperatingSystem,OperatingSystemVersion,DistinguishedName,ObjectGUID,DNSHostName,whenCreated,whenChanged,lastLogonTimestamp)
$records = [System.Collections.Generic.List[object]]::new()
foreach ($computer in $computers) {
    $endpointStatus = 'unknown'; $reason = $null; $hardware = $null; $hardwareScannedAt = $null
    $target = if ($computer.DNSHostName) { [string]$computer.DNSHostName } else { [string]$computer.Name }
    $pingResponded = $false
    try { $pingResponded = [bool](Test-Connection -ComputerName $target -Count 1 -Quiet -ErrorAction Stop) } catch { $pingResponded = $false }
    try {
        $hardware = Invoke-Command -ComputerName $target -ErrorAction Stop -ScriptBlock {
            $system = Get-CimInstance Win32_ComputerSystem -ErrorAction Stop
            $bios = Get-CimInstance Win32_BIOS -ErrorAction Stop
            $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop
            $memory = @(Get-CimInstance Win32_PhysicalMemory -ErrorAction Stop)
            $memoryBytes = ($memory | Measure-Object -Property Capacity -Sum).Sum
            $cpu = Get-CimInstance Win32_Processor -ErrorAction Stop | Select-Object -First 1
            $disks = @(Get-CimInstance Win32_DiskDrive -ErrorAction Stop)
            $diskBytes = ($disks | Measure-Object -Property Size -Sum).Sum
            [pscustomobject]@{
                Manufacturer=[string]$system.Manufacturer; Model=[string]$system.Model
                ManufacturerSerial=[string]$bios.SerialNumber; Processor=[string]$cpu.Name
                RamGb=[int][math]::Ceiling($(if($memoryBytes){$memoryBytes}else{$system.TotalPhysicalMemory}) / 1GB)
                StorageGb=if ($diskBytes) { [int][math]::Ceiling($diskBytes / 1GB) } else { $null }
                OperatingSystem=[string]$os.Caption; OperatingSystemVersion=[string]$os.Version
            }
        }
        $endpointStatus = 'online'; $reason = 'Remote CIM/WinRM responded.'; $hardwareScannedAt = [DateTime]::UtcNow.ToString('o')
    } catch {
        if ($pingResponded) { $endpointStatus='online'; $reason='ICMP responded; remote CIM/WinRM failed: ' + $_.Exception.Message }
        elseif ($_.Exception.Message -match '(?i)timed out|timeout|unreachable|no such host|could not resolve|network path|cannot connect|failed to connect|connection refused|network is unavailable') { $endpointStatus='offline'; $reason='No ICMP or remote CIM/WinRM response.' }
        else { $endpointStatus='unreachable'; $reason='Remote CIM/WinRM check was inconclusive: ' + $_.Exception.Message }
        if($reason.Length -gt 490){$reason=$reason.Substring(0,490)}
    }
    $serial = if ($hardware -and $hardware.ManufacturerSerial -and $hardware.ManufacturerSerial.Trim() -notmatch '^(|To be filled by O\.E\.M\.|Default string|System Serial Number)$') { $hardware.ManufacturerSerial.Trim() } else { $null }
    $stamp = { param($d) if ($d) { ([DateTime]$d).ToUniversalTime().ToString('o') } else { $null } }
    $lastLogon = $null
    if ($computer.lastLogonTimestamp -and [long]$computer.lastLogonTimestamp -gt 0) { $lastLogon = [DateTime]::FromFileTimeUtc([long]$computer.lastLogonTimestamp).ToString('o') }
    $dn = [string]$computer.DistinguishedName
    $ou = ($dn -split ',(?=OU=)' | Where-Object { $_ -match '^OU=' }) -join ','
    $records.Add([pscustomobject]@{
        ad_guid=$computer.ObjectGUID.ToString().ToLowerInvariant(); computer_name=[string]$computer.Name
        distinguished_name=$dn; enabled=[bool]$computer.Enabled; operating_system=if($hardware -and $hardware.OperatingSystem){$hardware.OperatingSystem}else{[string]$computer.OperatingSystem}
        operating_system_version=if($hardware -and $hardware.OperatingSystemVersion){$hardware.OperatingSystemVersion}else{[string]$computer.OperatingSystemVersion}
        manufacturer=if($hardware){$hardware.Manufacturer}else{$null}; model=if($hardware){$hardware.Model}else{$null}
        manufacturer_serial=$serial; processor=if($hardware){$hardware.Processor}else{$null}
        ram_gb=if($hardware -and $hardware.RamGb -ge 1 -and $hardware.RamGb -le 255){[int]$hardware.RamGb}else{$null}
        storage_gb=if($hardware -and $hardware.StorageGb -ge 1 -and $hardware.StorageGb -le 65535){[int]$hardware.StorageGb}else{$null}
        purchase_date=$null; warranty_expiry=$null; dns_hostname=[string]$computer.DNSHostName
        organizational_unit=$ou; last_logon_at=$lastLogon; account_created_at=&$stamp $computer.whenCreated
        account_modified_at=&$stamp $computer.whenChanged; endpoint_status=$endpointStatus
        endpoint_check_reason=$reason; hardware_last_scanned_at=$hardwareScannedAt; ad_sync_error=$null
    })
    if ($endpointStatus -ne 'online' -or -not $hardware) { Write-Warning ("{0}: {1}. Existing database hardware values will be retained." -f $computer.Name,$reason) }
}
Write-Output ("AD objects: {0}; endpoint online: {1}; offline: {2}; unreachable: {3}" -f $computers.Count,(@($records|Where-Object endpoint_status -eq online).Count),(@($records|Where-Object endpoint_status -eq offline).Count),(@($records|Where-Object endpoint_status -eq unreachable).Count))
if (-not $Send) { Write-Output 'Preview only. Use -Send to submit records.'; return }
$chunks = [System.Collections.Generic.List[object]]::new()
for($i=0;$i -lt $records.Count;$i+=100){$count=[Math]::Min(100,$records.Count-$i);$chunks.Add(@($records.GetRange($i,$count)))}
if($chunks.Count -eq 0){$chunks.Add(@())}
for($i=0;$i -lt $chunks.Count;$i++){
    $payload=@{version=1;run_id=$RunId;scope_id=$SearchBase;full_scope=$true;final_batch=($i -eq $chunks.Count-1);request_id=$RequestId;computers=$chunks[$i]}|ConvertTo-Json -Depth 7 -Compress
    if([Text.Encoding]::UTF8.GetByteCount($payload) -gt 2MB){throw 'Batch exceeds the 2 MiB API request limit.'}
    Invoke-RestMethod -Method Post -Uri $ApiUrl -Headers @{Authorization="Bearer $env:AD_SYNC_TOKEN"} -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($payload)) -TimeoutSec 90 | ConvertTo-Json -Depth 6
}
Write-Output ("Directory run {0} submitted." -f $RunId)
