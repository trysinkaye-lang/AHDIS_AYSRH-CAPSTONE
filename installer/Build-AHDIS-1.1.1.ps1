# AHDIS 1.1.1: prepare a disposable sanitized staging copy, then compile with Inno Setup.
# Does not run/uninstall AHDIS or delete/modify production data.
# Place this script and AHDIS_Updated_1.1.1.iss in C:\AHDIS-New-Installer.
$ErrorActionPreference = 'Stop'

$root   = 'C:\AHDIS-New-Installer'
$source = 'C:\AHD Information System\www'
$pkg    = Join-Path $root 'AHDIS_Package'
$iss    = Join-Path $root 'AHDIS_Updated_1.1.1.iss'
$out    = Join-Path $root 'Output'
$stamp  = Get-Date -Format 'yyyyMMdd-HHmmss'
$stage  = Join-Path $root ("AHDIS_Stage_1.1.1_" + $stamp)
$exeName = 'Adolescent Health and Development Information System.exe'

$corrected = @(
  'profiling\add.php',
  'profiling\save.php',
  'includes\fertility_validation.php',
  'import file\import_data.php',
  'import file\import_backup.php'
)

foreach ($path in @($source, $pkg, $iss)) {
  if (-not (Test-Path -LiteralPath $path)) { throw "Missing required path: $path" }
}
if (-not (Test-Path -LiteralPath (Join-Path $pkg $exeName))) {
  throw "Expected desktop executable is absent from the package: $exeName"
}
foreach ($relative in $corrected) {
  if (-not (Test-Path -LiteralPath (Join-Path $source $relative))) {
    throw "Missing updated source file: $relative"
  }
}

# Copy the working package; exclude only its own mutable database/cache directories.
# Do NOT delete/modify the package, QA install, or currently installed AHDIS.
$excluded = @(
  (Join-Path $pkg 'www\data'),
  (Join-Path $pkg 'webcache')
)
$backupDirs = @(Get-ChildItem -LiteralPath (Join-Path $pkg 'www') -Directory -ErrorAction SilentlyContinue |
  Where-Object { $_.Name -match '(?i)(^ahdis-.*backup|^_?backups?$)' } |
  Select-Object -ExpandProperty FullName)
$excluded += $backupDirs

Write-Host "Preparing sanitized staging directory: $stage" -ForegroundColor Cyan
$copyArgs = @($pkg, $stage, '/E', '/R:1', '/W:1', '/NP', '/NFL', '/NDL', '/NJH', '/NJS', '/XD') +
  $excluded + @('/XF', '*.db', '*.db-wal', '*.db-shm', '*.sqlite', '*.sqlite-wal', '*.sqlite-shm', '*.sqlite3', '*.sqlite3-wal', '*.sqlite3-shm')
& robocopy.exe @copyArgs | Out-Null
$copyExit = $LASTEXITCODE
if ($copyExit -gt 7) { throw "Staging copy failed (robocopy exit code $copyExit)." }

# Always take the currently corrected fertility files directly from source.
foreach ($relative in $corrected) {
  $src = Join-Path $source $relative
  $dst = Join-Path (Join-Path $stage 'www') $relative
  New-Item -ItemType Directory -Path (Split-Path -Parent $dst) -Force | Out-Null
  Copy-Item -LiteralPath $src -Destination $dst -Force
  $a = (Get-FileHash -LiteralPath $src -Algorithm SHA256).Hash
  $b = (Get-FileHash -LiteralPath $dst -Algorithm SHA256).Hash
  if ($a -ne $b) { throw "Source/stage hash mismatch: $relative" }
  Write-Host "VERIFIED: $relative" -ForegroundColor Green
}

# Enforce no packaged SQLite databases, regardless of their original location.
$databases = @(Get-ChildItem -LiteralPath $stage -File -Recurse -ErrorAction Stop |
  Where-Object { $_.Name -match '(?i)\.(db|sqlite|sqlite3)(-(wal|shm))?$' })
if ($databases.Count -gt 0) {
  $databases | Select-Object -ExpandProperty FullName
  throw 'Database files found in staging. No installer was compiled.'
}
$knownDummyFiles = @(Get-ChildItem -LiteralPath $stage -File -Recurse -ErrorAction Stop |
  Where-Object { $_.Name -match '(?i)^(dummy_import|dummy_profiles|test_profiles)\.' })
if ($knownDummyFiles.Count -gt 0) {
  $knownDummyFiles | Select-Object -ExpandProperty FullName
  throw 'Test import files detected in staging. No installer was compiled.'
}

# AHDIS creates the database itself on first launch.
New-Item -ItemType Directory -Path (Join-Path $stage 'www\data') -Force | Out-Null

$compilerCandidates = @(
  'C:\Program Files (x86)\Inno Setup 6\ISCC.exe',
  'C:\Program Files\Inno Setup 6\ISCC.exe',
  'C:\Program Files\Inno Setup 7\ISCC.exe',
  'C:\Program Files (x86)\Inno Setup 7\ISCC.exe'
)
$cmd = Get-Command ISCC.exe -ErrorAction SilentlyContinue
if ($cmd) { $compilerCandidates = @($cmd.Source) + $compilerCandidates }
$compiler = $compilerCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $compiler) { throw 'Inno Setup ISCC.exe not found. Install Inno Setup or update its path.' }

New-Item -ItemType Directory -Path $out -Force | Out-Null
$target = Join-Path $out 'AHDIS_Clean_Setup_1.1.1.exe'
if (Test-Path -LiteralPath $target) {
  $existingBackup = Join-Path $out ("AHDIS_Clean_Setup_1.1.1.previous-$stamp.exe")
  Copy-Item -LiteralPath $target -Destination $existingBackup -Force
  Write-Host "Previous installer backed up to $existingBackup" -ForegroundColor Yellow
}
Write-Host 'Compiling AHDIS 1.1.1 with Inno Setup...' -ForegroundColor Cyan
& $compiler ("/DStageDir=$stage") ("/O$out") $iss
if ($LASTEXITCODE -ne 0) { throw "Inno Setup compilation failed: $LASTEXITCODE" }
if (-not (Test-Path -LiteralPath $target)) { throw "Compiled installer not found: $target" }

Write-Host 'SUCCESS: Installer compiled. NOT YET QA-VERIFIED.' -ForegroundColor Green
Get-Item -LiteralPath $target | Select-Object FullName, Length, LastWriteTime
Write-Host "Staging copy preserved at: $stage"
Write-Host 'Next: install on a disposable test setup; test forms, import, uninstall and fresh reinstall.'