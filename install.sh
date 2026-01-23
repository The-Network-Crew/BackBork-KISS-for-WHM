#!/bin/bash
#
# BackBork KISS - Installation Script
# Disaster Recovery Plugin for WHM
#
# Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
# https://github.com/The-Network-Crew/BackBork-KISS-for-WHM
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program. If not, see <https://www.gnu.org/licenses/>.
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Plugin info
PLUGIN_NAME="BackBork KISS"

echo -e "${BLUE}"
echo "╔═══════════════════════════════════════════════════════╗"
echo "║               BackBork KISS Installer                 ║"
echo "║          Disaster Recovery Plugin for WHM             ║"
echo "╚═══════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Error: This script must be run as root${NC}"
    exit 1
fi

# Check for cPanel
if [ ! -d "/usr/local/cpanel" ]; then
    echo -e "${RED}Error: WHM not found. This plugin requires WHM.${NC}"
    exit 1
fi

echo -e "${YELLOW}Starting installation...${NC}"

# Detect database server and install backup tools
echo -e "${BLUE}Detecting database server...${NC}"

DB_INFO=$(whmapi1 current_mysql_version --output=json 2>/dev/null)
DB_SERVER=$(echo "$DB_INFO" | grep -o '"server":"[^"]*"' | cut -d'"' -f4)
DB_VERSION=$(echo "$DB_INFO" | grep -o '"version":"[^"]*"' | cut -d'"' -f4)

if [ -z "$DB_SERVER" ]; then
    echo -e "${YELLOW}  ⚠ Could not detect database server, skipping backup tool installation${NC}"
else
    echo -e "${GREEN}  ✓ Detected: ${DB_SERVER} ${DB_VERSION}${NC}"
    
    if [ "$DB_SERVER" = "mariadb" ]; then
        # Check if mariadb-backup is installed
        if command -v mariadb-backup &> /dev/null || command -v mariabackup &> /dev/null; then
            echo -e "${GREEN}  ✓ mariadb-backup is already installed${NC}"
        else
            echo -e "${YELLOW}  Installing mariadb-backup package...${NC}"
            
            # Detect package manager and install
            if command -v dnf &> /dev/null; then
                dnf install -y MariaDB-backup 2>/dev/null && \
                    echo -e "${GREEN}  ✓ mariadb-backup installed successfully${NC}" || \
                    echo -e "${YELLOW}  ⚠ Could not install mariadb-backup (optional)${NC}"
            elif command -v yum &> /dev/null; then
                yum install -y MariaDB-backup 2>/dev/null && \
                    echo -e "${GREEN}  ✓ mariadb-backup installed successfully${NC}" || \
                    echo -e "${YELLOW}  ⚠ Could not install mariadb-backup (optional)${NC}"
            elif command -v apt-get &> /dev/null; then
                apt-get install -y mariadb-backup 2>/dev/null && \
                    echo -e "${GREEN}  ✓ mariadb-backup installed successfully${NC}" || \
                    echo -e "${YELLOW}  ⚠ Could not install mariadb-backup (optional)${NC}"
            else
                echo -e "${YELLOW}  ⚠ Unknown package manager, please install mariadb-backup manually${NC}"
            fi
        fi
    elif [ "$DB_SERVER" = "mysql" ]; then
        # Check if mysqlbackup is installed (MySQL Enterprise only)
        if command -v mysqlbackup &> /dev/null; then
            echo -e "${GREEN}  ✓ mysqlbackup is already installed${NC}"
        else
            echo -e "${YELLOW}  ⚠ mysqlbackup not found (MySQL Enterprise Backup)${NC}"
            echo -e "${YELLOW}    This is a commercial product - install manually if licensed${NC}"
            echo -e "${YELLOW}    The plugin will use mysqldump via pkgacct as fallback${NC}"
        fi
    fi
fi

# Get the directory where the script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Define installation paths
WHM_CGI_DIR="/usr/local/cpanel/whostmgr/docroot/cgi"
APPS_DIR="/var/cpanel/apps"
CONFIG_DIR="/usr/local/cpanel/3rdparty/backbork"
ICON_DIR="/usr/local/cpanel/whostmgr/docroot/addon_plugins"

# Create directories
echo -e "${BLUE}Creating directories...${NC}"

