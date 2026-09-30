param([switch]$Verify)
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$env:PATH='C:\Program Files\Docker\Docker\resources\bin;'+$env:PATH
$compose=@('compose','--env-file',"$root/infra/compose/.env",'-f',"$root/infra/compose/dev.yaml")
$stamp=Get-Date -Format 'yyyyMMddHHmmss'
$directory=Join-Path $root 'infra/backups'
New-Item -ItemType Directory -Force $directory | Out-Null
$target=Join-Path $directory "grim-hollow-$stamp.sql"
& docker @compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF grim_hollow > /tmp/grim-hollow-backup.sql'
if($LASTEXITCODE -ne 0){throw 'Backup failed'}
& docker @compose cp mysql:/tmp/grim-hollow-backup.sql $target
if($LASTEXITCODE -ne 0){throw 'Could not save backup'}
Write-Host "Backup: $target"
if($Verify){
    $database="grim_hollow_restore_$stamp"
    if($database -notmatch '^grim_hollow_restore_[0-9]{14}$'){throw 'Invalid isolated restore database'}
    & docker @compose exec -T mysql sh -c "MYSQL_PWD=`"`$MYSQL_ROOT_PASSWORD`" mysql -uroot -e 'CREATE DATABASE $database'"
    if($LASTEXITCODE -ne 0){throw 'Could not create isolated restore database'}
    & docker @compose exec -T mysql sh -c "MYSQL_PWD=`"`$MYSQL_ROOT_PASSWORD`" mysql -uroot $database < /tmp/grim-hollow-backup.sql"
    if($LASTEXITCODE -ne 0){throw 'Restore verification failed'}
    $counts=& docker @compose exec -T mysql sh -c "MYSQL_PWD=`"`$MYSQL_ROOT_PASSWORD`" mysql -uroot -N -e 'SELECT COUNT(*) FROM $database.users; SELECT COUNT(*) FROM $database.characters; SELECT COUNT(*) FROM $database.settlements; SELECT COUNT(*) FROM $database.character_items;'"
    if($LASTEXITCODE -ne 0){throw 'Restore query failed'}
    $hash=(Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash
    @("Restored successfully into isolated database: $database","SHA256: $hash","Counts (accounts, heroes, settlements, items):",$counts) | Set-Content -LiteralPath "$directory/verify-$stamp.txt"
    Write-Host "Restore verified in $database; live database unchanged."
}
