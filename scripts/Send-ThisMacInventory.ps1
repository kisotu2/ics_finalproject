[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $ApiUrl,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$')]
    [string] $AdGuid,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$')]
    [string] $DirectoryComputerName,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $DistinguishedName,

    [switch] $Enabled,

    [string] $DirectoryOperatingSystem,

    [string] $DirectoryOperatingSystemVersion,

    [switch] $Send
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if (-not $IsMacOS) {
    throw 'This collector is for macOS and requires PowerShell 7 or later.'
}

$uri = [Uri] $ApiUrl
if (-not $uri.IsAbsoluteUri -or $uri.Scheme -ne 'https') {
    throw 'ApiUrl must be an absolute HTTPS URL.'
}

function Invoke-MacCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path,

        [string[]] $Arguments = @()
    )

    $output = & $Path @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "The macOS command '$Path' failed with exit code $LASTEXITCODE."
    }

    return ($output -join "`n").Trim()
}

function Get-OptionalProperty {
    param(
        [Parameter(Mandatory = $true)]
        [object] $InputObject,

        [Parameter(Mandatory = $true)]
        [string] $Name
    )

    $property = $InputObject.PSObject.Properties[$Name]
    if ($null -eq $property) {
        return $null
    }

    return $property.Value
}

$systemProfiler = Invoke-MacCommand -Path '/usr/sbin/system_profiler' -Arguments @(
    '-json',
    'SPHardwareDataType'
) | ConvertFrom-Json

$hardware = @(Get-OptionalProperty -InputObject $systemProfiler -Name 'SPHardwareDataType') | Select-Object -First 1
if ($null -eq $hardware) {
    throw 'macOS did not return hardware information from system_profiler.'
}

$operatingSystemName = $DirectoryOperatingSystem
if ([string]::IsNullOrWhiteSpace($operatingSystemName)) {
    $operatingSystemName = Invoke-MacCommand -Path '/usr/bin/sw_vers' -Arguments @('-productName')
}

$operatingSystemVersion = $DirectoryOperatingSystemVersion
if ([string]::IsNullOrWhiteSpace($operatingSystemVersion)) {
    $operatingSystemVersion = Invoke-MacCommand -Path '/usr/bin/sw_vers' -Arguments @('-productVersion')
}

$memoryBytes = [long](Invoke-MacCommand -Path '/usr/sbin/sysctl' -Arguments @('-n', 'hw.memsize'))
$ramGb = [int][math]::Ceiling($memoryBytes / 1GB)
if ($ramGb -lt 1 -or $ramGb -gt 255) {
    $ramGb = $null
}

$storageGb = $null
try {
    $diskInfo = Invoke-MacCommand -Path '/usr/sbin/diskutil' -Arguments @('info', '-plist', '/')
    [xml] $diskPlist = $diskInfo
    $totalSizeNode = $diskPlist.SelectSingleNode("//key[.='TotalSize']/following-sibling::*[1]")
    $diskBytes = 0L
    if ($null -ne $totalSizeNode -and [long]::TryParse($totalSizeNode.InnerText, [ref]$diskBytes)) {
        $storageValue = [int][math]::Ceiling($diskBytes / 1GB)
        if ($storageValue -ge 1 -and $storageValue -le 65535) {
            $storageGb = $storageValue
        }
    }
} catch {
    Write-Warning 'Could not read the startup disk capacity; storage will be reported as unknown.'
}

$manufacturerSerial = [string] (Get-OptionalProperty -InputObject $hardware -Name 'serial_number')
if ([string]::IsNullOrWhiteSpace($manufacturerSerial) -or
    $manufacturerSerial -match '^(To be filled by O\.E\.M\.|Default string|System Serial Number)$') {
    $manufacturerSerial = $null
}

$model = [string] (Get-OptionalProperty -InputObject $hardware -Name 'machine_model_name')
if ([string]::IsNullOrWhiteSpace($model)) {
    $model = [string] (Get-OptionalProperty -InputObject $hardware -Name 'machine_model')
}
if ([string]::IsNullOrWhiteSpace($model)) {
    $model = $null
}

$processor = [string] (Get-OptionalProperty -InputObject $hardware -Name 'chip_type')
if ([string]::IsNullOrWhiteSpace($processor)) {
    $processor = [string] (Get-OptionalProperty -InputObject $hardware -Name 'cpu_type')
}
if ([string]::IsNullOrWhiteSpace($processor)) {
    $processor = $null
}

$record = [pscustomobject]@{
    ad_guid = $AdGuid.ToLowerInvariant()
    computer_name = $DirectoryComputerName
    distinguished_name = $DistinguishedName
    enabled = $Enabled.IsPresent
    operating_system = $operatingSystemName
    operating_system_version = $operatingSystemVersion
    manufacturer = 'Apple'
    model = $model
    manufacturer_serial = $manufacturerSerial
    processor = $processor
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

if (-not $Send) {
    Write-Output 'Preview only; no data was sent. Review this record, then rerun with -Send to submit it:'
    $record | ConvertTo-Json -Depth 5
    return
}

$token = [Environment]::GetEnvironmentVariable('AD_SYNC_TOKEN', 'Process')
if ([string]::IsNullOrWhiteSpace($token) -or $token.Length -lt 32) {
    throw 'AD_SYNC_TOKEN must be provisioned to this process by your administrator; do not put it in this script.'
}

$response = Invoke-RestMethod `
    -Method Post `
    -Uri $uri `
    -Headers @{ Authorization = "******" } `
    -ContentType 'application/json; charset=utf-8' `
    -Body ([System.Text.Encoding]::UTF8.GetBytes($payload)) `
    -TimeoutSec 60

Write-Output 'This Mac was submitted to the asset inventory:'
$response | ConvertTo-Json -Depth 5
