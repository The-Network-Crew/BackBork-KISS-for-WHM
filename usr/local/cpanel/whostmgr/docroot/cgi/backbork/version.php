<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Defines the current plugin version number (semantic versioning).
 *   Included by all PHP files for version consistency across the plugin.
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

// Plugin Version - follows semantic versioning (MAJOR.MINOR.PATCH)
define('BACKBORK_VERSION', '1.4.8');

// Last Commit - populated by install.sh/updater.sh from git
// BACKBORK_COMMIT: short hash (e.g., "abc1234") or "dev" if not from git
// BACKBORK_COMMIT_DATE: timestamp (e.g., "2025-12-22 15:51:05") or empty
define('BACKBORK_COMMIT', 'unknown');
define('BACKBORK_COMMIT_DATE', '');
