[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$SearchBase,[string]$ApiUrl='https://localhost/ics_finalproject',[int]$IntervalMinutes=15,[int]$PollSeconds=30)
$ErrorActionPreference='Continue'
$scriptPath=Join-Path $PSScriptRoot 'Sync-ActiveDirectoryInventory.ps1'
$lastScheduled=[DateTime]::MinValue
while($true){
  if(([DateTime]::UtcNow-$lastScheduled).TotalMinutes -ge $IntervalMinutes){
    try { $scheduledRunId=[guid]::NewGuid().ToString(); & $scriptPath -SearchBase $SearchBase -ApiUrl ($ApiUrl.TrimEnd('/')+'/ad_sync_api.php') -Send -RunId $scheduledRunId; $lastScheduled=[DateTime]::UtcNow }
    catch { $reason=$_.Exception.Message -replace '[\r\n]+',' '; $json=@{action='run_fail';run_id=$scheduledRunId;reason=$reason}|ConvertTo-Json -Compress; try{Invoke-RestMethod -Method Post -Uri ($ApiUrl.TrimEnd('/')+'/api/ad_sync_agent.php') -Headers @{Authorization="Bearer $env:AD_SYNC_TOKEN"} -ContentType 'application/json' -Body $json -TimeoutSec 30}catch{}; Write-Error ('Scheduled AD sync failed: '+$reason) }
  }
  try {
    $claim=Invoke-RestMethod -Method Post -Uri ($ApiUrl.TrimEnd('/')+'/api/ad_sync_agent.php') -Headers @{Authorization="Bearer $env:AD_SYNC_TOKEN"} -ContentType 'application/json' -Body '{"action":"claim"}' -TimeoutSec 30
    if($claim.request){
      try { & $scriptPath -SearchBase $claim.request.scope_id -ApiUrl ($ApiUrl.TrimEnd('/')+'/ad_sync_api.php') -Send -RequestId ([long]$claim.request.id); $lastScheduled=[DateTime]::UtcNow }
      catch { $reason=$_.Exception.Message -replace '[\r\n]+',' '; $json=@{action='fail';request_id=[long]$claim.request.id;reason=$reason}|ConvertTo-Json -Compress; Invoke-RestMethod -Method Post -Uri ($ApiUrl.TrimEnd('/')+'/api/ad_sync_agent.php') -Headers @{Authorization="Bearer $env:AD_SYNC_TOKEN"} -ContentType 'application/json' -Body $json -TimeoutSec 30 }
    }
  } catch { Write-Warning ('Manual sync queue check failed: '+$_.Exception.Message) }
  Start-Sleep -Seconds ([Math]::Max(10,$PollSeconds))
}
