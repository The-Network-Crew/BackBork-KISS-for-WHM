<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Manages all configuration storage for the plugin (JSON-based).
 *   Handles per-user settings, global settings, and directory structure.
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

class BackBorkConfig {
    
    // Base directory for all BackBork data files
    const CONFIG_DIR = '/usr/local/cpanel/3rdparty/backbork';
    
    // Global configuration file (shared settings like schedule locks)
    const GLOBAL_CONFIG_FILE = '/usr/local/cpanel/3rdparty/backbork/global.json';
    
    /**
     * Constructor - Initialise config directories
     * 
     * Creates the required directory structure if it doesn't exist.
     * Called automatically when Config is instantiated.
     */
    public function __construct() {
        $this->ensureConfigDir();
    }
    
    /**
     * Create all required directories for BackBork data storage
     * 
     * Directory structure:
     * - /users/     Per-user configuration JSON files
     * - /schedules/ Scheduled backup job definitions  
     * - /queue/     Pending backup jobs waiting to run
     * - /logs/      Operation logs and audit trail
     */
    private function ensureConfigDir() {
        // Create base directory if missing
        if (!is_dir(self::CONFIG_DIR)) {
            mkdir(self::CONFIG_DIR, 0700, true);
        }
        
        // User configs subdirectory - stores per-user settings
        $userConfigDir = self::CONFIG_DIR . '/users';
        if (!is_dir($userConfigDir)) {
            mkdir($userConfigDir, 0700, true);
        }
        
        // Schedules directory - stores recurring backup definitions
        $schedulesDir = self::CONFIG_DIR . '/schedules';
        if (!is_dir($schedulesDir)) {
            mkdir($schedulesDir, 0700, true);
        }
        
        // Queue directory - stores pending one-time backup jobs
        $queueDir = self::CONFIG_DIR . '/queue';
        if (!is_dir($queueDir)) {
            mkdir($queueDir, 0700, true);
        }
        
        // Logs directory - stores operation audit logs
        $logsDir = self::CONFIG_DIR . '/logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0700, true);
        }
    }
    
    /**
     * Get global configuration settings
     * 
     * Global config includes settings that apply server-wide (root only):
     * - debug_mode: Enable verbose logging to error_log
     * - schedules_locked: Prevent resellers from managing schedules
     * - notify_cron_errors: Alert root when cron health check fails
     * - notify_queue_failure: Alert root when queue processing fails
     * - notify_pruning: Alert root when backups are pruned
     * 
     * @return array Merged defaults with saved global config
     */
    public function getGlobalConfig() {
        // Start with sensible defaults
        $defaults = $this->getGlobalDefaults();
        
        // Return defaults if no config file exists yet
        if (!file_exists(self::GLOBAL_CONFIG_FILE)) {
            return $defaults;
        }
        
        // Read and parse the global config file
        $content = file_get_contents(self::GLOBAL_CONFIG_FILE);
        $config = json_decode($content, true);
        
        // Return defaults if JSON parsing failed
        if (!is_array($config)) {
            return $defaults;
        }
        
        // Merge saved config over defaults (saved values take precedence)
        return array_merge($defaults, $config);
    }
    
    /**
     * Save global configuration (root only)
     * 
     * Merges new settings with existing config and saves to disk.
     * Also logs the change for audit purposes.
     * 
     * @param array $config Key-value pairs to save
     * @param string $user Username making the change (for audit log)
     * @return array Result with 'success' boolean and 'message' string
     */
    public function saveGlobalConfig($config, $user = 'root') {
        // Get existing config to merge with
        $existing = $this->getGlobalConfig();
        
        // Merge new values over existing (new values win)
        $merged = array_merge($existing, $config);
        
        // Add metadata for tracking
        $merged['updated_at'] = date('Y-m-d H:i:s');
        $merged['updated_by'] = $user;
        
        // Write to disk with pretty formatting for readability
        $result = file_put_contents(self::GLOBAL_CONFIG_FILE, json_encode($merged, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            return ['success' => false, 'message' => 'Failed to save global configuration'];
        }
        
        // Secure the file - only root should read it
        chmod(self::GLOBAL_CONFIG_FILE, 0600);
        
        // Log the config update for audit trail
        if (class_exists('BackBorkLog')) {
            // Determine where the request came from
            $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
                ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
                : (isset($_SERVER['REMOTE_ADDR']) 
                    ? $_SERVER['REMOTE_ADDR'] 
                    : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
            
            // Build human-readable list of changes
            $changes = [];
            foreach ($config as $key => $value) {
                if (is_bool($value)) {
                    $changes[] = $key . '=' . ($value ? 'true' : 'false');
                } else {
                    $changes[] = $key . '=' . $value;
                }
            }
            BackBorkLog::logEvent($user, 'global_config_update', $changes, true, 'Global configuration saved', $requestor);
        }
        
        return ['success' => true, 'message' => 'Global configuration saved successfully'];
    }
    
    /**
     * Get default values for global configuration
     * 
     * @return array Default global settings
     */
    public function getGlobalDefaults() {
        return [
        'debug_mode' => false,                      // Verbose logging off by default
            'schedules_locked' => false,            // Resellers can manage schedules by default
            'schedules_locked_at' => null,          // Timestamp when lock was enabled
            'schedules_locked_by' => null,          // User who enabled the lock
            'reseller_deletion_locked' => false,    // Resellers can delete backups by default
            'root_only_destinations' => [],         // Destination IDs only visible to root
            'notify_cron_errors' => true,           // Root-only: alert on cron health issues
            'notify_queue_failure' => true,         // Root-only: alert on queue processing failures
            'notify_pruning' => true,               // Root-only: alert when backups are pruned
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Check if schedule management is locked for resellers
     * 
     * When locked, only root can create/edit/delete schedules.
     * Existing schedules continue to run normally.
     * 
     * @return bool True if schedules are locked
     */
    public static function areSchedulesLocked() {
        // Not locked if config file doesn't exist
        if (!file_exists(self::GLOBAL_CONFIG_FILE)) {
            return false;
        }
        
        // Parse config and check the flag
        $config = json_decode(file_get_contents(self::GLOBAL_CONFIG_FILE), true);
        return !empty($config['schedules_locked']);
    }
    
    /**
     * Check if reseller backup deletion is locked
     * 
     * When locked, only root can delete backups.
     * 
     * @return bool True if reseller deletions are locked
     */
    public static function areResellerDeletionsLocked() {
        // Not locked if config file doesn't exist
        if (!file_exists(self::GLOBAL_CONFIG_FILE)) {
            return false;
        }
        
        // Parse config and check the flag
        $config = json_decode(file_get_contents(self::GLOBAL_CONFIG_FILE), true);
        return !empty($config['reseller_deletion_locked']);
    }
    
    /**
     * Set the schedule lock status
     * 
     * Convenience method to toggle the schedule lock with proper metadata.
     * 
     * @param bool $locked True to lock, false to unlock
     * @param string $user Username making the change
     * @return array Result from saveGlobalConfig
     */
    public function setSchedulesLocked($locked, $user) {
        $config = [
            'schedules_locked' => (bool)$locked,
            'schedules_locked_at' => $locked ? date('Y-m-d H:i:s') : null,
            'schedules_locked_by' => $locked ? $user : null
        ];
        return $this->saveGlobalConfig($config, $user);
    }
    
    /**
     * Get array of destination IDs that are root-only
     * 
     * @return array List of destination IDs
     */
    public static function getRootOnlyDestinations() {
        if (!file_exists(self::GLOBAL_CONFIG_FILE)) {
            return [];
        }
        
        $config = json_decode(file_get_contents(self::GLOBAL_CONFIG_FILE), true);
        return isset($config['root_only_destinations']) ? $config['root_only_destinations'] : [];
    }
    
    /**
     * Check if a specific destination is root-only
     * 
     * @param string $destinationID Destination ID
     * @return bool True if destination is root-only
     */
    public static function isDestinationRootOnly($destinationID) {
        $rootOnly = self::getRootOnlyDestinations();
        return in_array($destinationID, $rootOnly);
    }
    
    /**
     * Set a destination's root-only visibility
     * 
     * @param string $destinationID Destination ID
     * @param bool $rootOnly True to make root-only, false for all users
     * @return bool True on success
     */
    public static function setDestinationRootOnly($destinationID, $rootOnly) {
        // Get current global config
        $config = [];
        if (file_exists(self::GLOBAL_CONFIG_FILE)) {
            $config = json_decode(file_get_contents(self::GLOBAL_CONFIG_FILE), true);
            if (!is_array($config)) {
                $config = [];
            }
        }
        
        // Get current root-only list
        $rootOnlyDests = isset($config['root_only_destinations']) ? $config['root_only_destinations'] : [];
        
        if ($rootOnly) {
            // Add to list if not already there
            if (!in_array($destinationID, $rootOnlyDests)) {
                $rootOnlyDests[] = $destinationID;
            }
        } else {
            // Remove from list
            $rootOnlyDests = array_values(array_diff($rootOnlyDests, [$destinationID]));
        }
        
        $config['root_only_destinations'] = $rootOnlyDests;
        $config['updated_at'] = date('Y-m-d H:i:s');
        
        return file_put_contents(self::GLOBAL_CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Get the path to a user's configuration file
     * 
     * Sanitises the username to prevent directory traversal attacks.
     * 
     * @param string $user Username
     * @return string Full path to user's config JSON file
     */
    private function getUserConfigFile($user) {
        // Sanitise username - only allow alphanumeric, underscore, hyphen
        $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '', $user);
        return self::CONFIG_DIR . '/users/' . $safeUser . '.json';
    }
    
    /**
     * Get configuration for a specific user
     * 
     * Returns saved settings merged with defaults.
     * Users who haven't saved settings yet get all defaults.
     * 
     * @param string $user Username
     * @return array User's configuration settings
     */
    public function getUserConfig($user) {
        $configFile = $this->getUserConfigFile($user);
        $defaults = $this->getDefaults();
        
        // Return defaults if user has no saved config
        if (!file_exists($configFile)) {
            return $defaults;
        }
        
        // Read and parse user's config file
        $content = file_get_contents($configFile);
        $config = json_decode($content, true);
        
        // Return defaults if JSON parsing failed
        if (!is_array($config)) {
            return $defaults;
        }
        
        // Merge saved config over defaults
        return array_merge($defaults, $config);
    }
    
    /**
     * Save configuration for a specific user
     * 
     * Sanitises input, merges with existing config, and saves to disk.
     * Also syncs certain settings to global config for root user.
     * 
     * @param string $user Username
     * @param array $config Configuration key-value pairs to save
     * @return array Result with 'success' boolean and 'message' string
     */
    public function saveUserConfig($user, $config) {
        $configFile = $this->getUserConfigFile($user);
        
        // Sanitise all input values for security
        $sanitised = $this->sanitiseConfig($config);
        
        // Merge with existing config (new values override)
        $existing = $this->getUserConfig($user);
        $merged = array_merge($existing, $sanitised);
        $merged['updated_at'] = date('Y-m-d H:i:s');
        
        // Write to disk with pretty formatting
        $result = file_put_contents($configFile, json_encode($merged, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            return ['success' => false, 'message' => 'Failed to save configuration'];
        }
        
        // Secure the file
        chmod($configFile, 0600);
        
        // Log config update for audit trail
        if (class_exists('BackBorkLog')) {
            $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
                ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
                : (isset($_SERVER['REMOTE_ADDR']) 
                    ? $_SERVER['REMOTE_ADDR'] 
                    : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
            
            // Build human-readable list of changes
            $changes = [];
            foreach ($sanitised as $key => $value) {
                if (is_bool($value)) {
                    $changes[] = $key . '=' . ($value ? 'true' : 'false');
                } elseif (is_array($value)) {
                    $changes[] = $key . '=' . json_encode($value);
                } else {
                    $changes[] = $key . '=' . $value;
                }
            }
            BackBorkLog::logEvent($user, 'config_update', $changes, true, 'Configuration saved', $requestor);
        }
        
        return ['success' => true, 'message' => 'Configuration saved successfully'];
    }
    
    /**
     * Save a single setting to the global config file
     * 
     * Used internally to sync certain per-user settings to global config.
     * 
     * @param string $key Setting name
     * @param mixed $value Setting value
     * @return bool True if saved successfully
     */
    private function saveGlobalSetting($key, $value) {
        // Load existing global config
        $globalConfig = [];
        if (file_exists(self::GLOBAL_CONFIG_FILE)) {
            $globalConfig = json_decode(file_get_contents(self::GLOBAL_CONFIG_FILE), true) ?: [];
        }
        
        // Update the specific setting
        $globalConfig[$key] = $value;
        $globalConfig['updated_at'] = date('Y-m-d H:i:s');
        
        // Save back to disk
        $result = file_put_contents(self::GLOBAL_CONFIG_FILE, json_encode($globalConfig, JSON_PRETTY_PRINT));
        if ($result !== false) {
            chmod(self::GLOBAL_CONFIG_FILE, 0600);
            return true;
        }
        return false;
    }
    
    /**
     * Get default values for user configuration
     * 
     * These are the settings new users start with.
     * 
     * @return array Default user settings
     */
    public function getDefaults() {
        return [
            // Notification settings - channels
            'notify_email' => '',           // Email for alerts (empty = disabled)
            'slack_webhook' => '',          // Slack webhook URL (empty = disabled)
            
            // Notification settings - backup events
            'notify_backup_success' => true,    // Notify on successful backup
            'notify_backup_failure' => true,    // Notify on failed backup
            'notify_backup_start' => false,     // Notify when backup starts
            
            // Notification settings - restore events
            'notify_restore_success' => true,   // Notify on successful restore
            'notify_restore_failure' => true,   // Notify on failed restore
            'notify_restore_start' => false,    // Notify when restore starts
            
            // Notification settings - user events
            'notify_daily_summary' => false,    // Daily summary at midnight
            
            // Backup settings
            'compression_level' => '5',     // Gzip compression level (1-9)
            'temp_directory' => '/home/backbork_tmp',  // Where to stage backups
            'exclude_paths' => '',          // Paths to exclude from backup
            'default_retention' => 30,      // Days to keep backups
            'default_schedule' => 'daily',  // Default schedule frequency
            
            // Metadata
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Check if debug mode is enabled globally
     * 
     * Debug mode enables verbose logging to error_log for troubleshooting.
     * 
     * @return bool True if debug mode is on
     */
    public static function isDebugMode() {
        $globalConfig = self::GLOBAL_CONFIG_FILE;
        if (file_exists($globalConfig)) {
            $config = json_decode(file_get_contents($globalConfig), true);
            return !empty($config['debug_mode']);
        }
        return false;
    }
    
    /**
     * Log a debug message if debug mode is enabled
     * 
     * Use this throughout the codebase for troubleshooting output.
     * Messages only appear in error_log when debug mode is on.
     * 
     * @param string $message Debug message to log
     */
    public static function debugLog($message) {
        if (self::isDebugMode()) {
            error_log('[BackBork DEBUG] ' . $message);
        }
    }
    
    /**
     * Sanitise configuration input for security
     * 
     * Validates and cleans all user-provided config values
     * to prevent injection attacks and invalid data.
     * 
     * @param array $config Raw user input
     * @return array Sanitised configuration
     */
    private function sanitiseConfig($config) {
        $sanitised = [];
        
        // Email - validate format
        if (isset($config['notify_email'])) {
            $email = filter_var($config['notify_email'], FILTER_SANITIZE_EMAIL);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) || empty($email)) {
                $sanitised['notify_email'] = $email;
            }
        }
        
        // Slack webhook - validate URL format
        if (isset($config['slack_webhook'])) {
            $webhook = filter_var($config['slack_webhook'], FILTER_SANITIZE_URL);
            // Only allow Slack webhook URLs or empty
            if (empty($webhook) || preg_match('/^https:\/\/hooks\.slack\.com\//', $webhook)) {
                $sanitised['slack_webhook'] = $webhook;
            }
        }
        
        // Boolean settings - cast to bool
        $booleans = [
            // Notification flags
            'notify_backup_success', 'notify_backup_failure', 'notify_backup_start',
            'notify_restore_success', 'notify_restore_failure', 'notify_restore_start',
            'notify_daily_summary'
        ];
        foreach ($booleans as $key) {
            if (isset($config[$key])) {
                $sanitised[$key] = (bool)$config[$key];
            }
        }
        
        // Compression level - validate range 1-9
        if (isset($config['compression_level'])) {
            $level = (int)$config['compression_level'];
            if ($level >= 1 && $level <= 9) {
                $sanitised['compression_level'] = (string)$level;
            }
        }
        
        // Temp directory - sanitise path characters
        if (isset($config['temp_directory'])) {
            $dir = preg_replace('/[^a-zA-Z0-9_\/\-.]/', '', $config['temp_directory']);
            // Must be an absolute path
            if (strpos($dir, '/') === 0) {
                $sanitised['temp_directory'] = $dir;
            }
        }
        
        // Exclude paths - sanitise path characters
        if (isset($config['exclude_paths'])) {
            $paths = preg_replace('/[^a-zA-Z0-9_\/\-.\n]/', '', $config['exclude_paths']);
            $sanitised['exclude_paths'] = $paths;
        }
        
        // Retention - validate range 1-365 days
        if (isset($config['default_retention'])) {
            $retention = (int)$config['default_retention'];
            if ($retention >= 1 && $retention <= 365) {
                $sanitised['default_retention'] = $retention;
            }
        }
        
        // Pass through other recognised config keys without modification
        // These are validated elsewhere or are internal settings
        $passthrough = [
            'mysql_version', 'dbbackup_type', 'compression_option',
            'opt_incremental', 'opt_split', 'opt_use_backups',
            'skip_homedir', 'skip_publichtml', 'skip_mysql', 'skip_pgsql',
            'skip_logs', 'skip_mailconfig', 'skip_mailman', 'skip_dnszones',
            'skip_ssl', 'skip_bwdata', 'skip_quota', 'skip_ftpusers',
            'skip_domains', 'skip_acctdb', 'skip_apitokens', 'skip_authnlinks',
            'skip_locale', 'skip_passwd', 'skip_shell', 'skip_resellerconfig',
            'skip_userdata', 'skip_linkednodes', 'skip_integrationlinks',
            'db_backup_method', 'db_backup_target_dir',
            'mdb_compress', 'mdb_parallel', 'mdb_slave_info', 'mdb_galera_info',
            'mdb_parallel_threads', 'mdb_extra_args',
            'myb_compress', 'myb_incremental', 'myb_backup_dir', 'myb_extra_args'
        ];
        
        foreach ($passthrough as $key) {
            if (isset($config[$key])) {
                $sanitised[$key] = $config[$key];
            }
        }
        
        return $sanitised;
    }
    
    /**
     * Get exclude paths as an array
     * 
     * Parses the newline-separated exclude paths string into an array.
     * 
     * @param string $user Username
     * @return array List of paths to exclude from backups
     */
    public function getExcludePaths($user) {
        $config = $this->getUserConfig($user);
        $paths = isset($config['exclude_paths']) ? $config['exclude_paths'] : '';
        
        if (empty($paths)) {
            return [];
        }
        
        // Split by newlines and trim whitespace
        return array_filter(array_map('trim', explode("\n", $paths)));
    }
    
    /**
     * Get the base config directory path
     * 
     * @return string Path to config directory
     */
    public static function getConfigDir() {
        return self::CONFIG_DIR;
    }
}
