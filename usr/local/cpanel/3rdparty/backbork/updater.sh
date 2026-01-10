#!/bin/bash
#
# BackBork KISS - Self-Update Script
# Disaster Recovery Plugin for WHM
#
# This script is stored permanently at /usr/local/cpanel/3rdparty/backbork/updater.sh
# and survives plugin updates. It downloads the latest version from GitHub,
# installs it, and sends notifications upon completion.
#
# Copyright (c) The Network Crew Pty Ltd & Velocity Host Pty Ltd
# https://github.com/The-Network-Crew/BackBork-KISS-for-WHM
#

set -e

# ============================================================================
# CONFIGURATION
# ============================================================================

# GitHub repository details
GITHUB_REPO="The-Network-Crew/BackBork-KISS-for-WHM"
GITHUB_BRANCH="main"
DOWNLOAD_URL="https://github.com/${GITHUB_REPO}/archive/refs/heads/${GITHUB_BRANCH}.tar.gz"

# Local paths
CONFIG_DIR="/usr/local/cpanel/3rdparty/backbork"
WORK_DIR="${CONFIG_DIR}/update_work"
LOG_FILE="${CONFIG_DIR}/logs/update.log"

# Arguments passed from API:
#   $1 = Current plugin version (for notification comparison)
#   $2 = Notification email address (plugin-configured)
#   $3 = Slack webhook URL (plugin-configured, optional)
OLD_VERSION="${1:-unknown}"
NOTIFY_EMAIL="${2:-}"
SLACK_WEBHOOK="${3:-}"

# ============================================================================
# LOGGING FUNCTIONS
# ============================================================================

log() {
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[${timestamp}] $1" | tee -a "${LOG_FILE}"
}

log_error() {
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[${timestamp}] ERROR: $1" | tee -a "${LOG_FILE}" >&2
}

# ============================================================================
# NOTIFICATION FUNCTIONS
# ============================================================================

# Get root email from system aliases
get_root_email() {
    local root_email=""
    
    # Check /etc/aliases for root forward
    if [ -f /etc/aliases ]; then
        root_email=$(grep "^root:" /etc/aliases | sed 's/root:[[:space:]]*//' | head -1)
    fi
    
    # Fallback to cpanel contact email
    if [ -z "$root_email" ] && [ -f /root/.contactemail ]; then
        root_email=$(cat /root/.contactemail 2>/dev/null)
    fi
    
    # Final fallback
    if [ -z "$root_email" ]; then
        root_email="root"
    fi
    
    echo "$root_email"
}

# Send email notification via sendmail
# Args: $1=recipient, $2=subject, $3=body
send_email() {
    local to="$1"
    local subject="$2"
    local body="$3"
    local hostname
    hostname=$(hostname)
    
    if [ -z "$to" ]; then
        return 1
    fi
    
    echo -e "Subject: ${subject}\nFrom: backbork@${hostname}\nContent-Type: text/plain; charset=UTF-8\n\n${body}" | /usr/sbin/sendmail -t "$to" 2>/dev/null || true
}

# Send Slack notification via webhook
# Args: $1=webhook_url, $2=message, $3=color (hex)
send_slack() {
    local webhook="$1"
    local message="$2"
    local color="$3"
    local hostname
    hostname=$(hostname)
    
    if [ -z "$webhook" ]; then
        return 1
    fi
    
    local payload=$(cat <<EOF
{
    "username": "BackBork KISS",
    "icon_emoji": ":shield:",
    "attachments": [{
        "fallback": "${message}",
        "color": "${color}",
        "pretext": "🔄 [${hostname}] Plugin Update",
        "text": "${message}",
        "footer": "BackBork KISS • https://backbork.com",
        "ts": $(date +%s)
    }]
}
EOF
)
    
    curl -s -X POST -H 'Content-Type: application/json' -d "${payload}" "${webhook}" >/dev/null 2>&1 || true
}

# Send all notifications (email to root + plugin contacts, Slack if configured)
# Args: $1=status (success|failure), $2=new_version, $3=error_msg (if failure)
send_notifications() {
    local status="$1"
    local new_version="$2"
    local error_msg="$3"
    local hostname
    local timestamp
    local root_email
    hostname=$(hostname)
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    root_email=$(get_root_email)
    
    if [ "$status" = "success" ]; then
        local subject="[BackBork KISS] Update Successful :: ${hostname}"
        local body="✅ BackBork KISS Update Completed

Server: ${hostname}
Time: ${timestamp}

Previous Version: ${OLD_VERSION}
New Version: ${new_version}

The plugin has been successfully updated.
No action required.

---
Open-source Disaster Recovery
v${new_version} • https://backbork.com"

        local slack_msg="*Update Completed Successfully*\n*Previous:* ${OLD_VERSION}\n*New:* ${new_version}\n*Time:* ${timestamp}"
        local slack_color="#059669"
    else
        local subject="[BackBork KISS] Update FAILED :: ${hostname}"
        local body="❌ BackBork KISS Update Failed

Server: ${hostname}
Time: ${timestamp}

Previous Version: ${OLD_VERSION}
Error: ${error_msg}

Please check the update log at:
${LOG_FILE}

Manual intervention may be required.
You can try running install.sh manually from the GitHub repository.

---
Open-source Disaster Recovery
https://backbork.com"

        local slack_msg="*Update Failed*\n*Version:* ${OLD_VERSION}\n*Error:* ${error_msg}\n*Time:* ${timestamp}"
        local slack_color="#dc2626"
    fi
    
    # Always send to root email (ensures visibility even if plugin notifications fail)
    log "Sending notification to root: ${root_email}"
    send_email "$root_email" "$subject" "$body"
    
    # Send to plugin-configured email if different from root
    if [ -n "$NOTIFY_EMAIL" ] && [ "$NOTIFY_EMAIL" != "$root_email" ]; then
        log "Sending notification to plugin contact: ${NOTIFY_EMAIL}"
        send_email "$NOTIFY_EMAIL" "$subject" "$body"
    fi
    
    # Send Slack notification if configured
    if [ -n "$SLACK_WEBHOOK" ]; then
        log "Sending Slack notification"
        send_slack "$SLACK_WEBHOOK" "$slack_msg" "$slack_color"
    fi
}