mkdir -p "${WHM_CGI_DIR}/backbork/includes"
mkdir -p "${WHM_CGI_DIR}/backbork/app/css"
mkdir -p "${WHM_CGI_DIR}/backbork/app/js"
mkdir -p "${WHM_CGI_DIR}/backbork/api"
mkdir -p "${WHM_CGI_DIR}/backbork/engine/whmapi1"
mkdir -p "${WHM_CGI_DIR}/backbork/engine/transport"
mkdir -p "${WHM_CGI_DIR}/backbork/engine/destinations"
mkdir -p "${WHM_CGI_DIR}/backbork/engine/backup"
mkdir -p "${WHM_CGI_DIR}/backbork/engine/restore"
mkdir -p "${WHM_CGI_DIR}/backbork/engine/queue"
mkdir -p "${WHM_CGI_DIR}/backbork/cron"
mkdir -p "${WHM_CGI_DIR}/backbork/templates/notifications"
mkdir -p "${WHM_CGI_DIR}/backbork/templates/schedules"
mkdir -p "${WHM_CGI_DIR}/backbork/includes/gui"
mkdir -p "${WHM_CGI_DIR}/backbork/includes/pages"
mkdir -p "${CONFIG_DIR}/users"
mkdir -p "${CONFIG_DIR}/schedules"
mkdir -p "${CONFIG_DIR}/queue"
mkdir -p "${CONFIG_DIR}/running"
mkdir -p "${CONFIG_DIR}/restores"
mkdir -p "${CONFIG_DIR}/completed"
mkdir -p "${CONFIG_DIR}/cancel"
mkdir -p "${CONFIG_DIR}/manifests"
mkdir -p "${CONFIG_DIR}/logs"
mkdir -p "${APPS_DIR}"
mkdir -p "${ICON_DIR}"

# Copy plugin files
echo -e "${BLUE}Installing plugin files...${NC}"

# Copy WHM CGI files
cp -r "${SCRIPT_DIR}/usr/local/cpanel/whostmgr/docroot/cgi/backbork/"* "${WHM_CGI_DIR}/backbork/"

# Copy updater script to config directory (persists across updates)
cp "${SCRIPT_DIR}/usr/local/cpanel/3rdparty/backbork/updater.sh" "${CONFIG_DIR}/updater.sh"
chmod 755 "${CONFIG_DIR}/updater.sh"

# Update commit info in version.php (only if installed via git clone)
VERSION_FILE="${WHM_CGI_DIR}/backbork/version.php"

if command -v git &> /dev/null && [ -d "${SCRIPT_DIR}/.git" ]; then
    COMMIT_HASH=$(cd "${SCRIPT_DIR}" && git rev-parse --short HEAD 2>/dev/null)
    COMMIT_DATE=$(cd "${SCRIPT_DIR}" && git log -1 --format="%ci" 2>/dev/null | cut -d' ' -f1-2)
    if [ -n "${COMMIT_HASH}" ]; then
        sed -i.bak "s/define('BACKBORK_COMMIT', '[^']*')/define('BACKBORK_COMMIT', '${COMMIT_HASH}')/" "${VERSION_FILE}"
        sed -i.bak "s/define('BACKBORK_COMMIT_DATE', '[^']*')/define('BACKBORK_COMMIT_DATE', '${COMMIT_DATE}')/" "${VERSION_FILE}"
        rm -f "${VERSION_FILE}.bak"
        echo -e "${GREEN}Commit: ${COMMIT_HASH} (${COMMIT_DATE})${NC}"
    fi
else
    echo -e "${YELLOW}Version/commit unknown (not installed via git clone)${NC}"
    echo -e "${YELLOW}For commit tracking, use: git clone https://github.com/The-Network-Crew/BackBork-KISS-for-WHM.git${NC}"
fi

# Copy AppConfig
cp "${SCRIPT_DIR}/var/cpanel/apps/backbork.conf" "${APPS_DIR}/backbork.conf"

# Set permissions
echo -e "${BLUE}Setting permissions...${NC}"

# WHM CGI files - root directory
chmod 755 "${WHM_CGI_DIR}/backbork"

# PHP files - readable/executable
find "${WHM_CGI_DIR}/backbork" -name "*.php" -exec chmod 644 {} \;

