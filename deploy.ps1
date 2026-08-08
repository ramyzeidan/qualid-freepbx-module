# QUALI-D FreePBX Module - Deploy Script
# Run from the FreePBX Module folder: .\deploy.ps1

$ErrorActionPreference = "Stop"

function Write-Header($msg) { Write-Host "" ; Write-Host $msg -ForegroundColor Cyan }
function Write-OK($msg)     { Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Info($msg)   { Write-Host "  [..] $msg" -ForegroundColor Gray }
function Write-Warn($msg)   { Write-Host "  [!!] $msg" -ForegroundColor Yellow }
function Write-Err($msg)    { Write-Host "  [XX] $msg" -ForegroundColor Red }

if (-not (Test-Path "qualid_remote/module.xml")) {
    Write-Err "Run this script from inside the FreePBX Module folder."
    exit 1
}

Write-Host ""
Write-Host "  QUALI-D FreePBX Module - Deploy" -ForegroundColor White
Write-Host "  ================================" -ForegroundColor DarkGray

Write-Header "Changed files:"
$status = git status --short

if ($status -ne $null -and $status.Count -gt 0) {
    $status | ForEach-Object { Write-Host "  $_" -ForegroundColor DarkYellow }
    Write-Header "Commit message (Enter to skip):"
    $msg = Read-Host "  >"
    if (-not $msg.Trim()) { $msg = "Deploy update" }
    Write-Info "Committing and pushing..."
    git add .
    git commit -m $msg
    git push
    Write-OK "Pushed to GitHub."
} else {
    Write-Warn "No changes -- proceeding to release."
}

$xml = [xml](Get-Content "qualid_remote/module.xml")
$currentVersion = $xml.module.version.Trim()
$parts = $currentVersion -split '\.'
$parts[-1] = [int]$parts[-1] + 1
$suggestedVersion = $parts -join '.'

Write-Header "Version:"
Write-Info "Current: $currentVersion  ->  suggested: $suggestedVersion"
$newVersion = Read-Host "  New version [Enter for $suggestedVersion]"
if (-not $newVersion.Trim()) { $newVersion = $suggestedVersion }

if ($newVersion -ne $currentVersion) {
    Write-Info "Updating module.xml to $newVersion..."
    $xmlContent = Get-Content "qualid_remote/module.xml" -Raw
    $xmlContent = $xmlContent -replace "<version>$([regex]::Escape($currentVersion))</version>", "<version>$newVersion</version>"
    Set-Content "qualid_remote/module.xml" $xmlContent -NoNewline
    git add qualid_remote/module.xml
    git commit -m "Bump version to $newVersion"
    git push
    Write-OK "module.xml updated and pushed."
}

$tag = "v$newVersion"
$existingTag = git tag -l $tag
if ($existingTag) {
    Write-Warn "Tag $tag already exists - deleting and recreating..."
    git tag -d $tag
    try {
        $prev = $ErrorActionPreference
        $ErrorActionPreference = "Continue"
        git push origin ":refs/tags/$tag" 2>&1 | Out-Null
        $ErrorActionPreference = $prev
    } catch { }
}

Write-Info "Creating tag $tag and pushing..."
git tag $tag
git push --tags
Write-OK "Tag $tag pushed - GitHub Actions is building the release."

$releaseUrl = "https://github.com/ramyzeidan/qualid-freepbx-module/releases/tag/$tag"
$installCmd = "fwconsole ma downloadinstall https://github.com/ramyzeidan/qualid-freepbx-module/releases/latest/download/qualid_remote.tar.gz"

Write-Host ""
Write-Host "  Release URL (ready in ~30s):" -ForegroundColor White
Write-Host "  $releaseUrl" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Install command:" -ForegroundColor White
Write-Host "  $installCmd" -ForegroundColor DarkCyan
Write-Host ""
