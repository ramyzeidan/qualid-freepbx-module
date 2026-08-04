#!/bin/bash
# =============================================================================
# QUALI-D Remote Agent FreePBX Module — Build Script
# Packages the qualid_remote/ directory into a .tar.gz ready for installation
#
# Usage:
#   ./build.sh              # builds qualid_remote-<version>.tar.gz
#   ./build.sh --publish    # also creates a GitHub release (requires gh CLI)
# =============================================================================

set -e

MODULE_DIR="qualid_remote"
VERSION=$(grep -oP '(?<=<version>)[^<]+' "$MODULE_DIR/module.xml" | head -1)
OUTPUT="qualid_remote-${VERSION}.tar.gz"

echo "Building QUALI-D Remote Agent Module v${VERSION}..."

# Clean previous build
rm -f "$OUTPUT"

# Create the tarball — the module dir must be at root of the archive
tar -czf "$OUTPUT" \
    --exclude="*.swp" \
    --exclude="*.DS_Store" \
    --exclude="__pycache__" \
    "$MODULE_DIR/"

echo ""
echo "✓ Built: $OUTPUT"
echo "  Size:  $(du -sh "$OUTPUT" | cut -f1)"
echo ""
echo "Install on FreePBX with:"
echo "  fwconsole ma downloadinstall https://your-host/releases/$OUTPUT"
echo ""

# Optional: publish as GitHub release
if [[ "$1" == "--publish" ]]; then
    if ! command -v gh &> /dev/null; then
        echo "ERROR: GitHub CLI (gh) not found. Install it from https://cli.github.com"
        exit 1
    fi

    echo "Creating GitHub release v${VERSION}..."
    gh release create "v${VERSION}" \
        "$OUTPUT" \
        --title "QUALI-D Remote Agent Module v${VERSION}" \
        --notes "FreePBX module release v${VERSION}. Install with:
\`\`\`
fwconsole ma downloadinstall https://github.com/\$(gh repo view --json nameWithOwner -q .nameWithOwner)/releases/download/v${VERSION}/${OUTPUT}
\`\`\`"

    echo ""
    echo "✓ GitHub release created."
    echo "  Install URL: https://github.com/$(gh repo view --json nameWithOwner -q .nameWithOwner)/releases/download/v${VERSION}/${OUTPUT}"
fi
