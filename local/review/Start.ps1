param([int]$ProxyPort=8820,[int]$BackendPort=8821,[switch]$LocalOnly,[string[]]$AllowedEmail=@())
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$state=Join-Path $root '.tools/https-review';$record=Join-Path $state 'processes.json'
$php=Join-Path $root '.tools/php/php.exe';$node=(Get-Command node -ErrorAction Stop).Source;$cloud=Join-Path $state 'cloudflared.exe'
if(Test-Path -LiteralPath $record){throw 'Review process record exists. Run local/review/Stop.ps1 before starting again.'}
if(!$LocalOnly -and !(Test-Path -LiteralPath $cloud)){throw 'Install the verified portable cloudflared binary. See docs/TEMPORARY_HTTPS_REVIEW.md.'}
if(!$LocalOnly -and (Get-FileHash -LiteralPath $cloud -Algorithm SHA256).Hash.ToLowerInvariant() -ne '12c7c887f8aaab779aef1dfa9b60673530a5adb88ecc55dd6df8f56e0b70f199'){throw 'Cloudflared checksum differs from the verified release 2026.10.2.'}
if(Test-Path -LiteralPath (Join-Path $root 'backend/bootstrap/cache/config.php')){throw 'Cached Laravel configuration must be handled before review; no services started.'}
foreach($port in @($ProxyPort,$BackendPort)){$probe=New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback,$port);try{$probe.Start()}finally{$probe.Stop()}}
if(!(Test-Path -LiteralPath (Join-Path $state 'snapshot.json'))){Push-Location (Join-Path $root 'backend');try{& $php (Join-Path $PSScriptRoot 'Prepare.php');if($LASTEXITCODE -ne 0){throw 'Snapshot preparation failed.'}}finally{Pop-Location}}
$identity=[Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls.exe $state /inheritance:r /grant:r "${identity}:(OI)(CI)F" 'SYSTEM:(OI)(CI)F' ((Get-Acl -LiteralPath $root).Owner+':(OI)(CI)F') | Out-Null
if($LASTEXITCODE -ne 0){throw 'Could not restrict the private review state directory.'}
$dist=Join-Path $root 'frontend/dist'
$html=Get-Content -LiteralPath (Join-Path $dist 'index.html') -Raw
if($html -notmatch 'src="(/assets/[^" ]+\.js)"'){throw 'Built React entry point missing.'}
$entry=Get-Content -LiteralPath (Join-Path $dist $Matches[1].TrimStart('/')) -Raw
if(!$entry.Contains('baseURL:"/"')){throw 'Rebuild React with VITE_API_URL=/ before sharing. No services started.'}
$processes=@();$previous=@{}
function Register-ReviewProcess($p,$role){$script:processes+=@{Id=$p.Id;StartTicks=$p.StartTime.ToUniversalTime().Ticks;Role=$role};ConvertTo-Json -InputObject @($script:processes)|Set-Content -LiteralPath $record -Encoding UTF8}
try{
 if($LocalOnly){$url="http://127.0.0.1:$ProxyPort";$hostName="127.0.0.1:$ProxyPort"}else{
  $tunnelArgs=@('tunnel','--url',"http://127.0.0.1:$ProxyPort",'--no-autoupdate','--protocol','http2','--metrics','127.0.0.1:0','--management-diagnostics=false')
  foreach($email in $AllowedEmail){if($email -notmatch '^[a-zA-Z0-9_.+@-]+$'){throw 'Use explicit email addresses.'};$tunnelArgs+=@('--allowed-mail',$email)}
  $p=Start-Process -FilePath $cloud -ArgumentList $tunnelArgs -WorkingDirectory $state -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $state 'tunnel.log') -RedirectStandardError (Join-Path $state 'tunnel-error.log');Register-ReviewProcess $p 'tunnel'
  $url=$null
  for($i=0;$i -lt 60;$i++){if($p.HasExited){throw 'Cloudflare tunnel exited; inspect private tunnel log.'};$log=Get-Content -LiteralPath (Join-Path $state 'tunnel-error.log') -Raw -ErrorAction SilentlyContinue;if($log -match 'https://[a-z0-9-]+\.trycloudflare\.com'){$url=$Matches[0];break};Start-Sleep -Seconds 1}
  if(!$url){throw 'Cloudflare did not issue a hostname within 60 seconds.'};$hostName=([Uri]$url).Host
 }
 $rng=[Security.Cryptography.RandomNumberGenerator]::Create();$bytes=New-Object byte[] 32;$rng.GetBytes($bytes);$rng.Dispose();$secret=([BitConverter]::ToString($bytes)).Replace('-','').ToLowerInvariant()
 $snapshot=Get-Content -LiteralPath (Join-Path $state 'snapshot.json') -Raw|ConvertFrom-Json
 $config=@{localOnly=[bool]$LocalOnly;host=$hostName;secret=$secret;proxyPort=$ProxyPort;backendPort=$BackendPort;project=$snapshot.project;report=$snapshot.report;client=$snapshot.client;assets=@($snapshot.assets)}
 $json=$config|ConvertTo-Json -Depth 5;[IO.File]::WriteAllText((Join-Path $state 'gateway.json'),$json,(New-Object Text.UTF8Encoding($false)))
 $settings=@{DB_CONNECTION='sqlite';DB_DATABASE=(Join-Path $state 'review.sqlite');DB_URL='null';LARAVEL_STORAGE_PATH=(Join-Path $state 'storage');LOCAL_PRIVATE_ROOT=(Join-Path $state 'storage/app/private');CACHE_STORE='database';QUEUE_CONNECTION='database';APP_ENV='review';APP_DEBUG='false';APP_URL=$url;FRONTEND_URL=$url;SANCTUM_STATEFUL_DOMAINS=$hostName;SESSION_DRIVER='database';SESSION_COOKIE='__Host-pentagon_review';SESSION_DOMAIN='null';SESSION_PATH='/';SESSION_SECURE_COOKIE='true';SESSION_HTTP_ONLY='true';SESSION_SAME_SITE='lax';SESSION_ENCRYPT='true';SESSION_LIFETIME='60';MAIL_MAILER='log';REVIEW_HOST=$hostName;REVIEW_ORIGIN_SECRET=$secret;PDF_NODE_BINARY=$node;PDF_CHROMIUM_PATH='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';DOCUMENT_PROCESSOR_SCRIPT='../document-processor/processor.py';DOCUMENT_PYTHON=(Join-Path $root '.tools/python/python.exe')}
 foreach($key in $settings.Keys){$previous[$key]=[Environment]::GetEnvironmentVariable($key,'Process');[Environment]::SetEnvironmentVariable($key,$settings[$key],'Process')}
 $p=Start-Process -FilePath $php -ArgumentList @('-S',"127.0.0.1:$BackendPort",('"'+(Join-Path $PSScriptRoot 'router.php')+'"')) -WorkingDirectory (Join-Path $root 'backend') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $state 'backend.log') -RedirectStandardError (Join-Path $state 'backend-error.log');Register-ReviewProcess $p 'backend'
 $p=Start-Process -FilePath $php -ArgumentList @('artisan','queue:work','--queue=exports','--sleep=1','--tries=1','--timeout=300') -WorkingDirectory (Join-Path $root 'backend') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $state 'worker.log') -RedirectStandardError (Join-Path $state 'worker-error.log');Register-ReviewProcess $p 'worker'
 $p=Start-Process -FilePath $node -ArgumentList @(('"'+(Join-Path $PSScriptRoot 'gateway.mjs')+'"'),('"'+(Join-Path $state 'gateway.json')+'"')) -WorkingDirectory $root -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $state 'gateway.log') -RedirectStandardError (Join-Path $state 'gateway-error.log');Register-ReviewProcess $p 'gateway'
 $url|Set-Content -LiteralPath (Join-Path $state 'url.txt') -Encoding ASCII
 $preview=$url+'/projects/'+$snapshot.project+'/reports/'+$snapshot.report+'/preview';$preview|Set-Content -LiteralPath (Join-Path $state 'preview-url.txt') -Encoding ASCII
 if($LocalOnly){Write-Host "Local test proxy: http://$hostName (HTTPS scheme emulated at private origin; not a public URL)."}else{Write-Host "Temporary HTTPS URL issued: $url";Write-Host "Private report: $preview";Write-Host 'External verification is still required. Credentials: .tools/https-review/ACCESS.txt (share privately).'}
 Write-Host 'Stop: powershell -NoProfile -ExecutionPolicy Bypass -File local/review/Stop.ps1'
}catch{if(Test-Path -LiteralPath $record){& (Join-Path $PSScriptRoot 'Stop.ps1')};throw}finally{foreach($key in $previous.Keys){[Environment]::SetEnvironmentVariable($key,$previous[$key],'Process')}}
