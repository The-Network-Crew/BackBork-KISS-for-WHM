<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Cron handler that processes scheduled backups and queue items.
 *   Called via system cron to execute pending backup/restore operations.
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
// CLI ONLY - This script must only be run from command line via cron
// ============================================================================
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

// Load version constant for logging
require_once(__DIR__ . '/../version.php');

// Load Bootstrap in CLI mode (initialises all classes and dependencies)
require_once(__DIR__ . '/../app/Bootstrap.php');

// Initialise application for CLI environment (no session handling)
BackBorkBootstrap::initCLI();

// ============================================================================
// CONSTANTS
// ============================================================================

// File to track last successful cron execution time
define('CRON_LAST_RUN_FILE', '/usr/local/cpanel/3rdparty/backbork/cron_last_run');

// Health check interval (30 minutes) - alert if cron hasn't run in this time
define('CRON_HEALTH_CHECK_INTERVAL', 1800);

// ============================================================================
// MAIN EXECUTION
// ============================================================================

// Create queue processor instance for handling backup jobs
$processor = new BackBorkQueueProcessor();

// Log start of cron run
BackBorkConfig::debugLog('Cron handler started at ' . date('Y-m-d H:i:s'));
echo '[BackBork] Cron handler started at ' . date('Y-m-d H:i:s') . "\n";

// Update last run timestamp (for health check monitoring)
file_put_contents(CRON_LAST_RUN_FILE, time());

// ============================================================================
// SPECIAL COMMANDS - Handle these BEFORE the lock check (they don't need it)
// ============================================================================

// Handle special 'cleanup' command for maintenance tasks
if (isset($argv[1]) && $argv[1] === 'cleanup') {
    runCleanup($processor);
    exit(0);
}

// Handle special 'summary' command for daily summary notifications
if (isset($argv[1]) && $argv[1] === 'summary') {
    sendDailySummary();
    exit(0);
}

// ============================================================================
// QUEUE PROCESSING - Requires exclusive lock to prevent concurrent execution
// ============================================================================

// Prevent concurrent execution - skip if already running
BackBorkConfig::debugLog('Checking if processor is running...');
if ($processor->isRunning()) {
    BackBorkConfig::debugLog('Queue processor already running, skipping');
    exit(0);
}
BackBorkConfig::debugLog('Processor not running, continuing...');

// Check cron health and send alerts if issues detected
performHealthCheck();

// ============================================================================
// SCHEDULE PROCESSING - Check for due scheduled backups and queue them
// ============================================================================
$scheduleResults = $processor->processSchedules();
if (!empty($scheduleResults['scheduled'])) {
    BackBorkConfig::debugLog('Scheduled items queued: ' . count($scheduleResults['scheduled']));
}

// ============================================================================
// QUEUE PROCESSING - Execute queued backup/restore jobs
// ============================================================================
$queueResults = $processor->processQueue();
BackBorkConfig::debugLog('Queue processing complete: ' . $queueResults['message']);

// Log significant events via BackBorkLog for history tracking
if (class_exists('BackBorkLog')) {
    $processed = $queueResults['processed'] ?? 0;
    $failed = $queueResults['failed'] ?? 0;
    $accounts = $queueResults['accounts'] ?? [];
    $logMessage = $queueResults['message'];
    
    // Only log if something was processed or there were errors
    if ($processed > 0 || $failed > 0 || !$queueResults['success']) {
        BackBorkLog::logEvent('root', 'queue_cron_process', $accounts, $queueResults['success'], $logMessage, 'cron');
    }
    
    // Send queue failure notifications if any jobs failed
    if ($failed > 0) {
        sendQueueFailureNotifications($queueResults);
    }
}

// Log current queue statistics
$stats = $processor->getStats();
BackBorkConfig::debugLog('Queue stats - Total: ' . $stats['total'] . 
          ', Queued: ' . $stats['queued'] . 
          ', Failed: ' . $stats['failed']);

// ============================================================================
// RETENTION PRUNING - Delete backups older than schedule retention settings
// ============================================================================
// Runs hourly to honour retention policies even with frequent schedules
$pruneResults = $processor->pruneOldBackups();
if ($pruneResults['pruned'] > 0) {
    BackBorkConfig::debugLog('Retention pruning completed: ' . $pruneResults['message']);
    
    // Send pruning alert if enabled in global config
    sendPruningNotification($pruneResults);
}

