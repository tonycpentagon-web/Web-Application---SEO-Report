. "$PSScriptRoot/Common.ps1"
$c=Read-LocalConfig
Assert-Prerequisites $c.Php $c.Python $c.Node $c.Browser
Push-Location (Join-Path $AppRoot 'backend')
try{Invoke-Checked $c.Php @('artisan','migrate:status');Invoke-Checked $c.Php @('artisan','documents:doctor')}finally{Pop-Location}
$pidFile=Join-Path $AppRoot 'local-runtime/processes.json'
if(Test-Path -LiteralPath $pidFile){$records=Get-Content -LiteralPath $pidFile -Raw|ConvertFrom-Json;foreach($record in $records){$p=Get-Process -Id $record.Id -ErrorAction SilentlyContinue;if(-not $p -or $p.StartTime.ToUniversalTime().Ticks -ne $record.StartTicks){throw "Recorded $($record.Role) wrapper is not running."}}}else{Write-Host 'Application stopped: no recorded processes.';return}
Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$($c.Port)/up" -TimeoutSec 10 | Out-Null
Write-Host 'HTTP responds and service wrappers exist. Confirm actual worker/scheduler heartbeats and PDF generation through Super Admin System Health.'
