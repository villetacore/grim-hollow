# Regenerates the README images in docs/images with the real rules engine and client renderer.
# Needs the server vendor directory (composer install), Lazarus/FPC and Python with Pillow.
param([string]$Php = 'php', [string]$Python = 'python')
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$lazarus = $env:LAZARUS_HOME
if (-not $lazarus) { $lazarus = Join-Path $root '.tools/lazarus/app' }
if (-not (Test-Path -LiteralPath "$lazarus/lazbuild.exe")) { $lazarus = 'C:/lazarus' }
& $Php "$root/tools/screenshots/scenes.php" "$root/build/screenshots"
if ($LASTEXITCODE -ne 0) { throw 'Scene generation failed' }
& "$lazarus/lazbuild.exe" "--lazarusdir=$lazarus" "--pcp=$root/.tools/lazarus-config" --widgetset=win32 "$root/tools/pascal/Screenshots.lpi" | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Screenshot tool compilation failed' }
& "$root/build/tools/screenshots.exe" "$root/build/screenshots" "$root/build/screenshots"
if ($LASTEXITCODE -ne 0) { throw 'Rendering failed' }
& $Python -c "import sys,glob,os;from PIL import Image;[Image.open(f).save(os.path.join(sys.argv[1],os.path.basename(f)[:-4]+'.png'),optimize=True) for f in glob.glob(os.path.join(sys.argv[2],'*.bmp'))]" "$root/docs/images" "$root/build/screenshots"
Write-Host 'Updated docs/images'