// ============================================================================
// CLEANUP - Remove old temporary files from retrieval operations
// ============================================================================
$retrieval = new BackBorkRetrieval();
$cleaned = $retrieval->cleanupTempFiles(24);  // Delete files older than 24 hours
if ($cleaned > 0) {
    BackBorkConfig::debugLog('Cleaned up ' . $cleaned . ' old temp files');
}

// Log completion
BackBorkConfig::debugLog('Cron handler finished at ' . date('Y-m-d H:i:s'));
echo '[BackBork] Cron handler finished at ' . date('Y-m-d H:i:s') . "\n";

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Run maintenance cleanup tasks.
 * Called with 'cleanup' argument: php handler.php cleanup
 * 
 * @param BackBorkQueueProcessor $processor Queue processor instance
 */
function runCleanup($processor) {
    BackBorkConfig::debugLog('Running cleanup tasks...');
    
    // Remove completed queue jobs older than 30 days
    $processor->cleanupCompletedJobs(30);
    
    // Rotate old log files (delete logs older than 30 days)
    $logDir = '/usr/local/cpanel/3rdparty/backbork/logs';
    $maxLogAge = 30 * 24 * 60 * 60; // 30 days in seconds
    
    foreach (glob($logDir . '/*.log') as $logFile) {
        if (filemtime($logFile) < (time() - $maxLogAge)) {
            unlink($logFile);
            BackBorkConfig::debugLog('Removed old log: ' . basename($logFile));
        }
    }
    
    // Clean orphaned temp files from failed/interrupted operations
    $retrieval = new BackBorkRetrieval();
    $cleaned = $retrieval->cleanupTempFiles(24);
    BackBorkConfig::debugLog('Cleanup complete. Removed ' . $cleaned . ' temp files.');
}

/**
 * Perform cron health self-check.
 * Verifies cron file exists and last run was recent.
 * Sends alerts if issues detected.
 */
function performHealthCheck() {
    // File to track when we last sent a health alert (prevent spam)
    $healthFile = '/usr/local/cpanel/3rdparty/backbork/cron_health_notified';
    
    // Check if cron configuration file exists
    if (!file_exists('/etc/cron.d/backbork')) {
        sendHealthAlert('Cron file /etc/cron.d/backbork is missing', $healthFile);
        return;
    }
    
    // Check last run time to detect stuck/failed cron
    if (file_exists(CRON_LAST_RUN_FILE)) {
        $lastRun = (int)file_get_contents(CRON_LAST_RUN_FILE);
        
        // Sanity check: if lastRun is 0 or very old (before 2020), file is likely corrupted
        // The file was just written at line 67, so this indicates a problem
        if ($lastRun < 1577836800) { // 2020-01-01
            BackBorkConfig::debugLog('CRON: cron_last_run file contains invalid timestamp: ' . $lastRun);
            // Don't alert on this edge case - the file will be rewritten on next run
            // Just clear any stale health notification
            if (file_exists($healthFile)) {
                unlink($healthFile);
            }
            return;
        }
        
        $timeSinceLastRun = time() - $lastRun;
        
        // Alert if gap exceeds expected interval
        if ($timeSinceLastRun > CRON_HEALTH_CHECK_INTERVAL) {
            // Format human-readable time delta
            if ($timeSinceLastRun < 3600) {
                $delta = round($timeSinceLastRun / 60) . ' minutes';
            } else {
                $delta = round($timeSinceLastRun / 3600, 1) . ' hours';
            }
            sendHealthAlert(
                'Cron has not run in over ' . $delta,
                $healthFile,
                $lastRun
            );
            return;
        }
    }
    
    // Clear previous health notification flag - we're healthy now
    if (file_exists($healthFile)) {
        unlink($healthFile);
    }
}

/**
 * Send health alert notifications via email and Slack.
 * Rate-limited to prevent notification spam (max once per 24 hours).
 * Only sends if notify_cron_errors is enabled in global config.
 * 
 * @param string $issue Description of the health issue
 * @param string $healthFile Path to notification tracking file
 * @param int|null $lastRun Timestamp of last successful run (optional)
 */