# Cron handler needs to be executable
chmod 755 "${WHM_CGI_DIR}/backbork/cron/handler.php"

# Background job runner needs to be executable (in api/ since it's spawned by the API)
chmod 755 "${WHM_CGI_DIR}/backbork/api/runner.php"

# Perl helper script needs to be executable
chmod 755 "${WHM_CGI_DIR}/backbork/engine/transport/cpanel_transport.pl"

# Directories
chmod 755 "${WHM_CGI_DIR}/backbork/includes"
chmod 755 "${WHM_CGI_DIR}/backbork/includes/gui"
chmod 755 "${WHM_CGI_DIR}/backbork/includes/pages"
chmod 755 "${WHM_CGI_DIR}/backbork/app"
chmod 755 "${WHM_CGI_DIR}/backbork/app/css"
chmod 755 "${WHM_CGI_DIR}/backbork/app/js"
chmod 755 "${WHM_CGI_DIR}/backbork/api"
chmod 755 "${WHM_CGI_DIR}/backbork/engine"
chmod 755 "${WHM_CGI_DIR}/backbork/engine/whmapi1"
chmod 755 "${WHM_CGI_DIR}/backbork/engine/transport"
chmod 755 "${WHM_CGI_DIR}/backbork/engine/destinations"
chmod 755 "${WHM_CGI_DIR}/backbork/engine/backup"
chmod 755 "${WHM_CGI_DIR}/backbork/engine/restore"
chmod 755 "${WHM_CGI_DIR}/backbork/engine/queue"
chmod 755 "${WHM_CGI_DIR}/backbork/cron"
chmod 755 "${WHM_CGI_DIR}/backbork/templates"
chmod 755 "${WHM_CGI_DIR}/backbork/templates/notifications"
chmod 755 "${WHM_CGI_DIR}/backbork/templates/schedules"

# Static assets - readable
chmod 644 "${WHM_CGI_DIR}/backbork/app/css/"*.css 2>/dev/null || true
chmod 644 "${WHM_CGI_DIR}/backbork/app/js/"*.js 2>/dev/null || true
chmod 644 "${WHM_CGI_DIR}/backbork/templates/notifications/"* 2>/dev/null || true
chmod 644 "${WHM_CGI_DIR}/backbork/templates/schedules/"* 2>/dev/null || true

# Config directory - root only
chmod 700 "${CONFIG_DIR}"
chmod 700 "${CONFIG_DIR}/users"
chmod 700 "${CONFIG_DIR}/schedules"
chmod 700 "${CONFIG_DIR}/queue"
chmod 700 "${CONFIG_DIR}/running"
chmod 700 "${CONFIG_DIR}/restores"
chmod 700 "${CONFIG_DIR}/completed"
chmod 700 "${CONFIG_DIR}/cancel"
chmod 700 "${CONFIG_DIR}/manifests"
chmod 700 "${CONFIG_DIR}/logs"

# AppConfig
chmod 644 "${APPS_DIR}/backbork.conf"

# Create plugin icon (base64 encoded simple shield SVG)
echo -e "${BLUE}Creating plugin icon...${NC}"

# Create a simple SVG icon with merlot/gold theme
cat > "${ICON_DIR}/backbork.svg" << 'ICON_EOF'
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48">
  <defs>
    <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#722f37;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#4a1c2a;stop-opacity:1" />
    </linearGradient>
    <linearGradient id="grad2" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#c9a962;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#9a7b4f;stop-opacity:1" />
    </linearGradient>
  </defs>
  <path fill="url(#grad1)" d="M24 4L6 12v12c0 11.1 7.7 21.5 18 24 10.3-2.5 18-12.9 18-24V12L24 4z"/>
  <path fill="url(#grad2)" d="M21 28l-5-5 2.1-2.1 2.9 2.9 7.9-7.9 2.1 2.1-10 10z"/>
</svg>
ICON_EOF

echo -e "${GREEN}  ✓ Icon created in addon_plugins${NC}"

# Register plugin with AppConfig
echo -e "${BLUE}Registering plugin with AppConfig...${NC}"
/usr/local/cpanel/bin/register_appconfig "${APPS_DIR}/backbork.conf"

# Setup cron job for queue processing
echo -e "${BLUE}Setting up cron job...${NC}"

