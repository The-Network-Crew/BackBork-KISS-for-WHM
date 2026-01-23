<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Data layer for managing backup/restore jobs and recurring schedules.
 *   Handles queued jobs, running jobs, completed jobs, and schedule storage.
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

class BackBorkQueue {
    
    // ========================================================================
    // DIRECTORY CONSTANTS
    // ========================================================================
    
    /** Directory for one-time queued backup jobs awaiting execution */
    const QUEUE_DIR = '/usr/local/cpanel/3rdparty/backbork/queue';
    
    /** Directory for recurring backup schedules */
    const SCHEDULES_DIR = '/usr/local/cpanel/3rdparty/backbork/schedules';
    
    /** Directory for currently executing jobs */
    const RUNNING_DIR = '/usr/local/cpanel/3rdparty/backbork/running';
    
    /** Directory for active restore operations */
    const RESTORES_DIR = '/usr/local/cpanel/3rdparty/backbork/restores';
    
    /** Directory for completed job records (historical) */
    const COMPLETED_DIR = '/usr/local/cpanel/3rdparty/backbork/completed';
    
    /** Directory for job cancellation markers */
    const CANCEL_DIR = '/usr/local/cpanel/3rdparty/backbork/cancel';
    
    /** Lock file to prevent concurrent queue processing */
    const LOCK_FILE = '/usr/local/cpanel/3rdparty/backbork/queue.lock';
    
    // ========================================================================
    // CONSTRUCTOR
    // ========================================================================
    
    /**
     * Initialise Queue Manager
     * Creates required storage directories if they don't exist
     */
    public function __construct() {
        $this->ensureDirectories();
    }
    
    /**
     * Create required directories with secure permissions
     * Uses mode 0700 to restrict access to owner only
     */
    private function ensureDirectories() {
        // List of all directories needed for queue operation
        $dirs = [
            self::QUEUE_DIR,      // Pending one-time jobs
            self::SCHEDULES_DIR,  // Recurring schedules
            self::RUNNING_DIR,    // In-progress jobs
            self::RESTORES_DIR,   // Active restores
            self::COMPLETED_DIR,  // Historical records
            self::CANCEL_DIR      // Cancellation markers
        ];
        
        // Create each directory with owner-only permissions
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
    }
    
    // ========================================================================
    // JOB CREATION
    // ========================================================================
    