function sendHealthAlert($issue, $healthFile, $lastRun = null) {
    // Rate limiting: Don't re-notify within 24 hours
    if (file_exists($healthFile)) {
        $lastNotified = (int)file_get_contents($healthFile);
        if ((time() - $lastNotified) < 86400) {
            return;
        }
    }
    
    BackBorkConfig::debugLog('HEALTH ALERT: ' . $issue);
    
    // Send via unified root notification system
    $sent = sendRootNotification('cron_health', 'notify_cron_errors', [
        'issue' => $issue,
        'last_run' => $lastRun !== null ? date('Y-m-d H:i:s T', $lastRun) : 'Never'
    ]);
    
    // Record notification timestamp to prevent re-alerting too soon
    if ($sent) {
        file_put_contents($healthFile, time());
    }
}

/**
 * Send daily summary notification via email and Slack.
 * Called with 'summary' argument: php handler.php summary
 * 
 * Iterates through all users who have daily summary enabled and sends
 * them a digest of activity from the past 24 hours.
 * 
 * SECURITY: Each user only receives stats for their own accounts.
 * Root user sees all activity, resellers only see their owned accounts.
 */
function sendDailySummary() {
    BackBorkConfig::debugLog('Generating daily summaries...');
    
    $config = new BackBorkConfig();
    $hostname = gethostname() ?: 'unknown';
    $date = date('Y-m-d');
    $generatedAt = date('Y-m-d H:i:s T');
    
    // Find all users with daily summary enabled
    $usersToNotify = [];
    
    // Check root user
    $rootConfig = $config->getUserConfig('root');
    if (!empty($rootConfig['notify_daily_summary'])) {
        $usersToNotify['root'] = $rootConfig;
    }
    
    // Check reseller users (scan users config directory)
    $usersConfigDir = '/usr/local/cpanel/3rdparty/backbork/users';
    if (is_dir($usersConfigDir)) {
        foreach (glob($usersConfigDir . '/*.json') as $configFile) {
            $username = basename($configFile, '.json');
            if ($username === 'root' || $username === 'global') continue;
            
            $userConfig = $config->getUserConfig($username);
            if (!empty($userConfig['notify_daily_summary'])) {
                $usersToNotify[$username] = $userConfig;
            }
        }
    }
    
    if (empty($usersToNotify)) {
        BackBorkConfig::debugLog('Daily Summary skipped - no users have it enabled');
        return;
    }
    
    BackBorkConfig::debugLog('Sending Daily Summary to ' . count($usersToNotify) . ' user(s)');
    
    // Send summary to each user with THEIR OWN filtered stats
    foreach ($usersToNotify as $username => $userConfig) {
        // Skip if no notification channels configured
        if (empty($userConfig['notify_email']) && empty($userConfig['slack_webhook'])) {
            continue;
        }
        
        // SECURITY: Gather stats filtered for this specific user
        $stats = gatherDailyStats($username);
        
        // Determine status based on THIS USER's stats
        $hasFailures = ($stats['backup_failures'] > 0 || $stats['restore_failures'] > 0);
        $hasActivity = ($stats['total_events'] > 0);
        $statusEmoji = $hasFailures ? '⚠️' : ($hasActivity ? '✅' : 'ℹ️');
        $statusText = $hasFailures ? 'Issues Detected' : ($hasActivity ? 'All Good' : 'No Activity');
        
        sendSummaryToUser($username, $userConfig, $stats, $hostname, $date, $generatedAt, $statusEmoji, $statusText);
    }
    
    BackBorkConfig::debugLog('Daily Summary complete!');
}

/**
 * Send daily summary to a specific user.
 * 
 * @param string $username User receiving the summary
 * @param array $userConfig User's notification configuration
 * @param array $stats Gathered statistics
 * @param string $hostname Server hostname
 * @param string $date Current date
 * @param string $generatedAt Generation timestamp
 * @param string $statusEmoji Status indicator emoji
 * @param string $statusText Status description
 */
