$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$client = Join-Path $root 'dist/windows/GrimHollow.exe'
if (Test-Path -LiteralPath "$root/dist/windows/latest-client.txt") {
    $latest = (Get-Content -LiteralPath "$root/dist/windows/latest-client.txt" -Raw).Trim()
    if ($latest -match '^GrimHollow[\w.-]*\.exe$') { $client=Join-Path "$root/dist/windows" $latest }
}
if (-not (Test-Path -LiteralPath $client)) { & "$PSScriptRoot/build-client.ps1" }
try { $null = Invoke-WebRequest -Uri 'http://127.0.0.1:8080/up' -TimeoutSec 3 }
catch { throw 'Start the game server first: .\tools\setup.ps1' }
# Running this script explicitly opens the interactive game window.
Start-Process -FilePath $client -WindowStyle Normal
