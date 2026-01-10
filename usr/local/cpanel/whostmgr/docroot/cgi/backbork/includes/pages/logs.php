<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Logs panel showing activity history for backup/restore operations.
 *   Provides filtering by type (backup, restore, error) and account.
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

// Get cPanel accounts for the filter dropdown
// Uses $acl from parent scope (index.php sets this via BackBorkBootstrap::getACL())
$logAccountOptions = '';
if (isset($acl) && $acl instanceof BackBorkACL) {
    $accessibleAccounts = $acl->getAccessibleAccounts();
    if (!empty($accessibleAccounts)) {
        // Sort alphabetically by username
        usort($accessibleAccounts, function($a, $b) {
            return strcasecmp($a['user'] ?? '', $b['user'] ?? '');
        });
        foreach ($accessibleAccounts as $account) {
            $username = htmlspecialchars($account['user'] ?? '');
            if (!empty($username)) {
                $logAccountOptions .= '<option value="' . $username . '">' . $username . '</option>';
            }
        }
    }
}
?>
<!-- ========================================================================
     LOGS PANEL
     Activity history showing backup/restore operations and errors
     - Filter by type (all, backups, restores, errors)
     - Filter by account (partial match)
     - Paginated log entries loaded via API
     - Shows timestamp, type, user, accounts, and details
======================================================================== -->
<div id="panel-logs" class="backbork-panel">
    <div class="backbork-card">
        <h3>Activity Logs 🔎</h3>
        
        <!-- Filter Controls: Type filter, account filter, and refresh button -->
        <div class="form-row">
            <div class="form-group">
                <!-- Type filter dropdown -->
                <label for="log-filter">Type</label>
                <select id="log-filter">
                    <option value="all">All Types</option>
                    <optgroup label="Jobs">
                        <option value="backup_local">Backup - Local</option>
                        <option value="backup_remote">Backup - Remote</option>
                        <option value="restore_local">Restore - Local</option>
                        <option value="restore_remote">Restore - Remote</option>
                    </optgroup>
                    <optgroup label="Queue">
                        <option value="queue_add">Queue - Add</option>
                        <option value="kill_all_jobs">Queue - Kill All</option>
                        <option value="queue_process">Queue - Process</option>
                        <option value="queue_cron_process">Queue - Process (Cron)</option>
                        <option value="queue_remove">Queue - Remove</option>
                    </optgroup>
                    <optgroup label="Schedules">
                        <option value="schedule_create">Schedule - Create</option>
                        <option value="schedule_delete">Schedule - Delete</option>
                    </optgroup>
                    <optgroup label="System">
                        <option value="global_config_update">Config - Update (Global)</option>
                        <option value="config_update">Config - Update</option>
                        <option value="delete">File - Delete</option>
                        <option value="prune">Prune - All</option>
                        <option value="daily_summary">Daily Summary</option>
                        <option value="update_started">Update - Started</option>
                    </optgroup>
                    <optgroup label="Status">
                        <option value="error">Errors (Only)</option>
                        <option value="success">Success (Only)</option>
                    </optgroup>
                </select>
            </div>
            <div class="form-group">
                <!-- Account filter dropdown -->
                <label for="log-account-filter">Account</label>
                <select id="log-account-filter">
                    <option value="">All Accounts</option>
                    <?php echo $logAccountOptions; ?>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <!-- Manual refresh - also auto-refreshes on tab switch -->
                <button type="button" class="btn btn-secondary" id="btn-refresh-logs">
                    🔄 Refresh
                </button>
            </div>
        </div>
        
        <!-- Logs Table: Populated via JavaScript API call -->
        <div class="table-container">
            <table class="backbork-table logs-table">
                <thead>
                    <tr>
                        <th>When / Outcome</th>
                        <th>Type / User</th>
                        <th>Account / Config</th>
                        <th>Details / Output</th>
                    </tr>
                </thead>
                <tbody id="logs-tbody">
                    <tr><td colspan="4">Loading logs...</td></tr>
                </tbody>
            </table>
        </div>
        <!-- Pagination controls rendered by JavaScript -->
        <div id="logs-pagination" style="margin-top: 15px; text-align: center;"></div>
    </div>
</div>
