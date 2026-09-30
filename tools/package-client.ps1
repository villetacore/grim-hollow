param([string]$Python = 'python')
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
& "$PSScriptRoot/build-client.ps1"
$name=(Get-Content -LiteralPath "$root/dist/windows/latest-client.txt" -Raw).Trim()
if($name -notmatch '^GrimHollow[\w.-]*\.exe$'){throw 'Invalid executable name'}
& $Python "$root/tools/release.py" client --platform windows-x64 --binary "$root/dist/windows/$name"
if($LASTEXITCODE -ne 0){throw 'Client packaging failed'}
Write-Host 'Requires a running Grim Hollow server; the local server is started from the source project with tools/setup.ps1.'
