$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'));$state=Join-Path $root '.tools/https-review';$record=Join-Path $state 'processes.json'
if(!(Test-Path -LiteralPath $record)){Write-Host 'No recorded review processes.';exit 0}
$entries=@(Get-Content -LiteralPath $record -Raw|ConvertFrom-Json);$failed=@()
foreach($entry in ($entries|Sort-Object @{Expression={if($_.Role -eq 'tunnel'){0}else{1}}})){
 $p=Get-Process -Id $entry.Id -ErrorAction SilentlyContinue
 if(!$p){continue}
 if($p.StartTime.ToUniversalTime().Ticks -ne [long]$entry.StartTicks){throw "PID $($entry.Id) was reused. Refusing to stop an unrelated process."}
 & taskkill.exe /PID $entry.Id /T /F | Out-Null
 if($LASTEXITCODE -ne 0){$failed+=$entry.Id}
}
if($failed.Count){throw "Could not stop recorded review processes: $($failed -join ', '). Keep the record and retry with sufficient Windows permissions."}
Copy-Item -LiteralPath $record -Destination (Join-Path $state 'stopped-processes.json') -Force
Remove-Item -LiteralPath $record
Write-Host 'Review tunnel, private origin, proxy and PDF worker stopped. Existing application services were untouched.'
