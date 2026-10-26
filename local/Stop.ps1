. "$PSScriptRoot/Common.ps1"
$c=Read-LocalConfig
$pidFile=Join-Path $AppRoot 'local-runtime/processes.json'
if(-not(Test-Path -LiteralPath $pidFile)){Write-Host 'No recorded services.';return}
$records=Get-Content -LiteralPath $pidFile -Raw|ConvertFrom-Json
# Exact start times reject reused PIDs. No WMI access or broad executable-name termination is needed.
foreach($record in $records){
 $p=Get-Process -Id $record.Id -ErrorAction SilentlyContinue
 if(-not $p){continue}
 if($p.StartTime.ToUniversalTime().Ticks -ne $record.StartTicks){throw 'PID has been reused. Refusing to stop an unrelated process.'}
 if([IO.Path]::GetFileName($p.Path) -notin @('powershell.exe','pwsh.exe')){throw 'Recorded wrapper identity does not match the local starter.'}
}
foreach($record in $records){
 $p=Get-Process -Id $record.Id -ErrorAction SilentlyContinue
 if(-not $p){continue}
 # Recheck immediately before native termination; taskkill /T targets only this recorded process tree.
 if($p.StartTime.ToUniversalTime().Ticks -ne $record.StartTicks){throw 'PID changed before stopping. No unrelated process will be stopped.'}
 & "$env:SystemRoot/System32/taskkill.exe" /PID $record.Id /T /F | Out-Null
 if($LASTEXITCODE -ne 0 -and (Get-Process -Id $record.Id -ErrorAction SilentlyContinue)){throw "Could not stop this installation's $($record.Role) process tree. Record retained."}
}
Remove-Item -LiteralPath $pidFile
Write-Host 'Recorded local service trees stopped. Database and uploads preserved.'
