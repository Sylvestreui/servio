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

# Créer le zip
Write-Host "Creating zip: $zipPath" -ForegroundColor Cyan
Compress-Archive -Path $buildDir -DestinationPath $zipPath -Force

# Stats
$zipSize = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
Write-Host ""
Write-Host "Done! $pluginSlug-$pluginVersion.zip ($zipSize MB)" -ForegroundColor Green
Write-Host "Path: $zipPath" -ForegroundColor Green