function sendSummaryToUser($username, $userConfig, $stats, $hostname, $date, $generatedAt, $statusEmoji, $statusText) {
    // ========================================================================
    // EMAIL NOTIFICATION
    // ========================================================================
    if (!empty($userConfig['notify_email'])) {
        $subject = "{$statusEmoji} [BackBork KISS] Daily Report for {$hostname} - {$date}";
        
        $body = "BackBork KISS - Daily Report\n";
        $body .= "==============================\n\n";
        $body .= "Server: {$hostname}\n";
        $body .= "Date: {$date}\n";
        $body .= "Status: {$statusText}\n";
        $body .= "Generated: {$generatedAt}\n\n";
        
        $body .= "Activity Summary (Last 24 Hours)\n";
        $body .= "------------------------------\n";
        $body .= "Backups Completed:  {$stats['backup_successes']}\n";
        $body .= "Backups Failed:     {$stats['backup_failures']}\n";
        $body .= "Restores Completed: {$stats['restore_successes']}\n";
        $body .= "Restores Failed:    {$stats['restore_failures']}\n";
        $body .= "Schedules Run:      {$stats['schedules_run']}\n";
        $body .= "Backups Pruned:     {$stats['backups_pruned']}\n";
        $body .= "Total Events:       {$stats['total_events']}\n\n";
        
        // Queue status
        $body .= "Current Queue Status\n";
        $body .= "------------------------------\n";
        $body .= "Pending Jobs:    {$stats['queue_pending']}\n";
        $body .= "Completed Jobs:  {$stats['queue_completed']}\n";
        $body .= "Failed Jobs:     {$stats['queue_failed']}\n\n";
        
        // Recent errors (if any)
        if (!empty($stats['recent_errors'])) {
            $body .= "Recent Errors\n";
            $body .= "------------------------------\n";
            foreach (array_slice($stats['recent_errors'], 0, 5) as $error) {
                $body .= "* [{$error['timestamp']}] {$error['type']}: {$error['message']}\n";
            }
            $body .= "\n";
        }
        
        $body .= "==============================\n";
        $body .= "BackBork KISS v" . BACKBORK_VERSION . " | Open-source Disaster Recovery\n";
        
        $mailResult = mail($userConfig['notify_email'], $subject, $body, "From: backbork@{$hostname}");
        if ($mailResult) {
            BackBorkLog::logEvent($username, 'daily_summary', ['email'], true, 'Daily report email sent to ' . $userConfig['notify_email']);
        } else {
            BackBorkLog::logEvent($username, 'daily_summary', ['email'], false, 'Daily report email failed to ' . $userConfig['notify_email']);
        }
    }
    
    // ========================================================================
    // SLACK NOTIFICATION
    // ========================================================================
    if (!empty($userConfig['slack_webhook'])) {
        $slackPayload = [
            'text' => "{$statusEmoji} BackBork KISS Daily Report for {$hostname}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => "{$statusEmoji} Daily Report - {$date}",
                        'emoji' => true
                    ]
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Server:*\n{$hostname}"],
                        ['type' => 'mrkdwn', 'text' => "*Status:*\n{$statusText}"]
                    ]
                ],
                [
                    'type' => 'divider'
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*📊 Activity (24h)*"
                    ]
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Backups:*\n✅ {$stats['backup_successes']} | ❌ {$stats['backup_failures']}"],
                        ['type' => 'mrkdwn', 'text' => "*Restores:*\n✅ {$stats['restore_successes']} | ❌ {$stats['restore_failures']}"],
                        ['type' => 'mrkdwn', 'text' => "*Schedules Run:*\n{$stats['schedules_run']}"],
                        ['type' => 'mrkdwn', 'text' => "*Pruned:*\n{$stats['backups_pruned']}"]
                    ]
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*📋 Queue:* {$stats['queue_pending']} pending | {$stats['queue_completed']} completed | {$stats['queue_failed']} failed"
                    ]
                ]
            ]
        ];
        
        // Add errors section if any
        if (!empty($stats['recent_errors'])) {
            $errorText = "*❌ Recent Errors:*\n";
            foreach (array_slice($stats['recent_errors'], 0, 3) as $error) {
                $errorText .= "• {$error['type']}: " . substr($error['message'], 0, 50) . "...\n";
            }
            $slackPayload['blocks'][] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $errorText]
            ];
        }
        
        // Footer
        $slackPayload['blocks'][] = [
            'type' => 'context',
            'elements' => [
                ['type' => 'mrkdwn', 'text' => "BackBork KISS v" . BACKBORK_VERSION . " | Generated {$generatedAt}"]
            ]
        ];
        
        $ch = curl_init($userConfig['slack_webhook']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($slackPayload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            BackBorkLog::logEvent($username, 'daily_summary', ['slack'], true, 'Daily summary sent to Slack');
        } else {
            $errorMsg = 'Daily summary Slack failed: HTTP ' . $httpCode . ($curlError ? " - {$curlError}" : '');
            BackBorkLog::logEvent($username, 'daily_summary', ['slack'], false, $errorMsg);
        }
    }
}

