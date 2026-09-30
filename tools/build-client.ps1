param([string]$OutputName = 'GrimHollow.exe')
$ErrorActionPreference = 'Stop'
if ($OutputName -notmatch '^GrimHollow[\w.-]*\.exe$') { throw 'Invalid client output name' }
$root = Split-Path -Parent $PSScriptRoot
$lazarusRoot = $env:LAZARUS_HOME
if (-not $lazarusRoot) { $lazarusRoot = Join-Path $root '.tools/lazarus/app' }
if (-not (Test-Path -LiteralPath "$lazarusRoot/lazbuild.exe")) {
    $command = Get-Command lazbuild -ErrorAction SilentlyContinue
    if ($command) { $lazarusRoot = Split-Path -Parent $command.Source }
    elseif (Test-Path -LiteralPath 'C:/lazarus/lazbuild.exe') { $lazarusRoot='C:/lazarus' }
    else { throw 'Install Lazarus with FPC or set LAZARUS_HOME.' }
}
$compiler = Join-Path $lazarusRoot 'fpc/3.2.2/bin/x86_64-win64/fpc.exe'
New-Item -ItemType Directory -Force "$root/build/client/win64" | Out-Null
$options = @("--lazarusdir=$lazarusRoot", "--pcp=$root/.tools/lazarus-config", '--widgetset=win32')
if (Test-Path -LiteralPath $compiler) { $options += "--compiler=$compiler" }
& "$lazarusRoot/lazbuild.exe" @options "$root/apps/client/GrimHollow.lpi"
if ($LASTEXITCODE -ne 0) { throw 'Client compilation failed' }
New-Item -ItemType Directory -Force "$root/dist/windows" | Out-Null
$destination = Join-Path "$root/dist/windows" $OutputName
try { Copy-Item -LiteralPath "$root/build/client/win64/grim-hollow.exe" -Destination $destination -Force }
catch {
    if ($OutputName -ne 'GrimHollow.exe') { throw }
    $destination = Join-Path "$root/dist/windows" ('GrimHollow-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.exe')
    Copy-Item -LiteralPath "$root/build/client/win64/grim-hollow.exe" -Destination $destination
}
if ($OutputName -eq 'GrimHollow.exe') { [IO.File]::WriteAllText("$root/dist/windows/latest-client.txt",(Split-Path -Leaf $destination)) }
Write-Host "Built: $destination"
