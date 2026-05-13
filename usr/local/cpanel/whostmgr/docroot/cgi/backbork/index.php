<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Wraps pkgacct, Cpanel::Transport::Files, and backup_restore_manager
 *   with destination support from WHM Backup Configuration
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

// Load version constant
require_once(__DIR__ . '/version.php');

// Error handling - log errors but don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include WHM PHP library (handles headers automatically)
require_once('/usr/local/cpanel/php/WHM.php');

// Load Bootstrap
require_once(__DIR__ . '/app/Bootstrap.php');

// Handle AJAX requests - route to API
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    require_once(__DIR__ . '/api/router.php');
    exit;
}

// Handle serve_download: binary file stream routed through the AppConfig-registered URL
// (WHM blocks direct requests to api/router.php for root/reseller-all sessions)
if (isset($_GET['action']) && $_GET['action'] === 'serve_download') {
    require_once(__DIR__ . '/api/router.php');
    exit;
}

// Initialise Bootstrap (handles ACL check)
if (!BackBorkBootstrap::init()) {
    BackBorkBootstrap::accessDenied();
}

// Get ACL and user info for template
$acl = BackBorkBootstrap::getACL();
$currentUser = $acl->getCurrentUser();
$isRoot = $acl->isRoot();

// Display WHM header
WHM::header('BackBork KISS', 0, 0);

// Include CSS
echo '<link rel="stylesheet" type="text/css" href="app/css/styles.css">';

// Include main GUI template
include(__DIR__ . '/includes/gui/main.php');

// Include JavaScript
echo '<script src="app/js/scripts.js"></script>';

// Display WHM footer
WHM::footer();
