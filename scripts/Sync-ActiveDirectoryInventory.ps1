[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $SearchBase,

    [string] $ApiUrl = 'https://localhost/ics_finalproject/ad_sync_api.php',

    [switch] $Send
)

$ErrorActionPreference = 'Stop'

Import-Module ActiveDirectory

if ($Send -and [string]::IsNullOrWhiteSpace($env:AD_SYNC_TOKEN)) {
    throw 'AD_SYNC_TOKEN must be configured in the process environment before using -Send.'
}

$directoryComputers = Get-ADComputer `
    -SearchBase $SearchBase `
    -SearchScope Subtree `
    -Filter * `
    -Properties Enabled, OperatingSystem, OperatingSystemVersion, DistinguishedName, ObjectGUID

$records = [System.Collections.Generic.List[object]]::new()
$offline = [System.Collections.Generic.List[string]]::new()

foreach ($computer in $directoryComputers) {
    try {
        $hardware = Invoke-Command -ComputerName $computer.Name -ErrorAction Stop -ScriptBlock {
            $system = Get-CimInstance -ClassName Win32_ComputerSystem -ErrorAction Stop
            $bios = Get-CimInstance -ClassName Win32_BIOS -ErrorAction Stop
            $operatingSystem = Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction Stop
            $processor = Get-CimInstance -ClassName Win32_Processor -ErrorAction Stop |
                Select-Object -First 1
            $disks = @(Get-CimInstance -ClassName Win32_DiskDrive -ErrorAction Stop)
            $diskBytes = ($disks | Measure-Object -Property Size -Sum).Sum
            $storageGb = $null
            if ($diskBytes) {
                $storageGb = [int] [math]::Ceiling($diskBytes / 1GB)
            }

            [pscustomobject]@{
                Manufacturer = [string] $system.Manufacturer
                Model = [string] $system.Model
                ManufacturerSerial = [string] $bios.SerialNumber
                Processor = [string] $processor.Name
                RamGb = [int] [math]::Ceiling($system.TotalPhysicalMemory / 1GB)
                StorageGb = $storageGb
                OperatingSystem = [string] $operatingSystem.Caption
                OperatingSystemVersion = [string] $operatingSystem.Version
            }
        }

        $manufacturerSerial = $hardware.ManufacturerSerial.Trim()
        if ($manufacturerSerial -match '^(|To be filled by O\.E\.M\.|Default string|System Serial Number)$') {
            $manufacturerSerial = $null
        }

        $ram = if ($hardware.RamGb -ge 1 -and $hardware.RamGb -le 255) {
            [int] $hardware.RamGb
        } else {
            $null
        }

        $storage = if ($null -ne $hardware.StorageGb -and $hardware.StorageGb -ge 1 -and $hardware.StorageGb -le 65535) {
            [int] $hardware.StorageGb
        } else {
            $null
        }

        $manufacturer = if ([string]::IsNullOrWhiteSpace($hardware.Manufacturer)) {
            $null
        } else {
            $hardware.Manufacturer.Trim()
        }
        $model = if ([string]::IsNullOrWhiteSpace($hardware.Model)) {
            $null
        } else {
            $hardware.Model.Trim()
        }
        $processorName = if ([string]::IsNullOrWhiteSpace($hardware.Processor)) {
            $null
        } else {
            $hardware.Processor.Trim()
        }
        $manufacturerSerialValue = if ([string]::IsNullOrWhiteSpace($manufacturerSerial)) {
            $null
        } else {
            $manufacturerSerial
        }

        $records.Add([pscustomobject]@{
            ad_guid = $computer.ObjectGUID.ToString().ToLowerInvariant()
            computer_name = $computer.Name
            distinguished_name = $computer.DistinguishedName
            enabled = [bool] $computer.Enabled
            operating_system = [string] $computer.OperatingSystem
            operating_system_version = [string] $computer.OperatingSystemVersion
            manufacturer = $manufacturer
            model = $model
            manufacturer_serial = $manufacturerSerialValue
            processor = $processorName
            ram_gb = $ram
            storage_gb = $storage
            purchase_date = $null
            warranty_expiry = $null
        })
    } catch {
        $offline.Add($computer.Name)
        Write-Warning ("Hardware query failed for {0}; it will not be sent. Check that the endpoint is online and remote CIM/WinRM access is allowed." -f $computer.Name)
    }
}

$duplicateSerials = $records |
    Where-Object { -not [string]::IsNullOrWhiteSpace($_.manufacturer_serial) } |
    Group-Object { $_.manufacturer_serial.ToLowerInvariant() } |
    Where-Object Count -gt 1

Write-Output ("Directory objects found: {0}" -f @($directoryComputers).Count)
Write-Output ("Online computers collected: {0}" -f $records.Count)
Write-Output ("Offline or unreachable computers: {0}" -f $offline.Count)
Write-Output ("Records with a duplicate manufacturer serial in this batch: {0}" -f $duplicateSerials.Count)

if ($offline.Count -gt 0) {
    Write-Output ("Not collected: {0}" -f ($offline -join ', '))
}

if ($records.Count -gt 0) {
    $records |
        Select-Object -First 10 computer_name, operating_system, manufacturer, model, ram_gb, storage_gb |
        Format-Table -AutoSize |
        Out-String |
        Write-Output
}

if (-not $Send) {
    Write-Output 'Preview only. Review the sample above; rerun with -Send to submit the collected records.'
    return
}

for ($offset = 0; $offset -lt $records.Count; $offset += 100) {
    $last = [Math]::Min($offset + 99, $records.Count - 1)
    $batch = @($records.GetRange($offset, $last - $offset + 1))
    $payload = @{
        version = 1
        computers = $batch
    } | ConvertTo-Json -Depth 6 -Compress

    if ([System.Text.Encoding]::UTF8.GetByteCount($payload) -gt 2MB) {
        throw 'A 100-computer batch exceeds the API 2 MiB limit; reduce the batch size in the connector.'
    }

    $response = Invoke-RestMethod `
        -Method Post `
        -Uri $ApiUrl `
        -Headers @{ Authorization = "Bearer $env:AD_SYNC_TOKEN" } `
        -ContentType 'application/json; charset=utf-8' `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($payload)) `
        -TimeoutSec 60

    $response | ConvertTo-Json -Depth 6
}

Write-Output 'Directory synchronization finished. Review the response summary and the server sync log.'