    /**
     * Add a backup job to the queue or create a recurring schedule
     * 
     * Creates either:
     * - A one-time job in queue/ (when schedule='once')
     * - A recurring schedule in schedules/ (hourly, daily, weekly, monthly)
     * 
     * @param array $accounts Account usernames to backup (or ['*'] for all)
     * @param string $destinationID Backup destination identifier
     * @param string $schedule Schedule type: 'once', 'hourly', 'daily', 'weekly', 'monthly'
     * @param string $user Username creating this job/schedule
     * @param array $options Optional settings:
     *                       - retention: Days to keep backups (default: 30)
     *                       - preferred_time: Hour to run (0-23, default: 2)
     *                       - day_of_week: Day for weekly schedules (0=Sun, 1=Mon, ..., 6=Sat)
     *                       - all_accounts: Boolean for dynamic account resolution
     * @return array Result with success status, message, and job_id
     */
    public function addToQueue($accounts, $destinationID, $schedule = 'once', $user = 'root', $options = []) {
        // Generate unique job identifier
        $jobID = $this->generateJobID();
        
        // Resolve destination name for display (store once at creation)
        $destinationName = $destinationID;
        $parser = new BackBorkDestinationsParser();
        $dest = $parser->getDestinationByID($destinationID);
        
        // Validate destination exists
        if (!$dest) {
            return [
                'success' => false,
                'message' => 'Invalid destination'
            ];
        }
        
        // Warn if destination is disabled (allow for one-time, block for schedules)
        if (empty($dest['enabled'])) {
            if ($schedule !== 'once') {
                return [
                    'success' => false,
                    'message' => 'Cannot create schedule: destination is disabled in WHM'
                ];
            }
            // For one-time jobs, allow but it will fail at execution time
        }
        
        if (!empty($dest['name'])) {
            $destinationName = $dest['name'];
        }
        
        // Build job record with all required fields
        $job = [
            'id' => $jobID,                                                      // Unique job identifier
            'type' => 'backup',                                                  // Job type (backup/restore)
            'accounts' => $accounts,                                             // Account list or ['*']
            'destination' => $destinationID,                                     // Target destination ID
            'destination_name' => $destinationName,                              // Human-readable name
            'schedule' => $schedule,                                             // Schedule frequency
            'user' => $user,                                                     // Owner of this job
            'created_at' => date('Y-m-d H:i:s'),                                // Creation timestamp
            'status' => 'queued',                                                // Current status
            'retention' => isset($options['retention']) ? (int)$options['retention'] : 30,           // Days to keep
            'preferred_time' => isset($options['preferred_time']) ? (int)$options['preferred_time'] : 2,  // Run hour
            'day_of_week' => isset($options['day_of_week']) ? (int)$options['day_of_week'] : 0,       // Weekly day (0=Sun)
            'all_accounts' => isset($options['all_accounts']) ? (bool)$options['all_accounts'] : false,  // Dynamic mode
            'schedule_id' => isset($options['schedule_id']) ? $options['schedule_id'] : null         // Parent schedule ID
        ];
        
        // Route based on schedule type
        if ($schedule === 'once') {
            // === ONE-TIME JOB: Add to immediate queue ===
            $queueFile = self::QUEUE_DIR . '/' . $jobID . '.json';
            file_put_contents($queueFile, json_encode($job, JSON_PRETTY_PRINT));
            chmod($queueFile, 0600);  // Secure permissions
            
            // Log the queue addition for audit trail
            if (class_exists('BackBorkLog')) {
                $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
                    ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
                    : (isset($_SERVER['REMOTE_ADDR']) 
                        ? $_SERVER['REMOTE_ADDR'] 
                        : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
                BackBorkLog::logEvent($user, 'queue_add', $accounts, true, 'Job added to queue', $requestor);
            }

            return [
                'success' => true,
                'message' => 'Job added to queue',
                'job_id' => $jobID
            ];
        } else {
            // === RECURRING SCHEDULE: Add to schedules directory ===
            
            // Calculate when this schedule should next run
            $job['next_run'] = $this->calculateNextRun($schedule, $job['preferred_time'], $job['day_of_week']);
            
            // Save schedule file
            $scheduleFile = self::SCHEDULES_DIR . '/' . $jobID . '.json';
            file_put_contents($scheduleFile, json_encode($job, JSON_PRETTY_PRINT));
            chmod($scheduleFile, 0600);  // Secure permissions
            
            // Log schedule creation for audit trail
            if (class_exists('BackBorkLog')) {
                $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
                    ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
                    : (isset($_SERVER['REMOTE_ADDR']) 
                        ? $_SERVER['REMOTE_ADDR'] 
                        : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
                // Build clean attribute list for Details column
                $scheduleAttrs = "Interval: " . ucfirst($schedule) . "\n" .
                                 "Destination: " . $destinationName . "\n" .
                                 "Retention: " . $job['retention'];
                BackBorkLog::logEvent($user, 'schedule_create', $accounts, true, $scheduleAttrs, $requestor);
            }

            return [
                'success' => true,
                'message' => 'Schedule created',
                'job_id' => $jobID
            ];
        }
    }
    
    // ========================================================================
    // SCHEDULE CALCULATION
    // ========================================================================
    
    /**
     * Calculate the next execution time for a recurring schedule
     * 
     * Schedule types:
     * - hourly: Top of the next hour
     * - daily: Preferred hour today (or tomorrow if already passed)
     * - weekly: Specified day of week at preferred hour
     * - monthly: 1st of month at preferred hour
     * 
     * @param string $schedule Schedule type (hourly, daily, weekly, monthly)
     * @param int $preferredHour Preferred execution hour (0-23, default: 2am)
     * @param int $dayOfWeek Day of week for weekly (0=Sunday, 1=Monday, ..., 6=Saturday)
     * @return string DateTime string in 'Y-m-d H:i:s' format
     */
    public function calculateNextRun($schedule, $preferredHour = 2, $dayOfWeek = 0) {
        $now = new DateTime();
        $next = new DateTime();
        $next->setTime($preferredHour, 0, 0);  // Start at preferred hour, minute 0
        
        switch ($schedule) {
            case 'hourly':
                // Run at the top of the next hour
                $next = new DateTime();
                $next->modify('+1 hour');
                $next->setTime((int)$next->format('H'), 0, 0);
                break;
                
            case 'daily':
                // Run daily at preferred hour
                // If today's preferred time has passed, schedule for tomorrow
                if ($now >= $next) {
                    $next->modify('+1 day');
                }
                break;
                
            case 'weekly':
                // Run on specified day of week at preferred hour
                // Map day number to PHP day name (0=Sunday)
                $dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                $targetDay = $dayNames[$dayOfWeek % 7] ?? 'sunday';
                $next->modify('next ' . $targetDay);
                $next->setTime($preferredHour, 0, 0);
                break;
                
            case 'monthly':
                // Run on the 1st of each month at preferred hour
                $next->modify('first day of next month');
                $next->setTime($preferredHour, 0, 0);
                break;
        }
        
        return $next->format('Y-m-d H:i:s');
    }
    
    // ========================================================================
    // QUEUE RETRIEVAL
    // ========================================================================
    
    /**
     * Get complete queue status including jobs, schedules, and restores
     * 
     * Returns all queue-related data filtered by user permissions:
     * - Root users see all data (or can filter by specific user)
     * - Non-root users see only their own data
     * 
     * @param string $user Current authenticated user
     * @param bool $isRoot Whether user has root privileges
     * @param string|null $viewAsUser Optional filter for root to view specific user's items
     * @return array Associative array with queued, running, schedules, restores arrays
     */
    public function getQueue($user, $isRoot, $viewAsUser = null) {
        // Initialise result structure
        $result = [
            'queued' => [],     // Pending one-time jobs
            'running' => [],    // Currently executing jobs
            'schedules' => [],  // Recurring schedules
            'restores' => []    // Active restore operations
        ];
        
        // Determine user filter based on permissions
        $filterUser = null;
        if ($isRoot && $viewAsUser !== null && $viewAsUser !== '' && $viewAsUser !== 'all') {
            // Root viewing as specific user
            $filterUser = $viewAsUser;
        } elseif (!$isRoot) {
            // Non-root users can only see their own items
            $filterUser = $user;
        }
        // If root and no viewAsUser (or 'all'), filterUser stays null = show all
        
        // --- Load queued jobs ---
        $queueFiles = glob(self::QUEUE_DIR . '/*.json');
        foreach ($queueFiles as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job) {
                // Apply user filter
                if ($filterUser === null || $job['user'] === $filterUser) {
                    $result['queued'][] = $job;
                }
            }
        }
        
        // --- Load running jobs ---
        $runningFiles = glob(self::RUNNING_DIR . '/*.json');
        foreach ($runningFiles as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job) {
                // Apply user filter
                if ($filterUser === null || $job['user'] === $filterUser) {
                    $result['running'][] = $job;
                }
            }
        }
        
        // --- Load recurring schedules ---
        $scheduleFiles = glob(self::SCHEDULES_DIR . '/*.json');
        foreach ($scheduleFiles as $file) {
            $schedule = json_decode(file_get_contents($file), true);
            if ($schedule) {
                // Apply user filter
                if ($filterUser === null || $schedule['user'] === $filterUser) {
                    $result['schedules'][] = $schedule;
                }
            }
        }
        
        // --- Load active restore operations ---
        $restoreFiles = glob(self::RESTORES_DIR . '/*.json');
        foreach ($restoreFiles as $file) {
            $restore = json_decode(file_get_contents($file), true);
            if ($restore) {
                // Apply user filter
                if ($filterUser === null || $restore['user'] === $filterUser) {
                    $result['restores'][] = $restore;
                }
            }
        }
        
        // Sort queued jobs by creation date (oldest first = FIFO)
        usort($result['queued'], function($a, $b) {
            return strtotime($a['created_at']) - strtotime($b['created_at']);
        });
        
        return $result;
    }
    
