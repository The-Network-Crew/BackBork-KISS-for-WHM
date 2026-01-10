<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   High-level restore orchestration using backup_restore_manager/restorepkg.
 *   Handles file retrieval, verification, execution, notifications, and logging.
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
 * High-level restore orchestration manager.
 * Coordinates account restoration using WHM's backup_restore_manager or restorepkg.
 * Handles file retrieval, verification, execution, notifications, and logging.
 */
class BackBorkRestoreManager {
    
    // Path to cPanel's restorepkg script (traditional restore method)
    const RESTOREPKG_BIN = '/scripts/restorepkg';
    
    // Path to backup_restore_manager (newer restore method for WHM 11.110+)
    const BACKUP_RESTORE_MANAGER = '/usr/local/cpanel/bin/backup_restore_manager';
    
    // Directory for operation logs
    const LOG_DIR = '/usr/local/cpanel/3rdparty/backbork/logs';
    
    /** @var BackBorkConfig User/global configuration handler */
    private $config;
    
    /** @var BackBorkNotify Email/Slack notification service */
    private $notify;
    
    /** @var BackBorkRetrieval Backup file retrieval service */
    private $retrieval;
    
    /** @var string|null Override requestor (IP or 'cron') - set by runner.php for manual jobs */
    private $requestorOverride = null;
    
