# 🔌 BackBork KISS — API Reference

Complete API documentation for BackBork's internal endpoints.

---

## 🔐 Authentication & Security

> [!IMPORTANT]
> **All API endpoints require WHM authentication.** There is no public API access.

### How Authentication Works

BackBork runs inside WHM's CGI environment. Every request is automatically authenticated by WHM's `WHM.php` library before your code executes.

```php
// This happens automatically when index.php loads
require_once('/usr/local/cpanel/php/WHM.php');

// WHM validates the session and provides:
// - $appname (always 'backbork')
// - Authenticated user context
// - Session token validation
```

### Security Layers

| Layer | Protection |
|-------|------------|
| 🔒 **WHM Session** | Must be logged into WHM with valid session |
| 🎫 **CSRF Token** | WHM's built-in token validation |
| 👤 **ACL Check** | User must have `list-accts` privilege |
| 🔑 **Ownership Filter** | Resellers only see their own accounts |

> [!CAUTION]
> **Never expose WHM ports (2086/2087) to the public internet.** Use firewall rules to restrict access to trusted IPs only.

## 🗄️ Audit Logging

BackBork writes operations and events to an audit log for operations tracing. Each entry includes:

- **Timestamp** — When the operation occurred
- **User** — Who initiated it (root/reseller)
- **Type** — Operation type with destination suffix (`backup_local`, `backup_remote`, `restore_local`, `restore_remote`)
- **Accounts** — Affected accounts with individual runtimes (e.g., `user1 (45s), user2 (1m 23s)`)
- **Success/Failure** — Operation outcome
- **Message** — Details including destination name (local) or hostname (remote) as the first line
- **Requestor** — IP address or 'cron'/'local'

The default log location is:

```
/usr/local/cpanel/3rdparty/backbork/logs/operations.log
```

Use this log to trace actions like queue add/remove, schedule create/delete, backup and restore operations, and configuration changes.

---

## 📡 Making Requests

### Base URL

```
https://your-server:2087/cgi/backbork/api/router.php
```

### Request Format

All endpoints use query parameters for the action and POST body for data:

```bash
# GET request
curl -k -H "Authorization: whm root:YOUR_API_TOKEN" \
  "https://server:2087/cgi/backbork/api/router.php?action=get_accounts"

# POST request
curl -k -X POST \
  -H "Authorization: whm root:YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"accounts":["user1"],"destination":"SFTP_Server"}' \
  "https://server:2087/cgi/backbork/api/router.php?action=create_backup"
```

> [!NOTE]
> The `-k` flag skips SSL verification. In production, use proper certificates.

### Response Format

All responses are JSON:

```json
{
  "success": true,
  "data": { ... },
  "message": "Operation completed"
}
```

Or on error:

```json
{
  "success": false,
  "error": "Error description",
  "code": "ERROR_CODE"
}
```

---

## 🖥️ CLI Access

The API can also be accessed directly from the command line, enabling automation via scripts, Ansible, or other orchestration tools.

### CLI vs HTTP

| Method | Authentication | Use Case |
|--------|----------------|----------|
| **HTTP** | WHM API token | Remote access, web UI |
| **CLI** | Root shell access | Local automation, Ansible |

### CLI Usage

```bash
php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/api/router.php \
  --action=ACTION_NAME \
  --data='JSON_PAYLOAD'
```

### CLI Examples

**GET-style actions (no data required):**

```bash
# List accounts
php router.php --action=get_accounts

# Get configuration
php router.php --action=get_config

# List destinations
php router.php --action=get_destinations

# Check cron status
php router.php --action=check_cron

# Get queue status
php router.php --action=get_queue
```

**POST-style actions (with JSON data):**

```bash
# Save configuration (partial updates supported)
php router.php --action=save_config \
  --data='{"notify_email":"admin@example.com"}'

# Save global config (partial updates supported)
php router.php --action=save_global_config \
  --data='{"schedules_locked":true}'

# Create a schedule
php router.php --action=create_schedule \
  --data='{"name":"Daily SFTP","all_accounts":true,"destination":"SFTP_Server","schedule":"daily","retention":30}'

# Queue an immediate backup
php router.php --action=queue_backup \
  --data='{"accounts":["user1","user2"],"destination":"Local","schedule":"immediate"}'

# Delete a schedule
php router.php --action=delete_schedule \
  --data='{"job_id":"schedule_abc123"}'
```

