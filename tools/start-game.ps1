$ErrorActionPreference='Stop'
try { $null=Invoke-WebRequest -Uri 'http://127.0.0.1:8080/up' -TimeoutSec 3 }
catch {
    & "$PSScriptRoot/setup.ps1"
    $ready=$false
    for($attempt=0;$attempt -lt 30;$attempt++) {
        try { $null=Invoke-WebRequest -Uri 'http://127.0.0.1:8080/up' -TimeoutSec 2; $ready=$true; break }
        catch { Start-Sleep -Seconds 1 }
    }
    if(-not $ready){throw 'The server did not become ready. Check Docker Desktop and tools/setup.ps1 output.'}
}
& "$PSScriptRoot/play.ps1"
