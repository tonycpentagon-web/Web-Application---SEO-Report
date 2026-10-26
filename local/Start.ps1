param([switch]$OpenBrowser)
. "$PSScriptRoot/Common.ps1"
$c=Read-LocalConfig
if([IO.Path]::GetFullPath($c.Root) -ne $AppRoot){throw 'Installation folder moved. Restore to the original path or follow the documented relocation/update steps.'}
$pidFile=Join-Path $AppRoot 'local-runtime/processes.json'
if(Test-Path -LiteralPath $pidFile){throw 'Process record exists. Run local/Health.ps1, then Stop.ps1 before starting again.'}
$probe=New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback,[int]$c.Port)
try{$probe.Start()}catch{throw "Port $($c.Port) is already occupied. No services were started."}finally{$probe.Stop()}
$children=@()
try {
 foreach($role in @('web','worker','scheduler')){
  $args=@('-NoProfile','-ExecutionPolicy','Bypass','-File',('"'+(Join-Path $AppRoot 'local/Run-Service.ps1')+'"'),'-Role',$role)
  $p=Start-Process -FilePath (Join-Path $PSHOME 'powershell.exe') -ArgumentList $args -WorkingDirectory $AppRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $AppRoot "local-runtime/$role.log") -RedirectStandardError (Join-Path $AppRoot "local-runtime/$role-error.log")
  $children+=@{Id=$p.Id;StartTicks=$p.StartTime.ToUniversalTime().Ticks;Role=$role}
 }
 $children|ConvertTo-Json|Set-Content -LiteralPath $pidFile -Encoding UTF8
 Start-Sleep -Seconds 3
 Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$($c.Port)/up" -TimeoutSec 10 | Out-Null
 Write-Host "Open http://127.0.0.1:$($c.Port) . Keep this machine awake. Logs are private under local-runtime."
 if($OpenBrowser){Start-Process "http://127.0.0.1:$($c.Port)"}
}catch{if(Test-Path -LiteralPath $pidFile){& "$PSScriptRoot/Stop.ps1"};throw}
