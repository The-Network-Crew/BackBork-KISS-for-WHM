<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Central API endpoint handling all AJAX requests from the frontend.
 *   Routes requests to appropriate handlers based on action parameters.
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
// INITIALIZATION
// ============================================================================

// Ensure Bootstrap is loaded (in case accessed directly via URL)
if (!defined('BACKBORK_VERSION')) {
    require_once(__DIR__ . '/../version.php');
}
if (!class_exists('BackBorkBootstrap')) {
    require_once(__DIR__ . '/../app/Bootstrap.php');
}

// ============================================================================
// CLI MODE SUPPORT
// Enables automation via Ansible, scripts, or direct command line access
// Usage: php router.php --action=get_config [--data='{"key":"value"}']
// Security: Requires root shell access - no additional risk
// ============================================================================

$isCLI = (php_sapi_name() === 'cli');
$cliAction = null;
$cliData = null;

if ($isCLI) {
    // Parse CLI arguments: --action=xxx --data='json'
    $args = getopt('', ['action:', 'data:']);
    $cliAction = $args['action'] ?? null;
    $cliData = isset($args['data']) ? json_decode($args['data'], true) : null;
    
    // Initialise for CLI (bypasses WHM auth - you're already root)
    BackBorkBootstrap::initCLI();
} else {
    // Web request - normal WHM authentication
    if (!BackBorkBootstrap::init()) {
        header('Content-Type: application/json');
        
        if (class_exists('BackBorkLog')) {
            $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
                ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
                : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
            BackBorkLog::logEvent('unknown', 'api_init_denied', [], false, 'API init failed (ACL or auth)', $requestor);
        }
        
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
}

// Set JSON content type for all API responses
if (!headers_sent()) {
    header('Content-Type: application/json');
}

// ============================================================================
// GET CURRENT USER CONTEXT
// ============================================================================

// Get ACL instance for permission checks
$acl = BackBorkBootstrap::getACL();

// Current authenticated user (from WHM session)
$currentUser = $acl->getCurrentUser();

// Is this user root? (has full access)
$isRoot = $acl->isRoot();

// Get requestor IP for audit logging
$requestor = BackBorkLog::getRequestor();

// ============================================================================
// ROUTE REQUEST TO HANDLER
// ============================================================================

// Get requested action (CLI args take precedence, then POST, then GET)
if ($isCLI && $cliAction) {
    $action = $cliAction;
} else {
    $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
}

/**
 * Get JSON request data from either CLI --data argument or HTTP body
 * @return array|null Decoded JSON data
 */
function backbork_get_request_data() {
    global $isCLI, $cliData;
    if ($isCLI) {
        return $cliData;
    }
    return json_decode(file_get_contents('php://input'), true);
}

// For backwards compatibility, also store in a variable that can be used directly
$requestData = backbork_get_request_data();

// Route to appropriate handler based on action
switch ($action) {

    // ========================================================================
    // ACCOUNT MANAGEMENT
    // ========================================================================
    
    /**
     * Get list of accounts the current user can access
     * Root sees all accounts, resellers see only their owned accounts
     */
    case 'get_accounts':
        echo json_encode($acl->getAccessibleAccounts());
        break;
    
    // ========================================================================
    // CONFIGURATION MANAGEMENT
    // ========================================================================
    
    /**
     * Get current user's configuration settings
     * Also includes global config info for root users
     */
    case 'get_config':
        $config = new BackBorkConfig();
        $userConfig = $config->getUserConfig($currentUser);
        
        // Root gets additional global config information
        if ($isRoot) {
            $userConfig['_global'] = $config->getGlobalConfig();
            $userConfig['_resellers'] = $acl->getResellers();
            $userConfig['_users_with_schedules'] = $acl->getUsersWithSchedules();
        } else {
            // Non-root users only get lock statuses
            $userConfig['_schedules_locked'] = BackBorkConfig::areSchedulesLocked();
            $userConfig['_deletions_locked'] = BackBorkConfig::areResellerDeletionsLocked();
        }
        echo json_encode($userConfig);
        break;
    
    /**
     * Get global configuration (root only)
     * Returns server-wide settings like schedule locks
     */
    case 'get_global_config':
        // Security: Only root can access global config
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $config = new BackBorkConfig();
        echo json_encode($config->getGlobalConfig());
        break;
    
    /**
     * Save global configuration (root only)
     * Updates server-wide settings
     */
    case 'save_global_config':
        // Security: Only root can modify global config
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $config = new BackBorkConfig();
        $data = backbork_get_request_data();
        $result = $config->saveGlobalConfig($data, $currentUser);
        echo json_encode($result);
        break;
    
    /**
     * Toggle schedule lock status (root only)
     * When locked, resellers cannot create/modify/delete schedules
     */
    case 'set_schedules_lock':
        // Security: Only root can lock schedules
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $data = backbork_get_request_data();
        $locked = isset($data['locked']) ? (bool)$data['locked'] : false;
        $config = new BackBorkConfig();
        $result = $config->setSchedulesLocked($locked, $currentUser);
        echo json_encode($result);
        break;
    
    /**
     * Save user's configuration settings
     * Each user has their own notification and backup settings
     */
    case 'save_config':
        $config = new BackBorkConfig();
        $data = backbork_get_request_data();
        
        // Root-only: handle batched global settings (single save, single log entry)
        if ($isRoot && isset($data['_global_settings']) && is_array($data['_global_settings'])) {
            $globalUpdates = [];
            foreach ($data['_global_settings'] as $key => $value) {
                if ($value !== null) {
                    $globalUpdates[$key] = (bool)$value;
                }
            }
            if (!empty($globalUpdates)) {
                $config->saveGlobalConfig($globalUpdates, $currentUser);
            }
            unset($data['_global_settings']);
        }
        
        $result = $config->saveUserConfig($currentUser, $data);
        echo json_encode($result);
        break;
    
    // ========================================================================
    // DATABASE INFO
    // ========================================================================
    
    /**
     * Get database server information
     * Returns MySQL/MariaDB version and available backup tools
     */
    case 'get_db_info':
        $system = new BackBorkWhmApiSystem();
        echo json_encode($system->detectDatabaseServer());
        break;
    
    /**
     * Get reseller count (root only, for status bar)
     * Returns list and count of reseller accounts
     */
    case 'get_resellers':
        if (!$isRoot) {
            echo json_encode(['success' => false, 'count' => 0, 'message' => 'Root access required']);
            break;
        }
        $system = new BackBorkWhmApiSystem();
        $result = $system->getResellers();
        $result['success'] = true;
        echo json_encode($result);
        break;
    
    /**
     * Check for plugin updates
     * Compares local version against GitHub main branch
     */
    case 'check_update':
        $localVersion = BACKBORK_VERSION;
        $remoteVersion = null;
        $updateAvailable = false;
        
        // Fetch remote version from GitHub (with timeout)
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $remoteUrl = 'https://raw.githubusercontent.com/The-Network-Crew/BackBork-KISS-for-WHM/refs/heads/main/version';
        $remoteContent = @file_get_contents($remoteUrl, false, $ctx);
        
        if ($remoteContent !== false) {
            $remoteVersion = trim($remoteContent);
            // Compare versions (simple string compare works for semver)
            if (version_compare($remoteVersion, $localVersion, '>')) {
                $updateAvailable = true;
            }
        }
        
        echo json_encode([
            'success' => true,
            'local_version' => $localVersion,
            'remote_version' => $remoteVersion,
            'update_available' => $updateAvailable
        ]);
        break;
    
    /**
     * Perform self-update
     * Downloads latest version from GitHub and runs installer
     * Root only - spawns background update script that survives the update
     */
    case 'perform_update':
        // Security: Only root can trigger updates
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied - root only']);
            break;
        }
        
        $currentVersion = BACKBORK_VERSION;
        $updaterScript = '/usr/local/cpanel/3rdparty/backbork/updater.sh';
        $logFile = '/usr/local/cpanel/3rdparty/backbork/logs/update.log';
        
        // Verify updater script exists
        if (!file_exists($updaterScript)) {
            BackBorkLog::logEvent($currentUser, 'update_failed', ['version' => $currentVersion], false, 'Updater script not found', $requestor);
            echo json_encode(['success' => false, 'message' => 'Updater script not found. Please reinstall the plugin.']);
            break;
        }
        
        // Get notification settings from user config
        $config = new BackBorkConfig();
        $userConfig = $config->getUserConfig('root');
        $notifyEmail = $userConfig['notify_email'] ?? '';
        $slackWebhook = $userConfig['slack_webhook'] ?? '';
        
        // Fetch remote version for logging
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $remoteUrl = 'https://raw.githubusercontent.com/The-Network-Crew/BackBork-KISS-for-WHM/refs/heads/main/version';
        $remoteVersion = @file_get_contents($remoteUrl, false, $ctx);
        $remoteVersion = $remoteVersion !== false ? trim($remoteVersion) : 'latest';
        
        // Log the update initiation with version info
        BackBorkLog::logEvent($currentUser, 'update_started', ["Version {$currentVersion} to {$remoteVersion}"], true, 'Self-update initiated', $requestor);
        
        // Initialise update log
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] Update initiated by {$currentUser} from {$requestor}\n", FILE_APPEND);
        
        // Build command with escaped arguments
        $cmd = escapeshellcmd($updaterScript) . ' ' 
             . escapeshellarg($currentVersion) . ' '
             . escapeshellarg($notifyEmail) . ' '
             . escapeshellarg($slackWebhook)
             . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        
        // Execute in background (nohup ensures it survives parent exit)
        exec('nohup ' . $cmd);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Update started. You will be notified when complete.',
            'log_file' => $logFile
        ]);
        break;
    
    // ========================================================================
    // BACKUP OPERATIONS
    // ========================================================================
    
    /**
     * Create an immediate backup
     * Returns backup_id immediately, then runs backup with real-time logging
     */
    case 'create_backup':
        $data = backbork_get_request_data();
        $accounts = isset($data['accounts']) ? $data['accounts'] : [];
        $destinationID = isset($data['destination']) ? $data['destination'] : '';
        
        // Security: Validate user can access requested accounts
        $accessibleAccounts = $acl->getAccessibleAccounts();
        $validAccounts = array_intersect($accounts, array_column($accessibleAccounts, 'user'));
        
        if (empty($validAccounts)) {
            echo json_encode(['success' => false, 'message' => 'No valid accounts selected']);
            break;
        }
        
        // Generate backup_id early and create initial log file
        $backupID = 'backup_' . time() . '_' . substr(md5(uniqid()), 0, 8);
        $logDir = '/usr/local/cpanel/3rdparty/backbork/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/' . $backupID . '.log';
        file_put_contents($logFile, "[" . date('H:i:s') . "] Backup initiated, preparing...\n");
        
        // Create a job file that the CLI runner will pick up
        $jobFile = $logDir . '/' . $backupID . '.job';
        $jobData = [
            'type' => 'backup',
            'backup_id' => $backupID,
            'accounts' => array_values($validAccounts),
            'destination' => $destinationID,
            'user' => $currentUser,
            'requestor' => $requestor,
            'created_at' => date('Y-m-d H:i:s')
        ];
        file_put_contents($jobFile, json_encode($jobData));
        
        // Spawn background process to run the backup
        $phpBin = '/usr/local/cpanel/3rdparty/bin/php';
        $runnerScript = __DIR__ . '/runner.php';
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($runnerScript) . ' ' . escapeshellarg($jobFile) . ' > /dev/null 2>&1 &';
        exec($cmd);
        
        // Return immediately with backup_id so client can start polling
        echo json_encode(['success' => true, 'backup_id' => $backupID, 'message' => 'Backup started']);
        break;
    
    /**
     * Add backup job to queue
     * Job will be processed by cron handler
     */
    case 'queue_backup':
        $data = backbork_get_request_data();
        $accounts = isset($data['accounts']) ? $data['accounts'] : [];
        $destinationID = isset($data['destination']) ? $data['destination'] : '';
        $schedule = isset($data['schedule']) ? $data['schedule'] : 'daily';
        
        // Security: Validate user can access requested accounts
        $accessibleAccounts = $acl->getAccessibleAccounts();
        $validAccounts = array_intersect($accounts, array_column($accessibleAccounts, 'user'));
        
        if (empty($validAccounts)) {
            echo json_encode(['success' => false, 'message' => 'No valid accounts selected']);
            break;
        }
        
        // Add to queue
        $queue = new BackBorkQueue();
        $options = [];
        if (isset($data['retention'])) $options['retention'] = (int)$data['retention'];
        if (isset($data['preferred_time'])) $options['preferred_time'] = (int)$data['preferred_time'];
        
        $result = $queue->addToQueue($validAccounts, $destinationID, $schedule, $currentUser, $options);
        echo json_encode($result);
        break;
    
    // ========================================================================
    // SCHEDULE MANAGEMENT
    // ========================================================================
    
    /**
     * Create a recurring backup schedule
     * Supports "all accounts" mode for dynamic account resolution
     */
    case 'create_schedule':
        $data = backbork_get_request_data();
        $accounts = isset($data['accounts']) ? $data['accounts'] : [];
        $destinationID = isset($data['destination']) ? $data['destination'] : '';
        $schedule = isset($data['schedule']) ? $data['schedule'] : 'daily';
        $allAccounts = isset($data['all_accounts']) ? (bool)$data['all_accounts'] : false;
        
        // Security: Check if schedules are locked for resellers
        if (!$isRoot && BackBorkConfig::areSchedulesLocked()) {
            echo json_encode(['success' => false, 'message' => 'Schedules are locked by administrator']);
            break;
        }
        
        // Handle "all accounts" mode - store wildcard for runtime resolution
        if ($allAccounts || (is_array($accounts) && in_array('*', $accounts))) {
            // Store ['*'] as placeholder - resolved to actual accounts at execution time
            $validAccounts = ['*'];
        } else {
            // Validate user can access specific requested accounts
            $accessibleAccounts = $acl->getAccessibleAccounts();
            $validAccounts = array_intersect($accounts, array_column($accessibleAccounts, 'user'));
            
            if (empty($validAccounts)) {
                echo json_encode(['success' => false, 'message' => 'No valid accounts selected']);
                break;
            }
        }

        // Create the schedule
        $queue = new BackBorkQueue();
        $options = [];
        if (isset($data['retention'])) $options['retention'] = (int)$data['retention'];
        if (isset($data['preferred_time'])) $options['preferred_time'] = (int)$data['preferred_time'];
        if (isset($data['day_of_week'])) $options['day_of_week'] = (int)$data['day_of_week'];
        if ($allAccounts) $options['all_accounts'] = true;

        $result = $queue->addToQueue($validAccounts, $destinationID, $schedule, $currentUser, $options);
        echo json_encode($result);
        break;
    
    /**
     * Delete a schedule
     * Users can only delete their own schedules unless root
     */
    case 'delete_schedule':
        $data = backbork_get_request_data();
        $jobID = isset($data['job_id']) ? $data['job_id'] : '';
        
        // Security: Check if schedules are locked for resellers
        if (!$isRoot && BackBorkConfig::areSchedulesLocked()) {
            echo json_encode(['success' => false, 'message' => 'Schedules are locked by administrator']);
            break;
        }
        
        // Delete the schedule
        $queue = new BackBorkQueue();
        echo json_encode($queue->removeFromQueue($jobID, $currentUser, $isRoot));
        break;
    
    // ========================================================================
    // QUEUE MANAGEMENT
    // ========================================================================
    
    /**
     * Get queue status including pending jobs, running jobs, and schedules
     * Root can filter by specific user with view_user parameter
     */
    case 'get_queue':
        $queue = new BackBorkQueue();
        // Optional: Root can view as specific user
        $viewAsUser = isset($_GET['view_user']) ? $_GET['view_user'] : null;
        echo json_encode($queue->getQueue($currentUser, $isRoot, $viewAsUser));
        break;
    
    /**
     * Remove a job from the queue
     * Users can only remove their own jobs unless root
     */
    case 'remove_from_queue':
        $data = backbork_get_request_data();
        $jobID = isset($data['job_id']) ? $data['job_id'] : '';
        
        $queue = new BackBorkQueue();
        echo json_encode($queue->removeFromQueue($jobID, $currentUser, $isRoot));
        break;
    
    /**
     * Cancel a running job
     * Creates a cancel marker that the worker checks after each account
     * Users can only cancel their own jobs unless root
     */
    case 'cancel_job':
        $data = backbork_get_request_data();
        $jobID = isset($data['job_id']) ? $data['job_id'] : '';
        
        if (empty($jobID)) {
            echo json_encode(['success' => false, 'message' => 'Job ID required']);
            break;
        }
        
        $queue = new BackBorkQueue();
        $result = $queue->requestCancel($jobID, $currentUser, $isRoot);
        
        // Log the cancellation request
        if ($result['success'] && class_exists('BackBorkLog')) {
            BackBorkLog::logEvent($currentUser, 'queue_remove', [$jobID], true, 
                'Cancellation requested for running job', $requestor);
        }
        
        echo json_encode($result);
        break;
    
    /**
     * Manually trigger queue processing (root only)
     * Processes schedules and runs pending queue jobs
     */
    case 'process_queue':
        // Security: Only root can manually trigger processing
        if (!$isRoot) {
            if (class_exists('BackBorkLog')) {
                BackBorkLog::logEvent($currentUser, 'queue_process_denied', [], false, 
                    'Non-root user attempted to trigger process_queue', $requestor);
            }
            echo json_encode(['success' => false, 'message' => 'Access denied: manual processing requires root']);
            break;
        }

        // Process schedules and queue
        $processor = new BackBorkQueueProcessor();
        try {
            $scheduled = $processor->processSchedules();
            $processed = $processor->processQueue();
            
            // Build log message with account details
            $logAccounts = $processed['accounts'] ?? [];
            $processedCount = $processed['processed'] ?? 0;
            $failedCount = $processed['failed'] ?? 0;
            $logMessage = "Manual queue process: {$processedCount} succeeded, {$failedCount} failed";
            if (!empty($processed['processed_accounts'])) {
                $logMessage .= "\nSucceeded: " . implode(', ', $processed['processed_accounts']);
            }
            if (!empty($processed['failed_accounts'])) {
                $logMessage .= "\nFailed: " . implode(', ', $processed['failed_accounts']);
            }
            
            // Log successful processing with accounts
            if (class_exists('BackBorkLog')) {
                BackBorkLog::logEvent($currentUser, 'queue_process', $logAccounts, true, 
                    $logMessage, $requestor);
            }
            echo json_encode(['success' => true, 'scheduled' => $scheduled, 'processed' => $processed]);
        } catch (Exception $e) {
            // Log failed processing
            if (class_exists('BackBorkLog')) {
                BackBorkLog::logEvent($currentUser, 'queue_process', [], false, 
                    'Manual queue process failed: ' . $e->getMessage(), $requestor);
            }
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
    
    /**
     * Kill all queued and running jobs (root only)
     * Emergency function for clearing stuck queues and killing processes
     */
    case 'kill_all_jobs':
        // Security: Only root can kill all jobs
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied: requires root']);
            break;
        }
        
        $queue = new BackBorkQueue();
        $result = $queue->killAllJobs();
        
        // Log the action
        if (class_exists('BackBorkLog')) {
            $logMsg = 'Killed ' . $result['queued_removed'] . ' queued, ' . $result['running_cancelled'] . ' running';
            if ($result['processes_killed'] > 0) {
                $logMsg .= ', terminated ' . $result['processes_killed'] . ' process(es)';
            }
            BackBorkLog::logEvent($currentUser, 'kill_all_jobs', [], true, $logMsg, $requestor);
        }
        
        echo json_encode($result);
        break;
    
    /**
     * Get running job status
     */
    case 'get_status':
        $queue = new BackBorkQueue();
        echo json_encode($queue->getRunningJobs($currentUser, $isRoot));
        break;
    
    // ========================================================================
    // RESTORE OPERATIONS
    // ========================================================================
    
    /**
     * Get list of local backups for an account
     */
    case 'get_backups':
        $account = isset($_GET['account']) ? $_GET['account'] : '';
        
        // Security: Validate user can access this account
        if (!$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        $backupManager = new BackBorkBackupManager();
        echo json_encode($backupManager->listBackups($account, $currentUser));
        break;
    
    /**
     * Get list of remote backups from a destination
     */
    case 'get_remote_backups':
        $destinationID = isset($_GET['destination']) ? $_GET['destination'] : '';
        $account = isset($_GET['account']) ? $_GET['account'] : '';
        
        $backupManager = new BackBorkBackupManager();
        echo json_encode($backupManager->listRemoteBackups($destinationID, $currentUser, $account));
        break;
    
    /**
     * Restore account from backup
     * Returns restore_id immediately, then runs restore in background
     */
    case 'restore_backup':
        $data = backbork_get_request_data();
        $backupFile = isset($data['backup_file']) ? $data['backup_file'] : '';
        $account = isset($data['account']) ? $data['account'] : '';
        $restoreOptions = isset($data['options']) ? $data['options'] : [];
        $destinationID = isset($data['destination']) ? $data['destination'] : '';
        
        // Security: Validate user can access this account
        if (!$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        // Generate restore_id early and create initial log file
        $restoreID = 'restore_' . time() . '_' . substr(md5(uniqid()), 0, 8);
        $logDir = '/usr/local/cpanel/3rdparty/backbork/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/' . $restoreID . '.log';
        file_put_contents($logFile, "[" . date('H:i:s') . "] Restore initiated, preparing...\n");
        
        // Create a job file that the CLI runner will pick up
        $jobFile = $logDir . '/' . $restoreID . '.job';
        $jobData = [
            'type' => 'restore',
            'restore_id' => $restoreID,
            'backup_file' => $backupFile,
            'account' => $account,
            'destination' => $destinationID,
            'options' => $restoreOptions,
            'user' => $currentUser,
            'requestor' => $requestor,
            'created_at' => date('Y-m-d H:i:s')
        ];
        file_put_contents($jobFile, json_encode($jobData));
        
        // Spawn background process to run the restore
        $phpBin = '/usr/local/cpanel/3rdparty/bin/php';
        $runnerScript = __DIR__ . '/runner.php';
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($runnerScript) . ' ' . escapeshellarg($jobFile) . ' > /dev/null 2>&1 &';
        exec($cmd);
        
        // Return immediately with restore_id so client can start polling
        echo json_encode(['success' => true, 'restore_id' => $restoreID, 'message' => 'Restore started']);
        break;
    
    /**
     * Delete a backup file from a destination
     * Supports both Local and remote (SFTP/FTP) destinations
     */
    case 'delete_backup':
        $data = backbork_get_request_data();
        $destinationID = isset($data['destination']) ? $data['destination'] : '';
        // Accept 'path' (full), 'filename', or 'backup_file'
        $backupPath = isset($data['path']) ? $data['path'] : '';
        $backupFile = isset($data['filename']) ? $data['filename'] : 
                     (isset($data['backup_file']) ? $data['backup_file'] : '');
        
        if (empty($destinationID) || (empty($backupFile) && empty($backupPath))) {
            echo json_encode(['success' => false, 'message' => 'Destination and backup file are required']);
            break;
        }
        
        // Extract account name from backup filename for permission check
        // Official format: backup-MM.DD.YYYY_HH-MM-SS_USER.tar.gz
        $account = isset($data['account']) ? $data['account'] : null;
        $fileToCheck = $backupPath ?: $backupFile;
        if (!$account && preg_match('/^backup-\d{2}\.\d{2}\.\d{4}_\d{2}-\d{2}-\d{2}_([a-z0-9_]+)\.tar(\.gz)?$/i', basename($fileToCheck), $matches)) {
            $account = $matches[1];
        }
        
        // Security: Validate user can access this account's backups
        if ($account && !$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        // Security: Check if reseller deletions are locked
        if (!$isRoot && BackBorkConfig::areResellerDeletionsLocked()) {
            echo json_encode(['success' => false, 'message' => 'Backup deletion is disabled for resellers']);
            break;
        }
        
        // Get destination info
        $parser = new BackBorkDestinationsParser();
        $destination = $parser->getDestinationByID($destinationID);
        
        if (!$destination) {
            echo json_encode(['success' => false, 'message' => 'Destination not found']);
            break;
        }
        
        $destType = strtolower($destination['type'] ?? 'local');
        
        // Handle deletion based on destination type
        if ($destType === 'local') {
            // Local deletion - use filesystem
            $basePath = $destination['path'] ?? '/backup';
            if ($backupPath && strpos($backupPath, $basePath) === 0) {
                $fullPath = $backupPath;
            } else {
                $accountDir = rtrim($basePath, '/') . '/' . $account . '/' . $backupFile;
                $rootDir = rtrim($basePath, '/') . '/' . $backupFile;
                $fullPath = file_exists($accountDir) ? $accountDir : $rootDir;
            }
            
            if (!file_exists($fullPath)) {
                echo json_encode(['success' => false, 'message' => 'Backup file not found']);
                break;
            }
            
            if (unlink($fullPath)) {
                BackBorkLog::logEvent($currentUser, 'delete', [$account ?? basename($fullPath)], true, 
                    "Deleted backup: " . basename($fullPath), BackBorkBootstrap::getRequestor());
                echo json_encode(['success' => true, 'message' => 'Backup deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete backup file']);
            }
        } else {
            // Remote deletion - use transport
            $validator = new BackBorkDestinationsValidator();
            $transport = $validator->getTransportForDestination($destination);
            
            $remotePath = $backupPath ?: $backupFile;
            $result = $transport->delete($remotePath, $destination);
            
            if ($result['success']) {
                BackBorkLog::logEvent($currentUser, 'delete', [$account ?? basename($remotePath)], true, 
                    "Deleted remote backup: " . basename($remotePath) . " from " . $destination['name'], 
                    BackBorkBootstrap::getRequestor());
            }
            
            echo json_encode($result);
        }
        break;
    
    /**
     * Bulk delete multiple backup files from a destination
     * Requires explicit confirmation text and checkbox acceptance
     * Supports both Local and remote (SFTP/FTP) destinations
     */
    case 'bulk_delete_backups':
        $data = backbork_get_request_data();
        $destinationID = isset($data['destination']) ? $data['destination'] : '';
        $backups = isset($data['backups']) ? $data['backups'] : [];
        $confirmText = isset($data['confirm_text']) ? trim($data['confirm_text']) : '';
        $acceptUndone = isset($data['accept_undone']) ? (bool)$data['accept_undone'] : false;
        
        // Validate required fields
        if (empty($destinationID) || empty($backups) || !is_array($backups)) {
            echo json_encode(['success' => false, 'message' => 'Destination and backups array are required']);
            break;
        }
        
        // Validate confirmation text (must be exact match)
        $expectedText = 'Yes, I want to bulk delete these backups.';
        if ($confirmText !== $expectedText) {
            echo json_encode(['success' => false, 'message' => 'Confirmation text does not match']);
            break;
        }
        
        // Validate checkbox acceptance
        if (!$acceptUndone) {
            echo json_encode(['success' => false, 'message' => 'You must accept that this action cannot be undone']);
            break;
        }
        
        // Security: Check if reseller deletions are locked
        if (!$isRoot && BackBorkConfig::areResellerDeletionsLocked()) {
            echo json_encode(['success' => false, 'message' => 'Backup deletion is disabled for resellers']);
            break;
        }
        
        // Get destination info
        $parser = new BackBorkDestinationsParser();
        $destination = $parser->getDestinationByID($destinationID);
        
        if (!$destination) {
            echo json_encode(['success' => false, 'message' => 'Destination not found']);
            break;
        }
        
        $destType = strtolower($destination['type'] ?? 'local');
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        $results = ['deleted' => [], 'failed' => []];
        
        foreach ($backups as $backup) {
            $account = isset($backup['account']) ? $backup['account'] : '';
            $filename = isset($backup['filename']) ? $backup['filename'] : '';
            $path = isset($backup['path']) ? $backup['path'] : '';
            
            if (empty($filename)) continue;
            
            // Security: Validate user can access this account's backups
            if ($account && !$acl->canAccessAccount($account)) {
                $results['failed'][] = ['filename' => $filename, 'reason' => 'Access denied'];
                continue;
            }
            
            try {
                if ($destType === 'local') {
                    // Local deletion
                    $basePath = $destination['path'] ?? '/backup';
                    if ($path && strpos($path, $basePath) === 0) {
                        $fullPath = $path;
                    } else {
                        $accountDir = rtrim($basePath, '/') . '/' . $account . '/' . $filename;
                        $rootDir = rtrim($basePath, '/') . '/' . $filename;
                        $fullPath = file_exists($accountDir) ? $accountDir : $rootDir;
                    }
                    
                    if (!file_exists($fullPath)) {
                        $results['failed'][] = ['filename' => $filename, 'reason' => 'File not found'];
                        continue;
                    }
                    
                    if (unlink($fullPath)) {
                        $results['deleted'][] = $filename;
                    } else {
                        $results['failed'][] = ['filename' => $filename, 'reason' => 'Delete failed'];
                    }
                } else {
                    // Remote deletion
                    $remotePath = $path ?: $filename;
                    $result = $transport->delete($remotePath, $destination);
                    
                    if ($result['success']) {
                        $results['deleted'][] = $filename;
                    } else {
                        $results['failed'][] = ['filename' => $filename, 'reason' => $result['message'] ?? 'Delete failed'];
                    }
                }
            } catch (Exception $e) {
                $results['failed'][] = ['filename' => $filename, 'reason' => $e->getMessage()];
            }
        }
        
        // Log the bulk deletion
        $deletedCount = count($results['deleted']);
        $failedCount = count($results['failed']);
        BackBorkLog::logEvent($currentUser, 'bulk_delete', [], $failedCount === 0, 
            "Bulk deleted {$deletedCount} backups" . ($failedCount > 0 ? ", {$failedCount} failed" : "") . " from " . $destination['name'], 
            BackBorkBootstrap::getRequestor());
        
        echo json_encode([
            'success' => true,
            'message' => "Deleted {$deletedCount} backup(s)" . ($failedCount > 0 ? ", {$failedCount} failed" : ""),
            'deleted' => $results['deleted'],
            'failed' => $results['failed']
        ]);
        break;
    
    /**
     * Get list of accounts that have backups at a destination
     * Lists account directories/files in the backup path
     * Supports both Local and remote (SFTP/FTP) destinations
     */
    case 'get_backup_accounts':
        $data = backbork_get_request_data();
        $destinationID = isset($data['destination']) ? $data['destination'] : 
                        (isset($_GET['destination']) ? $_GET['destination'] : '');
        
        BackBorkConfig::debugLog('get_backup_accounts: destination=' . $destinationID);
        
        if (empty($destinationID)) {
            echo json_encode(['success' => false, 'message' => 'Destination is required']);
            break;
        }
        
        // Get destination info
        $parser = new BackBorkDestinationsParser();
        $destination = $parser->getDestinationByID($destinationID);
        
        if (!$destination) {
            BackBorkConfig::debugLog('get_backup_accounts: Destination not found');
            echo json_encode(['success' => false, 'message' => 'Destination not found']);
            break;
        }
        
        $destType = strtolower($destination['type'] ?? 'local');
        BackBorkConfig::debugLog('get_backup_accounts: destType=' . $destType);
        $accounts = [];
        
        if ($destType === 'local') {
            // Local: Use transport to list account folders (consistent with remote)
            $validator = new BackBorkDestinationsValidator();
            $transport = $validator->getTransportForDestination($destination);
            $files = $transport->listFiles('', $destination);
            
            foreach ($files as $file) {
                $filename = $file['file'] ?? '';
                $fileType = $file['type'] ?? 'file';
                
                // Check if it's a directory (account folder)
                if ($fileType === 'dir') {
                    $account = strtolower($filename);
                    if ($acl->canAccessAccount($account)) {
                        $accounts[] = $account;
                    }
                }
                // Check for official format: backup-MM.DD.YYYY_HH-MM-SS_USER.tar.gz
                elseif (preg_match('/^backup-\d{2}\.\d{2}\.\d{4}_\d{2}-\d{2}-\d{2}_([a-z0-9_]+)\.tar(\.gz)?$/i', $filename, $matches)) {
                    $account = strtolower($matches[1]);
                    if ($acl->canAccessAccount($account) && !in_array($account, $accounts)) {
                        $accounts[] = $account;
                    }
                }
            }
        } else {
            // Remote: List directories (account folders) at destination root
            // Backups are stored as {account}/backup-MM.DD.YYYY_HH-MM-SS_{account}.tar.gz
            BackBorkConfig::debugLog('get_backup_accounts: Listing remote directories...');
            $validator = new BackBorkDestinationsValidator();
            $transport = $validator->getTransportForDestination($destination);
            $files = $transport->listFiles('', $destination);
            
            BackBorkConfig::debugLog('get_backup_accounts: Got ' . count($files) . ' entries from remote');
            
            $foundAccounts = [];
            foreach ($files as $file) {
                $filename = $file['file'] ?? '';
                $fileType = $file['type'] ?? 'file';
                BackBorkConfig::debugLog('get_backup_accounts: Checking: ' . $filename . ' (type=' . $fileType . ')');
                
                // Check if it's a directory (account folder)
                if ($fileType === 'dir' || $fileType === 'd' || $fileType === 'directory') {
                    $account = strtolower($filename);
                    // Skip hidden directories and common non-account dirs
                    if (strpos($account, '.') === 0 || in_array($account, ['lost+found', 'tmp', 'temp'])) {
                        continue;
                    }
                    BackBorkConfig::debugLog('get_backup_accounts: Found account folder=' . $account);
                    if ($acl->canAccessAccount($account)) {
                        $foundAccounts[$account] = true;
                    } else {
                        BackBorkConfig::debugLog('get_backup_accounts: ACL denied access to ' . $account);
                    }
                }
                // Also check for flat backup files
                // Official format: backup-MM.DD.YYYY_HH-MM-SS_USER.tar.gz
                elseif (preg_match('/^backup-\d{2}\.\d{2}\.\d{4}_\d{2}-\d{2}-\d{2}_([a-z0-9_]+)\.tar(\.gz)?$/i', $filename, $matches)) {
                    $account = strtolower($matches[1]);
                    BackBorkConfig::debugLog('get_backup_accounts: Found flat backup file (official), account=' . $account);
                    if ($acl->canAccessAccount($account)) {
                        $foundAccounts[$account] = true;
                    }
                }
            }
            $accounts = array_keys($foundAccounts);
        }
        
        BackBorkConfig::debugLog('get_backup_accounts: Returning ' . count($accounts) . ' accounts');
        sort($accounts);
        echo json_encode(['success' => true, 'accounts' => $accounts]);
        break;
    
    /**
     * List backups for a specific account at a destination
     * Supports both Local and remote (SFTP/FTP) destinations
     */
    case 'list_backups':
        $data = backbork_get_request_data();
        $destinationID = isset($data['destination']) ? $data['destination'] : 
                        (isset($_GET['destination']) ? $_GET['destination'] : '');
        $account = isset($data['account']) ? $data['account'] : 
                  (isset($_GET['account']) ? $_GET['account'] : '');
        
        if (empty($destinationID) || empty($account)) {
            echo json_encode(['success' => false, 'message' => 'Destination and account are required']);
            break;
        }
        
        // Security: Validate user can access this account
        if (!$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        // Get destination info
        $parser = new BackBorkDestinationsParser();
        $destination = $parser->getDestinationByID($destinationID);
        
        if (!$destination) {
            echo json_encode(['success' => false, 'message' => 'Destination not found']);
            break;
        }
        
        $destType = strtolower($destination['type'] ?? 'local');
        $backups = [];
        
        if ($destType === 'local') {
            // Local: List files in account directory
            $backupDir = isset($destination['path']) ? $destination['path'] : '';
            if (empty($backupDir) || !is_dir($backupDir)) {
                echo json_encode(['success' => false, 'message' => 'Backup directory not found']);
                break;
            }
            
            $accountDir = rtrim($backupDir, '/') . '/' . $account;
            
            if (is_dir($accountDir)) {
                // Official format: backup-*.tar.gz
                $officialFiles = glob($accountDir . '/backup-*.tar.gz');
                foreach ($officialFiles as $file) {
                    $backups[] = [
                        'file' => basename($file),
                        'path' => $file,
                        'size' => filesize($file),
                        'modified' => filemtime($file)
                    ];
                }
            }
        } else {
            // Remote: List files inside account subdirectory
            // Backups are stored as {account}/backup-MM.DD.YYYY_HH-MM-SS_{account}.tar.gz
            $validator = new BackBorkDestinationsValidator();
            $transport = $validator->getTransportForDestination($destination);
            $files = $transport->listFiles($account, $destination);  // List inside account folder
            
            foreach ($files as $file) {
                $filename = $file['file'] ?? '';
                // Official format: backup-*.tar.gz
                if (preg_match('/^backup-.*\\.tar\\.gz$/i', $filename)) {
                    $backups[] = [
                        'file' => $filename,
                        'path' => $account . '/' . $filename,  // Full path for delete/restore
                        'size' => $file['size'] ?? 0,
                        'modified' => $file['mtime'] ?? 0
                    ];
                }
            }
        }
        
        // Sort by modified date (oldest first) for local, by filename for remote
        usort($backups, function($a, $b) {
            if ($a['modified'] && $b['modified']) {
                return $a['modified'] - $b['modified'];
            }
            return strcmp($a['file'], $b['file']);
        });
        
        echo json_encode(['success' => true, 'backups' => $backups]);
        break;
    
    // ========================================================================
    // DESTINATION MANAGEMENT
    // ========================================================================
    
    /**
     * Get list of available backup destinations
     * Reads from WHM's backup configuration
     * Filters out root-only destinations for resellers
     */
    case 'get_destinations':
        $parser = new BackBorkDestinationsParser();
        echo json_encode($parser->getAvailableDestinations($isRoot));
        break;
    
    /**
     * Get destination visibility settings (root only)
     * Returns which destinations are marked as root-only
     */
    case 'get_destination_visibility':
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $rootOnlyDests = BackBorkConfig::getRootOnlyDestinations();
        echo json_encode(['success' => true, 'root_only_destinations' => $rootOnlyDests]);
        break;
    
    /**
     * Set destination visibility (root only)
     * Mark specific destinations as root-only (hidden from resellers)
     */
    case 'set_destination_visibility':
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $data = backbork_get_request_data();
        $destinationID = isset($data['destination_id']) ? $data['destination_id'] : '';
        $rootOnly = isset($data['root_only']) ? (bool)$data['root_only'] : false;
        
        if (empty($destinationID)) {
            echo json_encode(['success' => false, 'message' => 'Destination ID required']);
            break;
        }
        
        $result = BackBorkConfig::setDestinationRootOnly($destinationID, $rootOnly);
        if ($result) {
            BackBorkLog::logEvent($currentUser, 'destination_visibility_changed', [
                'destination' => $destinationID,
                'root_only' => $rootOnly
            ], true, "Destination '$destinationID' visibility set to " . ($rootOnly ? 'root-only' : 'all users'), $requestor);
        }
        echo json_encode(['success' => $result]);
        break;
    
    /**
     * Enable a disabled destination (root only)
     * Calls WHM API to set disabled=0 for the destination
     */
    case 'enable_destination':
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $data = backbork_get_request_data();
        $destinationID = isset($data['destination_id']) ? $data['destination_id'] : '';
        
        if (empty($destinationID)) {
            echo json_encode(['success' => false, 'message' => 'Destination ID required']);
            break;
        }
        
        // Use WHM API to enable the destination
        $whmCmd = '/usr/local/cpanel/bin/whmapi1 backup_destination_set id=' . escapeshellarg($destinationID) . ' disabled=0 2>&1';
        $output = shell_exec($whmCmd);
        
        // Check for success in output
        $success = (strpos($output, 'result: 1') !== false || strpos($output, '"result":1') !== false);
        
        if ($success) {
            BackBorkLog::logEvent($currentUser, 'destination_enabled', [
                'destination' => $destinationID
            ], true, "Destination '$destinationID' enabled via WHM API", $requestor);
            echo json_encode(['success' => true, 'message' => 'Destination enabled']);
        } else {
            BackBorkLog::logEvent($currentUser, 'destination_enable_failed', [
                'destination' => $destinationID,
                'output' => $output
            ], false, "Failed to enable destination '$destinationID'", $requestor);
            echo json_encode(['success' => false, 'message' => 'Failed to enable destination', 'output' => $output]);
        }
        break;
    
    /**
     * Validate a remote destination (root only)
     * Runs backup_cmd to test the connection
     */
    case 'validate_destination':
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $data = backbork_get_request_data();
        $destinationID = isset($data['destination_id']) ? $data['destination_id'] : '';
        
        if (empty($destinationID)) {
            echo json_encode(['success' => false, 'message' => 'Destination ID required']);
            break;
        }
        
        // Don't allow validating local destinations
        if (strtolower($destinationID) === 'local') {
            echo json_encode(['success' => false, 'message' => 'Local destinations do not require validation']);
            break;
        }
        
        // Run backup_cmd with correct syntax: id=<transport_id> disableonfail=0
        $cmd = '/usr/local/cpanel/bin/backup_cmd id=' . escapeshellarg($destinationID) . ' disableonfail=0 2>&1';
        $output = [];
        $returnCode = 0;
        
        // Use proc_open for timeout control
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        
        if (is_resource($process)) {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            
            $startTime = time();
            $stdout = '';
            $stderr = '';
            
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $returnCode = $status['exitcode'];
                    break;
                }
                if (time() - $startTime > 20) {
                    proc_terminate($process);
                    echo json_encode(['success' => false, 'message' => 'Validation timed out after 20 seconds']);
                    break 2;
                }
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                usleep(100000); // 100ms
            }
            
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            
            $fullOutput = trim($stdout . "\n" . $stderr);
            
            // Check for success - backup_cmd returns 0 on success and output contains validation info
            // Failure indicators: "failed", "error", "unable", "cannot", or showing usage/help
            $isFailure = ($returnCode !== 0) ||
                         stripos($fullOutput, 'failed') !== false ||
                         stripos($fullOutput, 'error') !== false ||
                         stripos($fullOutput, 'unable') !== false ||
                         stripos($fullOutput, 'cannot') !== false ||
                         stripos($fullOutput, 'Usage:') !== false;
            
            if (!$isFailure && $returnCode === 0) {
                echo json_encode(['success' => true, 'message' => 'Destination validated successfully', 'output' => $fullOutput]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Validation failed', 'output' => $fullOutput]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to execute validation command']);
        }
        break;
    
    // ========================================================================
    // NOTIFICATIONS
    // ========================================================================
    
    /**
     * Send a test notification (email or Slack)
     * Accepts current field values so user can test before saving
     */
    case 'test_notification':
        $data = backbork_get_request_data();
        $type = isset($data['type']) ? $data['type'] : 'email';
        
        $config = new BackBorkConfig();
        $notify = new BackBorkNotify();
        
        // Get saved config as base, but override with passed values for testing
        $testConfig = $config->getUserConfig($currentUser);
        if ($type === 'email' && !empty($data['email'])) {
            $testConfig['notify_email'] = $data['email'];
        }
        if ($type === 'slack' && !empty($data['webhook'])) {
            $testConfig['notify_slack'] = $data['webhook'];
        }
        
        echo json_encode($notify->testNotification($type, $testConfig));
        break;
    
    // ========================================================================
    // LOGS
    // ========================================================================
    
    /**
     * Get operation logs
     * Supports pagination and filtering
     */
    case 'get_logs':
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
        $accountFilter = isset($_GET['account']) ? $_GET['account'] : '';
        
        // Use centralised logger
        if (class_exists('BackBorkLog')) {
            echo json_encode(BackBorkLog::getLogs($currentUser, $isRoot, $page, $limit, $filter, $accountFilter));
        } else {
            // Fallback to backup manager's log method
            $backupManager = new BackBorkBackupManager();
            echo json_encode($backupManager->getLogs($currentUser, $isRoot, $page, $limit, $filter));
        }
        break;
    
    /**
     * Get verbose log content for viewing from logs tab
     * Returns full content of backup_* or restore_* log files
     */
    case 'get_verbose_log':
        $jobID = isset($_GET['job_id']) ? $_GET['job_id'] : '';
        
        // Validate job_id format (backup_* or restore_*)
        if (!preg_match('/^(backup|restore)_[0-9]+_[a-f0-9]+$/', $jobID)) {
            echo json_encode(['success' => false, 'message' => 'Invalid job ID format']);
            break;
        }
        
        $logFile = '/usr/local/cpanel/3rdparty/backbork/logs/' . $jobID . '.log';
        
        if (!file_exists($logFile)) {
            echo json_encode(['success' => false, 'message' => 'Log file not found']);
            break;
        }
        
        $content = file_get_contents($logFile);
        echo json_encode(['success' => true, 'content' => $content]);
        break;
    
    /**
     * Get restore log content for real-time progress viewing
     * Used for tailing restore output during long operations
     */
    case 'get_restore_log':
        $restoreID = isset($_GET['restore_id']) ? $_GET['restore_id'] : '';
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        // Validate restore_id format (security)
        if (!preg_match('/^restore_[0-9]+_[a-f0-9]+$/', $restoreID)) {
            echo json_encode(['success' => false, 'message' => 'Invalid restore ID']);
            break;
        }
        
        $logFile = '/usr/local/cpanel/3rdparty/backbork/logs/' . $restoreID . '.log';
        
        if (!file_exists($logFile)) {
            echo json_encode(['success' => false, 'message' => 'Log file not found', 'content' => '', 'offset' => 0, 'complete' => false]);
            break;
        }
        
        // Clear file stat cache to get fresh file size (critical for real-time tailing)
        clearstatcache(true, $logFile);
        
        // Read log content from offset
        $content = '';
        $fileSize = filesize($logFile);
        
        if ($offset < $fileSize) {
            $handle = fopen($logFile, 'r');
            fseek($handle, $offset);
            $content = fread($handle, $fileSize - $offset);
            fclose($handle);
        }
        
        // Check if restore is complete (look for completion markers)
        // Re-read file for completion check to ensure we have latest content
        clearstatcache(true, $logFile);
        $fullContent = file_get_contents($logFile);
        $isComplete = (strpos($fullContent, 'RESTORE COMPLETED SUCCESSFULLY') !== false) ||
                      (strpos($fullContent, 'RESTORE FAILED') !== false);
        
        echo json_encode([
            'success' => true,
            'content' => $content,
            'offset' => $fileSize,
            'complete' => $isComplete
        ]);
        break;
    
    /**
     * Get backup log content for real-time progress viewing
     * Used for tailing backup output during long operations
     */
    case 'get_backup_log':
        $backupID = isset($_GET['backup_id']) ? $_GET['backup_id'] : '';
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        // Validate backup_id format (security)
        if (!preg_match('/^backup_[0-9]+_[a-f0-9]+$/', $backupID)) {
            echo json_encode(['success' => false, 'message' => 'Invalid backup ID']);
            break;
        }
        
        $logFile = '/usr/local/cpanel/3rdparty/backbork/logs/' . $backupID . '.log';
        
        if (!file_exists($logFile)) {
            echo json_encode(['success' => false, 'message' => 'Log file not found', 'content' => '', 'offset' => 0, 'complete' => false]);
            break;
        }
        
        // Clear file stat cache to get fresh file size (critical for real-time tailing)
        clearstatcache(true, $logFile);
        
        // Read log content from offset
        $content = '';
        $fileSize = filesize($logFile);
        
        if ($offset < $fileSize) {
            $handle = fopen($logFile, 'r');
            fseek($handle, $offset);
            $content = fread($handle, $fileSize - $offset);
            fclose($handle);
        }
        
        // Check if backup is complete (look for completion markers)
        // Re-read file for completion check to ensure we have latest content
        clearstatcache(true, $logFile);
        $fullContent = file_get_contents($logFile);
        $isComplete = (strpos($fullContent, 'BACKUP COMPLETED SUCCESSFULLY') !== false) ||
                      (strpos($fullContent, 'BACKUP FAILED') !== false);
        
        echo json_encode([
            'success' => true,
            'content' => $content,
            'offset' => $fileSize,
            'complete' => $isComplete
        ]);
        break;
    
    // ========================================================================
    // CRON STATUS
    // ========================================================================
    
    /**
     * Check if cron job is properly configured
     * Returns cron file path, schedule, and command
     */
    case 'check_cron':
        $cronStatus = [
            'installed' => false, 
            'path' => '', 
            'schedule' => '', 
            'command' => '', 
            'message' => ''
        ];
        
        // Check /etc/cron.d/backbork first (preferred location)
        $cronFile = '/etc/cron.d/backbork';
        if (file_exists($cronFile)) {
            $cronStatus['installed'] = true;
            $cronStatus['path'] = $cronFile;
            $content = file_get_contents($cronFile);
            
            // Parse cron schedule and command from file
            if (preg_match('/^([0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+)\s+(.+)$/m', $content, $matches)) {
                $cronStatus['schedule'] = trim($matches[1]);
                $cronStatus['command'] = trim($matches[2]);
            } else {
                $cronStatus['command'] = trim($content);
            }
        } else {
            // Fallback: Check root's crontab
            $crontab = shell_exec('crontab -l 2>/dev/null');
            if ($crontab && strpos($crontab, 'backbork') !== false) {
                $cronStatus['installed'] = true;
                $cronStatus['path'] = 'root crontab';
                
                // Parse backbork line from crontab
                if (preg_match('/^([0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+)\s+(.*backbork.*)$/m', $crontab, $matches)) {
                    $cronStatus['schedule'] = trim($matches[1]);
                    $cronStatus['command'] = trim($matches[2]);
                }
            } else {
                $cronStatus['message'] = 'No cron job found. Run install.sh to configure.';
            }
        }
        
        echo json_encode($cronStatus);
        break;
    
    // ========================================================================
    // DEFAULT (INVALID ACTION)
    // ========================================================================
    
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}