    // ========================================================================
    // JOB REMOVAL
    // ========================================================================
    
    /**
     * Remove a job from the queue or delete a schedule
     * 
     * Handles removal from both queue/ and schedules/ directories.
     * Non-root users can only remove their own items.
     * 
     * @param string $jobID Unique job identifier
     * @param string $user Current authenticated user
     * @param bool $isRoot Whether user has root privileges
     * @return array Result with success status and message
     */
    public function removeFromQueue($jobID, $user, $isRoot) {
        // --- Check queue directory first ---
        $queueFile = self::QUEUE_DIR . '/' . $jobID . '.json';
        if (file_exists($queueFile)) {
            $job = json_decode(file_get_contents($queueFile), true);
            
            // Security: Non-root can only remove their own jobs
            if (!$isRoot && $job['user'] !== $user) {
                return ['success' => false, 'message' => 'Access denied'];
            }
            
            // Delete the job file
            unlink($queueFile);
            
            // Log removal for audit trail
            if (class_exists('BackBorkLog')) {
                $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
                    ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
                    : (isset($_SERVER['REMOTE_ADDR']) 
                        ? $_SERVER['REMOTE_ADDR'] 
                        : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
                BackBorkLog::logEvent($user, 'queue_remove', [$jobID], true, 'Job removed from queue', $requestor);
            }
            return ['success' => true, 'message' => 'Job removed from queue'];
        }
        
        // --- Check schedules directory ---
        $scheduleFile = self::SCHEDULES_DIR . '/' . $jobID . '.json';
        if (file_exists($scheduleFile)) {
            $schedule = json_decode(file_get_contents($scheduleFile), true);
            
            // Security: Non-root can only remove their own schedules
            if (!$isRoot && $schedule['user'] !== $user) {
                return ['success' => false, 'message' => 'Access denied'];
            }
            
            // Delete the schedule file
            unlink($scheduleFile);
            
            // Log removal for audit trail
            if (class_exists('BackBorkLog')) {
                $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
                    ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
                    : (isset($_SERVER['REMOTE_ADDR']) 
                        ? $_SERVER['REMOTE_ADDR'] 
                        : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
                BackBorkLog::logEvent($user, 'schedule_delete', [$jobID], true, 'Schedule removed', $requestor);
            }
            return ['success' => true, 'message' => 'Schedule removed'];
        }
        
        // Job not found in either location
        return ['success' => false, 'message' => 'Job not found'];
    }
    
    /**
     * Update an existing schedule with new settings
     * 
     * @param string $jobID Schedule ID to update
     * @param array $updates Associative array of fields to update:
     *                       - accounts: Array of account usernames
     *                       - destination: Destination ID
     *                       - schedule: Frequency (hourly, daily, weekly, monthly)
     *                       - retention: Days to keep backups
     *                       - preferred_time: Hour to run (0-23)
     *                       - day_of_week: Day for weekly (0=Sun, 6=Sat)
     *                       - all_accounts: Boolean for dynamic mode
     * @param string $user Current authenticated user
     * @param bool $isRoot Whether user has root privileges
     * @return array Result with success status and message
     */
    public function updateSchedule($jobID, $updates, $user, $isRoot) {
        $scheduleFile = self::SCHEDULES_DIR . '/' . $jobID . '.json';
        
        // Check if schedule exists
        if (!file_exists($scheduleFile)) {
            return ['success' => false, 'message' => 'Schedule not found'];
        }
        
        // Load existing schedule
        $schedule = json_decode(file_get_contents($scheduleFile), true);
        if (!$schedule) {
            return ['success' => false, 'message' => 'Failed to read schedule'];
        }
        
        // Security: Non-root can only update their own schedules
        if (!$isRoot && $schedule['user'] !== $user) {
            return ['success' => false, 'message' => 'Access denied'];
        }
        
        // Track what changed for logging
        $changes = [];
        
        // Update accounts if provided
        if (isset($updates['accounts'])) {
            $allAccounts = isset($updates['all_accounts']) ? (bool)$updates['all_accounts'] : false;
            if ($allAccounts || (is_array($updates['accounts']) && in_array('*', $updates['accounts']))) {
                $schedule['accounts'] = ['*'];
                $schedule['all_accounts'] = true;
                $changes[] = 'accounts: All Accounts';
            } else {
                $schedule['accounts'] = $updates['accounts'];
                $schedule['all_accounts'] = false;
                $changes[] = 'accounts: ' . count($updates['accounts']) . ' selected';
            }
        }
        
        // Update destination if provided
        if (isset($updates['destination']) && $updates['destination'] !== $schedule['destination']) {
            $parser = new BackBorkDestinationsParser();
            $dest = $parser->getDestinationByID($updates['destination']);
            if (!$dest) {
                return ['success' => false, 'message' => 'Invalid destination'];
            }
            if (empty($dest['enabled'])) {
                return ['success' => false, 'message' => 'Cannot use disabled destination'];
            }
            $schedule['destination'] = $updates['destination'];
            $schedule['destination_name'] = !empty($dest['name']) ? $dest['name'] : $updates['destination'];
            $changes[] = 'destination: ' . $schedule['destination_name'];
        }
        
        // Update schedule frequency if provided
        $frequencyChanged = false;
        if (isset($updates['schedule']) && in_array($updates['schedule'], ['hourly', 'daily', 'weekly', 'monthly'])) {
            if ($updates['schedule'] !== $schedule['schedule']) {
                $schedule['schedule'] = $updates['schedule'];
                $frequencyChanged = true;
                $changes[] = 'frequency: ' . ucfirst($updates['schedule']);
            }
        }
        
        // Update retention if provided
        if (isset($updates['retention'])) {
            $schedule['retention'] = (int)$updates['retention'];
            $changes[] = 'retention: ' . $schedule['retention'];
        }
        
        // Update preferred_time if provided
        $timeChanged = false;
        if (isset($updates['preferred_time'])) {
            $newTime = (int)$updates['preferred_time'];
            if ($newTime >= 0 && $newTime <= 23 && $newTime !== $schedule['preferred_time']) {
                $schedule['preferred_time'] = $newTime;
                $timeChanged = true;
                $changes[] = 'time: ' . sprintf('%02d:00', $newTime);
            }
        }
        
        // Update day_of_week if provided (for weekly schedules)
        $dowChanged = false;
        if (isset($updates['day_of_week'])) {
            $newDow = (int)$updates['day_of_week'];
            if ($newDow >= 0 && $newDow <= 6 && $newDow !== ($schedule['day_of_week'] ?? 0)) {
                $schedule['day_of_week'] = $newDow;
                $dowChanged = true;
                $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $changes[] = 'day: ' . $days[$newDow];
            }
        }
        
        // Recalculate next_run if timing-related fields changed
        if ($frequencyChanged || $timeChanged || $dowChanged) {
            $schedule['next_run'] = $this->calculateNextRun(
                $schedule['schedule'],
                $schedule['preferred_time'],
                $schedule['day_of_week'] ?? 0
            );
        }
        
        // Update modification timestamp
        $schedule['updated_at'] = date('Y-m-d H:i:s');
        
        // Save updated schedule
        if (file_put_contents($scheduleFile, json_encode($schedule, JSON_PRETTY_PRINT)) === false) {
            return ['success' => false, 'message' => 'Failed to save schedule'];
        }
        chmod($scheduleFile, 0600);
        
        // Log the update for audit trail
        if (class_exists('BackBorkLog') && !empty($changes)) {
            $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
                ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
                : (isset($_SERVER['REMOTE_ADDR']) 
                    ? $_SERVER['REMOTE_ADDR'] 
                    : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
            BackBorkLog::logEvent($user, 'schedule_update', [$jobID], true, implode("\n", $changes), $requestor);
        }
        
        return [
            'success' => true,
            'message' => 'Schedule updated',
            'schedule' => $schedule
        ];
    }
    
    // ========================================================================
    // JOB STATUS
    // ========================================================================
    
    /**
     * Get currently running jobs
     * 
     * @param string $user Current authenticated user
     * @param bool $isRoot Whether user has root privileges
     * @return array Associative array with 'running' key containing job list
     */
    public function getRunningJobs($user, $isRoot) {
        $running = [];
        
        // Read all running job files
        $files = glob(self::RUNNING_DIR . '/*.json');
        foreach ($files as $file) {
            $job = json_decode(file_get_contents($file), true);
            // Filter by user permissions
            if ($job && ($isRoot || $job['user'] === $user)) {
                $running[] = $job;
            }
        }
        
        return ['running' => $running];
    }
    
    /**
     * Retrieve a job by ID from any storage location
     * 
     * Searches queue, running, schedules, and completed directories.
     * 
     * @param string $jobID Unique job identifier
     * @return array|null Job data or null if not found
     */
    public function getJob($jobID) {
        // Search all possible locations
        $locations = [
            self::QUEUE_DIR,      // Pending jobs
            self::RUNNING_DIR,    // In-progress jobs
            self::SCHEDULES_DIR,  // Recurring schedules
            self::COMPLETED_DIR   // Historical records
        ];
        
        foreach ($locations as $dir) {
            $file = $dir . '/' . $jobID . '.json';
            if (file_exists($file)) {
                return json_decode(file_get_contents($file), true);
            }
        }
        
        return null;  // Not found
    }
    
    // ========================================================================
    // JOB UPDATES
    // ========================================================================
    
    /**
     * Update a job's data by merging new values
     * 
     * @param string $jobID Unique job identifier
     * @param array $data Key-value pairs to merge into job data
     * @param string|null $location Directory to search (auto-detects if null)
     * @return bool Success status
     */
    public function updateJob($jobID, $data, $location = null) {
        // Auto-detect location if not specified
        if (!$location) {
            $locations = [self::QUEUE_DIR, self::RUNNING_DIR, self::SCHEDULES_DIR];
            foreach ($locations as $dir) {
                if (file_exists($dir . '/' . $jobID . '.json')) {
                    $location = $dir;
                    break;
                }
            }
        }
        
        // Job not found
        if (!$location) {
            return false;
        }
        
        $file = $location . '/' . $jobID . '.json';
        if (!file_exists($file)) {
            return false;
        }
        
        // Load, merge, and save job data
        $job = json_decode(file_get_contents($file), true);
        $job = array_merge($job, $data);
        
        return file_put_contents($file, json_encode($job, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Move a job between directories (e.g., queue→running→completed)
     * 
     * Used during job lifecycle transitions:
     * - queue → running (when job starts)
     * - running → completed (when job finishes)
     * 
     * @param string $jobID Unique job identifier
     * @param string $from Source directory path
     * @param string $to Destination directory path
     * @param array $additionalData Extra data to add during move
     * @return bool Success status
     */
    public function moveJob($jobID, $from, $to, $additionalData = []) {
        $sourceFile = $from . '/' . $jobID . '.json';
        if (!file_exists($sourceFile)) {
            return false;
        }
        
        // Load job and merge additional data
        $job = json_decode(file_get_contents($sourceFile), true);
        $job = array_merge($job, $additionalData);
        
        // Write to destination
        $destFile = $to . '/' . $jobID . '.json';
        if (file_put_contents($destFile, json_encode($job, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        chmod($destFile, 0600);  // Secure permissions
        
        // Remove from source
        unlink($sourceFile);
        return true;
    }
    
    // ========================================================================
    // UTILITY METHODS
    // ========================================================================
    
    /**
     * Generate a unique job identifier
     * 
     * Format: bb_YYYYMMDD_HHMMSS_XXXXXXXX
     * - Prefix: 'bb_' (BackBork)
     * - Timestamp: Date and time for easy sorting
     * - Random: 8 character hex for uniqueness
     * 
     * @return string Unique job ID
     */
    public function generateJobID() {
        return 'bb_' . date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
    }
    
    // ========================================================================
    // LOCKING
    // ========================================================================
    
    /**
     * Acquire exclusive lock for queue processing
     * 
     * Prevents concurrent cron runs from processing the same queue.
     * Uses non-blocking lock (LOCK_NB) to fail immediately if locked.
     * 
     * @return resource|false File handle if lock acquired, false if already locked
     */
    public function acquireLock() {
        $lock = fopen(self::LOCK_FILE, 'w');
        // Try non-blocking exclusive lock
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;  // Already locked by another process
        }
        return $lock;
    }
    
    /**
     * Release queue processing lock
     * 
     * @param resource $lock File handle from acquireLock()
     */
    public function releaseLock($lock) {
        flock($lock, LOCK_UN);  // Unlock
        fclose($lock);          // Close handle
    }
    
    // ========================================================================
    // STATIC DIRECTORY ACCESSORS
    // ========================================================================
    
    /**
     * Get schedules directory path
     * Used by QueueProcessor and other components
     * @return string Directory path
     */
    public static function getSchedulesDir() {
        return self::SCHEDULES_DIR;
    }
    
    /**
     * Get queue directory path
     * @return string Directory path
     */
    public static function getQueueDir() {
        return self::QUEUE_DIR;
    }
    
    /**
     * Get running jobs directory path
     * @return string Directory path
     */
    public static function getRunningDir() {
        return self::RUNNING_DIR;
    }
    
    /**
     * Get completed jobs directory path
     * @return string Directory path
     */
    public static function getCompletedDir() {
        return self::COMPLETED_DIR;
    }
    
    /**
     * Get cancel markers directory path
     * @return string Directory path
     */
    public static function getCancelDir() {
        return self::CANCEL_DIR;
    }
    
    // ========================================================================
    // JOB CANCELLATION
    // ========================================================================
    
    /**
     * Request cancellation of a running job
     * Creates a cancel marker file that the worker checks after each account
     * 
     * @param string $jobID Job ID to cancel
     * @param string $user User requesting cancellation (for permission check)
     * @param bool $isRoot Whether user has root privileges
     * @return array Result with success status and message
     */
    public function requestCancel($jobID, $user, $isRoot) {
        // Find the job in running directory
        $runningFile = self::RUNNING_DIR . '/' . $jobID . '.json';
        
        if (!file_exists($runningFile)) {
            // Check if it's in queue (not yet started)
            $queueFile = self::QUEUE_DIR . '/' . $jobID . '.json';
            if (file_exists($queueFile)) {
                // Job hasn't started yet - just delete it from queue
                $job = json_decode(file_get_contents($queueFile), true);
                
                // Permission check
                if (!$isRoot && isset($job['user']) && $job['user'] !== $user) {
                    return ['success' => false, 'message' => 'Permission denied'];
                }
                
                unlink($queueFile);
                
                // Log the cancellation
                if (class_exists('BackBorkLog')) {
                    BackBorkLog::logEvent($user, 'queue_remove', $job['accounts'] ?? [], true, 'Queued job cancelled before start');
                }
                
                return ['success' => true, 'message' => 'Queued job removed'];
            }
            
            return ['success' => false, 'message' => 'Job not found or already completed'];
        }
        
        // Job is running - check permissions
        $job = json_decode(file_get_contents($runningFile), true);
        if (!$isRoot && isset($job['user']) && $job['user'] !== $user) {
            return ['success' => false, 'message' => 'Permission denied'];
        }
        
        // Create cancel marker file
        $cancelFile = self::CANCEL_DIR . '/' . $jobID . '.cancel';
        
        // Ensure cancel directory exists
        if (!is_dir(self::CANCEL_DIR)) {
            mkdir(self::CANCEL_DIR, 0700, true);
        }
        
        // Write cancel request with timestamp and user
        $cancelData = [
            'job_id' => $jobID,
            'requested_by' => $user,
            'requested_at' => date('Y-m-d H:i:s')
        ];
        
        file_put_contents($cancelFile, json_encode($cancelData, JSON_PRETTY_PRINT));
        chmod($cancelFile, 0600);
        
        return ['success' => true, 'message' => 'Cancellation requested - job will stop after current account'];
    }
    
    /**
     * Check if a job has a pending cancellation request
     * Called by BackupManager after each account backup
     * 
     * @param string $jobID Job ID to check
     * @return bool True if cancellation was requested
     */
    public static function isCancelRequested($jobID) {
        $cancelFile = self::CANCEL_DIR . '/' . $jobID . '.cancel';
        return file_exists($cancelFile);
    }
    
    /**
     * Clear cancellation marker after job has been cancelled
     * Called by BackupManager when it honours a cancel request
     * 
     * @param string $jobID Job ID to clear
     */
    public static function clearCancelRequest($jobID) {
        $cancelFile = self::CANCEL_DIR . '/' . $jobID . '.cancel';
        if (file_exists($cancelFile)) {
            unlink($cancelFile);
        }
    }
    
    /**
     * Kill all jobs in queue and cancel all running jobs
     * Root-only emergency function for clearing stuck queues
     * Also kills any running pkgacct/restorepkg processes
     * 
     * @return array Result with counts of removed/cancelled jobs
     */
    public function killAllJobs() {
        $queuedRemoved = 0;
        $runningCancelled = 0;
        $processesKilled = 0;
        
        // Remove all queued jobs
        $queueFiles = glob(self::QUEUE_DIR . '/*.json');
        foreach ($queueFiles as $file) {
            if (unlink($file)) {
                $queuedRemoved++;
            }
        }
        
        // Cancel all running jobs and remove their files
        $runningFiles = glob(self::RUNNING_DIR . '/*.json');
        foreach ($runningFiles as $file) {
            $jobID = basename($file, '.json');
            
            // Create cancel marker (in case process is still checking)
            if (!is_dir(self::CANCEL_DIR)) {
                mkdir(self::CANCEL_DIR, 0700, true);
            }
            
            $cancelFile = self::CANCEL_DIR . '/' . $jobID . '.cancel';
            $cancelData = [
                'job_id' => $jobID,
                'requested_by' => 'root',
                'requested_at' => date('Y-m-d H:i:s'),
                'reason' => 'kill_all_jobs'
            ];
            file_put_contents($cancelFile, json_encode($cancelData, JSON_PRETTY_PRINT));
            chmod($cancelFile, 0600);
            
            // Remove the running job file
            unlink($file);
            $runningCancelled++;
        }
        
        // Kill any running pkgacct or restorepkg processes
        // These are the cPanel utilities that actually perform backup/restore
        $processesKilled = $this->killBackupProcesses();
        
        // Also remove the queue processor lock file if it exists
        $lockFile = '/usr/local/cpanel/3rdparty/backbork/queue/queue.lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        return [
            'success' => true,
            'queued_removed' => $queuedRemoved,
            'running_cancelled' => $runningCancelled,
            'processes_killed' => $processesKilled
        ];
    }
    
    /**
     * Kill any running pkgacct or restorepkg processes
     * Uses pkill to terminate backup/restore utilities
     * 
     * @return int Number of processes killed
     */
    private function killBackupProcesses() {
        $killed = 0;
        
        // Find and kill pkgacct processes (backup)
        exec('pgrep -f "pkgacct"', $pkgacctPids, $ret1);
        if (!empty($pkgacctPids)) {
            exec('pkill -9 -f "pkgacct"', $out, $ret);
            $killed += count($pkgacctPids);
        }
        
        // Find and kill restorepkg processes (restore)
        exec('pgrep -f "restorepkg"', $restorepkgPids, $ret2);
        if (!empty($restorepkgPids)) {
            exec('pkill -9 -f "restorepkg"', $out, $ret);
            $killed += count($restorepkgPids);
        }
        
        return $killed;
    }
}
