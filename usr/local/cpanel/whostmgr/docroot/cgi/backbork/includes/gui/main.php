<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Main GUI template defining HTML structure for the WHM interface.
 *   Renders the complete plugin interface with tabs and panels.
 *
 *  This program is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or
 *   (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *   along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *  @package BackBork
 *  @version See version.php (constant: BACKBORK_VERSION)
 *  @author The Network Crew Pty Ltd & Velocity Host Pty Ltd
 */

// ============================================================================
// SECURITY CHECK - Prevent direct access to template file
// Must be included through index.php which defines BACKBORK_VERSION
// ============================================================================
if (!defined('BACKBORK_VERSION')) {
    die('Access denied');
}
?>
<!-- ========================================================================
     BackBork KISS Main Interface Container
     Renders the complete WHM plugin interface with tabs and panels
======================================================================== -->
<div class="backbork-container">
    <!-- ================================================================
         HEADER: Logo, version, status monitor, and user badge
    ================================================================ -->
    <div class="backbork-header">
        <div class="backbork-header-left">
            <!-- Plugin branding with dynamic version from version.php -->
            <h1><img src="app/img/logo.png" alt="BackBork KISS" class="header-logo"></h1>
        </div>
        
        <!-- Status Monitor: Real-time job counts updated via JavaScript polling -->
        <div class="status-monitor">
            <div class="status-item processing" id="status-processing-indicator" style="display: none;" title="Queue is actively being processed">
                <span class="processing-cog">⚙️</span>
                <span class="label">Processing</span>
            </div>
            <div class="status-item restores" title="Active restore operations in progress">
                <span class="label">Restores</span>
                <span class="value" id="status-restores">0</span>
            </div>
            <div class="status-item jobs" title="Total backup jobs (queued + running)">
                <span class="label">Back-ups</span>
                <span class="value" id="status-jobs">0</span>
            </div>
            <div class="status-item transit" title="Jobs currently executing">
                <span class="label">In-Transit</span>
                <span class="value" id="status-transit">0</span>
            </div>
            <div class="status-item resellers" title="Resellers on this server">
                <span class="label">Resellers</span>
                <span class="value" id="status-resellers">0</span>
            </div>
        </div>
        
        <!-- User badge: Shows current WHM user with Root/Reseller indicator -->
        <div class="user-info">
            <span><code><?php echo htmlspecialchars($currentUser); ?></code></span>
            <?php if ($isRoot): ?>
                <!-- Root users get full access to all features -->
                <span class="status-badge status-success">ADMIN</span>
            <?php else: ?>
                <!-- Resellers have ACL-restricted access to their accounts only -->
                <span class="status-badge status-pending">RESELLER</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================================================================
         NAVIGATION TABS: Switch between plugin sections
         Tab switching handled by scripts.js
    ================================================================ -->
    <div class="backbork-tabs">
        <div class="backbork-tab active" data-tab="backup">📦 Backup</div>
        <div class="backbork-tab" data-tab="restore">🔄 Restore</div>
        <div class="backbork-tab" data-tab="schedule">⏰ Schedules</div>
        <div class="backbork-tab" data-tab="data">💾 Data</div>
        <div class="backbork-tab" data-tab="queue">📋 Queue</div>
        <div class="backbork-tab" data-tab="logs">📜 Logs</div>
        <div class="backbork-tab" data-tab="settings">⚙️ Settings</div>
    </div>

    <!-- Update Alert: Shows when a newer version is available on GitHub -->
    <div id="update-alert" class="update-alert" style="display: none;">
        <span>🚀 <strong>Update available!</strong> Version <span id="update-version"></span> is available on GitHub.</span>
        <div class="update-alert-actions">
            <?php if ($isRoot): ?>
            <button type="button" class="btn btn-sm btn-primary" onclick="performUpdate()" id="btn-perform-update">Update Now!</button>
            <?php endif; ?>
            <a href="https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/" target="_blank" class="btn btn-sm btn-secondary">Open the Repo →</a>
        </div>
        <button type="button" class="update-alert-dismiss" onclick="dismissUpdateAlert()" title="Dismiss">✕</button>
    </div>

    <!-- ================================================================
         CONTENT PANELS: Each page is included as a separate template
         Only one panel is visible at a time (controlled by CSS/JS)
    ================================================================ -->
    
    <!-- Backup Panel: Create ad-hoc backups or queue for later -->
    <?php include(__DIR__ . '/../pages/backup.php'); ?>

    <!-- Restore Panel: Restore accounts from remote destinations -->
    <?php include(__DIR__ . '/../pages/restore.php'); ?>

    <!-- Schedule Panel: Configure recurring automated backups -->
    <?php include(__DIR__ . '/../pages/schedule.php'); ?>

    <!-- Data Panel: Browse and delete backup files -->
    <?php include(__DIR__ . '/../pages/data.php'); ?>

    <!-- Queue Panel: View and manage pending/running jobs -->
    <?php include(__DIR__ . '/../pages/queue.php'); ?>

    <!-- Logs Panel: Activity history and error tracking -->
    <?php include(__DIR__ . '/../pages/logs.php'); ?>

    <!-- Settings Panel: Notifications, pkgacct options, global config -->
    <?php include(__DIR__ . '/../pages/settings.php'); ?>

    <!-- ================================================================
         FOOTER: Version info, project links, and copyright
    ================================================================ -->
    <div class="backbork-footer">
        <div><code title="<?php echo (defined('BACKBORK_COMMIT_DATE') && BACKBORK_COMMIT_DATE !== '') ? 'Committed: ' . htmlspecialchars(BACKBORK_COMMIT_DATE) : ''; ?> AEST"><strong>v<?php echo BACKBORK_VERSION; ?></strong><?php if (defined('BACKBORK_COMMIT')): ?> [<?php echo (BACKBORK_COMMIT === 'Unknown') ? 'Unofficial' : htmlspecialchars(BACKBORK_COMMIT); ?>]<?php endif; ?></code><strong><a href="https://www.gnu.org/licenses/agpl-3.0.txt" target="_blank">AGPLv3</a> &bull; <a href="https://backbork.com" target="_blank">Open-source DR</a> &bull; <a href="https://github.com/The-Network-Crew/BackBork-KISS-for-WHM" target="_blank">Repo</a> &bull; <a href="https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/issues/new/choose" target="_blank">Bug?</a></strong></div>
        <div><strong>&copy; <a href="https://tnc.works" target="_blank">The Network Crew Pty Ltd</a> &amp; <a href="https://velocityhost.com.au" target="_blank">Velocity Host Pty Ltd</a></strong> 💜</div>
    </div>
</div>

<!-- ========================================================================
     MODAL DIALOGS: Overlay modals for confirmations and warnings
======================================================================== -->

<!-- Restore Confirmation Modal: Shown before executing a restore -->
<div id="restore-modal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Restore</h3>
            <button class="modal-close" onclick="closeModal('restore-modal')">&times;</button>
        </div>
        <div class="alert alert-warning">
            <strong>Warning:</strong> This will overwrite existing data for the selected account. Make sure you have a recent backup if needed.
        </div>
        <div id="restore-confirm-details"></div>
        <div style="margin-top: 20px; text-align: right;">
            <button class="btn btn-secondary" onclick="closeModal('restore-modal')">Cancel</button>
            <button class="btn btn-danger" id="btn-confirm-restore">Confirm Restore</button>
        </div>
    </div>
</div>

<!-- Delete Backup Confirmation Modal: Shown before deleting a backup file -->
<div id="delete-backup-modal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-trash-alt"></i> Confirm Deletion</h3>
            <button class="modal-close" onclick="closeModal('delete-backup-modal')">&times;</button>
        </div>
        <div class="alert alert-danger">
            <strong>Warning:</strong> This will permanently delete the selected backup file. This action cannot be undone.
        </div>
        <div class="modal-details">
            <p><strong>Account:</strong> <span id="delete-backup-account"></span></p>
            <p><strong>Filename:</strong> <code id="delete-backup-filename"></code></p>
        </div>
        <div style="margin-top: 20px; text-align: right;">
            <button class="btn btn-secondary" onclick="closeModal('delete-backup-modal')">Cancel</button>
            <button class="btn btn-danger" id="btn-confirm-delete-backup" onclick="confirmDeleteBackup()">
                <i class="fas fa-trash-alt"></i> Delete Backup
            </button>
        </div>
    </div>
</div>

<!-- Bulk Delete Confirmation Modal: Shown before bulk deleting multiple backup files -->
<div id="bulk-delete-modal" class="modal-overlay">
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h3><i class="fas fa-trash-alt"></i> Confirm Bulk Deletion</h3>
            <button class="modal-close" onclick="closeModal('bulk-delete-modal')">&times;</button>
        </div>
        <div class="alert alert-danger">
            <strong>WARNING:</strong> You are about to permanently delete <strong><span id="bulk-delete-count">0</span> backup(s) </strong>This action cannot ever be undone!
        </div>
        <div class="modal-details">
            <p><strong>Account:</strong> <span id="bulk-delete-account"></span></p>
            <p><strong>Files to delete:</strong></p>
            <ul class="bulk-delete-file-list" id="bulk-delete-file-list"></ul>
        </div>
        <div class="bulk-delete-confirmation">
            <div class="form-group">
                <label for="bulk-delete-confirm-text"><strong>Repeat the following:</strong></label>
                <code class="confirm-text-display">Yes, I want to bulk delete these backups.</code>
                <input type="text" id="bulk-delete-confirm-text" class="form-control" placeholder="Type/copy the confirmation text." autocomplete="off" oninput="validateBulkDeleteForm()">
            </div>
            <div class="form-group">
                <label class="bulk-checkbox-label bulk-confirm-checkbox">
                    <input type="checkbox" id="bulk-delete-accept-undone" onchange="validateBulkDeleteForm()">
                    <span>I accept that this cannot be undone.</span>
                </label>
            </div>
        </div>
        <div style="margin-top: 20px; text-align: right;">
            <button class="btn btn-secondary" onclick="closeModal('bulk-delete-modal')">Cancel</button>
            <button class="btn btn-danger" id="btn-confirm-bulk-delete" onclick="confirmBulkDelete()" disabled>
                <i class="fas fa-trash-alt"></i> Delete <span id="btn-bulk-delete-count">0</span> Backup(s)
            </button>
        </div>
    </div>
</div>
