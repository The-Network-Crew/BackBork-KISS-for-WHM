<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Queue panel showing pending jobs and processing status.
 *   Root users can manually trigger queue processing.
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
?>
<!-- Queue Panel: View pending jobs and processing status -->
<div id="panel-queue" class="backbork-panel">
    <!-- ================================================================
         QUEUE HEADER ACTIONS
         Root users can manually trigger queue processing
         Resellers see informational message only
    ================================================================ -->
    <div class="queue-header-actions">
        <?php if ($isRoot): ?>
            <!-- Root-only: Manual queue processing trigger -->
            <button id="btn-process-queue" class="btn btn-process-queue">
                <span class="btn-icon">▶</span> Process Queue
            </button>
            <button id="btn-kill-queue" class="btn btn-kill-queue">
                <span class="btn-icon">☠️</span> Kill All Jobs
            </button>
        <?php else: ?>
            <!-- Reseller view: Informational message about cron processing -->
            <div class="cron-info-box">
                <span class="info-icon">ℹ️</span>
                <strong>Queue processing runs automatically every 5 minutes.</strong> Manual processing requires root access.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- ================================================================
         RUNNING JOBS TABLE
         Shows currently executing backup/restore operations
         Updated via JavaScript polling for real-time status
    ================================================================ -->
    <div class="backbork-card">
        <h3>Running Jobs 🏃</h3>
        <div class="table-container">
            <table class="backbork-table">
                <thead>
                    <tr>
                        <th>Accounts</th>
                        <th>Job</th>
                        <th>Started</th>
                        <th>Status / Actions</th>
                    </tr>
                </thead>
                <!-- Table body populated via JavaScript API call -->
                <tbody id="running-jobs-tbody">
                    <tr><td colspan="4">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================================================================
         QUEUED JOBS TABLE
         Shows pending jobs waiting for cron processing
         Root can cancel queued jobs; resellers can only cancel their own
    ================================================================ -->
    <div class="backbork-card">
        <h3>Queued Jobs ⏳</h3>
        <div class="table-container">
            <table class="backbork-table">
                <thead>
                    <tr>
                        <th>Accounts</th>
                        <th>Job</th>
                        <th>Destination</th>
                        <th>Queued</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <!-- Table body populated via JavaScript API call -->
                <tbody id="queue-tbody">
                    <tr><td colspan="5">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