    /**
     * Constructor - Initialise all dependencies.
     * Sets up configuration, notification, and retrieval services.
     */
    public function __construct() {
        // Initialise helper services
        $this->config = new BackBorkConfig();
        $this->notify = new BackBorkNotify();
        $this->retrieval = new BackBorkRetrieval();
        
        // Ensure log directory exists for operation history
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0700, true);
        }
    }
    
    /**
     * Restore an account from a backup file.
     * Complete restore workflow: retrieve file, verify, restore, notify, log.
     * Also handles accompanying DB backup files (from mariadb-backup/mysqlbackup).
     * 
     * @param string $backupFile Path to backup file or remote path
     * @param string $destinationID Destination ID where backup is stored
     * @param array $options Restore options (force, newuser, ip)
     * @param string $user User initiating restore (for logging/permissions)
     * @return array Result with success status and details
     */
    public function restoreAccount($backupFile, $destinationID, $options, $user) {
        // Generate restore ID for this operation
        $restoreID = 'restore_' . time() . '_' . substr(md5($backupFile), 0, 8);
        return $this->executeRestore($backupFile, $destinationID, $options, $user, $restoreID);
    }
    
    /**
     * Restore an account with a pre-generated restore ID.
     * Used when restore_id needs to be returned to client before restore starts.
     * 
     * @param string $backupFile Path to backup file or remote path
     * @param string $destinationID Destination ID where backup is stored
     * @param array $options Restore options (force, newuser, ip)
     * @param string $user User initiating restore (for logging/permissions)
     * @param string $restoreID Pre-generated restore ID for log tracking
     * @return array Result with success status and details
     */
    public function restoreAccountWithID($backupFile, $destinationID, $options, $user, $restoreID) {
        return $this->executeRestore($backupFile, $destinationID, $options, $user, $restoreID);
    }
    
    /**
     * Execute the actual restore operation.
     * Internal method that handles the restore workflow.
     * 
     * @param string $backupFile Path to backup file or remote path
     * @param string $destinationID Destination ID where backup is stored
     * @param array $options Restore options (force, newuser, ip)
     * @param string $user User initiating restore (for logging/permissions)
     * @param string $restoreID Unique restore ID for tracking
     * @return array Result with success status and details
     */
    private function executeRestore($backupFile, $destinationID, $options, $user, $restoreID) {
        // Track start time for duration logging
        $restoreStartTime = microtime(true);
        
        // Load user-specific configuration for notifications
        $userConfig = $this->config->getUserConfig($user);
        
        // Get destination info for logging
        $destParser = new BackBorkDestinationsParser();
        $destination = $destParser->getDestinationByID($destinationID);
        
        // Validate destination exists and is enabled
        if (!$destination) {
            return [
                'success' => false,
                'message' => 'Invalid destination',
                'restore_id' => $restoreID
            ];
        }
        
        if (empty($destination['enabled'])) {
            return [
                'success' => false,
                'message' => 'Destination is disabled in WHM',
                'restore_id' => $restoreID
            ];
        }
        
        $destName = $destination['name'] ?? $destinationID;
        $destType = strtolower($destination['type'] ?? 'unknown');
        $isRemote = ($destType !== 'local');
        
        // Use provided restore ID
        $logFile = self::LOG_DIR . '/' . $restoreID . '.log';
        
        // Ensure log directory exists
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0700, true);
        }
        
        // Extract account name from backup filename for logging/notifications
        $account = $this->extractAccountFromFilename(basename($backupFile));
        
        // Start logging
        $this->writeLog($logFile, "=== BACKBORK RESTORE OPERATION ===");
        $this->writeLog($logFile, "Account: {$account}");
        $this->writeLog($logFile, "Backup file: " . basename($backupFile));
        $this->writeLog($logFile, "Source: {$destName} ({$destType})");
        $this->writeLog($logFile, str_repeat('-', 60));
        
        // ====================================================================
        // STEP 1: Retrieve backup file
        // ====================================================================
        if ($isRemote) {
            $this->writeLog($logFile, "Downloading backup from remote destination...");
            $this->writeLog($logFile, "Remote path: {$backupFile}");
        } else {
            $this->writeLog($logFile, "Locating backup file on local storage...");
        }
        
        $retrieveResult = $this->retrieval->retrieveBackup($destinationID, $backupFile);
        
        // Check retrieval success
        if (!$retrieveResult['success']) {
            $this->writeLog($logFile, "ERROR: Retrieval failed - " . ($retrieveResult['message'] ?? 'Unknown error'));
            $durationStr = $this->formatDuration(microtime(true) - $restoreStartTime);
            $logType = $isRemote ? 'restore_remote' : 'restore_local';
            $destInfo = $isRemote ? 'Host: ' . ($destination['host'] ?? $destName) : 'Destination: ' . $destName;
            $this->logOperation($user, $logType, ["{$account} ({$durationStr})"], false, $destInfo . "\nRetrieval failed: " . ($retrieveResult['message'] ?? 'Unknown error'), $restoreID);
            $retrieveResult['restore_id'] = $restoreID;
            $retrieveResult['log_file'] = $logFile;
            return $retrieveResult;
        }
        
        $localPath = $retrieveResult['local_path'];
        $filesToCleanup = [];
        
        // Only add to cleanup if it's a temp file (remote downloads)
        if ($isRemote && strpos($localPath, '/home/backbork_tmp') === 0) {
            $filesToCleanup[] = $localPath;
        }
        
        // Log download success
        $fileSize = $this->formatSize($retrieveResult['size'] ?? filesize($localPath));
        if ($isRemote) {
            $this->writeLog($logFile, "Download complete! Size: {$fileSize}");
            $this->writeLog($logFile, "Local path: {$localPath}");
        } else {
            $this->writeLog($logFile, "Backup file located: {$localPath} ({$fileSize})");
        }
        $this->writeLog($logFile, str_repeat('-', 60));
        
        // ====================================================================
        // STEP 2: Verify backup file
        // ====================================================================
        $this->writeLog($logFile, "Verifying backup file integrity...");
        
        // Verify backup file integrity and format
        $verification = $this->retrieval->verifyBackupFile($localPath);
        if (!$verification['valid']) {
            $this->writeLog($logFile, "ERROR: Invalid backup file - " . $verification['message']);
            $this->cleanupFilesWithLog($filesToCleanup, $logFile);
            $durationStr = $this->formatDuration(microtime(true) - $restoreStartTime);
            $logType = $isRemote ? 'restore_remote' : 'restore_local';
            $destInfo = $isRemote ? 'Host: ' . ($destination['host'] ?? $destName) : 'Destination: ' . $destName;
            $this->logOperation($user, $logType, ["{$account} ({$durationStr})"], false, $destInfo . "\nInvalid backup file: " . $verification['message'], $restoreID);
            return ['success' => false, 'message' => 'Invalid backup file: ' . $verification['message'], 'restore_id' => $restoreID, 'log_file' => $logFile];
        }
        
        $this->writeLog($logFile, "Backup file verified successfully.");
        $this->writeLog($logFile, str_repeat('-', 60));
        
        // ====================================================================
        // STEP 3: Check for accompanying DB backup
        // ====================================================================
        // Check for accompanying DB backup file (from mariadb-backup/mysqlbackup)
        $dbBackupFile = $this->findDbBackupFile($backupFile, $destinationID);
        $dbLocalPath = null;
        
        if ($dbBackupFile) {
            $this->writeLog($logFile, "Found accompanying database backup: " . basename($dbBackupFile));
            BackBorkConfig::debugLog("Found DB backup file: {$dbBackupFile}");
            
            if ($isRemote) {
                $this->writeLog($logFile, "Downloading database backup...");
            }
            
            $dbRetrieveResult = $this->retrieval->retrieveBackup($destinationID, $dbBackupFile);
            if ($dbRetrieveResult['success']) {
                $dbLocalPath = $dbRetrieveResult['local_path'];
                if ($isRemote && strpos($dbLocalPath, '/home/backbork_tmp') === 0) {
                    $filesToCleanup[] = $dbLocalPath;
                }
                $dbSize = $this->formatSize($dbRetrieveResult['size'] ?? filesize($dbLocalPath));
                $this->writeLog($logFile, "Database backup ready ({$dbSize})");
            } else {
                $this->writeLog($logFile, "Warning: Could not retrieve database backup - " . ($dbRetrieveResult['message'] ?? 'Unknown error'));
            }
            $this->writeLog($logFile, str_repeat('-', 60));
        }
        
        // ====================================================================
        // STEP 4: Send start notification
        // ====================================================================
        
        // Send start notification if user has enabled it
        $notifyStart = !empty($userConfig['notify_restore_start']);
        if ($notifyStart) {
            $this->writeLog($logFile, "Sending restore start notification...");
            $this->notify->sendNotification(
                'restore_start',
                [
                    'account' => $account,
                    'backup_file' => $backupFile,
                    'destination' => $destinationID,
                    'user' => $user,
                    'requestor' => $this->getRequestor()
                ],
                $userConfig
            );
        }
        
        // ====================================================================
        // STEP 5: Restore main backup (includes schema if hot DB was used)
        // ====================================================================
        $this->writeLog($logFile, "Restoring account using restorepkg...");
        $this->writeLog($logFile, "Source: " . basename($localPath));
        
        // Pass account name to restore options so restorepkg gets correct --user=
        $options['account'] = $account;
        
        $result = $this->executeRestoreTool($localPath, $options, $logFile);
        
        if (!$result['success']) {
            $this->writeLog($logFile, "ERROR: Restore failed - " . $result['message']);
            $this->cleanupFilesWithLog($filesToCleanup, $logFile);
            $durationStr = $this->formatDuration(microtime(true) - $restoreStartTime);
            $logType = $isRemote ? 'restore_remote' : 'restore_local';
            $destInfo = $isRemote ? 'Host: ' . ($destination['host'] ?? $destName) : 'Destination: ' . $destName;
            $this->logOperation($user, $logType, ["{$account} ({$durationStr})"], false, $destInfo . "\n" . $result['message'], $restoreID);
            $result['restore_id'] = $restoreID;
            $result['log_file'] = $logFile;
            return $result;
        }
        
        $this->writeLog($logFile, "Account restore completed successfully.");
        $this->writeLog($logFile, str_repeat('-', 60));
        
        // ====================================================================
        // STEP 6: Restore DB data if hot backup file exists
        // ====================================================================
        if ($dbLocalPath && file_exists($dbLocalPath)) {
            $this->writeLog($logFile, "Restoring database data from hot backup...");
            BackBorkConfig::debugLog("Restoring database data from: {$dbLocalPath}");
            
            $sqlRestore = new BackBorkSQLRestore();
            $dbResult = $sqlRestore->restoreDatabases($account, $dbLocalPath, $userConfig);
            
            if (!$dbResult['success']) {
                // DB restore failed but main restore succeeded - partial success
                $this->writeLog($logFile, "WARNING: Database data restore failed - " . ($dbResult['message'] ?? 'Unknown'));
                $result['message'] .= ' (Warning: DB data restore failed: ' . ($dbResult['message'] ?? 'Unknown') . ')';
                $result['db_restore_failed'] = true;
            } else {
                $this->writeLog($logFile, "Database data restored successfully.");
                $result['message'] .= ' (DB data restored)';
            }
            $this->writeLog($logFile, str_repeat('-', 60));
        }
        
        // ====================================================================
        // STEP 7: Cleanup temp files
        // ====================================================================
        $this->cleanupFilesWithLog($filesToCleanup, $logFile);
        
        // Log the operation to centralised log (with duration)
        // Type includes _local or _remote suffix based on destination
        $durationStr = $this->formatDuration(microtime(true) - $restoreStartTime);
        $logType = $isRemote ? 'restore_remote' : 'restore_local';
        $destInfo = $isRemote ? 'Host: ' . ($destination['host'] ?? $destName) : 'Destination: ' . $destName;
        $this->logOperation($user, $logType, ["{$account} ({$durationStr})"], $result['success'], $destInfo . "\n" . $result['message'], $restoreID);
        
        // ====================================================================
        // STEP 8: Send completion notification
        // ====================================================================
        // Check notification preferences
        $notifySuccess = !empty($userConfig['notify_restore_success']);
        $notifyFailure = !empty($userConfig['notify_restore_failure']);
        
        // Send success notification if restore succeeded and notifications enabled
        if ($result['success'] && $notifySuccess) {
            $this->writeLog($logFile, "Sending restore success notification...");
            $this->notify->sendNotification(
                'restore_success',
                [
                    'account' => $account,
                    'backup_file' => $backupFile,
                    'user' => $user,
                    'requestor' => $this->getRequestor()
                ],
                $userConfig
            );
        // Send failure notification if restore failed and notifications enabled
        } elseif (!$result['success'] && $notifyFailure) {
            $this->writeLog($logFile, "Sending restore failure notification...");
            $this->notify->sendNotification(
                'restore_failure',
                [
                    'account' => $account,
                    'backup_file' => $backupFile,
                    'user' => $user,
                    'requestor' => $this->getRequestor(),
                    'error' => $result['message']
                ],
                $userConfig
            );
        }
        
        // Final completion message
        $this->writeLog($logFile, str_repeat('=', 60));
        $this->writeLog($logFile, "RESTORE COMPLETED SUCCESSFULLY");
        $this->writeLog($logFile, str_repeat('=', 60));
        
        $result['restore_id'] = $restoreID;
        $result['log_file'] = $logFile;
        return $result;
    }
    
    /**
     * Find accompanying DB backup file for a backup.
     * Looks for db-backup-{account}_{timestamp}.tar.gz matching the main backup.
     * 
     * @param string $backupFile Main backup filename
     * @param string $destinationID Destination to search
     * @return string|null DB backup filename if found, null otherwise
     */
    private function findDbBackupFile($backupFile, $destinationID) {
        // Extract account and timestamp from main backup filename
        // Official format: backup-MM.DD.YYYY_HH-MM-SS_USER.tar.gz
        $basename = basename($backupFile);
        
        if (!preg_match('/^backup-(\\d{2})\\.(\\d{2})\\.(\\d{4})_(\\d{2})-(\\d{2})-(\\d{2})_([a-z0-9_]+)\\.tar(\\.gz)?$/i', $basename, $matches)) {
            return null;
        }
        
        $account = $matches[7];
        // Convert to db-backup timestamp format: YYYY-MM-DD_HH-MM-SS
        $timestamp = "{$matches[3]}-{$matches[1]}-{$matches[2]}_{$matches[4]}-{$matches[5]}-{$matches[6]}";
        
        $dbBackupName = "db-backup-{$account}_{$timestamp}.tar.gz";
        
        // Check if DB backup exists in same directory
        $dir = dirname($backupFile);
        $dbBackupPath = ($dir === '.' || $dir === '') ? $dbBackupName : $dir . '/' . $dbBackupName;
        
        // Verify file exists at destination
        $destination = (new BackBorkDestinationsParser())->getDestinationByID($destinationID);
        if (!$destination) {
            return null;
        }
        
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        if ($transport->fileExists($dbBackupPath, $destination)) {
            return $dbBackupPath;
        }
        
        return null;
    }
    
    /**
     * Clean up temporary files.
     * 
     * @param array $files List of file paths to delete
     */
    private function cleanupFiles($files) {
        foreach ($files as $file) {
            if ($file && strpos($file, '/home/backbork_tmp') === 0 && file_exists($file)) {
                unlink($file);
                BackBorkConfig::debugLog("Cleaned up temp file: {$file}");
            }
        }
    }
    
    /**
     * Clean up temporary files with logging.
     * 
     * @param array $files List of file paths to delete
     * @param string $logFile Path to log file
     */
    private function cleanupFilesWithLog($files, $logFile) {
        if (empty($files)) {
            return;
        }
        
        $this->writeLog($logFile, "Cleaning up temporary files...");
        
        foreach ($files as $file) {
            if ($file && strpos($file, '/home/backbork_tmp') === 0 && file_exists($file)) {
                unlink($file);
                $this->writeLog($logFile, "Removed temporary file: " . basename($file));
                BackBorkConfig::debugLog("Cleaned up temp file: {$file}");
            }
        }
        
        $this->writeLog($logFile, "Cleanup complete.");
        $this->writeLog($logFile, str_repeat('-', 60));
    }
    
    /**
     * Execute restore using appropriate WHM tool.
     * Automatically selects backup_restore_manager or restorepkg based on WHM version.
     * 
     * @param string $backupPath Absolute path to backup file
     * @param array $options Restore options (force, newuser, ip)
     * @param string|null $logFile Path to existing log file (optional)
     * @return array Result with success status and details
     */
    private function executeRestoreTool($backupPath, $options = [], $logFile = null) {
        // Always use restorepkg for direct file restoration
        // backup_restore_manager is queue-based and designed for restore points,
        // not direct file restoration. restorepkg supports --disable=Module for
        // granular control and is the documented approach for file-based restores.
        return $this->restoreViaRestorepkg($backupPath, $options, $logFile);
    }
    
    /**
     * Restore via restorepkg (traditional method).
     * Works on all WHM versions with granular --disable=Module control.
     * 
     * Note: backup_restore_manager is queue-based and designed for restore points
     * (e.g., selective restoration to existing live accounts). For direct file
     * restoration, restorepkg is the documented and recommended approach.
     * 
     * @param string $backupPath Absolute path to backup file
     * @param array $options Restore options (force, newuser, homedir, mysql, mail, etc.)
     * @param string|null $existingLogFile Path to existing log file to append to
     * @return array Result with success status and details
     */
    private function restoreViaRestorepkg($backupPath, $options = [], $existingLogFile = null) {
        // Build restorepkg command - restorepkg parses account from official filename formats.
        // We use: backup-{MM.DD.YYYY}_{HH-MM-SS}_{USER}.tar.gz
        $command = self::RESTOREPKG_BIN;
        
        // Build list of modules to disable based on unchecked options
        // restorepkg uses --disable=Module1,Module2 format
        $disableModules = [];
        
        if (isset($options['homedir']) && $options['homedir'] === false) {
            $disableModules[] = 'Homedir';
        }
        if (isset($options['mysql']) && $options['mysql'] === false) {
            $disableModules[] = 'Mysql';
        }
        if (isset($options['mail']) && $options['mail'] === false) {
            $disableModules[] = 'Mail';
            $disableModules[] = 'MailRouting';
        }
        if (isset($options['ssl']) && $options['ssl'] === false) {
            $disableModules[] = 'SSL';
        }
        if (isset($options['cron']) && $options['cron'] === false) {
            $disableModules[] = 'Cron';
        }
        if (isset($options['dns']) && $options['dns'] === false) {
            $disableModules[] = 'ZoneFile';
        }
        if (isset($options['subdomains']) && $options['subdomains'] === false) {
            // Domains module handles subdomains, parked domains, and addon domains together
            // We'll only disable if both subdomains AND addon_domains are false
            if (isset($options['addon_domains']) && $options['addon_domains'] === false) {
                $disableModules[] = 'Domains';
            }
        }
        
        // Add disable flag if any modules should be skipped
        if (!empty($disableModules)) {
            $command .= ' --disable=' . escapeshellarg(implode(',', $disableModules));
        }
        
        // Add --skipaccount to skip account verification (required before path)
        $command .= ' --skipaccount';
        
        // Add backup file path (must be last argument)
        $command .= ' ' . escapeshellarg($backupPath);
        
        // Use existing log file or generate new one
        if ($existingLogFile) {
            $logFile = $existingLogFile;
            $restoreID = basename($logFile, '.log');
        } else {
            // Generate unique restore ID for log tracking
            $restoreID = 'restore_' . time() . '_' . substr(md5($backupPath), 0, 8);
            $logFile = self::LOG_DIR . '/' . $restoreID . '.log';
            
            // Write initial status to log (only if creating new log)
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Starting restore...\n");
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Backup file: " . basename($backupPath) . "\n", FILE_APPEND);
            if (!empty($disableModules)) {
                file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Disabled modules: " . implode(', ', $disableModules) . "\n", FILE_APPEND);
            }
            file_put_contents($logFile, str_repeat('-', 60) . "\n", FILE_APPEND);
        }
        
        // Log disabled modules if any (append to existing log)
        if (!empty($disableModules) && $existingLogFile) {
            $this->writeLog($logFile, "Disabled modules: " . implode(', ', $disableModules));
        }
        
        // Log the FULL command being executed for debugging
        BackBorkConfig::debugLog("RESTOREPKG FULL COMMAND: " . $command);
        
        // Execute command with real-time output capture using proc_open
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to start restore process\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => 'Failed to start restore process',
                'restore_id' => $restoreID,
                'log_file' => $logFile
            ];
        }
        
        // Close stdin - we don't need to write to it
        fclose($pipes[0]);
        
        // Set streams to non-blocking for real-time reading
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $output = [];
        $allOutput = '';
        
        // Read output in real-time and write to log file
        while (true) {
            $stdout = fgets($pipes[1]);
            $stderr = fgets($pipes[2]);
            
            if ($stdout !== false) {
                $line = trim($stdout);
                if ($line !== '') {
                    $output[] = $line;
                    $allOutput .= $line . "\n";
                    // Write to log with timestamp for important lines
                    if (preg_match('/^(Restoring|Creating|Extracting|Installing|Updating|Running|Completed|Error|Warning|Failed)/i', $line)) {
                        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $line . "\n", FILE_APPEND);
                    } else {
                        file_put_contents($logFile, $line . "\n", FILE_APPEND);
                    }
                }
            }
            
            if ($stderr !== false) {
                $line = trim($stderr);
                if ($line !== '') {
                    $output[] = "[STDERR] " . $line;
                    $allOutput .= "[STDERR] " . $line . "\n";
                    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [STDERR] " . $line . "\n", FILE_APPEND);
                }
            }
            
            // Check if process has finished
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Read any remaining output
                while (($line = fgets($pipes[1])) !== false) {
                    $output[] = trim($line);
                    file_put_contents($logFile, trim($line) . "\n", FILE_APPEND);
                }
                while (($line = fgets($pipes[2])) !== false) {
                    $output[] = "[STDERR] " . trim($line);
                    file_put_contents($logFile, "[STDERR] " . trim($line) . "\n", FILE_APPEND);
                }
                break;
            }
            
            // Small delay to prevent CPU spinning
            usleep(50000); // 50ms
        }
        
        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        // Get exit code
        $returnCode = proc_close($process);
        
        // Write final status
        file_put_contents($logFile, str_repeat('-', 60) . "\n", FILE_APPEND);
        if ($returnCode !== 0) {
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] restorepkg FAILED (exit code: {$returnCode})\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => 'Restore failed (exit code ' . $returnCode . ')',
                'restore_id' => $restoreID,
                'log_file' => $logFile,
                'output' => $output,
                'log' => $allOutput,
                'return_code' => $returnCode
            ];
        }
        
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] restorepkg completed successfully.\n", FILE_APPEND);
        
        return [
            'success' => true,
            'message' => 'Restore completed successfully',
            'restore_id' => $restoreID,
            'log_file' => $logFile,
            'output' => $output,
            'log' => $allOutput
        ];
    }
    
    /**
     * Get available restore options for UI display.
     * Returns configuration schema for restore options form.
     * 
     * @param string $backupPath Path to backup file (for potential analysis)
     * @return array Available restore options with labels and types
     */
    public function getRestoreOptions($backupPath) {
        $options = [
            'force' => [
                'label' => 'Force restore (overwrite existing)',
                'type' => 'boolean',
                'default' => false
            ],
            'newuser' => [
                'label' => 'Restore as different username',
                'type' => 'string',
                'default' => ''
            ],
            'ip' => [
                'label' => 'Assign to specific IP',
                'type' => 'string',
                'default' => ''
            ]
        ];
        
        return $options;
    }
    
    /**
     * Preview backup contents without restoring.
     * Analyzes archive to show what data is included.
     * 
     * @param string $backupPath Absolute path to backup file
     * @return array Backup contents summary with flags for each data type
     */
    public function previewBackup($backupPath) {
        // List archive contents using tar
        $output = [];
        exec('tar -tzf ' . escapeshellarg($backupPath) . ' 2>/dev/null', $output);
        
        // Initialise preview data with content flags
        $preview = [
            'total_files' => count($output),
            'has_homedir' => false,     // Home directory present
            'has_mysql' => false,       // MySQL databases present
            'has_pgsql' => false,       // PostgreSQL databases present
            'has_email' => false,       // Email data present
            'has_ssl' => false,         // SSL certificates present
            'has_dnszones' => false,    // DNS zones present
            'account' => null,          // Extracted account name
            'sample_files' => array_slice($output, 0, 50)  // First 50 files for preview
        ];
        
        // Analyze file listing to detect content types
        foreach ($output as $line) {
            // Extract account name from cpmove directory
            if (preg_match('/cpmove-([a-z0-9_]+)/', $line, $matches)) {
                $preview['account'] = $matches[1];
            }
            // Check for various content types by path patterns
            if (strpos($line, 'homedir') !== false) $preview['has_homedir'] = true;
            if (strpos($line, 'mysql') !== false) $preview['has_mysql'] = true;
            if (strpos($line, 'pgsql') !== false || strpos($line, 'postgres') !== false) $preview['has_pgsql'] = true;
            if (strpos($line, 'mail') !== false || strpos($line, '/et/') !== false) $preview['has_email'] = true;
            if (strpos($line, 'ssl') !== false || strpos($line, 'sslkeys') !== false) $preview['has_ssl'] = true;
            if (strpos($line, 'dnszones') !== false) $preview['has_dnszones'] = true;
        }
        
        // Add file size information
        $preview['size'] = filesize($backupPath);
        $preview['size_formatted'] = $this->formatSize($preview['size']);
        
        return $preview;
    }
    
    /**
     * Check if account already exists on server.
     * Used to warn about potential overwrites before restore.
     * 
     * @param string $account Account username to check
     * @return bool True if account exists
     */
    public function accountExists($account) {
        // Check passwd file for user entry
        $passwd = @file_get_contents('/etc/passwd');
        if ($passwd && preg_match('/^' . preg_quote($account, '/') . ':/m', $passwd)) {
            return true;
        }
        
        // Check for home directory existence
        if (is_dir('/home/' . $account)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Extract account name from backup filename.
     * Parses official cPanel backup naming convention:
     *   - backup-{MM.DD.YYYY}_{HH-MM-SS}_{USER}.tar.gz
     * 
     * @param string $filename Backup filename
     * @return string|null Account name or null if not parseable
     */
    private function extractAccountFromFilename($filename) {
        // Official format: backup-MM.DD.YYYY_HH-MM-SS_USER.tar.gz
        if (preg_match('/^backup-\d{2}\.\d{2}\.\d{4}_\d{2}-\d{2}-\d{2}_([a-z0-9_]+)\.tar(\.gz)?$/i', $filename, $matches)) {
            return $matches[1];
        }
        return null;
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
     * Log a restore operation to centralised log system.
     * Delegates to BackBorkLog for consistent logging format.
     * 
     * @param string $user User who performed the operation
     * @param string $type Operation type ('restore')
     * @param array $accounts Affected accounts (array of usernames)
     * @param bool $success Whether operation succeeded
     * @param string $message Details/error message
     * @param string $jobID Optional job ID for linking to verbose logs
     */
    private function logOperation($user, $type, $accounts, $success, $message, $jobID = '') {
        // Only log if BackBorkLog class is available
        if (class_exists('BackBorkLog')) {
            // Use override if set, otherwise detect requestor
            $logRequestor = $this->getRequestor();
            
            // Log event through centralised logging
            BackBorkLog::logEvent($user, $type === 'restore' ? 'restore' : $type, $accounts, $success, $message, $logRequestor, $jobID);
        }
    }
    
    /**
     * Write a timestamped message to the restore log file.
     * 
     * @param string $logFile Path to the log file
     * @param string $message Message to log
     */
    private function writeLog($logFile, $message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
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
}
