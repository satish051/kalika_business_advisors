# Security Architecture & Policies

## Overview
This application uses a highly secured, flat-file JSON architecture to manage the frontend presentation without a SQL database.

## Secret Management
- **Configuration**: System variables are managed via `.env`.
- **Credentials**: Stored in `storage/auth.json`. Passwords are hashed using standard PHP `password_hash()` and dynamically rehashed when newer algorithms or cost factors are detected by `password_needs_rehash()`.

## Password Policy
The administrative portal enforces strict password complexity to mitigate credential stuffing and dictionary attacks:
- Minimum length: 12 characters.
- Must contain: Uppercase, lowercase, numeric, and special characters.
- Blacklisted terms: Common dictionary passwords (`password123`) and contextual identifiers (`admin`, `kalika`).
- History Enforcement: The last 5 passwords cannot be reused.

## Backup & Recovery Policy
- **Automatic Snapshots**: The system automatically generates a versioned snapshot of `data.json` before any successful mutation.
- **Checksum Integrity**: Backups are accompanied by a `.sha256` mathematical checksum.
- **Restoration**: Restoring a backup requires re-authentication via the administrator's password and a validation of the checksum to ensure the backup file was not maliciously altered on the filesystem.

## Logging Policy
- **Audit Trails**: All security events (login success, login failure, password changes, CSRF failures, backup restorations) are logged.
- **Log Format**: Logs are written exclusively in `JSON Lines` format (`.jsonl`) to prevent log injection payloads via carriage-returns.
- **PII Scrubbing**: Passwords, session cookies, and sensitive tokens are completely excluded from logs.

## Incident Response Steps
If a security breach or anomaly is detected:
1. Revoke the active session by terminating the PHP process or deleting the active session files in the OS temp directory.
2. Review the structured `storage/audit-YYYY-MM-DD.jsonl` logs for unauthorized IP addresses or brute-force warnings.
3. If `data.json` was compromised, utilize the Backup Restore feature in the dashboard to revert to a pre-breach state, as backup integrity is guaranteed by SHA256 checksums.
4. Rotate the administrator password via `settings.php`.