> [!NOTE]
> CLI mode bypasses WHM authentication since you must already have root shell access. This is intentional for automation use cases.

See [ORCH.md](ORCH.md) for complete Ansible playbook examples.

---

## 📋 Endpoints Reference

### Account Management

#### `GET ?action=get_accounts`

Lists accounts the current user can access.

**Response:**
```json
{
  "success": true,
  "accounts": [
    {
      "user": "someuser",
      "domain": "example.com",
      "owner": "root",
      "email": "user@example.com",
      "plan": "default",
      "suspended": false,
      "diskused": "1.2G",
      "disklimit": "unlimited"
    }
  ]
}
```

> [!TIP]
> For resellers, this automatically filters to only show accounts they own.

---

### Destination Management

#### `GET ?action=get_destinations`

Lists available backup destinations from WHM Backup Configuration.

**Response:**
```json
{
  "success": true,
  "destinations": [
    {
      "id": "SFTP_BackupServer",
      "name": "Backup Server",
      "type": "SFTP",
      "host": "backup.example.com",
      "port": 22,
      "path": "/backups",
      "enabled": true
    },
    {
      "id": "local",
      "name": "Local Storage",
      "type": "Local",
      "path": "/backup",
      "enabled": true
    }
  ]
}
```

#### `POST ?action=validate_destination`

Tests a destination connection.

**Request:**
```json
{
  "destination": "SFTP_BackupServer"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Destination validated successfully"
}
```

> [!NOTE]
> This wraps `/usr/local/cpanel/bin/backup_cmd` internally.

#### `POST ?action=enable_destination` — Root-only

Re-enables a disabled WHM backup destination. This is useful when a destination has been automatically disabled due to validation failures.

**Request:**
```json
{
  "destination": "SFTP_BackupServer"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Destination enabled successfully"
}
```

> [!NOTE]
> This calls WHM's `backup_destination_set` API with `disabled=0`. Only root users can enable destinations.

> [!WARNING]
> **Destination Validation:** When WHM disables a destination (usually due to connection failures), you should resolve the underlying issue before re-enabling. Re-enabling a misconfigured destination will just cause it to be disabled again on the next validation failure.

**Common Reasons for Disabled Destinations:**
- SFTP authentication failures (changed password/key)
- Network connectivity issues
- Remote storage full or unavailable
- Permission denied on remote path

---

### Backup Operations

#### `POST ?action=create_backup`

Starts a backup job for one or more accounts with real-time progress logging.

**Request:**
```json
{
  "accounts": ["user1", "user2"],
  "destination": "SFTP_BackupServer"
}
```

**Response:**
```json
{
  "success": true,
  "backup_id": "backup_1702234567_a1b2c3d4",
  "message": "All backups completed successfully",
  "results": {
    "user1": {"success": true, "message": "Backup completed successfully"},
    "user2": {"success": true, "message": "Backup completed successfully"}
  },
  "errors": [],
  "log": "[user1] SUCCESS: Backup completed successfully\n[user2] SUCCESS: Backup completed successfully"
}
```

> [!TIP]
> Use the `backup_id` returned to poll for real-time progress using `get_backup_log`.

#### `GET ?action=get_backup_log`

Polls the backup progress log for real-time updates. Use this to display live progress in the UI.

**Request:**
```
?action=get_backup_log&backup_id=backup_1702234567_a1b2c3d4&offset=0
```

**Response:**
```json
{
  "success": true,
  "content": "[10:30:15] ========================================\n[10:30:15] BACKBORK BACKUP OPERATION\n...",
  "offset": 1234,
  "complete": false
}
```

| Field | Description |
|-------|-------------|
| `content` | New log content since the given offset |
| `offset` | Current file size (use as next offset) |
| `complete` | `true` when backup finished (look for `BACKUP COMPLETED SUCCESSFULLY` or `BACKUP FAILED`) |