# ============================================================================
# UPDATE FUNCTIONS
# ============================================================================

# Remove temporary work directory
cleanup() {
    log "Cleaning up work directory..."
    rm -rf "${WORK_DIR}" 2>/dev/null || true
}

# Main update workflow: download, extract, install, notify
perform_update() {
    log "=========================================="
    log "BackBork KISS Self-Update Starting"
    log "=========================================="
    log "Current version: ${OLD_VERSION}"
    log "Download URL: ${DOWNLOAD_URL}"
    
    # Clean any previous work directory and create fresh
    rm -rf "${WORK_DIR}" 2>/dev/null || true
    mkdir -p "${WORK_DIR}"
    cd "${WORK_DIR}"
    
    # Download latest release
    log "Downloading latest version from GitHub..."
    if ! curl -sL "${DOWNLOAD_URL}" -o backbork.tar.gz; then
        log_error "Failed to download update package"
        send_notifications "failure" "" "Failed to download update package from GitHub"
        exit 1
    fi
    
    # Extract archive
    log "Extracting update package..."
    if ! tar -xzf backbork.tar.gz; then
        log_error "Failed to extract update package"
        send_notifications "failure" "" "Failed to extract update package"
        exit 1
    fi
    
    # Find extracted directory (should be BackBork-KISS-for-WHM-main)
    local extracted_dir
    extracted_dir=$(find . -maxdepth 1 -type d -name "BackBork*" | head -1)
    if [ -z "$extracted_dir" ] || [ ! -d "$extracted_dir" ]; then
        log_error "Could not find extracted plugin directory"
        send_notifications "failure" "" "Update package structure is invalid"
        exit 1
    fi
    
    # Get new version from extracted files
    local new_version
    new_version=$(cat "${extracted_dir}/version" 2>/dev/null || echo "unknown")
    log "New version: ${new_version}"
    
    # Verify install.sh exists
    if [ ! -f "${extracted_dir}/install.sh" ]; then
        log_error "install.sh not found in update package"
        send_notifications "failure" "${new_version}" "install.sh missing from update package"
        exit 1
    fi
    
    # Make install script executable
    chmod +x "${extracted_dir}/install.sh"
    
    # Run the installer
    log "Running installer..."
    cd "${extracted_dir}"
    
    # Execute install.sh and capture output
    if ! bash install.sh >> "${LOG_FILE}" 2>&1; then
        log_error "Installation script failed"
        send_notifications "failure" "${new_version}" "Installation script returned an error"
        exit 1
    fi
    
    # Update commit info from GitHub API (since tarball doesn't include .git)
    log "Fetching commit info from GitHub..."
    local commit_json commit_hash commit_date utc_date
    commit_json=$(curl -sL "https://api.github.com/repos/${GITHUB_REPO}/commits/${GITHUB_BRANCH}" 2>/dev/null)
    commit_hash=$(echo "${commit_json}" | grep -m1 '"sha"' | sed 's/.*"sha": "\([^"]*\)".*/\1/' | cut -c1-7)
    # Extract UTC date and convert to AEST
    utc_date=$(echo "${commit_json}" | grep -m1 '"date"' | sed 's/.*"date": "\([^"]*\)".*/\1/')
    commit_date=$(TZ="Australia/Sydney" date -d "${utc_date}" "+%Y-%m-%d %H:%M:%S" 2>/dev/null)
    # Fallback if date conversion fails
    if [ -z "${commit_date}" ]; then
        commit_date=$(echo "${utc_date}" | sed 's/T/ /; s/Z.*//')
    fi
    if [ -n "${commit_hash}" ] && [ "${commit_hash}" != "" ]; then
        VERSION_FILE="/usr/local/cpanel/whostmgr/docroot/cgi/backbork/version.php"
        sed -i.bak "s/define('BACKBORK_COMMIT', '[^']*')/define('BACKBORK_COMMIT', '${commit_hash}')/" "${VERSION_FILE}"
        sed -i.bak "s/define('BACKBORK_COMMIT_DATE', '[^']*')/define('BACKBORK_COMMIT_DATE', '${commit_date}')/" "${VERSION_FILE}"
        rm -f "${VERSION_FILE}.bak"
        log "Commit: ${commit_hash} (${commit_date} AEST)"
    else
        log "Could not fetch commit info from GitHub API"
    fi
    
    log "=========================================="
    log "Update completed successfully!"
    log "New version: ${new_version}"
    log "=========================================="
    

    # Send success notifications
    send_notifications "success" "${new_version}" ""
    
    # Clean up work directory
    cleanup
    
    exit 0
}

# ============================================================================
# MAIN EXECUTION
# ============================================================================

# Ensure log directory exists
mkdir -p "${CONFIG_DIR}/logs" 2>/dev/null || true

# Run the update
perform_update
