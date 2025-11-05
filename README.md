# Lexware Mail Import

Fetch IMAP emails, extract voucher PDFs, persist metadata, and upload files to the Lexware Office Public API. Built on Symfony 7.3 and PHP 8.2+.

Updated: 2025-11-05

---

## Table of Contents
- Overview
- Features
- Requirements
- Quick start
- Installation
- Configuration
  - Environment variables (.env)
  - Service parameters (config/services.yaml)
- Database & storage
- Running the importer (CLI)
- Logging & observability
- Architecture overview
- Error handling & retries
- Troubleshooting
- Docker/Compose notes
- Development

---

## Overview
This service connects to an IMAP mailbox, finds attachments, filters them to PDF vouchers, stores them on disk and in the database, and uploads them to Lexware over HTTPS. It is designed for reliability, clear logging, and easy operations.

## Features
- IMAP fetch with multiple providers to extract attachments
- PDF detection and minimal magic-header validation
- Persistent tracking of imported attachments; idempotent uploads
- Robust Lexware upload client with retries/backoff
- Structured logging with dedicated channels (`lexware`, `importer`)
- Email notifications on upload failures
- Configurable limits and behavior via `.env` and DI parameters

## Requirements
- PHP >= 8.2
- Extensions: `ext-imap`, `ext-fileinfo`, `ext-ctype`, `ext-iconv`
- Composer
- Database supported by Doctrine (example config uses PostgreSQL 16)
- Access to IMAP server
- Lexware Office Public API base URL and API key

## Quick start
```bash
# 1) Install dependencies
composer install

# 2) Create .env.local and configure your environment (see below)
cp .env .env.local  # then edit .env.local

# 3) Create database & run migrations
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate -n

# 4) Ensure storage directory exists and is writable
mkdir -p var/uploads/lexware
chmod -R 775 var/uploads

# 5) Run the importer once
php bin/console app:import-mails --limit=50 --unseen
```

## Installation
- Clone the repository.
- Run `composer install`.
- Configure environment variables (`.env.local` recommended).
- Prepare the database and storage directory.

## Configuration
Configuration is split between environment variables (`.env`, `.env.local`) and Symfony service parameters (`config/services.yaml`).

### Environment variables (.env)
Key variables and their meaning (defaults are in `.env`; override in `.env.local` for your environment):

Lexware API
- `LEXWARE_BASE_URI` (e.g. `https://api.lexware.io`): Base URL of the API
- `LEXWARE_API_KEY`: API token (used as Bearer by default)
- `LEXWARE_TENANT` (optional): Tenant header
- `LEXWARE_UPLOAD_ENDPOINT` (default `/v1/files`): Upload path

Mailer for error notifications
- `MAILER_DSN`: Symfony Mailer DSN
- `MAILER_FROM`: From address used by notifier
- `MAILER_TO`: Destination for error notifications

IMAP credentials
- `IMAP_HOST`, `IMAP_PORT` (e.g. 993)
- `IMAP_ENCRYPTION` (`ssl`, `tls`, or empty)
- `IMAP_USERNAME`, `IMAP_PASSWORD`
- `IMAP_MAILBOX` (default `INBOX`)
- `IMAP_SEARCH` (default `UNSEEN`)

Framework/DB
- `APP_ENV` (`dev`/`prod`)
- `APP_SECRET`
- `DEFAULT_URI` (for URL generation in CLI)
- `DATABASE_URL` (Doctrine connection string)

Misc
- `PDF_STORAGE_DIR`: Optional external path to storage; otherwise see `app.pdf_storage_dir` below

### Service parameters (config/services.yaml)
- `app.pdf_storage_dir`: Default storage directory for persisted PDFs (`%kernel.project_dir%/var/uploads/lexware`)
- `app.upload_max_bytes`: Max upload file size in bytes (default 5MB)
- `app.allowed_mimes`: Allowed MIME types for upload validation
  - `application/pdf`, `image/png`, `image/jpeg`, `application/xml`, `text/xml`
- `app.http_retry_max_attempts`: Max attempts for transient HTTP errors (default 3)
- `app.http_retry_base_sleep_ms`: Base backoff in milliseconds (default 250ms; exponential 2^n)

These parameters are injected into services:
- `FileInspector` uses `app.upload_max_bytes` and `app.allowed_mimes`
- `LexwareClient` uses `app.http_retry_*` values

## Database & storage
- Doctrine entities record imported mail and PDFs. Ensure your database is created and migrations are applied.
- Storage directory: files are saved under `app.pdf_storage_dir`.
  - Make sure the directory exists and the application has read/write permissions.

## Running the importer (CLI)
The primary entry point is a Symfony Console command:

```bash
php bin/console app:import-mails [options]
```

