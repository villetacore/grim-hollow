$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $projectRoot 'infra/compose/.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    function New-RandomSecret {
        $bytes = New-Object byte[] 32
        [System.Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
        return [Convert]::ToBase64String($bytes)
    }
    @("APP_KEY=base64:$(New-RandomSecret)", "DB_PASSWORD=$(New-RandomSecret)", "DB_ROOT_PASSWORD=$(New-RandomSecret)") | Set-Content -LiteralPath $envPath -Encoding utf8
}
$docker = Get-Command docker -ErrorAction SilentlyContinue
if ($docker) { $dockerPath = $docker.Source }
else { $dockerPath = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe' }
$env:PATH = (Split-Path -Parent $dockerPath) + [IO.Path]::PathSeparator + $env:PATH
& $dockerPath compose --env-file $envPath -f (Join-Path $projectRoot 'infra/compose/dev.yaml') up --build -d api world
if ($LASTEXITCODE -ne 0) { throw 'Docker build/start failed' }
Write-Host 'API: http://127.0.0.1:8080   WebSocket: ws://127.0.0.1:8082 (override with WORLD_PORT)'
