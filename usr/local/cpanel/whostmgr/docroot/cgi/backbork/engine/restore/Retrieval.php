<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Backup file retrieval service for restore operations.
 *   Downloads from remote destinations or locates local backups for restore.
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
 * Backup file retrieval service.
 * Downloads backup files from remote destinations or locates local backups.
 * Handles verification, cleanup of retrieved files, and staged download tokens.
 */
class BackBorkRetrieval {
    
    // Temporary directory for staging downloaded backups
    const TEMP_DIR = '/home/backbork_tmp';

    // Directory for staged download token manifests
    const DOWNLOADS_DIR = '/usr/local/cpanel/3rdparty/backbork/downloads';
    
    /** @var BackBorkDestinationsParser Parses WHM transport destinations */
    private $destinations;
    
    /**
     * Constructor - Initialise destination parser.
     */
    public function __construct() {
        $this->destinations = new BackBorkDestinationsParser();
    }
    
    /**
     * Retrieve backup file from a destination.
     * For local destinations, returns path directly.
     * For remote destinations, downloads file to temp directory.
     * 
     * @param string $destinationID Destination ID from WHM transport config
     * @param string $backupFile Backup file path at destination
     * @param string $localPath Optional specific local path to save to
     * @return array Result with success status and local_path
     */
    public function retrieveBackup($destinationID, $backupFile, $localPath = null) {
        // Look up destination configuration by ID
        $destination = $this->destinations->getDestinationByID($destinationID);
        
        // Validate destination exists
        if (!$destination) {
            return ['success' => false, 'message' => 'Destination not found'];
        }
        
        // For local destinations, just verify and return the path
        if (strtolower($destination['type']) === 'local') {
            // Construct full path by combining destination path and backup file
            $fullPath = rtrim($destination['path'], '/') . '/' . ltrim($backupFile, '/');
            
            // Verify file exists
            if (!file_exists($fullPath)) {
                return ['success' => false, 'message' => 'Backup file not found: ' . $fullPath];
            }
            
            return [
                'success' => true,
                'local_path' => $fullPath,
                'size' => filesize($fullPath)
            ];
        }
        
        // For remote destinations, download the file to temp directory
        $tempDir = self::TEMP_DIR;
        
        // Ensure temp directory exists with secure permissions
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0700, true);
        }
        
        // Use specified local path or generate one in temp directory
        if (!$localPath) {
            $localPath = $tempDir . '/' . basename($backupFile);
        }
        
        // Get appropriate transport handler for this destination type
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        // Download file from remote destination
        $result = $transport->download($backupFile, $localPath, $destination);
        
        // Add local path and size to result on success
        if ($result['success']) {
            $result['local_path'] = $localPath;
            $result['size'] = file_exists($localPath) ? filesize($localPath) : 0;
        }
        
        return $result;
    }
    
    /**
     * List available backups from a destination.
     * Parses filenames to extract account names and timestamps.
     * 
     * @param string $destinationID Destination ID from WHM transport config
     * @param string $accountFilter Optional account filter to narrow results
     * @return array Result with success status and list of backups
     */
    public function listAvailableBackups($destinationID, $accountFilter = null) {
        // Look up destination configuration by ID
        $destination = $this->destinations->getDestinationByID($destinationID);
        
        // Validate destination exists
        if (!$destination) {
            return ['success' => false, 'backups' => [], 'message' => 'Destination not found'];
        }
        
        // Get appropriate transport handler for this destination type
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        // List files at destination (filter by account if provided)
        $path = $accountFilter ? $accountFilter : '';
        $files = $transport->listFiles($path, $destination);
        
        // Parse backup files and extract metadata
        $backups = [];
        foreach ($files as $file) {
            $filename = $file['file'];
            
            // Extract account from backup filename
            // Official format: backup-MM.DD.YYYY_HH-MM-SS_USERNAME.tar.gz
            $account = null;
            $timestamp = null;
            
            if (preg_match('/^backup-(\\d{2})\\.(\\d{2})\\.(\\d{4})_(\\d{2})-(\\d{2})-(\\d{2})_([a-z0-9_]+)\\.tar(\\.gz)?$/i', $filename, $matches)) {
                // Official format
                $account = $matches[7];
                $timestamp = "{$matches[3]}-{$matches[1]}-{$matches[2]} {$matches[4]}:{$matches[5]}:{$matches[6]}";
            } else {
                // Skip non-backup files
                continue;
            }
            
            // Build path including account folder if we listed inside it
            $filePath = $path ? $path . '/' . $filename : $filename;
            
            $backups[] = [
                'file' => $filename,
                'path' => $filePath,
                'account' => $account,
                'size' => $file['size'] ?? 0,
                'date' => $timestamp ?? ($file['date'] ?? 'Unknown'),
                'destination' => $destinationID
            ];
        }
        
        // Sort by date descending (most recent first)
        usort($backups, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
        
        return ['success' => true, 'backups' => $backups];
    }
    
    /**
     * Find local WHM backup directory.
     * Checks common cPanel backup locations.
     * 
     * @return string|null Path to backup directory or null if not found
     */
    public function findLocalBackupDirectory() {
        // Common cPanel backup locations in order of preference
        $possiblePaths = [
            '/backup',
            '/backup/cpbackup',
            '/backup/daily',
            '/backup/weekly',
            '/backup/monthly',
            '/home/backup'
        ];
        
        // Return first existing directory
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }
        
        return null;
    }
    
    /**
     * Find backups from WHM's standard backup locations.
     * Searches daily, weekly, and monthly backup directories.
     * 
     * @param string $account Account username to search for
     * @return array List of backup files with metadata
     */
    public function findCpanelBackups($account) {
        $backups = [];
        
        // Standard cPanel backup locations
        $locations = [
            '/backup/cpbackup/daily',
            '/backup/cpbackup/weekly',
            '/backup/cpbackup/monthly',
            '/backup/daily',
            '/backup/weekly',
            '/backup/monthly'
        ];
        
        foreach ($locations as $location) {
            // Skip if directory doesn't exist
            if (!is_dir($location)) continue;
            
            // Check for account-specific subdirectory
            $accountDir = $location . '/' . $account;
            if (is_dir($accountDir)) {
                // Find all backup-*_{account}.tar.gz files in account directory
                $files = glob($accountDir . '/backup-*_' . $account . '.tar.gz');
                foreach ($files as $file) {
                    $backups[] = [
                        'file' => basename($file),
                        'path' => $file,
                        'account' => $account,
                        'size' => filesize($file),
                        'date' => date('Y-m-d H:i:s', filemtime($file)),
                        'source' => basename($location),  // e.g., 'daily', 'weekly'
                        'destination' => 'local'
                    ];
                }
            }
            
            // Also check for backup files directly in location directory
            $pattern = $location . '/backup-*_' . $account . '.tar.gz';
            $files = glob($pattern);
            foreach ($files as $file) {
                if (is_file($file)) {
                    $backups[] = [
                        'file' => basename($file),
                        'path' => $file,
                        'account' => $account,
                        'size' => filesize($file),
                        'date' => date('Y-m-d H:i:s', filemtime($file)),
                        'source' => basename($location),
                        'destination' => 'local'
                    ];
                }
            }
        }
        
        // Sort by date descending (most recent first)
        usort($backups, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
        
        return $backups;
    }
    
    /**
     * Verify retrieved backup file integrity.
     * Checks file exists, has correct extension, and is a valid tar.gz archive.
     * 
     * @param string $localPath Absolute path to backup file
     * @return array Validation result with 'valid' flag and message
     */
    public function verifyBackupFile($localPath) {
        // Check file exists
        if (!file_exists($localPath)) {
            return ['valid' => false, 'message' => 'File not found'];
        }
        
        // Check file extension (must be .tar.gz or .tgz)
        if (!preg_match('/\.(tar\.gz|tgz)$/i', $localPath)) {
            return ['valid' => false, 'message' => 'Invalid file format - expected .tar.gz'];
        }
        
        // Verify it's a valid tar.gz archive (try to list contents)
        $output = [];
        $returnCode = 0;
        exec('tar -tzf ' . escapeshellarg($localPath) . ' > /dev/null 2>&1', $output, $returnCode);
        
        // Non-zero return code indicates corrupt or invalid archive
        if ($returnCode !== 0) {
            return ['valid' => false, 'message' => 'File appears to be corrupted'];
        }
        
        // Check for expected cpmove structure in archive
        $output = [];
        exec('tar -tzf ' . escapeshellarg($localPath) . ' 2>/dev/null | head -20', $output);
        
        $hasCpmoveDir = false;
        $hasHomedir = false;
        
        // Look for cpmove directory or homedir in archive listing
        foreach ($output as $line) {
            if (strpos($line, 'cpmove-') === 0) $hasCpmoveDir = true;
            if (strpos($line, 'homedir') !== false) $hasHomedir = true;
        }
        
        // Must have either cpmove directory or homedir to be valid WHM backup
        if (!$hasCpmoveDir && !$hasHomedir) {
            return ['valid' => false, 'message' => 'Not a valid WHM backup format'];
        }
        
        return [
            'valid' => true,
            'message' => 'Backup file verified',
            'size' => filesize($localPath)
        ];
    }
    
    /**
     * Clean up old temporary downloaded files.
     * Removes files older than specified hours to free disk space.
     * 
     * @param int $olderThanHours Delete files older than this many hours (default 24)
     * @return int Number of files deleted
     */
    public function cleanupTempFiles($olderThanHours = 24) {
        $tempDir = self::TEMP_DIR;
        
        // Skip if temp directory doesn't exist
        if (!is_dir($tempDir)) {
            return 0;
        }
        
        $deleted = 0;
        
        // Calculate cutoff timestamp
        $cutoff = time() - ($olderThanHours * 3600);
        
        // Get all files in temp directory
        $files = glob($tempDir . '/*');
        
        // Delete files older than cutoff
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }

    // ========================================================================
    // STAGED DOWNLOAD TOKEN MANAGEMENT
    // ========================================================================

    /**
     * Write a download token manifest to disk.
     * Token files are stored in DOWNLOADS_DIR and contain all metadata
     * needed to authorise and serve the download later.
     *
     * @param array $data Associative array with keys:
     *   token, user, account, filename, destination, staged_path,
     *   status (staging|ready|failed), expires_at, error (optional)
     */
    public function writeTokenManifest(array $data) {
        $dir = self::DOWNLOADS_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . $data['token'] . '.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        chmod($file, 0600);
    }

    /**
     * Read a download token manifest from disk.
     * Returns null if the file does not exist or the token has expired.
     *
     * @param string $token Token string (validated before calling)
     * @return array|null Manifest data, or null on missing/expired
     */
    public function readTokenManifest($token) {
        $file = self::DOWNLOADS_DIR . '/' . $token . '.json';
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        // Treat expired manifests as non-existent
        if (isset($data['expires_at']) && $data['expires_at'] < time()) {
            return null;
        }
        return $data;
    }

    /**
     * Delete all expired token manifest files from the downloads directory.
     * Called by the cron handler on every run alongside cleanupTempFiles().
     *
     * @return int Number of manifest files deleted
     */
    public function cleanupExpiredTokens() {
        $dir = self::DOWNLOADS_DIR;
        if (!is_dir($dir)) {
            return 0;
        }
        $deleted = 0;
        foreach (glob($dir . '/*.json') as $file) {
            if (!is_file($file)) continue;
            $data = json_decode(file_get_contents($file), true);
            $expired = !is_array($data)
                || !isset($data['expires_at'])
                || $data['expires_at'] < time();
            if ($expired) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }
}
