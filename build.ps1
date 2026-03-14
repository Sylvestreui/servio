# ServiceFlow — Build script (WordPress.org distribution zip)
# Usage: .\build.ps1

$pluginSlug    = "serviceflow"
$pluginVersion = "1.0.0"
$rootDir       = $PSScriptRoot
$distDir       = Join-Path $rootDir "dist"
$buildDir      = Join-Path $distDir $pluginSlug
$zipPath       = Join-Path $distDir "$pluginSlug-$pluginVersion.zip"

# Fichiers/dossiers à exclure
$exclude = @(
    ".git",
    ".gitignore",
    ".distignore",
    ".claude",
    "build.ps1",
    "list-zip.ps1",
    "dist",
    "composer.json",
    "composer.lock",
    "phpcs.xml",
    "CLAUDE.md",
    "lib\.gitignore",
    "lib\CHANGELOG.md",
    "lib\CONTRIBUTING.md",
    "lib\README.md",
    "lib\justfile",
    "lib\OPENAPI_VERSION",
    "lib\composer.json"
)

# Nettoyer
if (Test-Path $distDir) { Remove-Item $distDir -Recurse -Force }
New-Item -ItemType Directory -Path $buildDir | Out-Null

Write-Host "Copying plugin files..." -ForegroundColor Cyan

# Copier tout sauf les exclusions
Get-ChildItem -Path $rootDir -Force | Where-Object {
    $_.Name -notin $exclude
} | ForEach-Object {
    $dest = Join-Path $buildDir $_.Name
    if ($_.PSIsContainer) {
        Copy-Item $_.FullName $dest -Recurse -Force
    } else {
        Copy-Item $_.FullName $dest -Force
    }
}

# Supprimer les fichiers exclus qui sont dans des sous-dossiers
foreach ($exc in $exclude) {
    $target = Join-Path $buildDir $exc
    if (Test-Path $target) {
        Remove-Item $target -Recurse -Force
    }
}

# Créer le zip avec forward slashes (compatibilité Linux/serveur)
Write-Host "Creating zip: $zipPath" -ForegroundColor Cyan
Add-Type -AssemblyName System.IO.Compression

$zipStream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::Create)
$archive   = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)

Get-ChildItem -Path $buildDir -Recurse -File | ForEach-Object {
    $rel   = $_.FullName.Substring($buildDir.Length).TrimStart('\', '/').Replace('\', '/')
    $entry = $archive.CreateEntry("$pluginSlug/$rel", [System.IO.Compression.CompressionLevel]::Optimal)
    $dst   = $entry.Open()
    $src   = [System.IO.File]::OpenRead($_.FullName)
    $src.CopyTo($dst)
    $src.Close()
    $dst.Close()
}

$archive.Dispose()
$zipStream.Close()

# Stats
$zipSize = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
Write-Host ""
Write-Host "Done! $pluginSlug-$pluginVersion.zip ($zipSize MB)" -ForegroundColor Green
Write-Host "Path: $zipPath" -ForegroundColor Green
