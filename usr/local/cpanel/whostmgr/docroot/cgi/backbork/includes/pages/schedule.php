<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Schedule panel for managing automated recurring backup schedules.
 *   Supports hourly, daily, weekly, and monthly backup configurations.
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
// ACL CHECK - Determine user permissions for conditional UI rendering
// Root users see additional controls like "view as user" selector
// ============================================================================
$scheduleAcl = BackBorkBootstrap::getACL();  // Get ACL instance from Bootstrap
$scheduleIsRoot = $scheduleAcl->isRoot();     // Check if current user is root
?>
<!-- Schedule Panel: Manage automated backup schedules -->
<div id="panel-schedule" class="backbork-panel">
    <!-- ================================================================
         SCHEDULES LOCKED ALERT
         Shown to resellers when root has enabled schedule_lock
         Hidden by default, visibility controlled by JavaScript
    ================================================================ -->
    <div id="schedules-locked-alert" class="alert alert-warning" style="display: none;">
        <strong>🔒 Schedules Locked</strong> — Schedule management has been disabled by the administrator.
    </div>

    <!-- Schedule Creation Form Card -->
    <div class="backbork-card" id="schedule-create-card">
        <h3>Scheduled Backups</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="schedule-destination">Backup Storage</label>
                <select id="schedule-destination" class="destination-select">
                    <option value="">Loading destinations...</option>
                </select>
            </div>
            <div class="form-group">
                <label for="schedule-frequency">Schedule Frequency</label>
                <select id="schedule-frequency">
                    <option value="hourly">Hourly</option>
                    <option value="daily" selected>Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="schedule-retention">Retained Backups (0 = Unlimited)</label>
                <input type="number" id="schedule-retention" value="30" min="0" max="365" title="0 = unlimited">
            </div>
            <div class="form-group">
                <label for="schedule-time">Time of Day to Execute</label>
                <select id="schedule-time">
                    <?php for ($i = 0; $i < 24; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i === 2 ? 'selected' : ''; ?>>
                            <?php echo sprintf('%02d:00', $i); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        
        <!-- Day of Week selector - shown only for Weekly schedules -->
        <div class="form-row" id="schedule-dow-row" style="display: none;">
            <div class="form-group">
                <label for="schedule-day-of-week">Day of Week</label>
                <select id="schedule-day-of-week">
                    <option value="1">Monday</option>
                    <option value="2">Tuesday</option>
                    <option value="3">Wednesday</option>
                    <option value="4">Thursday</option>
                    <option value="5">Friday</option>
                    <option value="6">Saturday</option>
                    <option value="0" selected>Sunday</option>
                </select>
            </div>
            <div class="form-group">
                <!-- Spacer for alignment -->
            </div>
        </div>
        
        <p style="font-size: 12px; color: var(--text-muted); margin: -10px 0 15px 0;">
            💡 Time of Day applies to Daily, Weekly, and Monthly schedules. Monthly runs on the 1st.
        </p>

        <!-- Account Selection: Choose which accounts to include in schedule -->
        <div class="form-group">
            <label>Accounts to be covered by this Schedule</label>
            <div class="account-list" id="schedule-account-list">
                <div class="select-all-container" style="display: flex; gap: 24px; flex-wrap: wrap;">
                    <!-- All Accounts: Dynamic mode - includes all accessible accounts at runtime -->
                    <label style="font-weight: 600; color: var(--primary);">
                        <input type="checkbox" id="schedule-all-accounts"> 🌐 All Accounts (Dynamic)
                    </label>
                    <!-- Select All Listed: Static mode - selects currently visible accounts -->
                    <label>
                        <input type="checkbox" id="select-all-schedule"> Select All (Listed)
                    </label>
                </div>
                <div id="schedule-accounts-container">
                    <div class="loading-spinner"></div> Loading accounts...
                </div>
            </div>
            <p id="all-accounts-hint" style="display: none; font-size: 12px; color: var(--text-muted); margin-top: 8px;">
                💡 When "All Accounts" is enabled, the schedule will dynamically include all accounts accessible to you at runtime.
            </p>
        </div>

        <button type="button" class="btn btn-primary" id="btn-create-schedule">
            ⏰ Create Schedule
        </button>
    </div>

    <div class="backbork-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0;">Active Schedules</h3>
            <?php if ($scheduleIsRoot): ?>
            <!-- Root-only: Filter schedules by owner - allows viewing reseller schedules -->
            <div class="form-group" style="margin: 0; min-width: 200px;">
                <select id="schedule-view-user" style="margin: 0;">
                    <option value="all">All Users</option>
                    <!-- Additional users populated via JavaScript API call -->
                </select>
            </div>
            <?php endif; ?>
        </div>
        <div class="table-container">
            <table class="backbork-table" id="schedules-table">
                <thead>
                    <tr>
                        <th>Accounts</th>
                        <th>Destination</th>
                        <th>Frequency</th>
                        <th>Retain</th>
                        <th>Next Run</th>
                        <?php if ($scheduleIsRoot): ?><th>Owner</th><?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="schedules-tbody">
                    <tr><td colspan="<?php echo $scheduleIsRoot ? '7' : '6'; ?>">Loading schedules...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================================================================
         EDIT SCHEDULE MODAL
         Allows editing an existing schedule's settings
    ================================================================ -->
    <div id="edit-schedule-modal" class="modal-overlay">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>✏️ Edit Schedule</h3>
                <button class="modal-close" onclick="closeEditScheduleModal()">&times;</button>
            </div>
            
            <input type="hidden" id="edit-schedule-id">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="edit-schedule-destination">Backup Storage</label>
                    <select id="edit-schedule-destination" class="destination-select">
                        <option value="">Loading destinations...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit-schedule-frequency">Schedule Frequency</label>
                    <select id="edit-schedule-frequency">
                        <option value="hourly">Hourly</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="edit-schedule-retention">Retained Backups (0 = Unlimited)</label>
                    <input type="number" id="edit-schedule-retention" value="30" min="0" max="365">
                </div>
                <div class="form-group">
                    <label for="edit-schedule-time">Time of Day to Execute</label>
                    <select id="edit-schedule-time">
                        <?php for ($i = 0; $i < 24; $i++): ?>
                            <option value="<?php echo $i; ?>"><?php echo sprintf('%02d:00', $i); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            
            <!-- Day of Week selector - shown only for Weekly schedules -->
            <div class="form-row" id="edit-schedule-dow-row" style="display: none;">
                <div class="form-group">
                    <label for="edit-schedule-day-of-week">Day of Week</label>
                    <select id="edit-schedule-day-of-week">
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                        <option value="0">Sunday</option>
                    </select>
                </div>
                <div class="form-group">
                    <!-- Spacer for alignment -->
                </div>
            </div>

            <!-- Account Selection -->
            <div class="form-group">
                <label>Accounts covered by this Schedule</label>
                <div class="account-list" id="edit-schedule-account-list" style="max-height: 250px;">
                    <div class="select-all-container" style="display: flex; gap: 24px; flex-wrap: wrap;">
                        <label style="font-weight: 600; color: var(--primary);">
                            <input type="checkbox" id="edit-schedule-all-accounts"> 🌐 All Accounts (Dynamic)
                        </label>
                        <label>
                            <input type="checkbox" id="edit-select-all-accounts"> Select All (Listed)
                        </label>
                    </div>
                    <div id="edit-schedule-accounts-container">
                        <div class="loading-spinner"></div> Loading accounts...
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="button" class="btn btn-primary" id="btn-save-schedule">
                    💾 Save Changes
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeEditScheduleModal()">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>
