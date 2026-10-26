param([ValidateSet('web','worker','scheduler')][string]$Role)
. "$PSScriptRoot/Common.ps1"
$c=Read-LocalConfig
Set-Location (Join-Path $AppRoot 'backend')
if($Role -eq 'web'){& $c.Php -d upload_max_filesize=25M -d post_max_size=110M -d max_file_uploads=20 -S "127.0.0.1:$($c.Port)" (Join-Path $AppRoot 'local/router.php')}
elseif($Role -eq 'worker'){& $c.Php artisan queue:work --queue=documents,exports,default --sleep=2 --tries=1 --timeout=1000}
else{& $c.Php artisan schedule:work}
exit $LASTEXITCODE