CRON_FILE="/etc/cron.d/backbork"
cat > "${CRON_FILE}" << 'CRON_EOF'
# BackBork KISS - Queue and Schedule Processor
# Runs every 5 minutes to process backup queue and scheduled jobs
*/5 * * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1

# Daily summary notification (runs at midnight)
0 0 * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php summary >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1

# Daily cleanup of old completed jobs and logs (runs at 3 AM)
0 3 * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php cleanup >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1
CRON_EOF

chmod 644 "${CRON_FILE}"

# Restart cpsrvd to apply AppConfig changes
echo -e "${BLUE}Restarting cpsrvd service...${NC}"
/usr/local/cpanel/scripts/restartsrv_cpsrvd

# Verify installation
echo -e "${BLUE}Verifying installation...${NC}"

ERRORS=0

if [ ! -f "${WHM_CGI_DIR}/backbork/index.php" ]; then
    echo -e "${RED}  ✗ Main plugin file not found${NC}"
    ERRORS=$((ERRORS + 1))
else
    echo -e "${GREEN}  ✓ Main plugin file installed${NC}"
fi

if [ ! -f "${WHM_CGI_DIR}/backbork/version.php" ]; then
    echo -e "${RED}  ✗ Version file not found${NC}"
    ERRORS=$((ERRORS + 1))
else
    echo -e "${GREEN}  ✓ Version file installed${NC}"
fi

if [ ! -f "${WHM_CGI_DIR}/backbork/app/Bootstrap.php" ]; then
    echo -e "${RED}  ✗ Bootstrap not found${NC}"
    ERRORS=$((ERRORS + 1))
else
    echo -e "${GREEN}  ✓ Bootstrap installed${NC}"
fi

if [ ! -f "${APPS_DIR}/backbork.conf" ]; then
    echo -e "${RED}  ✗ AppConfig file not found${NC}"
    ERRORS=$((ERRORS + 1))
else
    echo -e "${GREEN}  ✓ AppConfig registered${NC}"
fi

if [ ! -f "${CRON_FILE}" ]; then
    echo -e "${RED}  ✗ Cron job not created${NC}"
    ERRORS=$((ERRORS + 1))
else
    echo -e "${GREEN}  ✓ Cron job configured${NC}"
fi

if [ ! -d "${CONFIG_DIR}" ]; then
    echo -e "${RED}  ✗ Config directory not created${NC}"
    ERRORS=$((ERRORS + 1))
else
    echo -e "${GREEN}  ✓ Config directory ready${NC}"
fi

# Check database backup tool status
if [ "$DB_SERVER" = "mariadb" ]; then
    if command -v mariadb-backup &> /dev/null || command -v mariabackup &> /dev/null; then
        echo -e "${GREEN}  ✓ mariadb-backup available for hot backups${NC}"
    else
        echo -e "${YELLOW}  ⚠ mariadb-backup not available (will use mysqldump)${NC}"
    fi
elif [ "$DB_SERVER" = "mysql" ]; then
    if command -v mysqlbackup &> /dev/null; then
        echo -e "${GREEN}  ✓ mysqlbackup available for hot backups${NC}"
    else
        echo -e "${YELLOW}  ⚠ mysqlbackup not available (will use mysqldump)${NC}"
    fi
fi

# Final status
echo ""

# Get server FQDN for display
SERVER_FQDN=$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "your-server")

if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║          Installation completed successfully!             ║${NC}"
    echo -e "${GREEN}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${BLUE}Access the plugin:${NC}"
    echo "  WHM >> Backup >> BackBork KISS"
    echo ""
    echo -e "${BLUE}Or navigate to:${NC}"
    echo "  https://${SERVER_FQDN}:2087/cgi/backbork/index.php"
    echo ""
    echo -e "${YELLOW}Important:${NC}"
    echo "  1. Configure SFTP destinations in WHM >> Backup >> Backup Configuration"
    echo "  2. Set up email/Slack notifications in the plugin settings"
    echo "  3. Resellers can only backup accounts they own"
    echo ""
else
    echo -e "${RED}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║     Installation completed with ${ERRORS} error(s)                 ║${NC}"
    echo -e "${RED}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "Please check the errors above and try again."
    exit 1
fi
