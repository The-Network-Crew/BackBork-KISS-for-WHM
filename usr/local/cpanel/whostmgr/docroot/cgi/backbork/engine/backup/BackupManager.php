<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   High-level backup orchestration coordinating pkgacct and transport.
 *   Handles backup creation, destination transport, notifications, and logging.
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

/**
 * High-level backup orchestration manager.
 * Coordinates backup operations using pkgacct, handles transport to destinations,
 * sends notifications, and manages operation logging.
 */
class BackBorkBackupManager {
    
    // Default directory for operation logs
    const LOG_DIR = '/usr/local/cpanel/3rdparty/backbork/logs';
    
    // Temporary directory for staging backup archives before transport
    const TEMP_DIR = '/home/backbork_tmp';
    
    /** @var BackBorkConfig User/global configuration handler */
    private $config;
    
    /** @var BackBorkNotify Email/Slack notification service */
    private $notify;
    
    /** @var BackBorkDestinationsParser Parses WHM transport destinations */
    private $destinations;
    
    /** @var BackBorkPkgacct cPanel pkgacct wrapper for creating account archives */
    private $pkgacct;
    
    /** @var BackBorkSQLBackup Hot database backup handler */
    private $dbBackup;
    
    /** @var BackBorkManifest Manifest handler for backup tracking */
    private $manifest;
    
    /** @var string|null Override requestor (IP or 'cron') - set by runner.php for manual jobs */
    private $requestorOverride = null;
    
