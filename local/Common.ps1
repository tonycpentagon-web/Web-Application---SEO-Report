Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$AppRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
function Assert-InsideRoot([string]$Path) {
 $resolved = [IO.Path]::GetFullPath($Path)
 if (-not $resolved.StartsWith($AppRoot + [IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) { throw 'Path must remain inside this installation.' }
 return $resolved
}
function Read-LocalConfig {
 $path=Join-Path $AppRoot 'local-runtime/settings.json'
 if (-not (Test-Path -LiteralPath $path)) { throw 'Run local/Install.ps1 first.' }
 return Get-Content -LiteralPath $path -Raw | ConvertFrom-Json
}
function Invoke-Checked([string]$Exe,[string[]]$Arguments) {
 & $Exe @Arguments
 if ($LASTEXITCODE -ne 0) { throw "Command failed: $Exe (exit $LASTEXITCODE). Read the preceding message." }
}
function Assert-Prerequisites([string]$Php,[string]$Python,[string]$Node,[string]$Browser) {
 foreach($tool in @($Php,$Python,$Node,$Browser)) { if(-not(Test-Path -LiteralPath $tool -PathType Leaf)){throw "Required executable not found: $tool"} }
 Invoke-Checked $Php @((Join-Path $AppRoot 'local/Check-Php.php'))
 Invoke-Checked $Python @((Join-Path $AppRoot 'local/check-python.py'))
 Invoke-Checked $Node @((Join-Path $AppRoot 'local/check-node.cjs'))
}