/**
 * Gather statistics from logs and queue for the past 24 hours.
 * 
 * SECURITY: Filters results based on user permissions.
 * - Root user sees all events
 * - Resellers see only events for accounts they own
 * 
 * @param string $forUser The username to filter stats for ('root' sees all)
 * @return array Statistics array with counts and recent errors
 */
function gatherDailyStats($forUser = 'root') {
    $stats = [
        'backup_successes' => 0,
        'backup_failures' => 0,
        'restore_successes' => 0,
        'restore_failures' => 0,
        'schedules_run' => 0,
        'backups_pruned' => 0,
        'total_events' => 0,
        'queue_pending' => 0,
        'queue_completed' => 0,
        'queue_failed' => 0,
        'recent_errors' => []
    ];
    
    $cutoffTime = strtotime('-24 hours');
    $isRoot = ($forUser === 'root');
    
    // Get list of accounts this user owns (for non-root filtering)
    $userOwnedAccounts = [];
    if (!$isRoot) {
        $accountsApi = new BackBorkWhmApiAccounts();
        $accessibleAccounts = $accountsApi->getAccessibleAccounts($forUser, false);
        foreach ($accessibleAccounts as $acc) {
            $userOwnedAccounts[] = $acc['user'];
        }
    }
    
    // Parse operations log for last 24 hours
    $logFile = BackBorkLog::LOG_FILE;
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!$entry) continue;
            
            // Check if within 24 hour window
            $timestamp = strtotime($entry['timestamp'] ?? '');
            if ($timestamp < $cutoffTime) continue;
            
            // SECURITY: Filter by user ownership for non-root
            if (!$isRoot) {
                $entryUser = $entry['user'] ?? '';
                $entryAccounts = $entry['accounts'] ?? [];
                
                // Skip if this event wasn't triggered by this user
                // and doesn't involve any of their accounts
                $involvesUserAccounts = false;
                foreach ($entryAccounts as $acc) {
                    if (in_array($acc, $userOwnedAccounts)) {
                        $involvesUserAccounts = true;
                        break;
                    }
                }
                
                if ($entryUser !== $forUser && !$involvesUserAccounts) {
                    continue;
                }
            }
            
            $stats['total_events']++;
            $type = $entry['type'] ?? '';
            $success = $entry['success'] ?? true;
            
            // Count by type
            switch ($type) {
                case 'backup':
                    if ($success) {
                        $stats['backup_successes']++;
                    } else {
                        $stats['backup_failures']++;
                        $stats['recent_errors'][] = [
                            'timestamp' => $entry['timestamp'],
                            'type' => 'Backup',
                            'message' => $entry['message'] ?? 'Unknown error'
                        ];
                    }
                    break;
                    
                case 'restore':
                    if ($success) {
                        $stats['restore_successes']++;
                    } else {
                        $stats['restore_failures']++;
                        $stats['recent_errors'][] = [
                            'timestamp' => $entry['timestamp'],
                            'type' => 'Restore',
                            'message' => $entry['message'] ?? 'Unknown error'
                        ];
                    }
                    break;
                    
                case 'schedule_run':
                case 'queue_cron_process':
                    $stats['schedules_run']++;
                    break;
            }
            
            // Check message for pruning info
            $message = $entry['message'] ?? '';
            if (stripos($message, 'pruned') !== false || stripos($message, 'retention') !== false) {
                // Try to extract count from message
                if (preg_match('/(\d+)\s*(old\s+)?backup/i', $message, $matches)) {
                    $stats['backups_pruned'] += (int)$matches[1];
                }
            }
        }
    }
    
    // Get current queue status (filtered by user)
    $queue = new BackBorkQueue();
    $queueData = $queue->getQueue($forUser, $isRoot);
    
    // Filter queued jobs by user ownership
    $queuedJobs = $queueData['queued'] ?? [];
    if (!$isRoot) {
        $queuedJobs = array_filter($queuedJobs, function($job) use ($forUser, $userOwnedAccounts) {
            if (($job['user'] ?? '') === $forUser) return true;
            $jobAccounts = $job['accounts'] ?? [];
            return !empty(array_intersect($jobAccounts, $userOwnedAccounts));
        });
    }
    $stats['queue_pending'] = count($queuedJobs);
    
    // Count completed/failed from completed directory (filtered by user)
    $completedDir = BackBorkQueue::getCompletedDir();
    if (is_dir($completedDir)) {
        $completedFiles = glob($completedDir . '/*.json');
        foreach ($completedFiles as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job) {
                // SECURITY: Filter by user ownership for non-root
                if (!$isRoot) {
                    $jobUser = $job['user'] ?? '';
                    $jobAccounts = $job['accounts'] ?? [];
                    $involvesUserAccounts = !empty(array_intersect($jobAccounts, $userOwnedAccounts));
                    if ($jobUser !== $forUser && !$involvesUserAccounts) {
                        continue;
                    }
                }
                
                if (($job['status'] ?? '') === 'failed') {
                    $stats['queue_failed']++;
                } else {
                    $stats['queue_completed']++;
                }
            }
        }
    }
    
    // Sort errors by most recent first
    usort($stats['recent_errors'], function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return $stats;
}

