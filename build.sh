#!/bin/bash

# WP LMS Plugin Build Script
# Automatically increments version number and creates a ZIP package

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Plugin information
PLUGIN_NAME="wp-lms-plugin"
PLUGIN_FILE="wp-lms-plugin.php"
VERSION_FILE="version.txt"

echo -e "${BLUE}🚀 WP LMS Plugin Build Script${NC}"
echo "=================================="

# Check if we're in the right directory
if [ ! -f "$PLUGIN_FILE" ]; then
    echo -e "${RED}❌ Error: $PLUGIN_FILE not found. Are you in the plugin directory?${NC}"
    exit 1
fi

# Create version file if it doesn't exist
if [ ! -f "$VERSION_FILE" ]; then
    echo "1.0.0" > "$VERSION_FILE"
    echo -e "${YELLOW}📝 Created $VERSION_FILE with initial version 1.0.0${NC}"
fi

# Read current version
CURRENT_VERSION=$(cat "$VERSION_FILE")
echo -e "${BLUE}📋 Current version: $CURRENT_VERSION${NC}"

# Parse version components
IFS='.' read -r -a VERSION_PARTS <<< "$CURRENT_VERSION"
MAJOR=${VERSION_PARTS[0]}
MINOR=${VERSION_PARTS[1]}
PATCH=${VERSION_PARTS[2]}

# Ask user what type of version bump
echo ""
echo "Select version increment type:"
echo "1) Patch (bug fixes): $MAJOR.$MINOR.$((PATCH + 1))"
echo "2) Minor (new features): $MAJOR.$((MINOR + 1)).0"
echo "3) Major (breaking changes): $((MAJOR + 1)).0.0"
echo "4) Custom version"
echo ""
read -p "Enter choice (1-4) [default: 1]: " VERSION_CHOICE

