$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
& "$PSScriptRoot/build-client.ps1" -OutputName 'GrimHollow-Test.exe'
$exe = Join-Path $root 'dist/windows/GrimHollow-Test.exe'
$result = Join-Path $root 'dist/windows/test-artifacts/result.txt'
if (Test-Path -LiteralPath $result) { Remove-Item -LiteralPath $result }
$run = Start-Process -FilePath $exe -ArgumentList '--smoke-local' -WindowStyle Hidden -PassThru
if (-not $run.WaitForExit(40000)) {
    Stop-Process -Id $run.Id
    throw 'Windows client smoke test timed out'
}
if (-not (Test-Path -LiteralPath $result)) { throw 'Client did not produce a test result' }
$text = Get-Content -LiteralPath $result -Raw
Write-Host $text
if ($run.ExitCode -ne 0 -or -not $text.StartsWith('PASCAL_SMOKE_OK')) { throw 'Client smoke test failed' }