Options:
- `--since=YYYY-MM-DD` Lower bound for message date
- `--limit=NUM` Max messages to process (default `50`)
- `--from=STRING` Filter: substring match in From address
- `--subject-contains=STRING` Filter: substring match in Subject
- `--mailbox=NAME` Select mailbox/folder (e.g., `INBOX`)
- `--unseen` Only unseen messages
- `--seen` Only seen messages (mutually exclusive with `--unseen`)

Examples:
```bash
# Process up to 100 unseen messages since Oct 1st
php bin/console app:import-mails --since=2025-10-01 --limit=100 --unseen

# Process messages in a specific folder
php bin/console app:import-mails --mailbox=Invoices

# Filter by sender and subject
php bin/console app:import-mails --from=vendor@example.com --subject-contains=Invoice
```

## Logging & observability
Monolog is configured with dedicated channels and handlers in `config/packages/monolog.yaml`:
- Channels: `lexware`, `importer`
- Files (default dev):
  - `%kernel.logs_dir%/lexware.log` for Lexware client activity (level `info`)
  - `%kernel.logs_dir%/importer.log` for importer flow (level `notice`)
  - Environment logs under `%kernel.logs_dir%/%kernel.environment%.log`
- Production uses JSON formatting to stderr and a fingers_crossed handler

Components log key events:
- `Importer` logs imported attachments and skip conditions
- `VoucherUploader` logs preflight meta and upload outcomes
- `LexwareClient` logs multipart header lines (safe), responses, retries, and errors
- `ErrorNotifier` logs both successful notifications and failures

## Architecture overview
Core components and responsibilities:
- `Importer`: Orchestrates the pipeline (fetch -> attach -> PDF filter -> persist -> upload -> flush)
- `VoucherUploader`: Owns preflight validation and upload of a single PDF, updates entity flags/IDs
- `LexwareClient`: Builds multipart requests to Lexware and handles retries/backoff + error mapping
- `FileInspector`: Validates file presence, size, MIME and basic `%PDF-` magic header for PDFs
- `ErrorNotifier`: Sends email on failures and logs outcomes
- `Attachment` providers (`WebklexAttachmentProvider`, `RawMimeAttachmentProvider`, `ExtImapAttachmentProvider`) via `AttachmentChainProvider`
- `WebklexMessageFetcher` and `ImapConnectionFactory`: Fetch and filter IMAP messages
- `MailPersister`: Persists `ImportedMail`/`ImportedPdf` and flushes in controlled batches

Data model highlights:
- `ImportedMail` and `ImportedPdf` entities
- `ImportedPdf` contains flags: `synced`, `lexwareFileId`, `lexwareVoucherId`, `lastError`, plus `size`, `mime`, `fileHash`

## Error handling & retries
- Preflight validation failures raise `UploadPreflightException` (treated as validation errors; not retried)
- Lexware HTTP errors map to `LexwareHttpException` with a `statusCode`
  - 406: Not Acceptable (likely file type/extension or e‑invoice not enabled)
  - 409: Conflict (possible duplicate). Body is returned if parseable
- Transient conditions (408/429/5xx and transport exceptions) are retried with exponential backoff
- Any PHP warnings/notices during upload are converted to `ErrorException` and logged

## Troubleshooting
- 406 Not Acceptable: Check allowed MIME types, ensure the file really is a PDF (`%PDF-` header) or enable e‑invoice
- 409 Conflict: Likely duplicate upload; confirm file hash and prior `lexwareFileId`
- IMAP connection errors: Verify host/port/encryption and credentials; ensure `IMAP_MAILBOX` exists
- Permission denied on storage: Ensure `app.pdf_storage_dir` exists and is writable by the app user
- API auth failures: Verify `LEXWARE_API_KEY` and base URI/endpoints
- Email notifications not sent: Check `MAILER_DSN`, from/to addresses; review `importer.log`

## Docker/Compose notes
This repo contains `compose.yaml` and `compose.override.yaml`. Adjust services to your environment if you plan to run the importer inside containers. Ensure volumes include the `var/uploads` directory and that `.env` variables are passed.

## Development
- Coding style: Symfony conventions
- Suggested next steps
  - Add PHPUnit tests for `FileInspector`, `LexwareClient` (with mocked `HttpClientInterface`), `VoucherUploader`/`Importer`
  - Add a CS fix tool and `composer` scripts (e.g., `cs-fix`, `test`)
  - Optionally integrate PHPStan (level ~7) with a small baseline
- Logging: adjust levels in `config/packages/monolog.yaml` to fit your environment

---

If you have questions or need to extend behavior (new attachment providers, additional MIME types, different retry policy), see `config/services.yaml` and the classes under `src/Service`, `src/Attachment`, and `src/Imap`.