case $VERSION_CHOICE in
    2)
        NEW_VERSION="$MAJOR.$((MINOR + 1)).0"
        ;;
    3)
        NEW_VERSION="$((MAJOR + 1)).0.0"
        ;;
    4)
        read -p "Enter custom version (e.g., 2.1.5): " NEW_VERSION
        # Validate version format
        if [[ ! $NEW_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            echo -e "${RED}❌ Invalid version format. Use x.y.z format.${NC}"
            exit 1
        fi
        ;;
    *)
        NEW_VERSION="$MAJOR.$MINOR.$((PATCH + 1))"
        ;;
esac

echo -e "${GREEN}📈 New version: $NEW_VERSION${NC}"

# Update version in files
echo -e "${YELLOW}🔄 Updating version in files...${NC}"

# Update main plugin file
sed -i.bak "s/Version: $CURRENT_VERSION/Version: $NEW_VERSION/g" "$PLUGIN_FILE"
sed -i.bak "s/define('WP_LMS_VERSION', '$CURRENT_VERSION')/define('WP_LMS_VERSION', '$NEW_VERSION')/g" "$PLUGIN_FILE"

# Update README.md
if [ -f "README.md" ]; then
    sed -i.bak "s/### Version $CURRENT_VERSION/### Version $NEW_VERSION/g" README.md
fi

# Save new version
echo "$NEW_VERSION" > "$VERSION_FILE"

# Remove backup files
rm -f *.bak

echo -e "${GREEN}✅ Version updated to $NEW_VERSION${NC}"

# Create build directory
BUILD_DIR="build"
PLUGIN_DIR="$BUILD_DIR/$PLUGIN_NAME"
ZIP_FILE="$BUILD_DIR/${PLUGIN_NAME}-v${NEW_VERSION}.zip"

echo -e "${YELLOW}📦 Creating build package...${NC}"

# Clean and create build directory
rm -rf "$BUILD_DIR"
mkdir -p "$PLUGIN_DIR"

# Copy plugin files (exclude development files)
echo -e "${BLUE}📋 Copying plugin files...${NC}"

# Copy main files
cp "$PLUGIN_FILE" "$PLUGIN_DIR/"
cp "README.md" "$PLUGIN_DIR/"

# Copy includes directory
if [ -d "includes" ]; then
    cp -r "includes" "$PLUGIN_DIR/"
    echo "✓ Copied includes/"
fi

# Copy assets directory
if [ -d "assets" ]; then
    cp -r "assets" "$PLUGIN_DIR/"
    echo "✓ Copied assets/"
fi

# Copy languages directory if it exists
if [ -d "languages" ]; then
    cp -r "languages" "$PLUGIN_DIR/"
    echo "✓ Copied languages/"
fi

# Copy templates directory if it exists
if [ -d "templates" ]; then
    cp -r "templates" "$PLUGIN_DIR/"
    echo "✓ Copied templates/"
fi

# Create changelog entry
CHANGELOG_FILE="$PLUGIN_DIR/CHANGELOG.md"
if [ ! -f "CHANGELOG.md" ]; then
    echo "# Changelog" > "$CHANGELOG_FILE"
    echo "" >> "$CHANGELOG_FILE"
fi

# Add new version entry to changelog
CURRENT_DATE=$(date +"%Y-%m-%d")
{
    echo "## Version $NEW_VERSION - $CURRENT_DATE"
    echo ""
    echo "### Added"
    echo "- Version $NEW_VERSION release"
    echo ""
    if [ -f "CHANGELOG.md" ]; then
        tail -n +2 "CHANGELOG.md"
    fi
} > "$CHANGELOG_FILE.tmp" && mv "$CHANGELOG_FILE.tmp" "$CHANGELOG_FILE"

# Create ZIP file
echo -e "${YELLOW}🗜️  Creating ZIP archive...${NC}"
cd "$BUILD_DIR"
zip -r "${PLUGIN_NAME}-v${NEW_VERSION}.zip" "$PLUGIN_NAME" -q

# Calculate file size
FILE_SIZE=$(du -h "${PLUGIN_NAME}-v${NEW_VERSION}.zip" | cut -f1)

cd ..

echo ""
echo -e "${GREEN}🎉 Build completed successfully!${NC}"
echo "=================================="
echo -e "${BLUE}📦 Package: ${NC}$ZIP_FILE"
echo -e "${BLUE}📏 Size: ${NC}$FILE_SIZE"
echo -e "${BLUE}🏷️  Version: ${NC}$NEW_VERSION"
echo ""

# Show package contents
echo -e "${YELLOW}📋 Package contents:${NC}"
unzip -l "$ZIP_FILE" | head -20

# Optional: Open build directory
if command -v open >/dev/null 2>&1; then
    read -p "Open build directory? (y/N): " OPEN_DIR
    if [[ $OPEN_DIR =~ ^[Yy]$ ]]; then
        open "$BUILD_DIR"
    fi
elif command -v xdg-open >/dev/null 2>&1; then
    read -p "Open build directory? (y/N): " OPEN_DIR
    if [[ $OPEN_DIR =~ ^[Yy]$ ]]; then
        xdg-open "$BUILD_DIR"
    fi
fi

echo ""
echo -e "${GREEN}✅ Ready for deployment!${NC}"
echo -e "${BLUE}💡 Upload ${PLUGIN_NAME}-v${NEW_VERSION}.zip to WordPress${NC}"

# Git integration (optional)
if [ -d ".git" ]; then
    echo ""
    read -p "Create git tag for this version? (y/N): " CREATE_TAG
    if [[ $CREATE_TAG =~ ^[Yy]$ ]]; then
        git add .
        git commit -m "Release version $NEW_VERSION" || true
        git tag -a "v$NEW_VERSION" -m "Version $NEW_VERSION"
        echo -e "${GREEN}✅ Git tag v$NEW_VERSION created${NC}"
        
        read -p "Push to remote repository? (y/N): " PUSH_REMOTE
        if [[ $PUSH_REMOTE =~ ^[Yy]$ ]]; then
            git push origin main || git push origin master || true
            git push origin "v$NEW_VERSION"
            echo -e "${GREEN}✅ Pushed to remote repository${NC}"
        fi
    fi
fi

echo ""
echo -e "${BLUE}🎯 Build process completed!${NC}"