> [!TIP]
> Poll every 500ms for smooth real-time updates. Start with `offset=0` to get the full log.

#### `GET ?action=get_queue`

Returns current backup queue status.

**Response:**
```json
{
  "success": true,

#### `POST ?action=process_queue` (Manual Queue Processing) — Root-only

Manually triggers the queue processor to run scheduled jobs and process the pending queue. This action is intended for administrators (root access required) and will also be performed automatically by cron.

**Request:**
```
?action=process_queue
```

**Response:**
```json
{
  "success": true,
  "scheduled": { /* result from processSchedules */ },
  "processed": { /* result from processQueue */ }
}
```

#### `POST ?action=queue_backup`

Adds a backup job to the queue; can be used for immediate (`schedule: 'once'`) or recurring schedules (e.g., `daily`, `weekly`).

**Request:**
```json
{
  "accounts": ["user1", "user2"],
  "destination": "SFTP_BackupServer",
  "schedule": "once", // or 'daily', 'weekly', 'monthly', 'hourly'
  "retention": 30,
  "preferred_time": 2
}
```

**Response:**
```json
{
  "success": true,
  "job_id": "job_1702234567_a1b2c3d4",
  "message": "Job added to queue"
}
```

#### `POST ?action=remove_from_queue`

Removes a queued job immediately. This endpoint also supports removing schedules; if a schedule ID is provided the schedule will be removed instead.

**Request:**
```json
{ "job_id": "job_1702234567_a1b2c3d4" }
```

**Response:**
```json
{ "success": true, "message": "Job removed from queue" }
```
  "queue": {
    "pending": [
      {
        "id": "job_123",
        "accounts": ["user1"],
        "status": "pending",
        "created_at": "2024-01-15T10:00:00Z"
      }
    ],
    "running": [
      {
        "id": "job_456",
        "accounts": ["user1", "user2", "user3"],
        "status": "running",
        "started_at": "2024-01-15T10:05:00Z",
        "accounts_total": 3,
        "accounts_completed": 1
      }
    ],
    "completed": [
      {
        "id": "job_789",
        "accounts": ["user3"],
        "status": "completed",
        "success": true,
        "completed_at": "2024-01-15T09:30:00Z"
      }
    ]
  }
}
```

**Running Job Progress Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `accounts_total` | int | Total number of accounts in the backup job |
| `accounts_completed` | int | Number of accounts that have finished backing up |

Progress percentage can be calculated as: `(accounts_completed / accounts_total) * 100`

#### `POST ?action=cancel_job`

Cancels a running backup job. The job will stop after the current account backup completes — it won't interrupt a backup mid-process.

**Request:**
```json
{
  "job_id": "job_1702234567_a1b2c3d4"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Cancel request submitted for job job_1702234567_a1b2c3d4"
}
```

> [!NOTE]
> The cancel request creates a marker file that the backup worker checks after completing each account. The job will finish its current account backup, then stop and mark itself as "cancelled" in the completed jobs list.

---

### Restore Operations

#### `POST ?action=restore_backup`

Initiates a restore operation with real-time progress logging.

**Request:**
```json
{
  "account": "someuser",
  "backup_file": "backup-01.15.2024_02-00-00_someuser.tar.gz",
  "destination": "SFTP_BackupServer",
  "options": {
    "mysql": true,
    "mail_config": true,
    "subdomains": true,
    "homedir": true,
    "ssl": true,
    "cron": true
  }
}
```

**Response:**
```json
{
  "success": true,
  "restore_id": "restore_1702234567_e5f6g7h8",
  "message": "Restore initiated"
}
```

> [!IMPORTANT]
> If `options` is omitted or empty, a **full restore** is performed.

> [!TIP]
> Use the `restore_id` returned to poll for real-time progress using `get_restore_log`.

#### `GET ?action=get_restore_log`

Polls the restore progress log for real-time updates. Use this to display live progress in the UI.

**Request:**
```
?action=get_restore_log&restore_id=restore_1702234567_e5f6g7h8&offset=0
```

