<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Centralised logging system for all BackBork operations and events.
 *   Provides structured JSON logging with operation tracking and auditing.
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

// Ensure version constant is available
if (!defined('BACKBORK_VERSION')) {
    require_once(__DIR__ . '/../version.php');
}

/**
 * Class BackBorkLog
 * 
 * Static logging class providing centralised operation logging.
 * All methods are static for easy access throughout the application.
 */
class BackBorkLog {
    
    // ========================================================================
    // CONSTANTS
    // ========================================================================
    
    /** Directory for log storage */
    const LOG_DIR = '/usr/local/cpanel/3rdparty/backbork/logs';
    
    /** Main operations log file (JSON lines format) */
    const LOG_FILE = '/usr/local/cpanel/3rdparty/backbork/logs/operations.log';
    
    // ========================================================================
    // REQUESTOR DETECTION
    // ========================================================================

    /**
     * Get the requestor identifier (source of the request)
     * 
     * Determines who/what initiated the request:
     * - IP address for web requests (handles proxies)
     * - 'cron' for CLI/scheduled execution
     * - 'local' as fallback
     * 
     * @return string Requestor identifier
     */
    public static function getRequestor() {
        // Check for forwarded IP (request behind proxy/load balancer)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // X-Forwarded-For can contain multiple IPs; use the first (client)
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        
        // Direct remote IP address
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        
        // CLI mode indicates cron job execution
        if (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
            return 'cron';
        }
        
        // Fallback for unknown source
        return 'local';
    }
    
    // ========================================================================
    // LOG WRITING
    // ========================================================================

    /**
     * Log an operation event
     * 
     * Writes a structured JSON log entry for auditing and troubleshooting.
     * Each entry is appended as a single line (JSON Lines format).
     * 
     * @param string $user Username performing the action
     * @param string $type Event type (backup, restore, queue_add, schedule_create, etc.)
     * @param array|mixed $items Affected items (accounts, job IDs, etc.)
     * @param bool $success Whether the operation succeeded
     * @param string $message Human-readable description
     * @param string $requestor Source IP/identifier (auto-detected if empty)
     * @param string $jobID Optional job ID for linking to verbose logs
     */
    public static function logEvent($user, $type, $items = [], $success = true, $message = '', $requestor = '', $jobID = '') {
        // Ensure log directory exists
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0750, true);
        }
        
        // Debug: Confirm logEvent was called
        BackBorkConfig::debugLog('BackBorkLog::logEvent called: user=' . $user . ' type=' . $type . ' success=' . ($success ? 'true' : 'false'));

        // Auto-detect requestor if not provided
        if (empty($requestor)) {
            $requestor = self::getRequestor();
        }

        // Build structured log entry
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),           // When the event occurred
            'user'      => $user,                          // Who performed the action
            'type'      => $type,                          // Event type for filtering
            'items'     => is_array($items) ? $items : [$items],  // Affected items
            'success'   => (bool)$success,                 // Success/failure status
            'message'   => $message,                       // Human-readable message
            'requestor' => $requestor                      // Source IP or 'cron'
        ];
        
        // Add job_id if provided (for linking to verbose logs)
        if (!empty($jobID)) {
            $entry['job_id'] = $jobID;
        }

        // Write log entry as JSON line with file locking
        $line = json_encode($entry) . "\n";
        $written = @file_put_contents(self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
        
        if ($written === false) {
            // Log write failed - use debug log as fallback (only if debug enabled)
            BackBorkConfig::debugLog('Failed to write operations log. Fallback: ' . json_encode($entry));
        } else {
            // Set readable permissions (owner write, world read)
            @chmod(self::LOG_FILE, 0644);
        }
    }
    
    // ========================================================================
    // LOG RETRIEVAL
    // ========================================================================

    /**
     * Retrieve operation logs with pagination and filtering
     * 
     * Reads the operations log and returns structured results.
     * Non-root users only see their own log entries.
     * 
     * @param string $user Username requesting logs
     * @param bool $isRoot Whether user has root privileges
     * @param int $page Page number (1-indexed)
     * @param int $limit Items per page
     * @param string $filter Filter type: 'all', 'error', 'success', 'backup', 'restore', etc.
     * @param string $accountFilter Filter by account (partial match)
     * @return array Result with 'logs', 'total_pages', 'current_page', 'accounts'
     */
    public static function getLogs($user, $isRoot, $page = 1, $limit = 50, $filter = 'all', $accountFilter = '') {
        $logFile = self::LOG_FILE;
        $logs = [];
        $allAccounts = [];  // Track all unique accounts for filter dropdown

        // No log file yet
        if (!file_exists($logFile)) {
            return ['logs' => [], 'total_pages' => 0, 'current_page' => 1, 'accounts' => []];
        }

        // Read all log lines and reverse for newest-first display
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines);

        // Process each log entry
        foreach ($lines as $line) {
            // Parse JSON entry
            $entry = json_decode($line, true);
            if (!$entry) continue;

            // Security: Non-root users can only see their own logs
            if (!$isRoot && isset($entry['user']) && $entry['user'] !== $user) {
                continue;
            }

            // Extract status and type for filtering
            $status = (isset($entry['success']) && $entry['success']) ? 'success' : 'error';
            $type = $entry['type'] ?? 'event';
            
            // Format items list for display
            $items = $entry['items'] ?? [];
            $account = is_array($items) ? implode(', ', $items) : $items;
            
            // Collect unique accounts for filter dropdown (before applying account filter)
            if (!empty($items) && is_array($items)) {
                foreach ($items as $item) {
                    if (!empty($item) && !in_array($item, $allAccounts)) {
                        $allAccounts[] = $item;
                    }
                }
            }

            // Apply type filter
            if ($filter !== 'all') {
                // Status filters
                if ($filter === 'error' && $status !== 'error') continue;
                if ($filter === 'success' && $status !== 'success') continue;
                // Type filters - exact match
                if (!in_array($filter, ['error', 'success']) && $type !== $filter) continue;
            }
            
            // Apply account filter (partial match, case-insensitive)
            if (!empty($accountFilter)) {
                $accountLower = strtolower($account);
                $filterLower = strtolower($accountFilter);
                if (strpos($accountLower, $filterLower) === false) continue;
            }

            // Build display-friendly log entry
            $logs[] = [
                'timestamp' => $entry['timestamp'] ?? '',
                'type' => $type,
                'account' => $account,
                'user' => $entry['user'] ?? '',
                'requestor' => $entry['requestor'] ?? 'N/A',
                'status' => $status,
                'message' => $entry['message'] ?? '',
                'job_id' => $entry['job_id'] ?? ''
            ];
        }
        
        // Sort accounts alphabetically
        sort($allAccounts);

        // Calculate pagination
        $totalPages = ceil(count($logs) / $limit);
        $offset = ($page - 1) * $limit;
        $pagedLogs = array_slice($logs, $offset, $limit);

        return [
            'logs' => $pagedLogs,
            'total_pages' => $totalPages,
            'current_page' => $page,
            'accounts' => $allAccounts
        ];
    }
}
