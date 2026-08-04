# QUALI-D FreePBX Module - Deploy Script
# Run from the FreePBX Module folder: .\deploy.ps1

$ErrorActionPreference = "Stop"

function Write-Header($msg) { Write-Host "" ; Write-Host $msg -ForegroundColor Cyan }
function Write-OK($msg)     { Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Info($msg)   { Write-Host "  [..] $msg" -ForegroundColor Gray }
function Write-Warn($msg)   { Write-Host "  [!!] $msg" -ForegroundColor Yellow }
function Write-Err($msg)    { Write-Host "  [XX] $msg" -ForegroundColor Red }

# Make sure we are in the right folder
if (-not (Test-Path "qualid_remote/module.xml")) {
    Write-Err "Run this script from inside the FreePBX Module folder."
    exit 1
}

Write-Host ""
Write-Host "  QUALI-D FreePBX Module - Deploy" -ForegroundColor White
Write-Host "  ================================" -ForegroundColor DarkGray

# ---------------------------------------------------------------------------
# Show changed files
# ---------------------------------------------------------------------------
Write-Header "Changed files:"
$status = git status --short
$hasChanges = $status -ne $null -and $status.Count -gt 0

if ($hasChanges) {
    $status | ForEach-Object { Write-Host "  $_" -ForegroundColor DarkYellow }

    # Commit message
    Write-Header "Commit message:"
    $msg = Read-Host "  >"
    if (-not $msg.Trim()) {
        Write-Err "Commit message cannot be empty."
        exit 1
    }

    # Stage, commit, push
    Write-Header "Committing and pushing..."
    git add .
    git commit -m $msg
    git push
    Write-OK "Pushed to GitHub."

} else {
    Write-Warn "Nothing changed since last commit."
    $choice = Read-Host "  Nothing to commit. Create a release tag anyway? (y/N)"
    if ($choice -ne 'y') { exit 0 }
}

# ---------------------------------------------------------------------------
# Release?
# ---------------------------------------------------------------------------
Write-Host ""
$release = Read-Host "  Create a release? (y/N)"
if ($release -ne 'y') {
    Write-Host ""
    Write-OK "Done. Code is on GitHub but no release was created."
    Write-Host "  Customers will not get an update until you create a release." -ForegroundColor DarkGray
    Write-Host ""
    exit 0
}

# ---------------------------------------------------------------------------
# Read current version from module.xml
# ---------------------------------------------------------------------------
$xml = [xml](Get-Content "qualid_remote/module.xml")
$currentVersion = $xml.module.version.Trim()
Write-Info "Current version in module.xml: $currentVersion"

# ---------------------------------------------------------------------------
# New version number
# ---------------------------------------------------------------------------
$newVersion = Read-Host "  New version (e.g. 1.0.1) [enter to keep $currentVersion]"
if (-not $newVersion.Trim()) {
    $newVersion = $currentVersion
}

# Update module.xml if version changed
if ($newVersion -ne $currentVersion) {
    Write-Info "Updating module.xml to $newVersion..."
    $content = Get-Content "qualid_remote/module.xml" -Raw
    $content = $content -replace "<version>$currentVersion</version>", "<version>$newVersion</version>"
    Set-Content "qualid_remote/module.xml" $content -NoNewline
    git add qualid_remote/module.xml
    git commit -m "Bump version to $newVersion"
    git push
    Write-OK "module.xml updated and pushed."
}

# ---------------------------------------------------------------------------
# Tag and push
# ---------------------------------------------------------------------------
$tag = "v$newVersion"
$existingTag = git tag -l $tag
if ($existingTag) {
    Write-Warn "Tag $tag already exists - deleting and recreating..."
    git tag -d $tag
    git push origin ":refs/tags/$tag" 2>$null
}

Write-Info "Creating tag $tag and pushing..."
git tag $tag
git push --tags
Write-OK "Tag $tag pushed - GitHub Actions is building the release now."

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
$releaseUrl = "https://github.com/ramyzeidan/qualid-freepbx-module/releases/tag/$tag"
$installCmd = "fwconsole ma downloadinstall https://github.com/ramyzeidan/qualid-freepbx-module/releases/latest/download/qualid_remote.tar.gz"

Write-Host ""
Write-Host "  Release URL (ready in ~30s):" -ForegroundColor White
Write-Host "  $releaseUrl" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Customer install command:" -ForegroundColor White
Write-Host "  $installCmd" -ForegroundColor DarkCyan
Write-Host ""
