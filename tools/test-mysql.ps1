$ErrorActionPreference = 'Stop'
$env:PATH = 'C:\Program Files\Docker\Docker\resources\bin;' + $env:PATH
$root = Split-Path -Parent $PSScriptRoot
$compose = @('compose','--env-file',"$root/infra/compose/.env",'-f',"$root/infra/compose/dev.yaml")
& docker @compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE DATABASE IF NOT EXISTS grim_hollow_test; GRANT ALL ON grim_hollow_test.* TO ''grim''@''%'';"'
if ($LASTEXITCODE -ne 0) { throw 'Could not prepare isolated test database' }
& docker @compose run --rm test
if ($LASTEXITCODE -ne 0) { throw 'MySQL tests failed' }
