. "$PSScriptRoot/Common.ps1"
$c=Read-LocalConfig
Push-Location (Join-Path $AppRoot 'backend')
try{Invoke-Checked $c.Php @('artisan','admin:create')}finally{Pop-Location}
