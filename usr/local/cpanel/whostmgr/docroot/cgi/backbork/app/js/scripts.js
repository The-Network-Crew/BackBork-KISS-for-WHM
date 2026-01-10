/**
 * BackBork KISS - Disaster Recovery for cPanel (WHM Plugin)
 * Copyright (C) 2024-2025 The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *
 * THIS FILE: JavaScript Application Logic
 * Handles all client-side UI, API communication, backup/restore operations, and real-time status.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * @package  BackBork
 * @version See version.php (constant: BACKBORK_VERSION)
 * @author   The Network Crew Pty Ltd & Velocity Host Pty Ltd
 */

(function() {
    'use strict';

    // =========================================================================
    // APPLICATION STATE
    // Global variables tracking current UI state and cached data
    // =========================================================================
    let accounts = [];              // List of cPanel accounts accessible to user
    let destinations = [];          // Available backup destinations from WHM
    let currentConfig = {};         // User's saved configuration settings
    let currentLogPage = 1;         // Current page number for log pagination
    let isRootUser = false;         // Whether current user is root (full access)
    let schedulesLocked = false;    // Whether schedules are locked by admin
    let currentScheduleViewUser = 'all';  // Filter for schedule view (root only)

    // =========================================================================
    // JOB TRACKING STATE
    // Variables for monitoring running jobs and handling cancellation requests
    // =========================================================================
    let hasRunningJobs = false;         // Flag for faster refresh when jobs running
    let fastRefreshInterval = null;     // Interval handle for fast polling mode

    // Track jobs with pending cancellation requests (prevents duplicate cancel calls)
    const cancellingJobs = new Set();

    // =========================================================================
    // INITIALIZATION
    // Entry point when DOM is ready - loads all data and sets up event handlers
    // =========================================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Load all necessary data in parallel
        initTabs();
        loadDestinations();
        loadAccounts();
        loadConfig();
        loadQueue();
        loadLogs();
        initEventListeners();
        checkForUpdates();
        
        // Set up status polling (every 15 seconds in normal mode)
        setInterval(refreshStatus, 15000);
        
        // Initial status monitor update
        refreshStatus();
    });
    
    // =========================================================================
    // UPDATE CHECK
    // Compares local version against GitHub main branch to alert on new releases
    // =========================================================================
    function checkForUpdates() {
        apiCall('check_update', {}, 'GET').then(data => {
            if (data.success && data.update_available && data.remote_version) {
                const alertEl = document.getElementById('update-alert');
                const versionEl = document.getElementById('update-version');
                if (alertEl && versionEl) {
                    versionEl.textContent = data.remote_version;
                    alertEl.style.display = 'flex';
                }
            }
        }).catch(err => {
            console.log('Update check failed:', err);
        });
    }
    
    // Dismiss update alert for current page view only (will return on reload)
    window.dismissUpdateAlert = function() {
        const alertEl = document.getElementById('update-alert');
        if (alertEl) alertEl.style.display = 'none';
    };
    
    // Perform self-update (root only)
    // Downloads latest version from GitHub and runs installer in background
    // Notifications sent to root email + plugin contacts upon completion
    window.performUpdate = function() {
        const btn = document.getElementById('btn-perform-update');
        const alertEl = document.getElementById('update-alert');
        
        // Confirm before proceeding
        if (!confirm('This will download and install the latest version of BackBork KISS.\n\nThe update runs in the background. You will receive email/Slack notifications when complete.\n\nDo you want to proceed?')) {
            return;
        }
        
        // Disable button and show progress
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-sm"></span> Updating...';
        }
        
        apiCall('perform_update', {}, 'POST').then(data => {
            if (data.success) {
                // Show success message
                if (alertEl) {
                    alertEl.innerHTML = '<span>🔄 <strong>Update in progress!</strong> You will be notified by email/Slack when complete. The page may need to be refreshed after update.</span>' +
                        '<button type="button" class="update-alert-dismiss" onclick="dismissUpdateAlert()" title="Dismiss">✕</button>';
                    alertEl.classList.add('update-in-progress');
                }
                showToast('Update started! You will be notified when complete.', 'success');
            } else {
                // Show error
                showToast('Update failed: ' + (data.message || 'Unknown error'), 'error');
                // Re-enable button
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Update Now';
                }
            }
        }).catch(err => {
            console.error('Update failed:', err);
            showToast('Update failed: ' + err.message, 'error');
            // Re-enable button
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'Update Now';
            }
        });
    };

    // =========================================================================
    // TAB NAVIGATION
    // Handles switching between panels and refreshing relevant data when tabs change
    // =========================================================================
    function initTabs() {
        document.querySelectorAll('.backbork-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.backbork-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.backbork-panel').forEach(p => p.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById('panel-' + this.dataset.tab).classList.add('active');
                
                // Refresh data when switching tabs
                if (this.dataset.tab === 'queue') loadQueue();
                if (this.dataset.tab === 'logs') loadLogs();
                if (this.dataset.tab === 'schedule') loadSchedules();
                if (this.dataset.tab === 'settings') { checkCronStatus(); loadDestinationVisibility(); loadDisabledDestinations(); }
            });
        });
    }

    // =========================================================================
    // API COMMUNICATION
    // Central helper for all AJAX requests to PHP backend via index.php
    // Handles JSON parsing, error handling, and request routing
    // =========================================================================
    function apiCall(action, data = {}, method = 'POST') {
        // Build URL to explicit API router to avoid routing issues through index.php
        const options = {
            method: method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',   // Signals AJAX request to PHP
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        };

        // Compute base path for the API - route through index.php (registered with AppConfig)
        const path = window.location.pathname;
        let base = path;
        // Remove index.php and any querystring part
        if (base.indexOf('/index.php') !== -1) {
            base = base.substring(0, base.lastIndexOf('/')) + '/';
        } else if (base.endsWith('/')) {
            // Keep it as-is
        } else {
            base = base.substring(0, base.lastIndexOf('/') + 1);
        }

        // Use index.php for API calls - it detects XMLHttpRequest and routes to router.php
        let url = base + 'index.php?action=' + encodeURIComponent(action);

        if (method === 'POST') {
            options.body = JSON.stringify(data);
        } else if (method === 'GET' && Object.keys(data).length > 0) {
            url += '&' + new URLSearchParams(data).toString();
        }

        // Robust error handling: parse as text, check content-type, and then parse JSON
        return fetch(url, options).then(async r => {
            const text = await r.text();
            const ct = r.headers.get('content-type') || '';

            if (!r.ok) {
                console.error('API request error:', r.status, r.statusText, url, text);
                throw new Error('API request failed: ' + r.status + ' ' + r.statusText);
            }

            if (ct.indexOf('application/json') !== -1) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response from API:', url, text);
                    throw new Error('Invalid JSON response from API');
                }
            }

            // If content-type is not JSON, try to parse anyway, otherwise log and throw
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Unexpected non-JSON API response:', url, text);
                throw new Error('Unexpected non-JSON response from server');
            }
        });
    }

    // =========================================================================
    // DESTINATION MANAGEMENT
    // Load and display backup destinations from WHM's backup configuration
    // =========================================================================
    function loadDestinations() {
        apiCall('get_destinations', {}, 'GET').then(data => {
            destinations = data.destinations || [];
            
            document.querySelectorAll('.destination-select').forEach(select => {
                select.innerHTML = '<option value="">-- Select Destination --</option>';
                destinations.forEach(dest => {
                    select.innerHTML += `<option value="${dest.id}">${dest.name} (${dest.type})</option>`;
                });
            });
            // Restore tab uses "Source" terminology
            const restoreSelect = document.getElementById('restore-destination');
            if (restoreSelect && restoreSelect.options[0]) {
                restoreSelect.options[0].text = '-- Select Source --';
            }
            // Data tab: Show all destinations (including remote) with "Source" terminology
            const dataSelect = document.getElementById('data-destination');
            if (dataSelect) {
                dataSelect.innerHTML = '<option value="">-- Select Source --</option>';
                destinations.forEach(dest => {
                    dataSelect.innerHTML += `<option value="${dest.id}">${dest.name} (${dest.type})</option>`;
                });
            }
        }).catch(err => {
            console.error('Failed to load destinations', err);
            document.querySelectorAll('.destination-select').forEach(select => {
                if (select) select.innerHTML = '<option value="">-- Unable to load destinations --</option>';
            });
        });
    }

    // =========================================================================
    // ACCOUNT MANAGEMENT
    // Load cPanel accounts and render checkboxes for backup/schedule selection
    // =========================================================================
    function loadAccounts() {
        apiCall('get_accounts', {}, 'GET').then(data => {
            accounts = data || [];
            // Sort alphabetically by username for consistent display
            accounts.sort((a, b) => a.user.toLowerCase().localeCompare(b.user.toLowerCase()));
            renderAccountLists();
        }).catch(err => {
            console.error('Failed to load accounts', err);
            accounts = [];
            renderAccountLists();
        });
    }

    // Render account checkboxes in backup and schedule panels
    function renderAccountLists() {
        const containers = ['backup-accounts-container', 'schedule-accounts-container'];
        
        containers.forEach(containerId => {
            const container = document.getElementById(containerId);
            if (!container) return;
            
            if (accounts.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No accounts available.</div>';
                return;
            }
            
            container.innerHTML = accounts.map(acc => `
                <div class="account-item">
                    <input type="checkbox" value="${acc.user}" class="account-checkbox">
                    <div class="account-info">
                        <div class="account-name">${acc.user}</div>
                        <div class="account-domain">${acc.domain || 'N/A'}</div>
                    </div>
                    ${acc.owner ? `<span class="account-owner">${acc.owner}</span>` : ''}
                </div>
            `).join('');
        });
        
        // Populate restore account dropdown
        const restoreAccount = document.getElementById('restore-account');
        if (restoreAccount) {
            restoreAccount.innerHTML = '<option value="">-- Select Account --</option>';
            accounts.forEach(acc => {
                restoreAccount.innerHTML += `<option value="${acc.user}">${acc.user} (${acc.domain || 'N/A'})</option>`;
            });
        }
    }

    // =========================================================================
    // CONFIGURATION MANAGEMENT
    // Load and apply user settings to form controls, handle global config for root
    // =========================================================================
    function loadConfig() {
        apiCall('get_config', {}, 'GET').then(data => {
            currentConfig = data || {};
            
            // Notification settings - channels
            if (data.notify_email) document.getElementById('notify-email').value = data.notify_email;
            if (data.slack_webhook) document.getElementById('slack-webhook').value = data.slack_webhook;
            
            // Notification settings - backup events
            const notifyBackupSuccess = document.getElementById('notify-backup-success');
            if (notifyBackupSuccess) {
                notifyBackupSuccess.checked = data.notify_backup_success !== undefined 
                    ? data.notify_backup_success 
                    : true;
            }
            const notifyBackupFailure = document.getElementById('notify-backup-failure');
            if (notifyBackupFailure) {
                notifyBackupFailure.checked = data.notify_backup_failure !== undefined 
                    ? data.notify_backup_failure 
                    : true;
            }
            const notifyBackupStart = document.getElementById('notify-backup-start');
            if (notifyBackupStart) {
                notifyBackupStart.checked = data.notify_backup_start !== undefined 
                    ? data.notify_backup_start 
                    : false;
            }
            
            // Notification settings - restore events
            const notifyRestoreSuccess = document.getElementById('notify-restore-success');
            if (notifyRestoreSuccess) notifyRestoreSuccess.checked = data.notify_restore_success !== undefined ? data.notify_restore_success : true;
            const notifyRestoreFailure = document.getElementById('notify-restore-failure');
            if (notifyRestoreFailure) notifyRestoreFailure.checked = data.notify_restore_failure !== undefined ? data.notify_restore_failure : true;
            const notifyRestoreStart = document.getElementById('notify-restore-start');
            if (notifyRestoreStart) notifyRestoreStart.checked = data.notify_restore_start !== undefined ? data.notify_restore_start : false;
            
            // Notification settings - system events
            const notifyQueueFailure = document.getElementById('notify-queue-failure');
            if (notifyQueueFailure) notifyQueueFailure.checked = data.notify_queue_failure !== undefined ? data.notify_queue_failure : true;
            const notifyDailySummary = document.getElementById('notify-daily-summary');
            if (notifyDailySummary) notifyDailySummary.checked = data.notify_daily_summary !== undefined ? data.notify_daily_summary : false;
            
            // Backup settings (temp-directory is root-only)
            const tempDirEl = document.getElementById('temp-directory');
            if (tempDirEl && data.temp_directory) tempDirEl.value = data.temp_directory;
            if (data.mysql_version) document.getElementById('mysql-version').value = data.mysql_version;
            if (data.dbbackup_type) document.getElementById('dbbackup-type').value = data.dbbackup_type;
            if (data.compression_option) document.getElementById('compression-option').value = data.compression_option;
            
            // Backup mode options (opt-split and opt-use-backups are root-only)
            if (data.opt_incremental) document.getElementById('opt-incremental').checked = data.opt_incremental;
            const optSplitEl = document.getElementById('opt-split');
            if (optSplitEl && data.opt_split) optSplitEl.checked = data.opt_split;
            const optUseBackupsEl = document.getElementById('opt-use-backups');
            if (optUseBackupsEl && data.opt_use_backups) optUseBackupsEl.checked = data.opt_use_backups;
            
            // Skip options
            const skipOptions = ['homedir', 'publichtml', 'mysql', 'pgsql', 'logs', 'mailconfig', 
                'mailman', 'dnszones', 'ssl', 'bwdata', 'quota', 'ftpusers', 'domains', 
                'acctdb', 'apitokens', 'authnlinks', 'locale', 'passwd', 'shell', 
                'resellerconfig', 'userdata', 'linkednodes', 'integrationlinks'];
            skipOptions.forEach(opt => {
                const el = document.getElementById('skip-' + opt);
                if (el && data['skip_' + opt]) el.checked = data['skip_' + opt];
            });
            
            // Database backup settings
            if (data.db_backup_method) {
                document.getElementById('db-backup-method').value = data.db_backup_method;
                toggleDbBackupOptions(data.db_backup_method);
            }
            const dbBackupTargetDirEl = document.getElementById('db-backup-target-dir');
            if (dbBackupTargetDirEl && data.db_backup_target_dir) dbBackupTargetDirEl.value = data.db_backup_target_dir;
            
            // MariaDB backup options
            if (data.mdb_compress) document.getElementById('mdb-compress').checked = data.mdb_compress;
            if (data.mdb_parallel) document.getElementById('mdb-parallel').checked = data.mdb_parallel;
            if (data.mdb_slave_info) document.getElementById('mdb-slave-info').checked = data.mdb_slave_info;
            if (data.mdb_galera_info) document.getElementById('mdb-galera-info').checked = data.mdb_galera_info;
            if (data.mdb_parallel_threads) document.getElementById('mdb-parallel-threads').value = data.mdb_parallel_threads;
            if (data.mdb_extra_args) document.getElementById('mdb-extra-args').value = data.mdb_extra_args;
            
            // MySQL backup options
            if (data.myb_compress) document.getElementById('myb-compress').checked = data.myb_compress;
            if (data.myb_incremental) document.getElementById('myb-incremental').checked = data.myb_incremental;
            if (data.myb_backup_dir) document.getElementById('myb-backup-dir').value = data.myb_backup_dir;
            if (data.myb_extra_args) document.getElementById('myb-extra-args').value = data.myb_extra_args;
            
            // Handle global config (root only) or lock status (resellers)
            if (data._global) {
                // Root user - has full global config
                isRootUser = true;
                schedulesLocked = data._global.schedules_locked || false;
                
                // Set schedules lock checkbox
                const lockEl = document.getElementById('schedules-locked');
                if (lockEl) {
                    lockEl.checked = schedulesLocked;
                }
                
                // Set debug mode checkbox (root only - global setting)
                const debugModeEl = document.getElementById('debug-mode');
                if (debugModeEl) {
                    debugModeEl.checked = data._global.debug_mode || false;
                }
                
                // Set cron error alerts checkbox (root only)
                const cronErrorsEl = document.getElementById('notify-cron-errors');
                if (cronErrorsEl) {
                    cronErrorsEl.checked = data._global.notify_cron_errors !== undefined ? data._global.notify_cron_errors : true;
                }
                
                // Set queue failure alerts checkbox (root only)
                const queueFailureEl = document.getElementById('notify-queue-failure');
                if (queueFailureEl) {
                    queueFailureEl.checked = data._global.notify_queue_failure !== undefined ? data._global.notify_queue_failure : true;
                }
                
                // Set pruning alerts checkbox (root only)
                const pruningEl = document.getElementById('notify-pruning');
                if (pruningEl) {
                    pruningEl.checked = data._global.notify_pruning !== undefined ? data._global.notify_pruning : false;
                }
                
                // Populate "View as user" dropdown in schedules
                const viewUserSelect = document.getElementById('schedule-view-user');
                if (viewUserSelect && data._users_with_schedules) {
                    viewUserSelect.innerHTML = '<option value="all">All Users</option>';
                    // Add root first if they have schedules
                    if (data._users_with_schedules.includes('root')) {
                        viewUserSelect.innerHTML += '<option value="root">root</option>';
                    }
                    // Add resellers
                    if (data._resellers) {
                        data._resellers.forEach(reseller => {
                            const hasSchedules = data._users_with_schedules.includes(reseller);
                            viewUserSelect.innerHTML += '<option value="' + reseller + '">' + reseller + (hasSchedules ? '' : ' (no schedules)') + '</option>';
                        });
                    }
                }
            } else if (data._schedules_locked !== undefined) {
                // Non-root user - just get lock status
                isRootUser = false;
                schedulesLocked = data._schedules_locked;
                
                // Update schedule UI based on lock status
                updateScheduleLockUI();
            }
        }).catch(err => {
            console.error('Failed to load config', err);
            // Keep currentConfig as-is and show a warning placeholder if present
            const settingsPanel = document.getElementById('panel-settings');
            if (settingsPanel) {
                // show a small warning in the settings panel
                const e = document.createElement('div');
                e.className = 'alert alert-warning';
                e.textContent = 'Unable to load configuration.';
                settingsPanel.prepend(e);
            }
        });
        
        // Load database server info
        loadDbServerInfo();
    }
    
    // Load Database Server Info
    function loadDbServerInfo() {
        apiCall('get_db_info', {}, 'GET').then(data => {
            const container = document.getElementById('db-server-info');
            if (!container) return;
            
            let html = `<div style="margin-bottom: 0;">`;
            html += `<strong style="font-size: 14px;">${data.type} ${data.version}</strong>`;
            html += `<div style="background: #1e293b; color: #22d3ee; font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; font-size: 12px; padding: 10px 14px; border-radius: 6px; margin: 10px 0 16px 0; overflow-x: auto;">${data.full_version}</div>`;
            html += `<div style="display: flex; gap: 16px; flex-wrap: wrap;">`;
            html += `<div style="display: flex; align-items: center; gap: 8px;"><strong style="color: #1e293b;">mariadb-backup</strong>`;
            html += data.mariadb_backup_available 
                ? '<span style="background: #22c55e; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">Available</span>' 
                : '<span style="background: #ef4444; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">Not Found</span>';
            html += `</div>`;
            html += `<div style="display: flex; align-items: center; gap: 8px;"><strong style="color: #1e293b;">mysqlbackup</strong>`;
            html += data.mysqlbackup_available 
                ? '<span style="background: #22c55e; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">Available</span>' 
                : '<span style="background: #ef4444; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">Not Found</span>';
            html += `</div>`;
            html += `</div></div>`;
            
            container.innerHTML = html;
        }).catch(err => {
            const container = document.getElementById('db-server-info');
            if (container) {
                container.innerHTML = '<div class="alert alert-warning">Unable to detect database server</div>';
            }
        });
    }
    
    // Toggle database backup options visibility
    function toggleDbBackupOptions(method) {
        document.getElementById('mariadb-backup-options').style.display = 
            method === 'mariadb-backup' ? 'block' : 'none';
        document.getElementById('mysqlbackup-options').style.display = 
            method === 'mysqlbackup' ? 'block' : 'none';
        
        // Auto-check skip-mysql if using external backup method
        if (method === 'mariadb-backup' || method === 'mysqlbackup' || method === 'skip') {
            document.getElementById('skip-mysql').checked = true;
        }
    }

    // Load Queue
    function loadQueue() {
        apiCall('get_queue', {}, 'GET').then(data => {
            const queueTbody = document.getElementById('queue-tbody');
            const runningTbody = document.getElementById('running-jobs-tbody');
            
            // Update status monitor
            updateStatusMonitor(data);
            
            // Queued jobs
            if (data.queued && data.queued.length > 0) {
                queueTbody.innerHTML = data.queued.map(job => {
                    // Format accounts: each in <code> with space between
                    const accountsHtml = job.accounts.map(acc => `<code>${acc}</code>`).join(' ');
                    return `
                    <tr>
                        <td>${accountsHtml}</td>
                        <td><div class="job-cell"><strong>${job.type}</strong><code>${job.id}</code></div></td>
                        <td>${job.destination_name || job.destination}</td>
                        <td><span class="log-timestamp">${job.created_at}</span></td>
                        <td>
                            <button class="btn btn-sm btn-danger" onclick="removeFromQueue('${job.id}')">Remove</button>
                        </td>
                    </tr>
                `}).join('');
            } else {
                queueTbody.innerHTML = '<tr><td colspan="5">No queued jobs.</td></tr>';
            }
            
            // Running jobs
            if (data.running && data.running.length > 0) {
                // Clean up cancellingJobs set - remove jobs no longer in running list
                const runningIds = new Set(data.running.map(j => j.id));
                for (const jobID of cancellingJobs) {
                    if (!runningIds.has(jobID)) {
                        cancellingJobs.delete(jobID);
                    }
                }
                
                runningTbody.innerHTML = data.running.map(job => {
                    // Calculate progress from accounts completed vs total
                    const total = job.accounts_total || 0;
                    const completed = job.accounts_completed || 0;
                    const progress = total > 0 ? Math.round((completed / total) * 100) : 0;
                    const progressText = total > 0 ? `${completed}/${total}` : '';
                    // Handle both 'accounts' array and legacy 'account' string
                    const accountsList = job.accounts || (job.account ? [job.account] : []);
                    const accountsHtml = accountsList.map(acc => `<code>${acc}</code>`).join(' ');
                    // Check if cancel already requested for this job
                    const isCancelling = cancellingJobs.has(job.id);
                    const cancelBtn = isCancelling
                        ? `<button class="btn btn-sm btn-secondary" disabled style="margin-top: 4px;" title="Cancellation pending">Cancelling...</button>`
                        : `<button class="btn btn-sm btn-danger" onclick="cancelJob('${job.id}')" style="margin-top: 4px;" title="Cancel this job">Cancel</button>`;
                    return `
                    <tr>
                        <td>${accountsHtml}</td>
                        <td><div class="job-cell"><strong>${job.type}</strong><code>${job.id}</code></div></td>
                        <td><span class="log-timestamp">${job.started_at}</span></td>
                        <td>
                            <span class="status-badge status-running">${job.status}</span>
                            <div class="progress-bar" style="width: 100%; margin-top: 4px;" title="${progressText}">
                                <div class="progress-bar-fill" style="width: ${progress}%"></div>
                            </div>
                            ${cancelBtn}
                        </td>
                    </tr>
                `}).join('');
            } else {
                runningTbody.innerHTML = '<tr><td colspan="4">No running jobs.</td></tr>';
            }
        }).catch(err => {
            console.error('Failed to load queue', err);
            const queueTbody = document.getElementById('queue-tbody');
            const runningTbody = document.getElementById('running-jobs-tbody');
            if (queueTbody) queueTbody.innerHTML = '<tr><td colspan="6">Unable to load queue.</td></tr>';
            if (runningTbody) runningTbody.innerHTML = '<tr><td colspan="4">Unable to load running jobs.</td></tr>';
            updateStatusMonitor({ queued: [], running: [], restores: [] });
        });
    }
    
    // Update Schedule Lock UI (for resellers when locked)
    function updateScheduleLockUI() {
        const lockedAlert = document.getElementById('schedules-locked-alert');
        const createCard = document.getElementById('schedule-create-card');
        const createBtn = document.getElementById('btn-create-schedule');
        
        if (schedulesLocked && !isRootUser) {
            // Show locked alert
            if (lockedAlert) lockedAlert.style.display = 'block';
            // Disable create button
            if (createBtn) {
                createBtn.disabled = true;
                createBtn.innerHTML = '🔒 Schedules Locked';
            }
            // Optionally dim the create card
            if (createCard) createCard.style.opacity = '0.6';
        } else {
            // Hide locked alert
            if (lockedAlert) lockedAlert.style.display = 'none';
            // Enable create button
            if (createBtn) {
                createBtn.disabled = false;
                createBtn.innerHTML = '⏰ Create Schedule';
            }
            if (createCard) createCard.style.opacity = '1';
        }
    }

    // Format schedule frequency for display (capitalize + add day/time info)
    function formatScheduleFrequency(schedule) {
        const freq = schedule.schedule || 'daily';
        const capitalised = freq.charAt(0).toUpperCase() + freq.slice(1);
        const preferredHour = schedule.preferred_time ?? 2;
        const hourStr = String(preferredHour).padStart(2, '0') + ':00';
        
        if (freq === 'weekly') {
            const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const dayOfWeek = schedule.day_of_week ?? 0;
            return capitalised + ' (' + dayNames[dayOfWeek % 7] + ' ' + hourStr + ')';
        } else if (freq === 'monthly') {
            return capitalised + ' (1st ' + hourStr + ')';
        } else if (freq === 'daily') {
            return capitalised + ' (' + hourStr + ')';
        } else if (freq === 'hourly') {
            return capitalised;
        }
        return capitalised;
    }
    
    // Load Schedules
    function loadSchedules() {
        // Build request params - include view_user for root
        let params = {};
        if (isRootUser && currentScheduleViewUser && currentScheduleViewUser !== 'all') {
            params.view_user = currentScheduleViewUser;
        }
        
        apiCall('get_queue', params, 'GET').then(data => {
            const tbody = document.getElementById('schedules-tbody');
            const colCount = isRootUser ? 7 : 6;
            
            // Update lock UI in case it changed
            updateScheduleLockUI();
            
            if (data.schedules && data.schedules.length > 0) {
                tbody.innerHTML = data.schedules.map(schedule => {
                    // Determine if delete button should be disabled
                    const canDelete = isRootUser || !schedulesLocked;
                    const deleteBtn = canDelete 
                        ? '<button class="btn btn-sm btn-danger" onclick="removeSchedule(\'' + schedule.id + '\')">Delete</button>'
                        : '<button class="btn btn-sm btn-danger" disabled title="Schedules locked by administrator">🔒</button>';
                    
                    // Display accounts - show "All Accounts" badge if dynamic
                    let accountsDisplay;
                    if (schedule.all_accounts || (schedule.accounts.length === 1 && schedule.accounts[0] === '*')) {
                        accountsDisplay = '<span class="status-badge" style="background: var(--primary); color: #fff;">🌐 All Accounts</span>';
                    } else {
                        accountsDisplay = schedule.accounts.join(', ');
                    }
                    
                    let row = '<tr>' +
                        '<td>' + accountsDisplay + '</td>' +
                        '<td>' + (schedule.destination_name || schedule.destination) + '</td>' +
                        '<td>' + formatScheduleFrequency(schedule) + '</td>' +
                        '<td>' + (schedule.retention == 0 ? '∞' : schedule.retention) + '</td>' +
                        '<td>' + schedule.next_run + '</td>';
                    
                    // Add owner column for root
                    if (isRootUser) {
                        row += '<td><span class="status-badge">' + (schedule.user || 'unknown') + '</span></td>';
                    }
                    
                    row += '<td>' + deleteBtn + '</td></tr>';
                    return row;
                }).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="' + colCount + '">No active schedules.</td></tr>';
            }
        }).catch(err => {
            console.error('Failed to load schedules', err);
            const tbody = document.getElementById('schedules-tbody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="6">Unable to load schedules.</td></tr>';
        });
    }

    // View verbose log in lightbox
    window.viewVerboseLog = function(jobID) {
        apiCall('get_verbose_log', { job_id: jobID }, 'GET').then(data => {
            if (data.success && data.content) {
                showLogLightbox(data.content, jobID);
            } else {
                alert('Log not found: ' + (data.message || 'Unknown error'));
            }
        }).catch(err => alert('Failed to load log: ' + err));
    };

    function showLogLightbox(content, title) {
        // Remove existing lightbox if any
        const existing = document.getElementById('log-lightbox');
        if (existing) existing.remove();
        
        const lightbox = document.createElement('div');
        lightbox.id = 'log-lightbox';
        lightbox.className = 'log-lightbox-overlay';
        lightbox.innerHTML = 
            '<div class="log-lightbox-content">' +
                '<div class="log-lightbox-header">' +
                    '<span class="log-lightbox-title">' + title + '</span>' +
                    '<button class="log-lightbox-close" onclick="document.getElementById(\'log-lightbox\').remove()">&times;</button>' +
                '</div>' +
                '<pre class="log-lightbox-body">' + escapeHtml(content) + '</pre>' +
            '</div>';
        
        document.body.appendChild(lightbox);
        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox) lightbox.remove();
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Load Logs
    function loadLogs(page = 1) {
        currentLogPage = page;
        const filter = document.getElementById('log-filter').value;
        const accountFilter = document.getElementById('log-account-filter').value;
        
        apiCall('get_logs', { page: page, filter: filter, account: accountFilter }, 'GET').then(data => {
            const tbody = document.getElementById('logs-tbody');
            
            if (data.logs && data.logs.length > 0) {
                tbody.innerHTML = data.logs.map(log => {
                    const viewLogLink = log.job_id ? '<a href="#" class="view-log-link" onclick="viewVerboseLog(\'' + log.job_id + '\'); return false;">Verbose</a>' : '';
                    return '<tr>' +
                        '<td><div class="log-cell-meta">' +
                            '<span class="log-timestamp">' + log.timestamp + '</span>' +
                            '<div class="log-status-row">' +
                                '<span class="status-badge status-' + log.status + '">' + log.status + '</span>' + viewLogLink +
                            '</div>' +
                        '</div></td>' +
                        '<td><div class="log-cell-type">' +
                            '<span class="log-type">' + log.type + '</span>' +
                            '<span class="log-user">' + log.user + ' <span class="log-requestor">(' + (log.requestor || 'N/A') + ')</span></span>' +
                        '</div></td>' +
                        '<td class="log-cell-account"><pre class="log-details">' + (log.account || 'N/A') + '</pre></td>' +
                        '<td class="log-cell-details"><pre class="log-details">' + (log.message || '') + '</pre></td>' +
                    '</tr>';
                }).join('');
                
                // Pagination
                renderPagination(data.total_pages, page);
            } else {
                tbody.innerHTML = '<tr><td colspan="4">No logs found.</td></tr>';
                renderPagination(0, 1);
            }
        }).catch(err => {
            console.error('Failed to load logs', err);
            const tbody = document.getElementById('logs-tbody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="4">Unable to load logs.</td></tr>';
            renderPagination(0, 1);
        });
    }

    // Render Pagination with windowed page numbers
    // Shows: [1][2][3]...[current-3]...[current+3]...[last-2][last-1][last]
    function renderPagination(totalPages, currentPage) {
        const container = document.getElementById('logs-pagination');
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        const windowSize = 3; // Pages either side of current
        let html = '';
        
        // Helper to create a page button
        const pageButton = (page) => {
            const active = page === currentPage ? 'btn-primary' : 'btn-secondary';
            return `<button class="btn btn-sm ${active}" onclick="loadLogs(${page})" style="margin: 0 2px;">${page}</button>`;
        };
        
        // Helper to create ellipsis
        const ellipsis = () => '<span style="margin: 0 4px; color: var(--text-muted);">...</span>';
        
        // If total pages <= 10, show all
        if (totalPages <= 10) {
            for (let i = 1; i <= totalPages; i++) {
                html += pageButton(i);
            }
        } else {
            // Always show first 3 pages
            for (let i = 1; i <= Math.min(3, totalPages); i++) {
                html += pageButton(i);
            }
            
            // Calculate window around current page
            const windowStart = Math.max(4, currentPage - windowSize);
            const windowEnd = Math.min(totalPages - 3, currentPage + windowSize);
            
            // Add ellipsis if gap before window
            if (windowStart > 4) {
                html += ellipsis();
            }
            
            // Show pages in window around current
            for (let i = windowStart; i <= windowEnd; i++) {
                if (i > 3 && i < totalPages - 2) {
                    html += pageButton(i);
                }
            }
            
            // Add ellipsis if gap after window
            if (windowEnd < totalPages - 3) {
                html += ellipsis();
            }
            
            // Always show last 3 pages
            for (let i = Math.max(totalPages - 2, 4); i <= totalPages; i++) {
                html += pageButton(i);
            }
        }
        
        container.innerHTML = html;
    }

    // Update Status Monitor
    function updateStatusMonitor(data) {
        const jobsEl = document.getElementById('status-jobs');
        const transitEl = document.getElementById('status-transit');
        const resellersEl = document.getElementById('status-resellers');
        const processingIndicator = document.getElementById('status-processing-indicator');
        
        if (!jobsEl || !transitEl) return;
        
        const queuedCount = (data.queued || []).length;
        const runningCount = (data.running || []).length;
        const totalJobs = queuedCount + runningCount;
        
        // Count jobs in transit (running)
        const inTransit = runningCount;
        
        jobsEl.textContent = totalJobs;
        transitEl.textContent = inTransit;
        
        // Show/hide processing indicator based on running jobs
        if (processingIndicator) {
            if (runningCount > 0) {
                processingIndicator.style.display = 'flex';
            } else {
                processingIndicator.style.display = 'none';
            }
        }
        
        // Update restores count
        const restoresEl = document.getElementById('status-restores');
        if (restoresEl) {
            const restoreCount = (data.restores || []).length;
            restoresEl.textContent = restoreCount;
        }
        
        // Fetch reseller count (only if element exists and root user)
        if (resellersEl && isRootUser) {
            apiCall('get_resellers', {}, 'GET').then(result => {
                if (result.success) {
                    resellersEl.textContent = result.count || 0;
                }
            }).catch(() => {
                resellersEl.textContent = 0;
            });
        } else if (resellersEl) {
            resellersEl.textContent = '😊';
        }
    }

    // Check Cron Status
    function checkCronStatus() {
        apiCall('check_cron', {}, 'GET').then(data => {
            const container = document.getElementById('cron-status-container');
            if (!container) return;
            
            if (data.installed) {
                const cronLine = data.schedule && data.command 
                    ? `${data.schedule} ${data.command}`
                    : (data.command || 'Cron entry found');
                container.innerHTML = `
                    <div class="alert alert-success" style="margin-bottom: 0;">
                        <strong>Cron is properly configured.</strong>
                        <small style="display: block; opacity: 0.8; margin-top: 4px;">Path: <code>${data.path || '/etc/cron.d/backbork'}</code></small>
                    </div>
                    <code style="display: block; margin-top: 12px; padding: 10px; background: var(--terminal-bg); border-radius: 6px; font-size: 12px; color: var(--terminal-text); overflow-x: auto; white-space: pre-wrap; word-break: break-all;">${cronLine}</code>
                `;
            } else {
                container.innerHTML = `
                    <div class="alert alert-danger" style="margin-bottom: 0;">
                        <strong>Cron is NOT properly configured!</strong><br>
                        <small>${data.message || 'Run the install script to set up the cron job.'}</small>
                    </div>
                `;
            }
        }).catch(err => {
            const container = document.getElementById('cron-status-container');
            if (container) {
                container.innerHTML = `
                    <div class="alert alert-warning" style="margin-bottom: 0;">
                        <strong>Unable to check cron status</strong><br>
                        <small>${err.message || 'An error occurred.'}</small>
                    </div>
                `;
            }
        });
    }

    // Load Destination Visibility (root only)
    function loadDestinationVisibility() {
        const container = document.getElementById('destination-visibility-list');
        if (!container) return; // Not root user, element doesn't exist
        
        // Get all destinations and current visibility settings in parallel
        Promise.all([
            apiCall('get_destinations', {}, 'GET'),
            apiCall('get_destination_visibility', {}, 'GET')
        ]).then(([destData, visData]) => {
            const destinations = destData.destinations || [];
            const rootOnlyIds = (visData.root_only_destinations || []);
            
            if (destinations.length === 0) {
                container.innerHTML = '<p style="color: var(--text-muted);">No destinations configured.</p>';
                return;
            }
            
            let html = '<div class="checkbox-group">';
            destinations.forEach(dest => {
                const isRootOnly = rootOnlyIds.includes(dest.id);
                const destName = dest.name || dest.id;
                const destType = dest.type || 'Unknown';
                const isLocal = dest.id.toLowerCase() === 'local' || destType.toLowerCase() === 'local';
                const validateBtn = isLocal ? '' : `<button class="btn btn-sm btn-secondary validate-dest-btn" data-destination="${dest.id}" data-dest-name="${destName}" style="margin-left: auto; padding: 2px 8px; font-size: 11px;">Validate</button>`;
                html += `
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" class="dest-visibility-checkbox" data-destination="${dest.id}" ${isRootOnly ? 'checked' : ''}>
                        <span><strong>${destName}</strong> <small style="color: var(--text-muted);">(${destType})</small></span>
                        ${isRootOnly ? '<span style="font-size: 11px; padding: 2px 6px; background: var(--danger-light); color: var(--danger); border-radius: 3px;">Root Only</span>' : ''}
                        ${validateBtn}
                    </label>
                `;
            });
            html += '</div>';
            html += '<div id="dest-validation-result" style="margin-top: 12px;"></div>';
            html += '<p style="font-size: 12px; color: var(--text-secondary); margin-top: 12px;"><code>Check a destination to make it root-only (hidden from resellers).</code></p>';
            
            container.innerHTML = html;
            
            // Add event listeners to checkboxes
            container.querySelectorAll('.dest-visibility-checkbox').forEach(cb => {
                cb.addEventListener('change', function() {
                    const destination = this.dataset.destination;
                    const rootOnly = this.checked;
                    
                    apiCall('set_destination_visibility', { destination_id: destination, root_only: rootOnly }).then(result => {
                        if (result.success) {
                            // Refresh the list to update labels
                            loadDestinationVisibility();
                        } else {
                            alert('Failed to update destination visibility: ' + (result.message || 'Unknown error'));
                            this.checked = !rootOnly; // Revert checkbox
                        }
                    }).catch(err => {
                        alert('Error updating destination visibility');
                        this.checked = !rootOnly; // Revert checkbox
                    });
                });
            });
            
            // Add event listeners to validate buttons
            container.querySelectorAll('.validate-dest-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const destination = this.dataset.destination;
                    const destName = this.dataset.destName || destination;
                    validateDestination(destination, destName, this);
                });
            });
        }).catch(err => {
            container.innerHTML = '<p style="color: var(--danger);">Failed to load destinations.</p>';
            console.error('Failed to load destination visibility:', err);
        });
    }

    // Validate a remote destination
    function validateDestination(destination, destName, buttonEl) {
        const resultDiv = document.getElementById('dest-validation-result');
        const originalText = buttonEl.textContent;
        
        // Show spinner on button
        buttonEl.disabled = true;
        buttonEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        // Clear previous result
        if (resultDiv) resultDiv.innerHTML = '';
        
        apiCall('validate_destination', { destination_id: destination }).then(result => {
            buttonEl.disabled = false;
            buttonEl.textContent = originalText;
            
            if (resultDiv) {
                if (result.success) {
                    resultDiv.innerHTML = `
                        <div class="alert alert-success" style="margin-bottom: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                            <strong style="flex-shrink: 0;">${destName}</strong>
                            ${result.output ? `<pre style="margin: 0; font-size: 11px; white-space: pre-wrap; max-height: 60px; overflow-y: auto; background: rgba(0,0,0,0.05); padding: 6px 10px; border-radius: 4px; flex: 1 1 50%; min-width: 200px;">${result.output}</pre>` : ''}
                        </div>`;
                } else {
                    resultDiv.innerHTML = `
                        <div class="alert alert-danger" style="margin-bottom: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                            <strong style="flex-shrink: 0;">${destName}</strong>
                            ${result.output ? `<pre style="margin: 0; font-size: 11px; white-space: pre-wrap; max-height: 60px; overflow-y: auto; background: rgba(0,0,0,0.05); padding: 6px 10px; border-radius: 4px; flex: 1 1 50%; min-width: 200px;">${result.output}</pre>` : ''}
                        </div>`;
                }
            }
        }).catch(err => {
            buttonEl.disabled = false;
            buttonEl.textContent = originalText;
            
            if (resultDiv) {
                resultDiv.innerHTML = `
                    <div class="alert alert-danger" style="margin-bottom: 0; display: flex; align-items: center;">
                        <strong>❌ ${destName}:</strong>&nbsp;Request failed - ${err.message || 'Unknown error'}
                    </div>`;
            }
            console.error('Destination validation failed:', err);
        });
    }

    // Load Disabled Destinations (root only)
    function loadDisabledDestinations() {
        const container = document.getElementById('disabled-destinations-list');
        if (!container) return; // Not root user, element doesn't exist
        
        apiCall('get_destinations', {}, 'GET').then(data => {
            const destinations = data.destinations || [];
            const disabledDests = destinations.filter(d => !d.enabled);
            
            if (disabledDests.length === 0) {
                container.innerHTML = '<div class="alert alert-success" style="margin-bottom: 0;"><strong>All destinations are enabled. <a href="https://www.youtube.com/watch?v=WViLb31x5Go" target="_blank">Very nice</a>.</strong></div>';
                return;
            }
            
            let html = '<div class="alert alert-danger" style="margin-bottom: 12px;"><strong>' + disabledDests.length + ' destination' + (disabledDests.length > 1 ? 's are' : ' is') + ' disabled. Please resolve!</strong></div>';
            html += '<div style="display: flex; flex-direction: column; gap: 8px;">';
            disabledDests.forEach(dest => {
                const destName = dest.name || dest.id;
                const destType = dest.type || 'Unknown';
                html += `
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: var(--danger-light); border-radius: 4px;">
                        <span>
                            <strong>${destName}</strong>
                            <small style="color: var(--text-muted);">(${destType})</small>
                            <span style="font-size: 11px; padding: 2px 6px; background: var(--danger); color: white; border-radius: 3px; margin-left: 8px;">Disabled</span>
                        </span>
                        <button class="btn btn-sm btn-success enable-dest-btn" data-destination="${dest.id}" data-dest-name="${destName}">
                            Enable
                        </button>
                    </div>
                `;
            });
            html += '</div>';
            html += '<p style="font-size: 12px; color: var(--text-secondary); margin-top: 12px;">Click Enable to activate the destination via WHM API.</p>';
            
            container.innerHTML = html;
            
            // Add event listeners to enable buttons
            container.querySelectorAll('.enable-dest-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const destination = this.dataset.destination;
                    const destName = this.dataset.destName || destination;
                    const buttonEl = this;
                    
                    buttonEl.disabled = true;
                    buttonEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    
                    apiCall('enable_destination', { destination_id: destination }).then(result => {
                        if (result.success) {
                            // Refresh both lists
                            loadDisabledDestinations();
                            loadDestinationVisibility();
                        } else {
                            alert('Failed to enable destination: ' + (result.message || 'Unknown error'));
                            buttonEl.disabled = false;
                            buttonEl.textContent = 'Enable';
                        }
                    }).catch(err => {
                        alert('Error enabling destination');
                        buttonEl.disabled = false;
                        buttonEl.textContent = 'Enable';
                    });
                });
            });
        }).catch(err => {
            container.innerHTML = '<p style="color: var(--danger);">Failed to load destinations.</p>';
            console.error('Failed to load disabled destinations:', err);
        });
    }

    // Refresh Status
    function refreshStatus() {
        const activeTab = document.querySelector('.backbork-tab.active');
        if (activeTab && activeTab.dataset.tab === 'queue') {
            loadQueue();
        }
        // Also refresh status monitor
        apiCall('get_queue', {}, 'GET').then(data => {
            updateStatusMonitor(data);
            
            // Check if there are running jobs - enable fast refresh if so
            const runningCount = (data.running?.length || 0) + (data.restores?.length || 0);
            if (runningCount > 0 && !fastRefreshInterval) {
                // Start fast refresh (every 5 seconds)
                hasRunningJobs = true;
                fastRefreshInterval = setInterval(refreshStatus, 5000);
            } else if (runningCount === 0 && fastRefreshInterval) {
                // Stop fast refresh when no running jobs
                hasRunningJobs = false;
                clearInterval(fastRefreshInterval);
                fastRefreshInterval = null;
            }
        }).catch(err => { console.error('Failed to refresh status', err); updateStatusMonitor({ queued: [], running: [], restores: [] }); });
    }

    // Event Listeners
    function initEventListeners() {
        // Select All checkboxes
        const selectAllBackup = document.getElementById('select-all-backup');
        if (selectAllBackup) {
            selectAllBackup.addEventListener('change', function() {
                document.querySelectorAll('#backup-accounts-container .account-checkbox').forEach(cb => {
                    cb.checked = this.checked;
                });
            });
        }
        
        const selectAllSchedule = document.getElementById('select-all-schedule');
        if (selectAllSchedule) {
            selectAllSchedule.addEventListener('change', function() {
                document.querySelectorAll('#schedule-accounts-container .account-checkbox').forEach(cb => {
                    cb.checked = this.checked;
                });
            });
        }

        // Backup Now
        const btnBackupNow = document.getElementById('btn-backup-now');
        if (btnBackupNow) {
            btnBackupNow.addEventListener('click', function() {
                const selectedAccounts = getSelectedAccounts('backup-accounts-container');
                const destination = document.getElementById('backup-destination').value;
                
                if (selectedAccounts.length === 0) {
                    alert('Please select at least one account.');
                    return;
                }
                
                if (!destination) {
                    alert('Please select a destination.');
                    return;
                }
                
                startBackup(selectedAccounts, destination);
            });
        }

        // Add to Queue
        const btnBackupQueue = document.getElementById('btn-backup-queue');
        if (btnBackupQueue) {
            btnBackupQueue.addEventListener('click', function() {
                const selectedAccounts = getSelectedAccounts('backup-accounts-container');
                const destination = document.getElementById('backup-destination').value;
                
                if (selectedAccounts.length === 0) {
                    alert('Please select at least one account.');
                    return;
                }
                
                if (!destination) {
                    alert('Please select a destination.');
                    return;
                }
                
                apiCall('queue_backup', {
                    accounts: selectedAccounts,
                    destination: destination,
                    schedule: 'once'
                }).then(data => {
                    if (data.success) {
                        alert('Jobs added to queue successfully!');
                        loadQueue();
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => { console.error('Error queue_backup', err); alert('Failed to queue backup: ' + (err.message || 'Unknown error')); });
            });
        }

        // Restore
        const btnRestore = document.getElementById('btn-restore');
        if (btnRestore) {
            btnRestore.addEventListener('click', function() {
                const destinationSelect = document.getElementById('restore-destination');
                const destination = destinationSelect.value;
                const destinationName = destinationSelect.options[destinationSelect.selectedIndex]?.text || destination;
                const account = document.getElementById('restore-account').value;
                const backupFile = document.getElementById('restore-backup-file').value;
                
                if (!destination || !account || !backupFile) {
                    alert('Please select destination, account, and backup file.');
                    return;
                }
                
                // Show confirmation modal
                document.getElementById('restore-confirm-details').innerHTML = `
                    <p><strong>Account:</strong> ${account}</p>
                    <p><strong>Backup File:</strong> ${backupFile}</p>
                    <p><strong>Source:</strong> ${destinationName}</p>
                `;
                document.getElementById('restore-modal').classList.add('active');
            });
        }

        const btnConfirmRestore = document.getElementById('btn-confirm-restore');
        if (btnConfirmRestore) {
            btnConfirmRestore.addEventListener('click', function() {
                closeModal('restore-modal');
                
                const destination = document.getElementById('restore-destination').value;
                const account = document.getElementById('restore-account').value;
                const backupFile = document.getElementById('restore-backup-file').value;
                
                const options = {
                    homedir: document.querySelector('[name="restore_homedir"]').checked,
                    mysql: document.querySelector('[name="restore_mysql"]').checked,
                    mail: document.querySelector('[name="restore_mail"]').checked,
                    ssl: document.querySelector('[name="restore_ssl"]').checked,
                    cron: document.querySelector('[name="restore_cron"]').checked,
                    dns: document.querySelector('[name="restore_dns"]').checked,
                    subdomains: document.querySelector('[name="restore_subdomains"]').checked,
                    addon_domains: document.querySelector('[name="restore_addon_domains"]').checked
                };
                
                startRestore(backupFile, account, options, destination);
            });
        }

        // Load backups when destination/account changes
        const restoreDestination = document.getElementById('restore-destination');
        if (restoreDestination) {
            restoreDestination.addEventListener('change', loadAvailableBackups);
        }
        
        const restoreAccount = document.getElementById('restore-account');
        if (restoreAccount) {
            restoreAccount.addEventListener('change', loadAvailableBackups);
        }

        // Create Schedule
        // All Accounts toggle for schedules
        const scheduleAllAccounts = document.getElementById('schedule-all-accounts');
        if (scheduleAllAccounts) {
            scheduleAllAccounts.addEventListener('change', function() {
                const container = document.getElementById('schedule-accounts-container');
                const selectAll = document.getElementById('select-all-schedule');
                const hint = document.getElementById('all-accounts-hint');
                
                if (this.checked) {
                    // Dim the account list and uncheck individual selections
                    if (container) container.style.opacity = '0.4';
                    if (selectAll) {
                        selectAll.checked = false;
                        selectAll.disabled = true;
                    }
                    document.querySelectorAll('#schedule-accounts-container .account-checkbox').forEach(cb => {
                        cb.checked = false;
                        cb.disabled = true;
                    });
                    if (hint) hint.style.display = 'block';
                } else {
                    // Restore the account list
                    if (container) container.style.opacity = '1';
                    if (selectAll) selectAll.disabled = false;
                    document.querySelectorAll('#schedule-accounts-container .account-checkbox').forEach(cb => {
                        cb.disabled = false;
                    });
                    if (hint) hint.style.display = 'none';
                }
            });
        }

        // Show/hide day-of-week selector based on frequency, and disable time for hourly
        const frequencySelect = document.getElementById('schedule-frequency');
        const dowRow = document.getElementById('schedule-dow-row');
        const timeSelect = document.getElementById('schedule-time');
        if (frequencySelect && dowRow) {
            const updateScheduleFields = function() {
                const isHourly = frequencySelect.value === 'hourly';
                const isWeekly = frequencySelect.value === 'weekly';
                
                // Show day-of-week only for weekly
                dowRow.style.display = isWeekly ? 'flex' : 'none';
                
                // Disable time selection for hourly (runs every hour)
                if (timeSelect) {
                    timeSelect.disabled = isHourly;
                    timeSelect.style.opacity = isHourly ? '0.5' : '1';
                    timeSelect.title = isHourly ? 'Hourly schedules run every hour' : '';
                }
            };
            frequencySelect.addEventListener('change', updateScheduleFields);
            // Run once on load
            updateScheduleFields();
        }
        
        // Create Schedule
        const btnCreateSchedule = document.getElementById('btn-create-schedule');
        if (btnCreateSchedule) {
            btnCreateSchedule.addEventListener('click', function() {
                const allAccountsChecked = document.getElementById('schedule-all-accounts')?.checked || false;
                const selectedAccounts = allAccountsChecked ? ['*'] : getSelectedAccounts('schedule-accounts-container');
                const destination = document.getElementById('schedule-destination').value;
                const frequency = document.getElementById('schedule-frequency').value;
                const retention = document.getElementById('schedule-retention').value;
                const time = document.getElementById('schedule-time').value;
                const dayOfWeek = document.getElementById('schedule-day-of-week')?.value || '0';
                
                if (selectedAccounts.length === 0) {
                    alert('Please select at least one account or enable "All Accounts".');
                    return;
                }
                
                if (!destination) {
                    alert('Please select a destination.');
                    return;
                }
                
                apiCall('create_schedule', {
                    accounts: selectedAccounts,
                    destination: destination,
                    schedule: frequency,
                    retention: parseInt(retention),
                    preferred_time: parseInt(time),
                    day_of_week: parseInt(dayOfWeek),
                    all_accounts: allAccountsChecked
                }).then(data => {
                    if (data.success) {
                        alert('Schedule created successfully!');
                        loadSchedules();
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => { console.error('Error schedule create', err); alert('Failed to create schedule: ' + (err.message || 'Unknown error')); });
            });
        }

        // Save Settings
        const btnSaveSettings = document.getElementById('btn-save-settings');
        if (btnSaveSettings) {
            btnSaveSettings.addEventListener('click', function() {
                const debugModeEl = document.getElementById('debug-mode');
                const cronErrorsEl = document.getElementById('notify-cron-errors');
                const queueFailureEl = document.getElementById('notify-queue-failure');
                const pruningEl = document.getElementById('notify-pruning');
                const config = {
                    // Notification settings - channels
                    notify_email: document.getElementById('notify-email').value,
                    slack_webhook: document.getElementById('slack-webhook').value,
                    
                    // Notification settings - backup events
                    notify_backup_success: document.getElementById('notify-backup-success').checked,
                    notify_backup_failure: document.getElementById('notify-backup-failure').checked,
                    notify_backup_start: document.getElementById('notify-backup-start').checked,
                    
                    // Notification settings - restore events
                    notify_restore_success: document.getElementById('notify-restore-success').checked,
                    notify_restore_failure: document.getElementById('notify-restore-failure').checked,
                    notify_restore_start: document.getElementById('notify-restore-start').checked,
                    
                    // Notification settings - system events (user-level)
                    notify_daily_summary: document.getElementById('notify-daily-summary').checked,
                    
                    // Database backup settings (db_backup_target_dir is root-only)
                    db_backup_method: document.getElementById('db-backup-method').value,
                    db_backup_target_dir: document.getElementById('db-backup-target-dir')?.value || '',
                    
                    // MariaDB backup options
                    mdb_compress: document.getElementById('mdb-compress').checked,
                    mdb_parallel: document.getElementById('mdb-parallel').checked,
                    mdb_slave_info: document.getElementById('mdb-slave-info').checked,
                    mdb_galera_info: document.getElementById('mdb-galera-info').checked,
                    mdb_parallel_threads: document.getElementById('mdb-parallel-threads').value,
                    mdb_extra_args: document.getElementById('mdb-extra-args').value,
                    
                    // MySQL backup options
                    myb_compress: document.getElementById('myb-compress').checked,
                    myb_incremental: document.getElementById('myb-incremental').checked,
                    myb_backup_dir: document.getElementById('myb-backup-dir').value,
                    myb_extra_args: document.getElementById('myb-extra-args').value,
                    
                    // pkgacct settings (temp_directory is root-only)
                    temp_directory: document.getElementById('temp-directory')?.value || '',
                    mysql_version: document.getElementById('mysql-version').value,
                    dbbackup_type: document.getElementById('dbbackup-type').value,
                    compression_option: document.getElementById('compression-option').value,
                    
                    // Backup mode options (opt_split and opt_use_backups are root-only)
                    opt_incremental: document.getElementById('opt-incremental').checked,
                    opt_split: document.getElementById('opt-split')?.checked || false,
                    opt_use_backups: document.getElementById('opt-use-backups')?.checked || false,
                    
                    // Skip options
                    skip_homedir: document.getElementById('skip-homedir').checked,
                    skip_publichtml: document.getElementById('skip-publichtml').checked,
                    skip_mysql: document.getElementById('skip-mysql').checked,
                    skip_pgsql: document.getElementById('skip-pgsql').checked,
                    skip_logs: document.getElementById('skip-logs').checked,
                    skip_mailconfig: document.getElementById('skip-mailconfig').checked,
                    skip_mailman: document.getElementById('skip-mailman').checked,
                    skip_dnszones: document.getElementById('skip-dnszones').checked,
                    skip_ssl: document.getElementById('skip-ssl').checked,
                    skip_bwdata: document.getElementById('skip-bwdata').checked,
                    skip_quota: document.getElementById('skip-quota').checked,
                    skip_ftpusers: document.getElementById('skip-ftpusers').checked,
                    skip_domains: document.getElementById('skip-domains').checked,
                    skip_acctdb: document.getElementById('skip-acctdb').checked,
                    skip_apitokens: document.getElementById('skip-apitokens').checked,
                    skip_authnlinks: document.getElementById('skip-authnlinks').checked,
                    skip_locale: document.getElementById('skip-locale').checked,
                    skip_passwd: document.getElementById('skip-passwd').checked,
                    skip_shell: document.getElementById('skip-shell').checked,
                    skip_resellerconfig: document.getElementById('skip-resellerconfig').checked,
                    skip_userdata: document.getElementById('skip-userdata').checked,
                    skip_linkednodes: document.getElementById('skip-linkednodes').checked,
                    skip_integrationlinks: document.getElementById('skip-integrationlinks').checked
                };
                
                // Root-only: batch all global settings together
                if (debugModeEl || cronErrorsEl || queueFailureEl || pruningEl) {
                    config._global_settings = {
                        debug_mode: debugModeEl ? debugModeEl.checked : undefined,
                        notify_cron_errors: cronErrorsEl ? cronErrorsEl.checked : undefined,
                        notify_queue_failure: queueFailureEl ? queueFailureEl.checked : undefined,
                        notify_pruning: pruningEl ? pruningEl.checked : undefined
                    };
                }
                
                apiCall('save_config', config).then(data => {
                    if (data.success) {
                        alert('Settings saved successfully!');
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => { console.error('Error save_config', err); alert('Failed to save configuration: ' + (err.message || 'Unknown error')); });
            });
        }
        
        // Database backup method change handler
        const dbBackupMethod = document.getElementById('db-backup-method');
        if (dbBackupMethod) {
            dbBackupMethod.addEventListener('change', function() {
                toggleDbBackupOptions(this.value);
            });
        }

        // Test notifications - pass current field values so user doesn't need to save first
        const btnTestEmail = document.getElementById('btn-test-email');
        if (btnTestEmail) {
            btnTestEmail.addEventListener('click', function() {
                const emailValue = document.getElementById('notify-email')?.value || '';
                if (!emailValue.trim()) {
                    alert('Please enter an email address first.');
                    return;
                }
                apiCall('test_notification', { type: 'email', email: emailValue }).then(data => {
                    alert(data.success ? 'Test email sent!\n\nRemember to Save Settings at the bottom of the page if you want it committed!' : 'Error: ' + data.message);
                }).catch(err => { console.error('Error test_notification email', err); alert('Failed to send test email: ' + (err.message || 'Unknown error')); });
            });
        }

        const btnTestSlack = document.getElementById('btn-test-slack');
        if (btnTestSlack) {
            btnTestSlack.addEventListener('click', function() {
                const slackValue = document.getElementById('notify-slack')?.value || '';
                if (!slackValue.trim()) {
                    alert('Please enter a Slack webhook URL first.');
                    return;
                }
                apiCall('test_notification', { type: 'slack', webhook: slackValue }).then(data => {
                    alert(data.success ? 'Test Slack message sent!\n\nRemember to Save Settings at the bottom of the page if you want it committed!' : 'Error: ' + data.message);
                }).catch(err => { console.error('Error test_notification slack', err); alert('Failed to send test slack: ' + (err.message || 'Unknown error')); });
            });
        }

        // Refresh logs
        const btnRefreshLogs = document.getElementById('btn-refresh-logs');
        if (btnRefreshLogs) {
            btnRefreshLogs.addEventListener('click', function() {
                loadLogs(currentLogPage);
            });
        }

        const logFilter = document.getElementById('log-filter');
        if (logFilter) {
            logFilter.addEventListener('change', function() {
                loadLogs(1);
            });
        }
        
        // Account filter for logs
        const logAccountFilter = document.getElementById('log-account-filter');
        if (logAccountFilter) {
            logAccountFilter.addEventListener('change', function() {
                loadLogs(1);
            });
        }
        
        // Schedule View User selector (root only)
        const scheduleViewUser = document.getElementById('schedule-view-user');
        if (scheduleViewUser) {
            scheduleViewUser.addEventListener('change', function() {
                currentScheduleViewUser = this.value;
                loadSchedules();
            });
        }
        
        // Schedules Locked toggle (root only)
        const schedulesLockedToggle = document.getElementById('schedules-locked');
        if (schedulesLockedToggle) {
            schedulesLockedToggle.addEventListener('change', function() {
                const newLockState = this.checked;
                
                apiCall('save_global_config', { schedules_locked: newLockState }).then(data => {
                    if (data.success) {
                        schedulesLocked = newLockState;
                        updateScheduleLockUI();
                        alert(newLockState ? 'Schedules are now locked for resellers.' : 'Schedules are now unlocked for resellers.');
                    } else {
                        // Revert checkbox on failure
                        schedulesLockedToggle.checked = !newLockState;
                        alert('Error: ' + (data.message || 'Failed to update lock status'));
                    }
                }).catch(err => {
                    console.error('Error save_global_config', err);
                    schedulesLockedToggle.checked = !newLockState;
                    alert('Failed to update lock status: ' + (err.message || 'Unknown error'));
                });
            });
        }
        
        // Process Queue Now button
        const btnProcessQueue = document.getElementById('btn-process-queue');
        if (btnProcessQueue) {
            btnProcessQueue.addEventListener('click', function() {
                if (!confirm('Process queue now?\n\nThis will check schedules and run any queued jobs.\n\nNote: Queue runs automatically every 5 minutes.')) return;
                
                btnProcessQueue.disabled = true;
                btnProcessQueue.innerHTML = '<span class="loading-spinner-small"></span> Processing...';
                
                // Immediately start fast refresh to show progress
                if (!fastRefreshInterval) {
                    fastRefreshInterval = setInterval(refreshStatus, 2000);
                }
                // Trigger immediate refresh to show queue moving to running
                setTimeout(() => { loadQueue(); refreshStatus(); }, 500);
                
                apiCall('process_queue', {}, 'POST').then(data => {
                    // Refresh queue to show final state
                    loadQueue();
                    refreshStatus();
                    
                    if (data.success) {
                        // Show brief success toast/message
                        const processed = data.processed?.processed || 0;
                        const failed = data.processed?.failed || 0;
                        const skipped = data.processed?.skipped || false;
                        
                        if (skipped) {
                            // Queue was already being processed
                            console.log('Queue processor already running');
                        } else if (processed > 0 || failed > 0) {
                            alert('Queue processed: ' + processed + ' succeeded, ' + failed + ' failed');
                        } else {
                            // No jobs were in queue
                            console.log('No jobs to process');
                        }
                    } else {
                        alert('Failed to process queue: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => {
                    console.error('Error process_queue', err);
                    alert('Error processing queue: ' + (err.message || 'Unknown error'));
                }).finally(() => {
                    btnProcessQueue.disabled = false;
                    btnProcessQueue.innerHTML = '▶ Process Queue Now';
                });
            });
        }
        
        // Kill All Jobs button
        const btnKillQueue = document.getElementById('btn-kill-queue');
        if (btnKillQueue) {
            btnKillQueue.addEventListener('click', function() {
                if (!confirm('Are you sure you want to kill all items in-queue?\n\nThis is harsher than cancelling below, please use with caution if you have a stuck process, etc.')) return;
                
                btnKillQueue.disabled = true;
                btnKillQueue.innerHTML = '<span class="loading-spinner-small"></span> Killing...';
                
                apiCall('kill_all_jobs', {}, 'POST').then(data => {
                    loadQueue();
                    refreshStatus();
                    
                    if (data.success) {
                        let msg = 'Killed ' + (data.queued_removed || 0) + ' queued and ' + (data.running_cancelled || 0) + ' running jobs.';
                        if (data.processes_killed > 0) {
                            msg += '\n\nTerminated ' + data.processes_killed + ' stuck process(es).';
                        }
                        alert(msg);
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => {
                    alert('Error killing jobs: ' + (err.message || 'Unknown error'));
                }).finally(() => {
                    btnKillQueue.disabled = false;
                    btnKillQueue.innerHTML = '<span class="btn-icon">☠️</span> Kill All Jobs';
                });
            });
        }
    }

    // Get Selected Accounts - Returns array of checked account usernames from a container
    function getSelectedAccounts(containerId) {
        const checkboxes = document.querySelectorAll(`#${containerId} .account-checkbox:checked`);
        return Array.from(checkboxes).map(cb => cb.value);
    }

    // =========================================================================
    // BACKUP EXECUTION
    // Handles immediate backup creation with real-time log tailing via polling
    // Creates backup job, then polls get_backup_log API for progress updates
    // =========================================================================
    function startBackup(accounts, destination) {
        const progressCard = document.getElementById('backup-progress');
        const progressBar = document.getElementById('backup-progress-bar');
        const statusMessage = document.getElementById('backup-status-message');
        const logDiv = document.getElementById('backup-log');
        
        progressCard.style.display = 'block';
        progressBar.style.width = '5%';
        statusMessage.innerHTML = '<div class="loading-spinner"></div> Starting backup...';
        logDiv.innerHTML = '<pre class="backup-log-output" style="background: var(--terminal-bg); color: var(--terminal-text); padding: 12px; border-radius: 6px; font-size: 12px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;"></pre>';
        
        const logOutput = logDiv.querySelector('.backup-log-output');
        let backupID = null;
        let logOffset = 0;
        let pollInterval = null;
        
        // Function to poll for log updates
        function pollBackupLog() {
            if (!backupID) return;
            
            apiCall('get_backup_log', { backup_id: backupID, offset: logOffset }, 'GET')
                .then(data => {
                    if (data.success && data.content) {
                        // Append new content
                        logOutput.textContent += data.content;
                        logOffset = data.offset;
                        
                        // Auto-scroll to bottom
                        logOutput.scrollTop = logOutput.scrollHeight;
                        
                        // Update progress bar based on log content
                        const text = logOutput.textContent.toLowerCase();
                        if (text.includes('step 1/5')) progressBar.style.width = '10%';
                        else if (text.includes('step 2/5')) progressBar.style.width = '20%';
                        else if (text.includes('step 3/5')) progressBar.style.width = '30%';
                        else if (text.includes('[3b]') || text.includes('pkgacct')) progressBar.style.width = '40%';
                        else if (text.includes('[3c]') || text.includes('database')) progressBar.style.width = '50%';
                        else if (text.includes('[3d]') || text.includes('uploading')) progressBar.style.width = '60%';
                        else if (text.includes('[3e]') || text.includes('cleanup')) progressBar.style.width = '75%';
                        else if (text.includes('step 4/5')) progressBar.style.width = '85%';
                        else if (text.includes('step 5/5')) progressBar.style.width = '95%';
                    }
                    
                    // Check if complete
                    if (data.complete) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                        
                        // Check final status and update log appearance
                        if (logOutput.textContent.includes('BACKUP COMPLETED SUCCESSFULLY')) {
                            progressBar.style.width = '100%';
                            statusMessage.innerHTML = '<span class="status-badge status-success">✓ Backup completed successfully!</span>';
                            logOutput.style.background = 'var(--terminal-success-bg)';
                            logOutput.style.color = 'var(--terminal-success-text)';
                        } else if (logOutput.textContent.includes('BACKUP FAILED')) {
                            progressBar.style.width = '100%';
                            progressBar.style.background = 'var(--danger)';
                            statusMessage.innerHTML = '<span class="status-badge status-error">✗ Backup failed</span>';
                            logOutput.style.background = 'var(--terminal-error-bg)';
                            logOutput.style.color = 'var(--terminal-error-text)';
                        }
                        
                        // Refresh the queue
                        loadQueue();
                    }
                })
                .catch(err => {
                    console.error('Error polling backup log:', err);
                });
        }
        
        // Start the backup
        apiCall('create_backup', {
            accounts: accounts,
            destination: destination
        }).then(data => {
            if (data.backup_id) {
                backupID = data.backup_id;
                statusMessage.innerHTML = '<div class="loading-spinner"></div> Backup in progress...';
                progressBar.style.width = '10%';
                
                // Always poll from offset 0 to get full log
                logOffset = 0;
                
                // Start polling for log updates (every 500ms)
                pollInterval = setInterval(pollBackupLog, 500);
                
                // Also do immediate poll
                pollBackupLog();
            }
            
            // Handle immediate completion (no backup_id means early failure)
            if (data.success !== undefined && !data.backup_id) {
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                
                if (data.success) {
                    progressBar.style.width = '100%';
                    statusMessage.innerHTML = '<span class="status-badge status-success">✓ Backup completed successfully!</span>';
                    logOutput.textContent = data.log || 'Backup completed.';
                    logOutput.style.background = 'var(--terminal-success-bg)';
                    logOutput.style.color = 'var(--terminal-success-text)';
                } else {
                    progressBar.style.width = '100%';
                    progressBar.style.background = 'var(--danger)';
                    statusMessage.innerHTML = '<span class="status-badge status-error">✗ Backup failed</span>';
                    let errorOutput = '';
                    if (data.message) errorOutput += data.message + '\n\n';
                    if (data.errors && data.errors.length > 0) errorOutput += 'Errors:\n' + data.errors.join('\n') + '\n\n';
                    if (data.log) errorOutput += 'Log:\n' + data.log;
                    logOutput.textContent = errorOutput || 'Unknown error occurred.';
                    logOutput.style.background = 'var(--terminal-error-bg)';
                    logOutput.style.color = 'var(--terminal-error-text)';
                }
                loadQueue();
            }
        }).catch(err => {
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            statusMessage.innerHTML = '<span class="status-badge status-error">✗ Error</span>';
            logOutput.textContent = 'Error: ' + err.message;
            logOutput.style.background = 'var(--terminal-error-bg)';
            logOutput.style.color = 'var(--terminal-error-text)';
        });
    }

    // =========================================================================
    // RESTORE EXECUTION
    // Handles account restoration with real-time log tailing via polling
    // Creates restore job, then polls get_restore_log API for progress updates
    // =========================================================================
    function startRestore(backupFile, account, options, destination) {
        const progressCard = document.getElementById('restore-progress');
        const progressBar = document.getElementById('restore-progress-bar');
        const statusMessage = document.getElementById('restore-status-message');
        const logDiv = document.getElementById('restore-log');
        
        progressCard.style.display = 'block';
        progressBar.style.width = '5%';
        statusMessage.innerHTML = '<div class="loading-spinner"></div> Starting restore...';
        logDiv.innerHTML = '<pre class="restore-log-output" style="background: var(--terminal-bg); color: var(--terminal-text); padding: 12px; border-radius: 6px; font-size: 12px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;"></pre>';
        
        const logOutput = logDiv.querySelector('.restore-log-output');
        let restoreID = null;
        let logOffset = 0;
        let pollInterval = null;
        
        // Function to poll for log updates
        function pollRestoreLog() {
            if (!restoreID) return;
            
            apiCall('get_restore_log', { restore_id: restoreID, offset: logOffset }, 'GET')
                .then(data => {
                    if (data.success && data.content) {
                        // Append new content
                        logOutput.textContent += data.content;
                        logOffset = data.offset;
                        
                        // Auto-scroll to bottom
                        logOutput.scrollTop = logOutput.scrollHeight;
                        
                        // Update progress bar based on log content (rough estimate)
                        const text = logOutput.textContent.toLowerCase();
                        if (text.includes('extracting')) progressBar.style.width = '20%';
                        else if (text.includes('creating') || text.includes('restoring')) progressBar.style.width = '40%';
                        else if (text.includes('mysql') || text.includes('database')) progressBar.style.width = '60%';
                        else if (text.includes('mail') || text.includes('dns')) progressBar.style.width = '75%';
                        else if (text.includes('completed') || text.includes('finished')) progressBar.style.width = '95%';
                    }
                    
                    // Check if complete
                    if (data.complete) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                        
                        // Check final status and update log appearance
                        if (logOutput.textContent.includes('RESTORE COMPLETED SUCCESSFULLY')) {
                            progressBar.style.width = '100%';
                            statusMessage.innerHTML = '<span class="status-badge status-success">✓ Restore completed successfully!</span>';
                            logOutput.style.background = 'var(--terminal-success-bg)';
                            logOutput.style.color = 'var(--terminal-success-text)';
                        } else if (logOutput.textContent.includes('RESTORE FAILED')) {
                            progressBar.style.width = '100%';
                            progressBar.style.background = 'var(--danger)';
                            statusMessage.innerHTML = '<span class="status-badge status-error">✗ Restore failed</span>';
                            logOutput.style.background = 'var(--terminal-error-bg)';
                            logOutput.style.color = 'var(--terminal-error-text)';
                        }
                    }
                })
                .catch(err => {
                    console.error('Error polling restore log:', err);
                });
        }
        
        // Start the restore
        apiCall('restore_backup', {
            backup_file: backupFile,
            account: account,
            options: options,
            destination: destination
        }).then(data => {
            if (data.restore_id) {
                restoreID = data.restore_id;
                statusMessage.innerHTML = '<div class="loading-spinner"></div> Restore in progress...';
                progressBar.style.width = '10%';
                
                // Always poll from offset 0 to get full log including our additions
                // Don't use data.log as it only contains restorepkg output
                logOffset = 0;
                
                // Start polling for log updates (every 500ms)
                pollInterval = setInterval(pollRestoreLog, 500);
                
                // Also do immediate poll
                pollRestoreLog();
            }
            
            // Handle immediate completion (small restores)
            if (data.success !== undefined && !data.restore_id) {
                // No restore_id means it failed before creating a log
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                
                if (data.success) {
                    progressBar.style.width = '100%';
                    statusMessage.innerHTML = '<span class="status-badge status-success">✓ Restore completed successfully!</span>';
                    logOutput.style.background = 'var(--terminal-success-bg)';
                    logOutput.style.color = 'var(--terminal-success-text)';
                } else {
                    progressBar.style.width = '100%';
                    progressBar.style.background = 'var(--danger)';
                    statusMessage.innerHTML = '<span class="status-badge status-error">✗ Restore failed</span>';
                    logOutput.textContent = data.message || 'Unknown error occurred.';
                    logOutput.style.background = 'var(--terminal-error-bg)';
                    logOutput.style.color = 'var(--terminal-error-text)';
                }
            }
        }).catch(err => {
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            statusMessage.innerHTML = '<span class="status-badge status-error">✗ Error</span>';
            logOutput.textContent = 'Error: ' + err.message;
            logOutput.style.background = 'var(--terminal-error-bg)';
            logOutput.style.color = 'var(--terminal-error-text)';
        });
    }

    // Load Available Backups
    function loadAvailableBackups() {
        const destination = document.getElementById('restore-destination').value;
        const account = document.getElementById('restore-account').value;
        const select = document.getElementById('restore-backup-file');
        
        if (!destination || !account) {
            select.innerHTML = '<option value="">Select destination and account first...</option>';
            return;
        }
        
        select.innerHTML = '<option value="">Loading backups...</option>';
        
        apiCall('get_remote_backups', { destination: destination, account: account }, 'GET').then(data => {
            if (data.backups && data.backups.length > 0) {
                // Sort by ISO 8601 timestamp (oldest first)
                const sorted = data.backups.sort((a, b) => {
                    const fileA = a.display_name || a.file;
                    const fileB = b.display_name || b.file;
                    const tsA = formatBackupTimestamp(fileA);
                    const tsB = formatBackupTimestamp(fileB);
                    return tsA.localeCompare(tsB);  // Ascending (oldest first)
                });
                
                select.innerHTML = '<option value="">-- Select Backup --</option>';
                sorted.forEach(backup => {
                    // Use full path (file) as value
                    const filename = backup.display_name || backup.file;
                    const timestamp = formatBackupTimestamp(filename);
                    const size = backup.size || 'Unknown size';
                    // Format: timestamp « size » filename
                    select.innerHTML += `<option value="${backup.file}">${timestamp}   «   ${size}   »   ${filename}</option>`;
                });
            } else {
                select.innerHTML = '<option value="">No backups found</option>';
            }
        }).catch(err => { console.error('Error get_remote_backups', err); const tbody = document.getElementById('remote-backups-tbody'); if(tbody) tbody.innerHTML = '<tr><td colspan="5">Unable to load remote backups.</td></tr>'; });
    }

    // Remove from Queue
    window.removeFromQueue = function(jobID) {
        if (!confirm('Are you sure you want to remove this job from the queue?')) return;
        
        apiCall('remove_from_queue', { job_id: jobID }).then(data => {
            if (data.success) {
                loadQueue();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        }).catch(err => { console.error('Error remove_from_queue', err); alert('Failed to remove job: ' + (err.message || 'Unknown error')); });
    };

    // Cancel Running Job
    window.cancelJob = function(jobID) {
        if (!confirm('Are you sure? The job will stop after the current account wraps up.')) return;
        
        apiCall('cancel_job', { job_id: jobID }).then(data => {
            if (data.success) {
                cancellingJobs.add(jobID);
                alert('Cancel request submitted. Please wait for it to wrap up.');
                loadQueue();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        }).catch(err => { console.error('Error cancel_job', err); alert('Failed to cancel job: ' + (err.message || 'Unknown error')); });
    };

    // Remove Schedule
    window.removeSchedule = function(scheduleID) {
        if (!confirm('Are you sure you want to delete this schedule?')) return;
        
        // Use dedicated API action for deleting schedules
        apiCall('delete_schedule', { job_id: scheduleID }).then(data => {
            if (data.success) {
                loadSchedules();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        }).catch(err => { console.error('Error delete_schedule', err); alert('Failed to remove schedule: ' + (err.message || 'Unknown error')); });
    };

    // ===== DATA BROWSER =====
    let currentDataDestination = null;
    let currentDataAccount = null;
    let accountSizeCache = {};  // Cache of account sizes: { account: { size: bytes, formatted: 'X.XX GB' } }
    
    // Refresh current data view
    window.refreshDataView = function() {
        // Show visual feedback that refresh is happening
        const accountsList = document.getElementById('data-accounts-list');
        const backupsList = document.getElementById('data-backups-list');
        
        if (currentDataAccount) {
            // Refresh backups for current account
            if (backupsList) {
                backupsList.innerHTML = '<div class="backups-loading"><i class="fas fa-spinner fa-spin"></i> Refreshing...</div>';
            }
            loadAccountBackups(currentDataAccount);
        } else if (currentDataDestination) {
            // Refresh accounts list
            if (accountsList) {
                accountsList.innerHTML = '<div class="accounts-loading"><i class="fas fa-spinner fa-spin"></i> Refreshing...</div>';
            }
            loadDataAccounts();
        }
    };
    
    // Load Data Accounts
    function loadDataAccounts() {
        const destination = document.getElementById('data-destination').value;
        const accountsList = document.getElementById('data-accounts-list');
        const backupsList = document.getElementById('data-backups-list');
        
        if (!destination) {
            accountsList.innerHTML = '<div class="accounts-empty">Select a destination</div>';
            backupsList.innerHTML = '<div class="backups-placeholder"><i class="fas fa-folder-open"></i><p>Select a destination to browse backups</p></div>';
            return;
        }
        
        currentDataDestination = destination;
        currentDataAccount = null;
        // Clear size cache when changing destination
        accountSizeCache = {};
        accountsList.innerHTML = '<div class="accounts-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
        backupsList.innerHTML = '<div class="backups-placeholder"><i class="fas fa-folder-open"></i><p>Select an account to view backups</p></div>';
        
        apiCall('get_backup_accounts', { destination: destination }).then(data => {
            if (data.success && data.accounts && data.accounts.length > 0) {
                // Sort alphabetically
                const sorted = data.accounts.sort((a, b) => a.localeCompare(b));
                accountsList.innerHTML = sorted.map(account => {
                    // Sanitise account name for use as HTML ID (replace non-alphanumeric)
                    const safeId = account.replace(/[^a-zA-Z0-9_-]/g, '_');
                    return `<div class="account-item" onclick="selectDataAccount('${account}')" data-account="${account}">
                        <span class="account-name">${account}</span>
                        <span class="account-size" id="account-size-${safeId}"></span>
                    </div>`;
                }).join('');
            } else {
                accountsList.innerHTML = '<div class="accounts-empty">No backup accounts found</div>';
            }
        }).catch(err => {
            console.error('Error get_backup_accounts', err);
            accountsList.innerHTML = '<div class="accounts-empty">Error loading accounts</div>';
        });
    }
    window.loadDataAccounts = loadDataAccounts;
    
    // Select Account to View Backups
    window.selectDataAccount = function(account) {
        currentDataAccount = account;
        
        // Update active state using data attribute for reliable matching
        document.querySelectorAll('#data-accounts-list .account-item').forEach(item => {
            item.classList.remove('active');
            if (item.dataset.account === account) {
                item.classList.add('active');
            }
        });
        
        loadAccountBackups(account);
    };
    
    // Load Account Backups
    function loadAccountBackups(account) {
        const backupsList = document.getElementById('data-backups-list');
        const backupsTitle = document.getElementById('data-backups-title');
        const backupsCount = document.getElementById('data-backups-count');
        
        backupsTitle.innerHTML = `<i class="fas fa-archive"></i> ${account}`;
        backupsList.innerHTML = '<div class="backups-loading"><i class="fas fa-spinner fa-spin"></i> Loading backups...</div>';
        backupsCount.textContent = '';
        
        apiCall('list_backups', { destination: currentDataDestination, account: account }).then(data => {
            if (data.success && data.backups && data.backups.length > 0) {
                // Calculate total size (ensure numeric addition)
                let totalSize = 0;
                data.backups.forEach(backup => {
                    if (backup.size) totalSize += parseInt(backup.size, 10) || 0;
                });
                
                // Cache and display size for this account
                accountSizeCache[account] = {
                    size: totalSize,
                    formatted: formatFileSize(totalSize)
                };
                updateAccountSizeDisplay(account);
                
                const sizeText = totalSize > 0 ? ` (${formatFileSize(totalSize)})` : '';
                backupsCount.textContent = `${data.backups.length} backup${data.backups.length !== 1 ? 's' : ''}${sizeText}`;
                
                // Sort by ISO 8601 timestamp (oldest first)
                const sorted = data.backups.sort((a, b) => {
                    const fileA = a.file || a;
                    const fileB = b.file || b;
                    const tsA = formatBackupTimestamp(fileA);
                    const tsB = formatBackupTimestamp(fileB);
                    return tsA.localeCompare(tsB);  // Ascending (oldest first)
                });
                
                backupsList.innerHTML = sorted.map(backup => {
                    const filename = backup.file || backup;
                    const path = backup.path || '';
                    const timestamp = formatBackupTimestamp(filename);
                    const sizeStr = backup.size ? formatFileSize(parseInt(backup.size, 10) || 0) : '';
                    return `
                        <div class="backup-item">
                            <div class="backup-info">
                                <div class="backup-meta">
                                    <span class="backup-timestamp">${timestamp}</span>
                                    ${sizeStr ? `<span class="backup-size">${sizeStr}</span>` : ''}
                                </div>
                                <small class="backup-filename"><code>${filename}</code></small>
                            </div>
                            <div class="backup-actions">
                                <button class="btn-delete-backup" onclick="showDeleteBackupModal('${account}', '${filename}', '${path}')">
                                    <i class="fas fa-trash-alt"></i> Delete
                                </button>
                            </div>
                        </div>
                    `;
                }).join('');
            } else {
                backupsCount.textContent = '0 backups';
                backupsList.innerHTML = '<div class="backups-empty"><i class="fas fa-inbox"></i><p>No backups found for this account</p></div>';
                // Clear cached size for this account
                delete accountSizeCache[account];
                updateAccountSizeDisplay(account);
            }
        }).catch(err => {
            console.error('Error list_backups', err);
            backupsList.innerHTML = '<div class="backups-empty"><i class="fas fa-exclamation-triangle"></i><p>Error loading backups</p></div>';
        });
    }
    
    // Update account size display in sidebar
    function updateAccountSizeDisplay(account) {
        // Sanitise account name for HTML ID lookup (must match ID generation)
        const safeId = account.replace(/[^a-zA-Z0-9_-]/g, '_');
        const sizeEl = document.getElementById('account-size-' + safeId);
        if (sizeEl) {
            const cached = accountSizeCache[account];
            sizeEl.textContent = cached ? cached.formatted : '';
        }
    }
    
    // Format file size to human readable
    function formatFileSize(bytes) {
        if (!bytes || bytes === 0) return '';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(i > 0 ? 2 : 0) + ' ' + units[i];
    }
    
    // Format backup timestamp from filename
    function formatBackupTimestamp(filename) {
        // Official cPanel format: backup-MM.DD.YYYY_HH-MM-SS_USER.tar.gz
        const match = filename.match(/^backup-(\d{2})\.(\d{2})\.(\d{4})_(\d{2})-(\d{2})-(\d{2})_/);
        if (match) {
            const [, month, day, year, hour, min, sec] = match;
            return `${year}-${month}-${day} ${hour}:${min}:${sec}`;
        }
        return 'Unknown';
    }
    
    // Show Delete Backup Modal
    window.showDeleteBackupModal = function(account, filename, path) {
        document.getElementById('delete-backup-account').textContent = account;
        document.getElementById('delete-backup-filename').textContent = filename;
        document.getElementById('delete-backup-filename').dataset.path = path || '';
        document.getElementById('delete-backup-modal').classList.add('active');
    };
    
    // Confirm Delete Backup
    window.confirmDeleteBackup = function() {
        const account = document.getElementById('delete-backup-account').textContent;
        const filename = document.getElementById('delete-backup-filename').textContent;
        const path = document.getElementById('delete-backup-filename').dataset.path || '';
        const btn = document.getElementById('btn-confirm-delete-backup');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        
        apiCall('delete_backup', {
            destination: currentDataDestination,
            account: account,
            filename: filename,
            path: path
        }).then(data => {
            closeModal('delete-backup-modal');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Backup';
            
            if (data.success) {
                // Refresh the backups list
                loadAccountBackups(account);
            } else {
                alert('Error: ' + (data.message || 'Failed to delete backup'));
            }
        }).catch(err => {
            console.error('Error delete_backup', err);
            closeModal('delete-backup-modal');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Backup';
            alert('Failed to delete backup: ' + (err.message || 'Unknown error'));
        });
    };

    // Close Modal
    window.closeModal = function(modalId) {
        document.getElementById(modalId).classList.remove('active');
    };

    // Make loadLogs global for pagination
    window.loadLogs = loadLogs;

})();