**Response:**
```json
{
  "success": true,
  "content": "[10:30:15] ========================================\n[10:30:15] BACKBORK RESTORE OPERATION\n...",
  "offset": 2048,
  "complete": false
}
```

| Field | Description |
|-------|-------------|
| `content` | New log content since the given offset |
| `offset` | Current file size (use as next offset) |
| `complete` | `true` when restore finished (look for `RESTORE COMPLETED SUCCESSFULLY` or `RESTORE FAILED`) |

> [!TIP]
> Poll every 500ms for smooth real-time updates. Start with `offset=0` to get the full log including download and verification steps.

#### `GET ?action=get_restore_status`

Check status of active restores.

**Request:**
```
?action=get_restore_status&restore_id=restore_1702234567_e5f6g7h8
```

**Response:**
```json
{
  "success": true,
  "restore": {
    "id": "restore_1702234567_e5f6g7h8",
    "account": "someuser",
    "status": "running",
    "started_at": "2024-01-15T10:00:00Z",
    "step": "Restoring MySQL databases"
  }
}
```

#### `GET ?action=get_backups` (Local/backups for an account)

Lists available backups stored locally for an account.

**Request:**
```
?action=get_backups&account=someuser
```

**Response:**
```json
{
  "success": true,
  "backups": [
    {
      "file": "backup-01.15.2024_02-00-00_someuser.tar.gz",
      "date": "2024-01-15T02:00:00Z",
      "size": 1073741824,
      "destination": "SFTP_BackupServer"
    },
    {
      "file": "backup-01.14.2024_02-00-00_someuser.tar.gz",
      "date": "2024-01-14T02:00:00Z",
      "size": 1048576000,
      "destination": "SFTP_BackupServer"
    }
  ]
}
```

#### `GET ?action=get_remote_backups` (Remote backups for a destination)

Lists available backups stored on a remote destination. You can optionally filter by an account substring in the filename using `account` query parameter.

**Request:**
```
?action=get_remote_backups&destination=SFTP_BackupServer&account=someuser
```

**Response:**
```json
{
  "success": true,
  "backups": [
    {
      "file": "backup-01.01.2025_12-00-00_someuser.tar.gz",
      "size": "1.2 GB",
      "date": "2025-01-01T12:00:00Z",
      "location": "remote"
    }
  ]
}
```

---

### Data Management

#### `GET ?action=get_backup_accounts`

Lists accounts that have backup files at a destination, including total storage used per account. Only returns accounts the current user can access.

**Request:**
```
?action=get_backup_accounts&destination=local
```

