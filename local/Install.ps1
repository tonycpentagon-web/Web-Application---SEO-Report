param(
 [string]$Php = 'D:\xampp\php\php.exe',
 [string]$Python = '',
 [string]$Node = '',
 [string]$Browser = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
 [ValidateRange(1024,65535)][int]$Port = 8787,
 [switch]$UseExistingPython
)
. "$PSScriptRoot/Common.ps1"
if (-not $Python) {$Python=(Get-Command python.exe -ErrorAction Stop).Source}
if (-not $Node) {$Node=(Get-Command node.exe -ErrorAction Stop).Source}
$Php=[IO.Path]::GetFullPath($Php);$Python=[IO.Path]::GetFullPath($Python);$Node=[IO.Path]::GetFullPath($Node);$Browser=[IO.Path]::GetFullPath($Browser)
Assert-Prerequisites $Php $Python $Node $Browser
$runtime=Assert-InsideRoot (Join-Path $AppRoot 'local-runtime')
$environment=Join-Path $AppRoot 'backend/.env'
if ((Test-Path -LiteralPath $environment) -or (Test-Path -LiteralPath $runtime)) {throw 'Existing configuration/runtime found. Installation refuses to overwrite it. Use a fresh extracted folder.'}
foreach($required in @('backend/vendor/autoload.php','backend/pdf-renderer/node_modules/playwright/package.json','frontend/dist/index.html')){if(-not(Test-Path -LiteralPath (Join-Path $AppRoot $required))){throw "Package component missing: $required. Use the complete local evaluation ZIP."}}
New-Item -ItemType Directory -Path $runtime | Out-Null
foreach($folder in @('backend/storage/app/private','backend/storage/framework/cache/data','backend/storage/framework/sessions','backend/storage/framework/views','backend/storage/logs','backend/bootstrap/cache')){New-Item -ItemType Directory -Force -Path (Join-Path $AppRoot $folder) | Out-Null}
if (-not $UseExistingPython) {
 Invoke-Checked $Python @('-m','venv',(Join-Path $runtime 'python'))
 $Python=Join-Path $runtime 'python/Scripts/python.exe'
 Invoke-Checked $Python @('-m','pip','install','-r',(Join-Path $AppRoot 'document-processor/requirements-windows.lock.txt'))
}
Invoke-Checked $Python @('-c','import pymupdf,pdfplumber,docx,openpyxl,PIL,xlrd,charset_normalizer,defusedxml,rapidocr_onnxruntime')
$db=Join-Path $runtime 'local.sqlite'
[IO.File]::WriteAllBytes($db,[byte[]]@())
$settings=@{Php=$Php;Python=$Python;Node=$Node;Browser=$Browser;Port=$Port;Root=$AppRoot}
$settings|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $runtime 'settings.json') -Encoding UTF8
$sample=Get-Content -LiteralPath (Join-Path $AppRoot 'backend/.env.example') -Raw
$values=@{APP_ENV='local';APP_DEBUG='false';APP_URL="http://127.0.0.1:$Port";FRONTEND_URL="http://127.0.0.1:$Port";SANCTUM_STATEFUL_DOMAINS="127.0.0.1:$Port,localhost:$Port";DB_CONNECTION='sqlite';DB_DATABASE=('"'+$db.Replace('\','/')+'"');DOCUMENT_PYTHON=('"'+$Python.Replace('\','/')+'"');PDF_NODE_BINARY=('"'+$Node.Replace('\','/')+'"');PDF_CHROMIUM_PATH=('"'+$Browser.Replace('\','/')+'"');SESSION_COOKIE=('pentagon_local_'+$Port);TRASH_RETENTION_DAYS='30'}
foreach($key in $values.Keys){$line=$key+'='+$values[$key];if($sample -match ('(?m)^'+$key+'=')){$sample=[regex]::Replace($sample,'(?m)^'+$key+'=.*$', [System.Text.RegularExpressions.MatchEvaluator]{param($m)$line})}else{$sample+="`r`n$line`r`n"}}
[IO.File]::WriteAllText($environment,$sample,(New-Object Text.UTF8Encoding($false)))
Push-Location (Join-Path $AppRoot 'backend')
try { Invoke-Checked $Php @('artisan','key:generate','--force');Invoke-Checked $Php @('artisan','migrate','--force');Invoke-Checked $Php @('artisan','documents:doctor') } finally {Pop-Location}
Write-Host 'Local installation created with an empty database. No default passwords or client data.'
Write-Host 'Next: run local/New-TestAdmin.ps1 in an interactive terminal, then local/Start.ps1.'
