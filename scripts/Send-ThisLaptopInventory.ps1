[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $ApiUrl
)

$ErrorActionPreference = 'Stop'

$uri = [Uri] $ApiUrl
if ($uri.Scheme -ne 'https') {
    throw 'ApiUrl must use HTTPS.'
}

$token = [Environment]::GetEnvironmentVariable('AD_SYNC_TOKEN', 'Process')
if ([string]::IsNullOrWhiteSpace($token) -or $token.Length -lt 32) {
    throw 'AD_SYNC_TOKEN must be provided to this process by your administrator using an approved secret-management method.'
}

Import-Module ActiveDirectory

$computerName = $env:COMPUTERNAME
if ([string]::IsNullOrWhiteSpace($computerName)) {
    throw 'Windows did not provide a computer name.'
}

$directoryComputer = Get-ADComputer `
    -Identity $computerName `
    -Properties Enabled, OperatingSystem, OperatingSystemVersion, DistinguishedName, ObjectGUID

$system = Get-CimInstance -ClassName Win32_ComputerSystem
$bios = Get-CimInstance -ClassName Win32_BIOS
$operatingSystem = Get-CimInstance -ClassName Win32_OperatingSystem
$processor = Get-CimInstance -ClassName Win32_Processor | Select-Object -First 1
$disks = @(Get-CimInstance -ClassName Win32_DiskDrive)

$diskBytes = ($disks | Measure-Object -Property Size -Sum).Sum
$storageGb = $null
if ($diskBytes) {
    $storageGb = [int] [math]::Ceiling($diskBytes / 1GB)
    if ($storageGb -lt 1 -or $storageGb -gt 65535) {
        $storageGb = $null
    }
}

$ramGb = [int] [math]::Ceiling($system.TotalPhysicalMemory / 1GB)
if ($ramGb -lt 1 -or $ramGb -gt 255) {
    $ramGb = $null
}

$manufacturerSerial = ([string] $bios.SerialNumber).Trim()
if ([string]::IsNullOrWhiteSpace($manufacturerSerial) -or
    $manufacturerSerial -match '^(To be filled by O\.E\.M\.|Default string|System Serial Number)$') {
    $manufacturerSerial = $null
}

$adOperatingSystem = $directoryComputer.OperatingSystem
if ([string]::IsNullOrWhiteSpace($adOperatingSystem)) {
    $adOperatingSystem = $operatingSystem.Caption
}

$adOperatingSystemVersion = $directoryComputer.OperatingSystemVersion
if ([string]::IsNullOrWhiteSpace($adOperatingSystemVersion)) {
    $adOperatingSystemVersion = $operatingSystem.Version
}

$manufacturerName = if ([string]::IsNullOrWhiteSpace($system.Manufacturer)) { $null } else { ([string] $system.Manufacturer).Trim() }
$modelName = if ([string]::IsNullOrWhiteSpace($system.Model)) { $null } else { ([string] $system.Model).Trim() }
$processorName = if ([string]::IsNullOrWhiteSpace($processor.Name)) { $null } else { ([string] $processor.Name).Trim() }

$record = [pscustomobject]@{
    ad_guid = $directoryComputer.ObjectGUID.ToString().ToLowerInvariant()
    computer_name = $directoryComputer.Name
    distinguished_name = $directoryComputer.DistinguishedName
    enabled = [bool] $directoryComputer.Enabled
    operating_system = [string] $adOperatingSystem
    operating_system_version = [string] $adOperatingSystemVersion
    manufacturer = $manufacturerName
    model = $modelName
    manufacturer_serial = $manufacturerSerial
    processor = $processorName
    ram_gb = $ramGb
    storage_gb = $storageGb
    purchase_date = $null
    warranty_expiry = $null
}

$payload = @{
    version = 1
    computers = @($record)
} | ConvertTo-Json -Depth 5 -Compress

if ([System.Text.Encoding]::UTF8.GetByteCount($payload) -gt 2MB) {
    throw 'The inventory record exceeds the server request size limit.'
}

$response = Invoke-RestMethod `
    -Method Post `
    -Uri $uri `
    -Headers @{ Authorization = "******" } `
    -ContentType 'application/json; charset=utf-8' `
    -Body ([System.Text.Encoding]::UTF8.GetBytes($payload)) `
    -TimeoutSec 60

Write-Output 'This laptop was submitted to the asset inventory:'
$response | ConvertTo-Json -Depth 5