**Response:**
```json
{
  "success": true,
  "accounts": [
    {
      "account": "account1",
      "size": 5368709120,
      "formatted_size": "5.00 GB"
    },
    {
      "account": "account2",
      "size": 2147483648,
      "formatted_size": "2.00 GB"
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `account` | string | Account username |
| `size` | int | Total size of all backups for this account in bytes |
| `formatted_size` | string | Human-readable size (e.g., "5.00 GB") |

> [!NOTE]
> For Local destinations, size is calculated by summing all backup files. For Remote destinations, size may not be available depending on the transport.

#### `GET ?action=list_backups`

Lists backup files for a specific account at a destination.

**Request:**
```
?action=list_backups&destination=local&account=someuser
```

**Response:**
```json
{
  "success": true,
  "backups": [
    {
      "file": "backup-01.14.2024_02-00-00_someuser.tar.gz",
      "size": 1048576000,
      "modified": 1705197600
    },
    {
      "file": "backup-01.15.2024_02-00-00_someuser.tar.gz",
      "size": 1073741824,
      "modified": 1705284000
    }
  ]
}
```

> [!NOTE]
> Results are sorted by modified date (oldest first). Only works for Local destinations.

#### `POST ?action=delete_backup`

Deletes a specific backup file from a destination.

**Request:**
```json
{
  "destination": "local",
  "account": "someuser",
  "filename": "backup-01.14.2024_02-00-00_someuser.tar.gz"
}
```

**Response:**
```json
{
  "success": true,
  "message": "File deleted successfully"
}
```

> [!CAUTION]
> **This action is irreversible.** The backup file is permanently deleted.

> [!NOTE]
> Works for both Local and remote (SFTP/FTP) destinations.

> [!NOTE]
> If `reseller_deletion_locked` is enabled in global config, resellers will receive an "Access denied" error.

#### `POST ?action=bulk_delete_backups`

Deletes multiple backup files at once. Requires explicit confirmation to prevent accidents.

**Request:**
```json
{
  "destination": "local",
  "backups": [
    {"account": "user1", "filename": "backup-01.14.2024_02-00-00_user1.tar.gz", "path": "/backup/user1/backup-01.14.2024_02-00-00_user1.tar.gz"},
    {"account": "user2", "filename": "backup-01.14.2024_02-00-00_user2.tar.gz", "path": "/backup/user2/backup-01.14.2024_02-00-00_user2.tar.gz"}
  ],
  "confirm_text": "Yes, I want to bulk delete these backups.",
  "accept_undone": true
}
```

**Response:**
```json
{
  "success": true,
  "message": "Deleted 2 backup(s)",
  "deleted": ["backup-01.14.2024_02-00-00_user1.tar.gz", "backup-01.14.2024_02-00-00_user2.tar.gz"],
  "failed": []
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `destination` | Yes | Destination ID |
| `backups` | Yes | Array of backup objects with account, filename, and path |
| `confirm_text` | Yes | Must be exactly: `Yes, I want to bulk delete these backups.` |
| `accept_undone` | Yes | Must be `true` to confirm action cannot be undone |

> [!CAUTION]
> **This action is irreversible.** All selected backup files are permanently deleted.

> [!NOTE]
> Works for both Local and remote (SFTP/FTP) destinations. Each backup is validated for ACL access before deletion.

---

### Schedule Management

#### `GET ?action=get_queue` (includes schedules)

Lists queued jobs, running jobs, and schedules for the current user. Use this endpoint to fetch schedules since the router returns all queue data in one request.

**Response:**
```json
{
  "success": true,
  "queued": [],
  "running": [],
  "schedules": [
    {
      "id": "sched_abc123",
      "name": "Daily Production Backups",
      "accounts": ["user1", "user2"],
      "destination": "SFTP_BackupServer",
      "frequency": "daily",
      "hour": 2,
      "minute": 0,
      "retention_days": 30,
      "enabled": true,
      "last_run": "2024-01-15T02:00:00Z",
      "next_run": "2024-01-16T02:00:00Z"
    }
  ],
  "restores": []
}
```

#### `POST ?action=create_schedule`

Creates a new backup schedule.

**Request:**
```json
{
  "name": "Daily Important Accounts",
  "accounts": ["user1", "user2"],
  "all_accounts": false,
  "destination": "SFTP_BackupServer",
  "frequency": "daily",
  "hour": 2,
  "minute": 0,
  "retention_days": 30
}
```

**Request (All Accounts Mode):**
```json
{
  "name": "Daily All Accounts",
  "accounts": [],
  "all_accounts": true,
  "destination": "SFTP_BackupServer",
  "frequency": "daily",
  "hour": 2,
  "minute": 0,
  "retention_days": 30
}
```

| Field | Type | Description |
|-------|------|-------------|
| `all_accounts` | bool | When `true`, dynamically includes all accounts accessible to the user at runtime |

**Frequency Options:**

| Value | Description |
|-------|-------------|
| `hourly` | Every hour at the specified minute |
| `daily` | Once per day at specified hour:minute |
| `weekly` | Once per week on specified `day_of_week` (0=Sunday, 6=Saturday) at hour:minute |
| `monthly` | Once per month on the 1st at specified hour:minute |

**Schedule Time Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `hour` | int | Hour to run (0-23) |
| `minute` | int | Minute to run (0-59) |
| `day_of_week` | int | Day for weekly schedules (0=Sunday, 1=Monday, ..., 6=Saturday) |

**Response:**
```json
{
  "success": true,
  "job_id": "sched_def456",
  "message": "Schedule created"
}
```

#### `POST ?action=update_schedule`

Updates an existing schedule. Users can only update their own schedules unless root.

**Request:**
```json
{
  "job_id": "sched_abc123",
  "destination": "SFTP_Backup",
  "schedule": "weekly",
  "retention": 14,
  "preferred_time": 3,
  "day_of_week": 0,
  "accounts": ["user1", "user2"],
  "all_accounts": false
}
```

| Field | Type | Description |
|-------|------|-------------|
| `job_id` | string | **Required.** The schedule ID to update |
| `destination` | string | Destination name from `deploy_destinations.yml` |
| `schedule` | string | `daily` or `weekly` |
| `retention` | integer | Days to retain backups (0 = unlimited) |
| `preferred_time` | integer | Hour of day to run (0-23) |
| `day_of_week` | integer | Day for weekly schedules (0=Sunday, 1=Monday, etc.) |
| `accounts` | array | Account usernames to back up |
| `all_accounts` | boolean | If `true`, backs up all accessible accounts |

> [!NOTE]
> Only include fields you want to change. Omitted fields retain their current values.

**Response:**
```json
{
  "success": true,
  "message": "Schedule updated successfully",
  "schedule": { ... }
}
```

**CLI Example:**
```bash
php router.php --action=update_schedule \
  --data='{"job_id":"sched_abc123","retention":7,"schedule":"weekly","day_of_week":6}'
```

> [!TIP]
> You can also edit schedules via the WHM GUI: BackBork → Schedule → Edit button.

#### `POST ?action=delete_schedule`

Deletes a schedule.

**Request:**
```json
{
  "job_id": "sched_abc123"
}
```

> [!TIP]
> Deleting a schedule does not delete any backups that were created by it.

---

### Configuration

#### `GET ?action=get_config`

Gets the current user's configuration.

**Response:**
```json
{
  "success": true,
  "config": {
    "notify_email": "admin@example.com",
    "slack_webhook": "https://hooks.slack.com/services/...",
    "notify_backup_success": true,
    "notify_backup_failure": true,
    "notify_backup_start": false,
    "notify_restore_success": true,
    "notify_restore_failure": true,
    "notify_restore_start": false,
    "notify_daily_summary": false,
    "compression_option": "compress",
    "compression_level": "5",
    "temp_directory": "/home/backbork_tmp",
    "exclude_paths": "",
    "default_retention": 30,
    "default_schedule": "daily",
    "mysql_version": "",
    "dbbackup_type": "all",
    "db_backup_method": "pkgacct",
    "opt_incremental": false,
    "opt_split": false,
    "opt_use_backups": false,
    "skip_homedir": false,
    "skip_publichtml": false,
    "skip_mysql": false,
    "skip_pgsql": false,
    "skip_logs": true,
    "skip_mailconfig": false,
    "skip_mailman": false,
    "skip_dnszones": false,
    "skip_ssl": false,
    "skip_bwdata": true,
    "skip_quota": false,
    "skip_ftpusers": false,
    "skip_domains": false,
    "skip_acctdb": false,
    "skip_apitokens": false,
    "skip_authnlinks": false,
    "skip_locale": false,
    "skip_passwd": false,
    "skip_shell": false,
    "skip_resellerconfig": false,
    "skip_userdata": false,
    "skip_linkednodes": false,
    "skip_integrationlinks": false,
    "created_at": "2024-01-15 10:00:00",
    "updated_at": "2024-01-15 14:30:00"
  }
}
```

> [!TIP]
> See [TECHNICAL.md](TECHNICAL.md#config-fields-explained) for a full explanation of each config field.

#### `POST ?action=save_config`

Saves user configuration. You can send partial updates — only the fields you include will be changed.

**Request (minimal):**
```json
{
  "notify_email": "admin@example.com",
  "notify_backup_failure": true
}
```

**Request (full):**
```json
{
  "notify_email": "admin@example.com",
  "slack_webhook": "https://hooks.slack.com/services/...",
  "notify_backup_success": true,
  "notify_backup_failure": true,
  "notify_backup_start": false,
  "notify_restore_success": true,
  "notify_restore_failure": true,
  "notify_restore_start": false,
  "notify_daily_summary": false,
  "compression_option": "compress",
  "compression_level": "5",
  "temp_directory": "/home/backbork_tmp",
  "default_retention": 30,
  "default_schedule": "daily",
  "mysql_version": "",
  "dbbackup_type": "all",
  "db_backup_method": "pkgacct",
  "opt_incremental": false,
  "opt_split": false,
  "opt_use_backups": false,
  "skip_homedir": false,
  "skip_publichtml": false,
  "skip_mysql": false,
  "skip_pgsql": false,
  "skip_logs": true,
  "skip_mailconfig": false,
  "skip_mailman": false,
  "skip_dnszones": false,
  "skip_ssl": false,
  "skip_bwdata": true,
  "skip_quota": false,
  "skip_ftpusers": false,
  "skip_domains": false,
  "skip_acctdb": false,
  "skip_apitokens": false,
  "skip_authnlinks": false,
  "skip_locale": false,
  "skip_passwd": false,
  "skip_shell": false,
  "skip_resellerconfig": false,
  "skip_userdata": false,
  "skip_linkednodes": false,
  "skip_integrationlinks": false
}
```

> [!NOTE]
> Each user (root, reseller) has their own separate configuration.

---

### Global Configuration (Root Only)

#### `GET ?action=get_global_config`

Gets the global configuration. **Root only.**

**Response:**
```json
{
  "success": true,
  "config": {
    "schedules_locked": false,
    "reseller_deletion_locked": false,
    "debug_mode": false,
    "updated_at": "2024-01-15 14:30:00"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `schedules_locked` | bool | When `true`, resellers cannot create, edit, or delete schedules |
| `reseller_deletion_locked` | bool | When `true`, resellers cannot delete backups |
| `debug_mode` | bool | When `true`, verbose debug logging is enabled |

> [!WARNING]
> Non-root users will receive an error if they attempt to access this endpoint.

#### `POST ?action=save_global_config`

Saves global configuration. **Root only.**

**Request:**
```json
{
  "schedules_locked": true,
  "reseller_deletion_locked": false,
  "debug_mode": false
}
```

**Response:**
```json
{
  "success": true,
  "message": "Global configuration saved"
}
```

> [!TIP]
> Enable `schedules_locked` to prevent resellers from creating or modifying backup schedules. Existing schedules will continue to run.

> [!TIP]
> Enable `reseller_deletion_locked` to prevent resellers from deleting backups. They will see an advisory notice on the Data page.

---

### Utility Endpoints

#### `GET ?action=get_db_info`

Returns MySQL/MariaDB server information.

**Response:**
```json
{
  "success": true,
  "database": {
    "type": "MariaDB",
    "version": "10.6.12",
    "mariadb_backup_available": true,
    "mysqlbackup_available": false
  }
}
```

#### `GET ?action=check_cron`

Verifies the cron job is configured correctly.

**Response:**
```json
{
  "success": true,
  "cron": {
    "installed": true,
    "file": "/etc/cron.d/backbork",
    "last_run": "2024-01-15T10:00:00Z"
  }
}
```

#### `POST ?action=test_notification`

Sends a test notification.

**Request:**
```json
{
  "type": "email"
}
```

Or for Slack:
```json
{
  "type": "slack"
}
```

#### `GET ?action=get_logs`

Retrieves operation logs with optional filtering.

**Request:**
```
?action=get_logs&job_id=job_1702234567_a1b2c3d4
```

Or for recent logs:
```
?action=get_logs&lines=100
```

Or filter by account:
```
?action=get_logs&account=someuser
```

**Filter Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `job_id` | string | Get logs for a specific job |
| `lines` | int | Number of recent log lines to return |
| `account` | string | Filter logs to show only entries for this account |

---

### Updates

#### `GET ?action=check_update`

Checks if a newer version is available on GitHub.

**Response:**
```json
{
  "success": true,
  "local_version": "1.4.2",
  "remote_version": "1.4.3",
  "update_available": true
}
```

---

#### `POST ?action=perform_update`

Triggers a self-update to the latest version. **Root only.**

The update runs in the background and survives the plugin being overwritten. Notifications are sent upon completion.

**Response:**
```json
{
  "success": true,
  "message": "Update started. You will be notified when complete.",
  "log_file": "/usr/local/cpanel/3rdparty/backbork/logs/update.log"
}
```

**Notifications Sent:**

| Recipient | Method |
|-----------|--------|
| System root email | Email via /etc/aliases forward |
| Plugin contact email | Email (if configured) |
| Plugin Slack webhook | Slack (if configured) |

> [!WARNING]
> The web interface may become temporarily unavailable during the update. Refresh after receiving the completion notification.

---

## 🚨 Error Codes

| Code | Description |
|------|-------------|
| `AUTH_REQUIRED` | Not authenticated to WHM |
| `ACCESS_DENIED` | User lacks permission for this action |
| `INVALID_ACTION` | Unknown action parameter |
| `INVALID_PARAMS` | Missing or invalid request parameters |
| `ACCOUNT_NOT_FOUND` | Specified account doesn't exist |
| `DESTINATION_NOT_FOUND` | Invalid destination ID |
| `DESTINATION_DISABLED` | Destination is disabled |
| `BACKUP_NOT_FOUND` | Backup file not found |
| `JOB_NOT_FOUND` | Job ID not found |
| `SCHEDULE_NOT_FOUND` | Schedule ID not found |
| `ALREADY_RUNNING` | A backup/restore is already running for this account |
| `TRANSPORT_FAILED` | Failed to upload/download via transport |
| `INTERNAL_ERROR` | Unexpected server error |

---

## 🔄 Rate Limiting

> [!NOTE]
> BackBork does not implement its own rate limiting. However, WHM naturally limits concurrent connections per session.

### Best Practices

1. **Don't poll aggressively** — Check queue status every 30-60 seconds, not every second
2. **Batch operations** — Backup multiple accounts in one request
3. **Use webhooks** — Configure Slack/email notifications instead of polling
4. **Respect job limits** — Default concurrent job limit is 2

---

## 🔧 Integration Examples

### Bash Script: Backup All Accounts

```bash
#!/bin/bash

API_TOKEN="your_whm_api_token"
SERVER="your-server.com"

# Get all accounts
ACCOUNTS=$(curl -sk \
  -H "Authorization: whm root:$API_TOKEN" \
  "https://$SERVER:2087/cgi/backbork/api/router.php?action=get_accounts" \
  | jq -r '.accounts[].user' | tr '\n' ',' | sed 's/,$//')

# Start backup
curl -sk -X POST \
  -H "Authorization: whm root:$API_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"accounts\":[\"${ACCOUNTS//,/\",\"}\"],\"destination\":\"SFTP_Server\"}" \
  "https://$SERVER:2087/cgi/backbork/api/router.php?action=create_backup"
```

# Delete a schedule
curl -sk -X POST \
  -H "Authorization: whm root:$API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"job_id":"sched_def456"}' \
  "https://$SERVER:2087/cgi/backbork/api/router.php?action=delete_schedule"

### PHP: Check Backup Status

```php
<?php
$server = 'your-server.com';
$token = 'your_whm_api_token';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "https://{$server}:2087/cgi/backbork/api/router.php?action=get_queue",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        "Authorization: whm root:{$token}"
    ]
]);

$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($response['success']) {
    $running = count($response['queue']['running']);
    $pending = count($response['queue']['pending']);
    echo "Running: {$running}, Pending: {$pending}\n";
}
```

---

## 📚 Related Documentation

| Resource | Link |
|----------|------|
| 📖 **README** | [README.md](README.md) |
| 🔧 **Technical Docs** | [TECHNICAL.md](TECHNICAL.md) |
| ⏰ **Cron Configuration** | [CRON.md](CRON.md) |
| 🐛 **Report Issues** | [GitHub Issues](https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/issues) |

---

**Made with 💜 by [The Network Crew Pty Ltd](https://tnc.works) & [Velocity Host Pty Ltd](https://velocityhost.com.au)** 💜
