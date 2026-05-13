<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Background job runner executed as a separate process for backup/restore.
 *   Reads job files, executes the appropriate operation, and handles logging.
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

// Must be run from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

// Check for job file argument
if (!isset($argv[1]) || empty($argv[1])) {
    die("Usage: php runner.php /path/to/job.job\n");
}

$jobFile = $argv[1];

// Verify job file exists
if (!file_exists($jobFile)) {
    die("Job file not found: $jobFile\n");
}

// Read and parse job file
$jobData = json_decode(file_get_contents($jobFile), true);
if (!$jobData) {
    die("Invalid job file format\n");
}

// Delete job file immediately to prevent re-execution
unlink($jobFile);

// Load BackBork (runner.php is now in api/, so parent dir is backbork/)
$baseDir = dirname(__DIR__);
require_once($baseDir . '/version.php');
require_once($baseDir . '/app/Bootstrap.php');

// Initialise for CLI mode
BackBorkBootstrap::initCLI();

// Get the log file path
$logDir = '/usr/local/cpanel/3rdparty/backbork/logs';
$operationID = isset($jobData['backup_id']) ? $jobData['backup_id'] : (isset($jobData['restore_id']) ? $jobData['restore_id'] : 'unknown');
$logFile = $logDir . '/' . $operationID . '.log';

// Helper function to append to log
function runner_log($message) {
    global $logFile;
    $timestamp = date('H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Process based on job type
$type = isset($jobData['type']) ? $jobData['type'] : '';

try {
    switch ($type) {
        case 'backup':
            runner_log("Background runner started for backup");
            
            $accounts = isset($jobData['accounts']) ? $jobData['accounts'] : [];
            $destinationID = isset($jobData['destination']) ? $jobData['destination'] : '';
            $backupID = $jobData['backup_id'];
            $user = isset($jobData['user']) ? $jobData['user'] : 'root';
            $jobRequestor = isset($jobData['requestor']) ? $jobData['requestor'] : 'cron';
            
            if (empty($accounts)) {
                runner_log("ERROR: No accounts specified");
                break;
            }
            
            $backupManager = new BackBorkBackupManager();
            $backupManager->setRequestor($jobRequestor);
            $backupManager->createBackupWithID($accounts, $destinationID, $user, $backupID);
            
            runner_log("Backup job completed");
            break;
            
        case 'restore':
            runner_log("Background runner started for restore");
            
            $backupFile = isset($jobData['backup_file']) ? $jobData['backup_file'] : '';
            $destinationID = isset($jobData['destination']) ? $jobData['destination'] : '';
            $restoreID = $jobData['restore_id'];
            $user = isset($jobData['user']) ? $jobData['user'] : 'root';
            $options = isset($jobData['options']) ? $jobData['options'] : [];
            $jobRequestor = isset($jobData['requestor']) ? $jobData['requestor'] : 'cron';
            
            if (empty($backupFile)) {
                runner_log("ERROR: No backup file specified");
                break;
            }
            
            $restoreManager = new BackBorkRestoreManager();
            $restoreManager->setRequestor($jobRequestor);
            $restoreManager->restoreAccountWithID($backupFile, $destinationID, $options, $user, $restoreID);
            
            runner_log("Restore job completed");
            break;

        case 'stage_download':
            runner_log("Background runner started for staged download");

            $token         = isset($jobData['token'])         ? $jobData['token']         : '';
            $destinationID = isset($jobData['destination'])   ? $jobData['destination']   : '';
            $backupFile    = isset($jobData['backup_file'])   ? $jobData['backup_file']   : '';
            $stagedPath    = isset($jobData['staged_path'])   ? $jobData['staged_path']   : '';

            if (empty($token) || empty($destinationID) || empty($backupFile) || empty($stagedPath)) {
                runner_log("ERROR: Missing required fields for stage_download");
                break;
            }

            $retrieval = new BackBorkRetrieval();

            // Fetch the backup from the remote destination into the pre-determined staging path
            $result = $retrieval->retrieveBackup($destinationID, $backupFile, $stagedPath);

            // Re-read manifest and update status; manifest was written by router.php before job spawn
            $manifest = $retrieval->readTokenManifest($token);
            if (!$manifest) {
                runner_log("ERROR: Token manifest not found or already expired for token " . $token);
                break;
            }

            if ($result['success']) {
                $manifest['status'] = 'ready';
                runner_log("Staged download ready: " . $stagedPath);
            } else {
                $manifest['status'] = 'failed';
                $manifest['error']  = $result['message'] ?? 'Unknown error during retrieval';
                runner_log("ERROR: Staged download failed: " . $manifest['error']);
            }

            $retrieval->writeTokenManifest($manifest);
            runner_log("Stage download job completed");
            break;
            
        default:
            runner_log("ERROR: Unknown job type: $type");
    }
} catch (Exception $e) {
    runner_log("ERROR: " . $e->getMessage());
}

exit(0);
