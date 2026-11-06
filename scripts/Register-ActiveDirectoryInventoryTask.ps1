[CmdletBinding(SupportsShouldProcess=$true)]
param([Parameter(Mandatory=$true)][string]$SearchBase,[Parameter(Mandatory=$true)][string]$ApiUrl,[string]$TaskName='Smart Asset AD Inventory', [string]$ServiceAccount)
$runner=Join-Path $PSScriptRoot 'Run-ActiveDirectoryInventoryService.ps1'
$args="-NoProfile -ExecutionPolicy RemoteSigned -File `"$runner`" -SearchBase `"$SearchBase`" -ApiUrl `"$ApiUrl`""
$action=New-ScheduledTaskAction -Execute 'PowerShell.exe' -Argument $args
$trigger=New-ScheduledTaskTrigger -AtStartup
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5)
$principal=if($ServiceAccount){New-ScheduledTaskPrincipal -UserId $ServiceAccount -LogonType ServiceAccount -RunLevel Limited}else{New-ScheduledTaskPrincipal -GroupId 'SYSTEM' -RunLevel Highest}
if($PSCmdlet.ShouldProcess($TaskName,'Register 15-minute AD inventory service task')){Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Polls the Smart Asset AD sync request queue and synchronizes the configured AD scope every 15 minutes.' -Force}