    /**
     * Constructor - Initialise all dependencies.
     * Sets up configuration, notification, destination parsing, and pkgacct services.
     * Creates required directories if they don't exist.
     */
    public function __construct() {
        // Initialise helper services
        $this->config = new BackBorkConfig();
        $this->notify = new BackBorkNotify();
        $this->destinations = new BackBorkDestinationsParser();
        $this->pkgacct = new BackBorkPkgacct();
        $this->dbBackup = new BackBorkSQLBackup();
        $this->manifest = new BackBorkManifest();
        
        // Ensure temp directory exists for staging backups (secure permissions)
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0700, true);
        }
        
        // Ensure log directory exists for operation history
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0700, true);
        }
    }
    
    /**
     * Create backup for multiple accounts with a pre-generated backup ID.
     * Used when backup_id needs to be returned to client before backup starts.
     * 
     * @param array $accounts List of account usernames to backup
     * @param string $destinationID Destination ID from WHM transport config
     * @param string $user User initiating the backup (for logging/permissions)
     * @param string $backupID Pre-generated backup ID for log tracking
     * @param callable|null $progressCallback Optional callback called after each account
     * @return array Result with success status, messages, per-account results, and errors
     */
    public function createBackupWithID($accounts, $destinationID, $user, $backupID, $progressCallback = null) {
        $logFile = self::LOG_DIR . '/' . $backupID . '.log';
        return $this->executeBackup($accounts, $destinationID, $user, $backupID, $logFile, $progressCallback);
    }
    
    /**
     * Create backup for multiple accounts.
     * Orchestrates the full backup workflow: validation, per-account backup,
     * transport to destination, notifications, and logging.
     * 
     * @param array $accounts List of account usernames to backup
     * @param string $destinationID Destination ID from WHM transport config
     * @param string $user User initiating the backup (for logging/permissions)
     * @param callable|null $progressCallback Optional callback called after each account: function(int $completed, int $total)
     * @param string|null $jobID Optional job ID for cancellation checking (from queue)
     * @param string|null $scheduleID Optional schedule ID for manifest tracking (null for manual backups)
     * @param int $retention Retention count for manifest (0 = unlimited)
     * @return array Result with success status, messages, per-account results, and errors
     */
    public function createBackup($accounts, $destinationID, $user, $progressCallback = null, $jobID = null, $scheduleID = null, $retention = 30) {
        // Generate unique backup ID for log tracking
        $backupID = 'backup_' . time() . '_' . substr(md5(uniqid()), 0, 8);
        $logFile = self::LOG_DIR . '/' . $backupID . '.log';
        return $this->executeBackup($accounts, $destinationID, $user, $backupID, $logFile, $progressCallback, $jobID, $scheduleID, $retention);
    }
    
    /**
     * Execute the actual backup operation.
     * Internal method that handles the backup workflow.
     * 
     * @param array $accounts List of account usernames to backup
     * @param string $destinationID Destination ID from WHM transport config
     * @param string $user User initiating the backup (for logging/permissions)
     * @param string $backupID Unique backup ID for tracking
     * @param string $logFile Path to the log file
     * @param callable|null $progressCallback Optional callback called after each account
     * @param string|null $jobID Optional job ID for cancellation checking
     * @param string|null $scheduleID Optional schedule ID for manifest tracking
     * @param int $retention Retention count for manifest
     * @return array Result with success status, messages, per-account results, and errors
     */
    private function executeBackup($accounts, $destinationID, $user, $backupID, $logFile, $progressCallback = null, $jobID = null, $scheduleID = null, $retention = 30) {
        
        // Initialise log file with header
        $this->writeBackupLog($logFile, "========================================");
        $this->writeBackupLog($logFile, "BACKBORK BACKUP OPERATION");
        $this->writeBackupLog($logFile, "========================================");
        $this->writeBackupLog($logFile, "Backup ID: {$backupID}");
        $this->writeBackupLog($logFile, "Started: " . date('Y-m-d H:i:s'));
        $this->writeBackupLog($logFile, "User: {$user}");
        $this->writeBackupLog($logFile, "Accounts: " . implode(', ', $accounts));
        $this->writeBackupLog($logFile, "");
        
        // Load user-specific configuration (temp dir, notification prefs, etc.)
        $userConfig = $this->config->getUserConfig($user);
        
        // Look up the destination configuration by ID
        $destination = $this->destinations->getDestinationByID($destinationID);
        
        // Validate destination exists
        if (!$destination) {
            $this->writeBackupLog($logFile, "[ERROR] Invalid destination ID: {$destinationID}");
            $this->writeBackupLog($logFile, "");
            $this->writeBackupLog($logFile, "BACKUP FAILED");
            return ['success' => false, 'message' => 'Invalid destination', 'backup_id' => $backupID];
        }
        
        // Check destination is enabled
        if (empty($destination['enabled'])) {
            $this->writeBackupLog($logFile, "[ERROR] Destination is disabled: {$destination['name']}");
            $this->writeBackupLog($logFile, "  → Enable via WHM → Backup Configuration → Additional Destinations");
            $this->writeBackupLog($logFile, "");
            $this->writeBackupLog($logFile, "BACKUP FAILED");
            return ['success' => false, 'message' => 'Destination is disabled in WHM', 'backup_id' => $backupID];
        }
        
        $destType = strtolower($destination['type'] ?? 'local');
        $this->writeBackupLog($logFile, "[STEP 1/5] Validating destination...");
        $this->writeBackupLog($logFile, "  → Destination: {$destination['name']}");
        $this->writeBackupLog($logFile, "  → Type: {$destType}");
        $this->writeBackupLog($logFile, "  → Path: " . ($destination['path'] ?? '/backup'));
        $this->writeBackupLog($logFile, "");
        
        // Track results and errors for each account
        $results = [];
        $errors = [];
        $logMessages = [];
        
        // Send start notification if user has enabled it
        $notifyStart = !empty($userConfig['notify_backup_start']);
        if ($notifyStart) {
            $this->writeBackupLog($logFile, "[STEP 2/5] Sending start notification...");
            $this->notify->sendNotification(
                'backup_start',
                [
                    'accounts' => $accounts,
                    'destination' => $destination['name'],
                    'user' => $user,
                    'requestor' => $this->getRequestor()
                ],
                $userConfig
            );
            $this->writeBackupLog($logFile, "  → Notification sent");
            $this->writeBackupLog($logFile, "");
        } else {
            $this->writeBackupLog($logFile, "[STEP 2/5] Start notification skipped (not enabled)");
            $this->writeBackupLog($logFile, "");
        }
        
        $totalAccounts = count($accounts);
        $currentAccount = 0;
        $accountsWithDuration = [];  // Track account names with run-time for logging
        $wasCancelled = false;       // Track if job was cancelled
        
        // Process each account sequentially
        foreach ($accounts as $account) {
            $currentAccount++;
            $this->writeBackupLog($logFile, "[STEP 3/5] Processing account {$currentAccount}/{$totalAccounts}: {$account}");
            $this->writeBackupLog($logFile, str_repeat('-', 40));
            
            // Track start time for this account
            $accountStartTime = microtime(true);
            
            // Backup single account (pkgacct + transport)
            $result = $this->backupSingleAccount($account, $destination, $userConfig, $user, $logFile);
            $results[$account] = $result;
            
            // Calculate duration for this account
            $accountDuration = microtime(true) - $accountStartTime;
            $durationStr = $this->formatDuration($accountDuration);
            $accountsWithDuration[] = "{$account} ({$durationStr})";
            
            // Track failures for summary
            if (!$result['success']) {
                $errors[] = $account . ': ' . $result['message'];
                $this->writeBackupLog($logFile, "  ✗ FAILED: " . $result['message'] . " ({$durationStr})");
            } else {
                $this->writeBackupLog($logFile, "  ✓ SUCCESS: " . $result['message'] . " ({$durationStr})");
                
                // Write to manifest for pruning tracking
                $manifestID = $scheduleID ?? BackBorkManifest::MANUAL_MANIFEST_ID;
                $this->manifest->addEntry(
                    $manifestID,
                    $account,
                    $result['file'] ?? '',
                    $result['db_file'] ?? null,
                    $result['size'] ?? 0,
                    $destinationID,
                    $retention
                );
            }
            
            // Build log message for this account
            $logMessages[] = "[{$account}] " . ($result['success'] ? 'SUCCESS' : 'FAILED') . ': ' . $result['message'];
            $this->writeBackupLog($logFile, "");
            
            // Notify progress callback (for queue progress tracking)
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $currentAccount, $totalAccounts);
            }
            
            // Check for cancellation request after each account (if running from queue)
            if ($jobID && class_exists('BackBorkQueue') && BackBorkQueue::isCancelRequested($jobID)) {
                $this->writeBackupLog($logFile, "");
                $this->writeBackupLog($logFile, "⚠️ CANCELLATION REQUESTED");
                $this->writeBackupLog($logFile, "Job cancelled by user after completing {$currentAccount}/{$totalAccounts} accounts");
                $this->writeBackupLog($logFile, "");
                
                // Clear the cancel marker
                BackBorkQueue::clearCancelRequest($jobID);
                
                // Log the cancellation
                $remainingAccounts = array_slice($accounts, $currentAccount);
                $logMessages[] = "[CANCELLED] Remaining accounts skipped: " . implode(', ', $remainingAccounts);
                
                $wasCancelled = true;
                break;  // Exit the loop
            }
        }
        
        // Overall success only if no errors occurred and not cancelled
        $success = empty($errors) && !$wasCancelled;
        
        $this->writeBackupLog($logFile, "[STEP 4/5] Backup processing complete");
        $this->writeBackupLog($logFile, "  → Completed: {$currentAccount}/{$totalAccounts}");
        $this->writeBackupLog($logFile, "  → Successful: " . ($currentAccount - count($errors)) . "/{$currentAccount}");
        $this->writeBackupLog($logFile, "  → Failed: " . count($errors) . "/{$currentAccount}");
        if ($wasCancelled) {
            $this->writeBackupLog($logFile, "  → Status: CANCELLED");
        }
        $this->writeBackupLog($logFile, "");
        
        // Log the complete operation with all account results (including per-account duration)
        // Type includes _local or _remote suffix based on destination
        $logType = ($destType === 'local') ? 'backup_local' : 'backup_remote';
        
        // Build log message with destination info as first line
        $destInfo = ($destType === 'local') 
            ? 'Destination: ' . ($destination['name'] ?? 'Local')
            : 'Host: ' . ($destination['host'] ?? $destination['name'] ?? 'Remote');
        $logMessage = $destInfo . "\n" . implode("\n", $logMessages);
        
        BackBorkConfig::debugLog('createBackup: Logging operation for user=' . $user . ' success=' . ($success ? 'true' : 'false') . ' accounts=' . implode(',', $accounts));
        $this->logOperation($user, $logType, $accountsWithDuration, $success, $logMessage, $backupID);
        
        // Check notification preferences
        $notifySuccess = !empty($userConfig['notify_backup_success']);
        $notifyFailure = !empty($userConfig['notify_backup_failure']);
        
        // Send completion notifications
        $this->writeBackupLog($logFile, "[STEP 5/5] Sending completion notification...");
        
        // Send success notification if all backups succeeded and notifications enabled
        if ($success && $notifySuccess) {
            $this->writeBackupLog($logFile, "  → Sending success notification");
            $this->notify->sendNotification(
                'backup_success',
                [
                    'accounts' => $accounts,
                    'destination' => $destination['name'],
                    'user' => $user,
                    'requestor' => $this->getRequestor(),
                    'results' => $results
                ],
                $userConfig
            );
        // Send failure notification if any backup failed and notifications enabled
        } elseif (!$success && $notifyFailure) {
            $this->writeBackupLog($logFile, "  → Sending failure notification");
            $this->notify->sendNotification(
                'backup_failure',
                [
                    'accounts' => $accounts,
                    'destination' => $destination['name'],
                    'user' => $user,
                    'requestor' => $this->getRequestor(),
                    'errors' => $errors
                ],
                $userConfig
            );
        } else {
            $this->writeBackupLog($logFile, "  → No notification configured for this outcome");
        }
        
        // Write final status to log
        $this->writeBackupLog($logFile, "");
        $this->writeBackupLog($logFile, "========================================");
        if ($wasCancelled) {
            $this->writeBackupLog($logFile, "BACKUP CANCELLED");
            $this->writeBackupLog($logFile, "Completed {$currentAccount}/{$totalAccounts} accounts before cancellation");
        } elseif ($success) {
            $this->writeBackupLog($logFile, "BACKUP COMPLETED SUCCESSFULLY");
        } else {
            $this->writeBackupLog($logFile, "BACKUP FAILED");
            $this->writeBackupLog($logFile, "Errors: " . implode('; ', $errors));
        }
        $this->writeBackupLog($logFile, "Finished: " . date('Y-m-d H:i:s'));
        $this->writeBackupLog($logFile, "========================================");
        
        // Determine result message
        $resultMessage = $success 
            ? 'All backups completed successfully' 
            : ($wasCancelled 
                ? "Cancelled after {$currentAccount}/{$totalAccounts} accounts" 
                : 'Some backups failed');
        
        // Return comprehensive result for API response
        return [
            'success' => $success,
            'cancelled' => $wasCancelled,
            'message' => $resultMessage,
            'results' => $results,
            'errors' => $errors,
            'log' => implode("\n", $logMessages),
            'backup_id' => $backupID
        ];
    }
    
    /**
     * Backup a single cPanel account.
     * For LOCAL: pkgacct writes directly to destination, rename in place.
     * For REMOTE: pkgacct to temp, upload, delete temp immediately.
     * 
     * @param string $account Account username to backup
     * @param array $destination Destination configuration (type, path, credentials, etc.)
     * @param array $userConfig User configuration (temp directory, options)
     * @param string $user User initiating backup (for logging)
     * @param string $logFile Path to log file for progress updates
     * @return array Result with success status and message
     */
    private function backupSingleAccount($account, $destination, $userConfig, $user, $logFile = null) {
        $destType = strtolower($destination['type'] ?? 'local');
        $isLocal = ($destType === 'local');
        
        // Determine working directory:
        // - LOCAL: Write directly to destination/{account}/
        // - REMOTE: Use temp directory, then upload and delete
        if ($isLocal) {
            $destPath = rtrim($destination['path'] ?? '/backup', '/');
            $workDir = $destPath . '/' . $account;
            
            // Ensure account directory exists
            if (!is_dir($workDir)) {
                if (!mkdir($workDir, 0700, true)) {
                    return [
                        'success' => false,
                        'message' => "Failed to create backup directory: {$workDir}"
                    ];
                }
            }
        } else {
            // Remote: use temp directory
            $workDir = isset($userConfig['temp_directory']) ? $userConfig['temp_directory'] : self::TEMP_DIR;
            
            // Ensure temp directory exists
            if (!is_dir($workDir)) {
                if (!mkdir($workDir, 0700, true)) {
                    return [
                        'success' => false,
                        'message' => "Failed to create temp directory: {$workDir}"
                    ];
                }
            }
        }
        
        $this->writeBackupLog($logFile, "  [3a] Preparing backup environment...");
        $this->writeBackupLog($logFile, "      → Destination type: {$destType}");
        $this->writeBackupLog($logFile, "      → Working directory: {$workDir}");
        
        // ====================================================================
        // STEP 1: Execute pkgacct (creates cpmove-{account}.tar.gz)
        // ====================================================================
        $this->writeBackupLog($logFile, "  [3b] Running pkgacct for {$account}...");
        $this->writeBackupLog($logFile, "      ────────────────────────────────────────────────────────");
        
        // Pass logFile to stream pkgacct output in real-time
        $pkgResult = $this->pkgacct->execute($account, $workDir, $userConfig, $logFile);
        
        $this->writeBackupLog($logFile, "      ────────────────────────────────────────────────────────");
        
        if (!$pkgResult['success']) {
            $this->writeBackupLog($logFile, "      ✗ pkgacct failed: " . ($pkgResult['message'] ?? 'Unknown error'));
            return $pkgResult;
        }
        
        $this->writeBackupLog($logFile, "      ✓ pkgacct completed successfully");
        
        // Get the created file path
        $createdFile = $pkgResult['path'];
        
        // Verify we have a file (pkgacct should always create .tar.gz with default options)
        if (!is_file($createdFile)) {
            $this->writeBackupLog($logFile, "      ✗ pkgacct did not create expected file: {$createdFile}");
            // Clean up any directory it may have created
            if (is_dir($createdFile)) {
                $this->recursiveDelete($createdFile);
            }
            return [
                'success' => false,
                'message' => "pkgacct did not create a valid archive file"
            ];
        }
        
        // Rename to official format: backup-{MM.DD.YYYY}_{HH-MM-SS}_{USER}.tar.gz
        $datePart = date('m.d.Y_H-i-s');
        $backupFile = "backup-{$datePart}_{$account}.tar.gz";
        $finalFile = $workDir . '/' . $backupFile;
        
        $this->writeBackupLog($logFile, "      → Renaming to: {$backupFile}");
        if (!rename($createdFile, $finalFile)) {
            $this->writeBackupLog($logFile, "      ✗ Failed to rename backup file");
            unlink($createdFile);  // Clean up
            return [
                'success' => false,
                'message' => "Failed to rename backup file"
            ];
        }
        
        // Secure file permissions before transport
        chmod($finalFile, 0600);
        
        $fileSize = filesize($finalFile);
        $this->writeBackupLog($logFile, "      → Archive size: " . $this->formatSize($fileSize));
        
        // Track files for remote upload/cleanup
        $filesToUpload = [['local' => $finalFile, 'remote' => $account . '/' . $backupFile]];
        $filesToCleanup = [$finalFile];
        
        // ====================================================================
        // STEP 2: Hot database backup (if configured)
        // ====================================================================
        $dbMethod = $userConfig['db_backup_method'] ?? 'pkgacct';
        
        if (in_array($dbMethod, ['mariadb-backup', 'mysqlbackup'], true)) {
            $this->writeBackupLog($logFile, "  [3c] Running hot database backup ({$dbMethod})...");
            
            $dbResult = $this->dbBackup->backupDatabases($account, $workDir, $userConfig);
            
            if (!$dbResult['success'] && empty($dbResult['skipped'])) {
                $this->writeBackupLog($logFile, "      ✗ Database backup failed: " . ($dbResult['message'] ?? 'Unknown error'));
                // Clean up main backup file
                if (file_exists($finalFile)) unlink($finalFile);
                return [
                    'success' => false,
                    'message' => "Database backup failed: " . ($dbResult['message'] ?? 'Unknown error')
                ];
            }
            
            if (!empty($dbResult['archive']) && file_exists($dbResult['archive'])) {
                $dbArchiveName = basename($dbResult['archive']);
                $dbSize = $this->formatSize(filesize($dbResult['archive']));
                $this->writeBackupLog($logFile, "      ✓ Database backup created: {$dbArchiveName} ({$dbSize})");
                $filesToUpload[] = ['local' => $dbResult['archive'], 'remote' => $account . '/' . $dbArchiveName];
                $filesToCleanup[] = $dbResult['archive'];
            } else {
                $this->writeBackupLog($logFile, "      → No databases to backup (skipped)");
            }
        } else {
            $this->writeBackupLog($logFile, "  [3c] Database backup method: pkgacct (included in archive)");
        }
        
        // ====================================================================
        // STEP 3: For REMOTE only - Upload then delete local
        // For LOCAL - files are already in place, nothing more to do
        // ====================================================================
        if ($isLocal) {
            $this->writeBackupLog($logFile, "  [3d] Local backup complete - files in place");
            return [
                'success' => true,
                'message' => 'Backup completed successfully',
                'file' => $backupFile,
                'db_file' => isset($dbArchiveName) ? $dbArchiveName : null,
                'size' => $fileSize
            ];
        }
        
        // Remote destination - upload files
        $this->writeBackupLog($logFile, "  [3d] Uploading to remote destination...");
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        $allSuccess = true;
        $messages = [];
        
        foreach ($filesToUpload as $file) {
            $filename = basename($file['local']);
            $this->writeBackupLog($logFile, "      → Uploading: {$filename}");
            $result = $transport->upload($file['local'], $file['remote'], $destination);
            
            if (!$result['success']) {
                $allSuccess = false;
                $messages[] = $filename . ': ' . ($result['message'] ?? 'Upload failed');
                $this->writeBackupLog($logFile, "        ✗ Upload failed: " . ($result['message'] ?? 'Unknown error'));
            } else {
                $this->writeBackupLog($logFile, "        ✓ Upload successful");
            }
        }
        
        // ====================================================================
        // STEP 4: Delete temp files IMMEDIATELY after upload (before next account)
        // ====================================================================
        $this->writeBackupLog($logFile, "  [3e] Cleaning up temporary files...");
        foreach ($filesToCleanup as $file) {
            if (file_exists($file)) {
                $this->writeBackupLog($logFile, "      → Removing: " . basename($file));
                unlink($file);
            }
        }
        $this->writeBackupLog($logFile, "      ✓ Cleanup complete");
        
        return [
            'success' => $allSuccess,
            'message' => $allSuccess ? 'Backup completed successfully' : implode('; ', $messages),
            'file' => $backupFile,
            'db_file' => isset($dbArchiveName) ? $dbArchiveName : null,
            'size' => $fileSize
        ];
    }
    
    /**
     * List backups for an account from local storage.
     * Searches the local /backup directory for existing backup archives.
     * 
     * @param string $account Account username to search for
     * @param string $user User requesting the list (for permission checks)
     * @return array Result with success status and list of backup files
     */
    public function listBackups($account, $user) {
        // Use local transport handler to search /backup directory
        $localTransport = new BackBorkTransportLocal();
        $localDest = ['type' => 'Local', 'path' => '/backup'];
        
        // Find all backup archives for this account
        $backups = $localTransport->findAccountBackups($account, $localDest);
        
        // Add human-readable size formatting to each backup entry
        foreach ($backups as &$backup) {
            $backup['size_formatted'] = $this->formatSize($backup['size']);
        }
        
        return ['success' => true, 'backups' => $backups];
    }
    
    /**
     * List backups from a remote destination.
     * Queries the specified destination for available backup files.
     * Optionally filters by account name substring.
     * 
     * @param string $destinationID Destination ID from WHM transport config
     * @param string $user User requesting (for permission filtering)
     * @param string $account Optional account filter (partial match)
     * @return array Result with success status and list of backup files
     */
    public function listRemoteBackups($destinationID, $user, $account = '') {
        // Look up destination configuration by ID
        $destination = $this->destinations->getDestinationByID($destinationID);
        
        // Validate destination exists
        if (!$destination) {
            return ['success' => false, 'backups' => [], 'message' => 'Invalid destination'];
        }
        
        // Get appropriate transport handler for this destination type
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        // For remote destinations with account filter, list inside account subdirectory
        // Backups are stored as {account}/backup-MM.DD.YYYY_HH-MM-SS_{account}.tar.gz
        $listPath = '';
        if (!empty($account)) {
            $listPath = $account;  // List inside account folder
        }
        $files = $transport->listFiles($listPath, $destination);

        // Security: Get list of accounts this user can access (for filtering)
        $acl = BackBorkBootstrap::getACL();
        $isRoot = $acl->isRoot();
        $accessibleAccounts = [];
        
        // Non-root users can only see backups for accounts they own
        if (!$isRoot) {
            $accessible = $acl->getAccessibleAccounts();
            foreach ($accessible as $acc) {
                $accessibleAccounts[] = strtolower($acc['user']);
            }
        }

        // If account filter provided, filter files by partial match on filename
        if (!empty($account)) {
            $accountLower = mb_strtolower($account);
            $filtered = [];
            foreach ($files as $fileInfo) {
                $filenameLower = mb_strtolower($fileInfo['file'] ?? '');
                // Include file if account substring found in filename
                if (strpos($filenameLower, $accountLower) !== false) {
                    $filtered[] = $fileInfo;
                }
            }
            $files = $filtered;
        }
        
        // Format results for display with human-readable sizes
        // Also filter by user access (resellers only see their accounts' backups)
        $backups = [];
        foreach ($files as $file) {
            $filename = $file['file'] ?? '';
            
            // Extract account name from backup filename
            // Official format: backup-MM.DD.YYYY_HH-MM-SS_USERNAME.tar.gz
            $backupAccount = null;
            if (preg_match('/^backup-\\d{2}\\.\\d{2}\\.\\d{4}_\\d{2}-\\d{2}-\\d{2}_([a-z0-9_]+)\\.tar(\\.gz)?$/i', $filename, $matches)) {
                $backupAccount = strtolower($matches[1]);
            }
            
            // Security: Skip backups for accounts the user doesn't own (resellers only)
            if (!$isRoot && $backupAccount !== null) {
                if (!in_array($backupAccount, $accessibleAccounts)) {
                    continue;  // Skip this backup - user doesn't own this account
                }
            }
            
            // Build file path for restore operations
            // For remote destinations, include account folder in path: {account}/{filename}
            // For local destinations, files may be in account subdirectories
            $destType = strtolower($destination['type'] ?? 'local');
            if ($destType === 'sftp' || $destType === 'ftp') {
                // Remote: prepend account folder if we listed inside it
                $filePath = !empty($listPath) ? $listPath . '/' . $filename : $filename;
            } else {
                // Local: use account subfolder if known
                $filePath = $backupAccount ? $backupAccount . '/' . $filename : $filename;
            }
            
            // Extract date from filename (e.g., backup-01.15.2024_12-30-00_user.tar.gz)
            $backupDate = 'Unknown';
            if (preg_match('/backup-(\\d{2})\\.(\\d{2})\\.(\\d{4})_(\\d{2})-(\\d{2})-(\\d{2})_/i', $filename, $dateMatches)) {
                $date = "{$dateMatches[3]}-{$dateMatches[1]}-{$dateMatches[2]}";
                $time = "{$dateMatches[4]}:{$dateMatches[5]}:{$dateMatches[6]}";
                $backupDate = "$date $time";
            }
            
            $backups[] = [
                'file' => $filePath,
                'display_name' => $filename,  // Just filename for display
                'size' => $this->formatSize($file['size'] ?? 0),
                'date' => $backupDate,
                'location' => 'remote',
                'account' => $backupAccount
            ];
        }
        
        return ['success' => true, 'backups' => $backups];
    }
    
    /**
     * Log a backup/restore operation to the centralised log system.
     * Delegates to BackBorkLog for consistent logging format.
     * 
     * @param string $user User who performed the operation
     * @param string $type Operation type ('backup' or 'restore')
     * @param array $accounts Affected accounts (array of usernames)
     * @param bool $success Whether operation succeeded
     * @param string $message Details/error message for the log entry
     * @param string $jobID Optional job ID for linking to verbose logs
     */
    public function logOperation($user, $type, $accounts, $success, $message, $jobID = '') {
        // Only log if BackBorkLog class is available
        if (class_exists('BackBorkLog')) {
            // Use override if set, otherwise detect requestor
            $logRequestor = $this->getRequestor();
            
            // Log event through centralised logging
            BackBorkLog::logEvent($user, $type === 'backup' ? 'backup' : $type, $accounts, $success, $message, $logRequestor, $jobID);
        }
    }
    
    /**
     * Get operation logs with pagination and filtering.
     * Delegates to BackBorkLog for consistent log retrieval.
     * Falls back to direct file reading if BackBorkLog unavailable.
     * 
     * @param string $user User requesting logs
     * @param bool $isRoot Whether user is root (sees all logs)
     * @param int $page Page number for pagination (1-indexed)
     * @param int $limit Items per page
     * @param string $filter Filter type: 'all', 'error', 'backup', 'restore'
     * @return array Paginated log entries with metadata
     */
    public function getLogs($user, $isRoot, $page = 1, $limit = 50, $filter = 'all') {
        // Delegate to centralised logger for consistency
        if (class_exists('BackBorkLog')) {
            return BackBorkLog::getLogs($user, $isRoot, $page, $limit, $filter);
        }

        // Fallback behaviour (should rarely be used - only if BackBorkLog not loaded)
        $logFile = self::LOG_DIR . '/operations.log';
        $logs = [];
        
        // Return empty if log file doesn't exist
        if (!file_exists($logFile)) {
            return ['logs' => [], 'total_pages' => 0, 'current_page' => 1];
        }
        
        // Read all log lines and reverse for most recent first
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines);
        
        // Process each log entry
        foreach ($lines as $line) {
            // Parse JSON log entry
            $entry = json_decode($line, true);
            if (!$entry) continue;
            
            // Non-root users can only see their own logs
            if (!$isRoot && isset($entry['user']) && $entry['user'] !== $user) {
                continue;
            }
            
            // Apply type filter if not 'all'
            if ($filter !== 'all') {
                if ($filter === 'error' && $entry['status'] !== 'error') continue;
                if ($filter === 'backup' && $entry['type'] !== 'backup') continue;
                if ($filter === 'restore' && $entry['type'] !== 'restore') continue;
            }
            
            // Format entry for display
            $logs[] = [
                'timestamp' => $entry['timestamp'],
                'type' => $entry['type'],
                'account' => is_array($entry['accounts']) ? implode(', ', $entry['accounts']) : $entry['accounts'],
                'user' => $entry['user'],
                'status' => $entry['status'],
                'message' => $entry['message']
            ];
        }
        
        // Calculate pagination
        $totalPages = ceil(count($logs) / $limit);
        $offset = ($page - 1) * $limit;
        $pagedLogs = array_slice($logs, $offset, $limit);
        
        return [
            'logs' => $pagedLogs,
            'total_pages' => $totalPages,
            'current_page' => $page
        ];
    }
    
    /**
     * Format file size in human-readable units.
     * Converts bytes to appropriate unit (B, KB, MB, GB, TB).
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size with unit (e.g., "15.3 MB")
     */
    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);  // Ensure non-negative
        
        // Calculate appropriate unit power (base 1024)
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);  // Cap at TB
        
        // Convert to selected unit
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Format duration in human-readable format.
     * Shows seconds for short durations, minutes+seconds for medium,
     * or hours+minutes for long operations.
     * 
     * @param float $seconds Duration in seconds (can be fractional)
     * @return string Formatted duration (e.g., "45s", "2m 30s", "1h 15m")
     */
    private function formatDuration($seconds) {
        $seconds = (int) round($seconds);
        
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        
        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return $secs > 0 ? "{$minutes}m {$secs}s" : "{$minutes}m";
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
    }
    
    /**
     * Write a message to the backup progress log file.
     * Used for real-time progress tracking during backup operations.
     * 
     * @param string|null $logFile Path to log file (null to skip logging)
     * @param string $message Message to write
     */
    private function writeBackupLog($logFile, $message) {
        if ($logFile === null) {
            return;
        }
        
        $timestamp = date('H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Set the requestor identifier (used by runner.php to pass original IP)
     * 
     * @param string $requestor IP address or identifier
     */
    public function setRequestor($requestor) {
        $this->requestorOverride = $requestor;
    }
    
    /**
     * Get requestor information (IP or 'cron' for CLI).
     * Uses override if set (for background jobs started from GUI).
     * 
     * @return string Requestor identifier
     */
    private function getRequestor() {
        // Use override if set (from runner.php for manual jobs)
        if ($this->requestorOverride !== null) {
            return $this->requestorOverride;
        }
        
        if (php_sapi_name() === 'cli') {
            return 'cron';
        }
        
        return isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
            ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
            : ($_SERVER['REMOTE_ADDR'] ?? 'local');
    }
    
    /**
     * Recursively delete a directory and its contents.
     * Used to clean up cpmove directories after compression.
     * 
     * @param string $dir Directory path to delete
     * @return bool True on success
     */
    private function recursiveDelete($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }
}