// ============================================================================
// UNIFIED ROOT NOTIFICATION SYSTEM
// ============================================================================

/**
 * Send a notification to root user for system events.
 * 
 * Centralised function for all root-only notifications (cron errors, pruning,
 * queue failures, etc.). Checks global config for the specific notification
 * type's enabled flag before sending.
 * 
 * @param string $eventType Event type (cron_health, pruning, queue_failure, etc.)
 * @param string $globalConfigKey Key in global config to check if enabled (e.g., 'notify_cron_errors')
 * @param array $data Event-specific data to include in notification
 * @return bool True if notification was sent, false if skipped
 */
function sendRootNotification($eventType, $globalConfigKey, $data = []) {
    $config = new BackBorkConfig();
    
    // Check if this notification type is enabled in global config
    $globalConfig = $config->getGlobalConfig();
    if (!empty($globalConfigKey) && isset($globalConfig[$globalConfigKey]) && $globalConfig[$globalConfigKey] === false) {
        BackBorkConfig::debugLog("Root notification '{$eventType}' skipped: {$globalConfigKey} is disabled");
        return false;
    }
    
    // Load root user's notification channels
    $rootConfig = $config->getUserConfig('root');
    
    if (empty($rootConfig['notify_email']) && empty($rootConfig['slack_webhook'])) {
        BackBorkConfig::debugLog("Root notification '{$eventType}' skipped: no channels configured");
        return false;
    }
    
    // Add standard context to data
    $data['hostname'] = $data['hostname'] ?? (gethostname() ?: 'unknown');
    $data['timestamp'] = $data['timestamp'] ?? date('Y-m-d H:i:s T');
    
    // Send via BackBorkNotify
    $notify = new BackBorkNotify();
    $notify->sendNotification($eventType, $data, $rootConfig);
    
    BackBorkConfig::debugLog("Root notification '{$eventType}' sent");
    return true;
}

/**
 * Send queue failure notifications.
 * 
 * @param array $queueResults Results from processQueue() with failed_accounts and results
 */
function sendQueueFailureNotifications($queueResults) {
    $failedAccounts = $queueResults['failed_accounts'] ?? [];
    $results = $queueResults['results'] ?? [];
    
    if (empty($failedAccounts)) {
        return;
    }
    
    BackBorkConfig::debugLog('Sending queue failure notification for ' . count($failedAccounts) . ' accounts');
    
    // Gather error details from results
    $errorDetails = [];
    foreach ($results as $jobID => $result) {
        if (!$result['success']) {
            $errorDetails[] = $jobID . ': ' . ($result['message'] ?? 'Unknown error');
        }
    }
    
    sendRootNotification('queue_failure', 'notify_queue_failure', [
        'accounts' => $failedAccounts,
        'errors' => $errorDetails
    ]);
}

/**
 * Send pruning notification to root if enabled.
 * 
 * @param array $pruneResults Results from pruneOldBackups() with pruned count and details
 */
function sendPruningNotification($pruneResults) {
    sendRootNotification('pruning', 'notify_pruning', [
        'pruned_count' => $pruneResults['pruned'] ?? 0,
        'details' => $pruneResults['details'] ?? [],
        'message' => $pruneResults['message'] ?? ''
    ]);
}