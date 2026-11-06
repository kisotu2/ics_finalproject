# Smart Asset Management System

**Machine Learning Enabled Smart Asset Management System for Predictive Maintenance and Asset Lifecycle Management**

A web-based ICT asset management application designed to help organisations maintain asset records, manage assignments, track maintenance activities, monitor asset lifecycle status and support maintenance planning through machine learning.

**Repository:** [kisotu2/ics_finalproject](https://github.com/kisotu2/ics_finalproject)

---

## Table of Contents

- [Project Overview](#project-overview)
- [Objectives](#objectives)
- [Key Features](#key-features)
- [Technology Stack](#technology-stack)
- [System Architecture](#system-architecture)
- [Predictive Maintenance Model](#predictive-maintenance-model)
- [Requirements](#requirements)
- [Installation and Setup](#installation-and-setup)
- [Database Migrations](#database-migrations)
- [Active Directory Laptop Inventory](#active-directory-laptop-inventory)
- [Running the Flask API](#running-the-flask-api)
- [Training the Model](#training-the-model)
- [Security Considerations](#security-considerations)
- [Release Archive Contents](#release-archive-contents)
- [Testing and Evaluation](#testing-and-evaluation)
- [Development Workflow](#development-workflow)
- [Limitations and Future Improvements](#limitations-and-future-improvements)

## Project Overview

Organisations depend on ICT equipment such as laptops to support their daily operations. Managing these assets requires accurate inventory records, controlled assignment, maintenance tracking and reliable information about their operational condition.

The Smart Asset Management System provides a centralised application for managing ICT assets throughout their operational lifecycle. It combines a PHP-based web application and MySQL database with a Python Flask API that serves a Logistic Regression model for predictive maintenance.

The predictive-maintenance component estimates whether an asset may require maintenance within the next 30 days based on its recorded repair history, age, warranty information and available usage indicators. The resulting risk assessment supports administrators in prioritising inspections and maintenance activities.

The system is intended as an administrative decision-support tool. Predictions should be considered alongside maintenance records, physical inspections and professional judgement.

## Objectives

The project aims to:

1. Centralise ICT asset registration and inventory information.
2. Support controlled asset assignment and lifecycle tracking.
3. Maintain records of reported issues, repairs and maintenance status.
4. Provide administrative reporting and accountability through access control and audit records.
5. Integrate a machine-learning model to estimate short-term maintenance risk.
6. Provide a modular architecture that separates the PHP application from the Python prediction service.

## Key Features

### Asset Inventory Management

- Register and maintain ICT asset information.
- Record relevant asset attributes, including asset identifiers, hardware specifications, purchase information and warranty details.
- Review asset availability and current lifecycle status.

### Asset Assignment and Lifecycle Tracking

- Support administrator-managed asset assignment.
- Track lifecycle statuses: `Available`, `Assigned`, `Maintenance`, `Retired` and `Disposed`.
- Support needs-based laptop assignment using recorded organisational and hardware requirements, where the corresponding module is available.

### Maintenance Management

- Record reported asset issues and maintenance activities.
- Track maintenance status and repair information.
- Use maintenance history as an input to the predictive-maintenance workflow.

### Predictive Maintenance

- Extract seven features from asset, maintenance and recorded usage information.
- Submit feature values to the Flask prediction API.
- Fall back to the same trained model artefact locally in PHP if the Flask API is offline.
- Estimate the likelihood of maintenance being required within the next 30 days.
- Present a risk classification of Low, Medium or High where supported by the application workflow.
- Recheck risk when usage or maintenance records are saved; notify the responsible administrator when an asset first enters High risk.

### Software Licence Management

The repository includes a migration associated with software licensing. Where the corresponding module is configured, it supports maintaining software licence information and tracking licence allocation and utilisation.

### Access Control and Reporting

- Provide authentication and role-based access control through the application's configured authentication modules.
- Support administrative oversight and reporting.
- Maintain audit information for relevant administrative activities where implemented.

> The availability of individual features depends on the application files, database schema and migrations in the version being installed. Features should be verified against the checked-out code before deployment.

## Technology Stack

| Technology | Purpose |
|---|---|
| PHP | Web application and server-side business logic |
| MySQL / MariaDB | Relational data storage |
| HTML, CSS and JavaScript | User interface and browser-side interaction |
| XAMPP | Local Apache, PHP and MySQL environment |
| Python | Model training and prediction service |
| Flask | HTTP API for predictive-maintenance requests |
| Scikit-learn | Logistic Regression and feature standardisation |
| NumPy | Numerical data processing |
| Git and GitHub | Source control and collaborative development |

Python dependency ranges are declared in [`requirements.txt`](requirements.txt). These ranges do not, by themselves, establish the exact package versions installed in a particular environment.

## System Architecture

The application separates asset-management operations from machine-learning inference.

```text
Administrator / User
         |
         v
   PHP Web Application
         |
         v
      MySQL Database
         |
         v
  Maintenance Feature Extraction
         |
         v
      Flask API
         |
         v
 Standardisation + Logistic Regression
         |
         v
 Maintenance Prediction Response
         |
         v
  PHP Maintenance Interface
```

The PHP application manages business operations and prepares the model's input features. The Flask service loads the exported model parameters and returns a prediction response. The web application can then use that response in its maintenance-risk workflow.

The model is trained separately from the live prediction request. The training script exports the model parameters to a JSON artefact, which the Flask service uses during inference.

## Predictive Maintenance Model

### Prediction Objective

The model predicts the binary target:

`maintenance_within_30_days`

The target is represented as:

- `1` — maintenance is required within the defined 30-day prediction window.
- `0` — maintenance is not required within that window.

The reliability of this target depends on the quality of the source records and the method used to assign labels. Synthetic or generated labels must not be represented as verified historical maintenance outcomes.

### Model Features

The training script defines the following seven input features:

| Feature | Description |
|---|---|
| `repair_count` | Number of recorded maintenance or repair records |
| `open_repair_count` | Number of unresolved or in-progress repairs |
| `asset_age_years` | Age of the asset in years |
| `warranty_days_remaining` | Days remaining until warranty expiry |
| `active_hours_30d` | Recorded active usage hours over the preceding 30 days |
| `crash_count_30d` | Recorded crash count over the preceding 30 days |
| `battery_health_percent` | Recorded battery health percentage |

The availability and accuracy of usage features depend on the information recorded in the database. The current prototype supports recording usage information through the application; an automatically installed endpoint-monitoring agent should be treated as future work unless separately implemented and tested.

### Training Process

The training script, [`ml/train_maintenance_model.py`](ml/train_maintenance_model.py):

1. Reads a labelled CSV dataset.
2. Checks that the required feature and target columns are present.
3. Validates numeric values and checks that both target classes exist.
4. Applies `StandardScaler` to the input features.
5. Trains a Scikit-learn Logistic Regression classifier.
6. Exports the model coefficients, intercept, scaling parameters, feature names and model version to a JSON file.

The script requires at least 30 labelled rows. This is a programmatic minimum and does not guarantee that the dataset is sufficiently large, representative or suitable for reliable predictions.

### Risk Interpretation

The application may classify predictions as Low, Medium or High risk. Risk thresholds and interpretation should be verified against the actual prediction code and documented configuration.

A prediction probability for an individual asset is not the same as the model's overall accuracy. Performance claims must be based on a separate, held-out test set and recorded evaluation metrics.

## Requirements

Before installation, prepare the following:

- XAMPP with Apache and MySQL enabled.
- PHP 8.0 or newer with `mysqli`, `mbstring` and JSON support enabled.
- Python 3 and `pip`.
- The Python packages listed in `requirements.txt`.
- A web browser.
- The project source code and required database schema.
- A compatible saved model artefact for the Flask API.

Composer dependencies may also be required if the checked-out PHP application uses packages managed through `composer.json`.

## Installation and Setup

### 1. Clone the Repository

Open a terminal and run:

```bash
git clone https://github.com/kisotu2/ics_finalproject.git
cd ics_finalproject
```

Alternatively, download the repository archive from GitHub and extract it into your chosen development directory.

### 2. Configure XAMPP

Place the project inside the XAMPP web root.

On macOS, a typical path is:

```text
/Applications/XAMPP/xamppfiles/htdocs/ics_finalproject
```

On Windows, a typical path is:

```text
C:\xampp\htdocs\ics_finalproject
```

Start Apache and MySQL from the XAMPP control panel.

If your local project uses a different web root, update the path accordingly.

### 3. Configure the Database

Inspect the project's `database.sql` file and the migration scripts before importing anything.

Using phpMyAdmin or the MySQL command line:

1. Create or select the database expected by the application.
2. Import the appropriate base schema.
3. Apply only the migrations needed to bring the database to the version expected by the application.
4. Verify that the required tables and columns exist.

Do not import the base schema and all migrations indiscriminately into an existing database. Back up existing data before making schema changes.

### 4. Configure Local Credentials

Check the application's database configuration files and identify the configuration format required by the current version.

The repository's `.gitignore` excludes `config.php`, environment files and selected local credentials. Start from the safe `config.example.php` template:

```bash
cp config.example.php config.php
```

Replace the placeholder values with local settings. Database values can instead be supplied through `ASSET_DB_HOST`, `ASSET_DB_NAME`, `ASSET_DB_USER` and `ASSET_DB_PASSWORD`. Do not commit the resulting `config.php`.

**Important:** Do not copy real passwords, SMTP credentials, API tokens or private credentials into tracked source files.

### 5. Install PHP Dependencies

If the repository contains a `composer.json` file and the application requires Composer-managed packages, install them from the project root:

```bash
composer install
```

Use the committed `composer.lock` file when available to reproduce the declared dependency versions. Do not run `composer update` merely to install the application unless you intend to update dependencies.

### 6. Open the Application

With Apache and MySQL running, open:

```text
http://localhost/ics_finalproject/
```

The exact landing page depends on the application's entry-point configuration.

If the project provides an initial administrator setup page, use it only in a controlled local setup environment. Restrict or disable that page after creating the initial administrator.

## Database Migrations

The repository contains migration scripts associated with additional functionality, including needs-based assignment, predictive maintenance and software licensing.

Examples of migration files include:

- `migrations/20260902_needs_based_assignment.sql`
- `migrations/20260812_add_predictive_maintenance.sql`
- `migrations/20260821_add_software_licensing.sql`

Before applying a migration:

1. Confirm that the file exists in your checked-out version.
2. Read the SQL statements and identify the tables and columns affected.
3. Back up the database.
4. Check whether the changes have already been applied.
5. Apply the migration and verify the resulting schema.

Migration filenames are provided as references; their existence and compatibility must be confirmed against the current branch.

### XAMPP migration and initial setup

For a fresh installation, import `database.sql` (it creates `ira_assets_recovered`, matching `config.example.php`), create local configuration from `config.example.php`, install Composer packages with `composer install`, and complete the administrator setup. The fresh schema includes the Active Directory inventory fields.

For an existing installation:

1. Back up the configured database.
2. Confirm the database contains the `laptops` table and has already received the other migrations required by this version.
3. Apply `migrations/20261026_active_directory_inventory.sql` to `ira_assets_recovered` (the current local configuration). If your deployment uses another database name, change the migration's initial `USE` statement to that already-configured database after confirming the backup.
4. Verify `laptops.ad_object_guid`, `laptops.manufacturer_serial`, `laptops.processor_description` and `ad_sync_events` exist before running the connector.
5. Test migration and sync behavior in a staging copy before applying to production.

The migration copies a legacy serial into `manufacturer_serial` only when that field is empty, then assigns database-ID-based internal serials to rows still using legacy serials. Existing manufacturer serial values and already-generated `IRA-LAP-` inventory serials (including collision suffixes) are preserved. Manufacturer serials are deliberately not unique because endpoint data can be missing or duplicated.

For an existing installation, apply `migrations/20261027_virtual_laptop_portal.sql` and `migrations/20261106_ad_endpoint_monitoring.sql` after backing up. Change each migration’s initial `USE` statement to the configured database if needed. The monitoring migration adds AD account metadata, endpoint status and run/request tracking. It keeps the legacy virtual display column for compatibility, but the application no longer reads or changes it. It does not delete or rewrite existing laptop or maintenance records. Restore the backup to roll back; do not drop new tables while they contain reports.

### Laptop portal and issue review

Employees can open **My Laptop** for devices assigned to their own account, review inventory and automated endpoint status, and report an issue. The page shows AD account status separately from the last endpoint connectivity result; it has no manual power control. The legacy API returns HTTP 410 for old virtual power requests. Reports are assigned to the laptop's responsible administrator, or notified to active super administrators when none is set. Administrators review reports from **Issue review**. Approving a report creates one linked maintenance record; rejection, information requests, employee responses, duplicates, and status changes are retained in issue updates and audit history.

AD sync is performed by the Windows connector described below. Offline or unreachable endpoints remain in the inventory, keep their last known hardware data, and are never retired or deleted automatically. A complete successful run can mark a previously discovered AD object missing from its configured scope for review; it does not remove the asset, assignment, maintenance history, or serial number.

## Active Directory Laptop Inventory

The PHP application does not read Microsoft Active Directory directly. It accepts records from `scripts/Sync-ActiveDirectoryInventory.ps1`, an authorized connector intended to run on a domain-joined Windows host.

### What is collected

The connector reads the AD computer object GUID, computer name, distinguished name, DNS hostname, OU, enabled state, operating-system metadata, last logon timestamp, creation date and modification date with the Active Directory PowerShell module. For every AD computer, it uses ICMP and PowerShell remoting/CIM to classify endpoint availability and, when reachable, query manufacturer, model, BIOS serial, processor, RAM and physical storage. It always sends the AD identity, even when endpoint collection fails; null hardware is preserved as unknown in the database. Active Directory commonly does **not** contain these current hardware properties.

Endpoint queries require administrator-approved remote management, appropriate least-privilege account permissions, WinRM/CIM configuration, and firewall rules on the endpoints. Endpoint availability is stored as `online`, `offline`, `unreachable` or `unknown`. ICMP response is enough to report online even when WinRM fails; failure reasons are retained for review. A failed endpoint does not stop other computers in the run. `lastLogonTimestamp` is replicated AD metadata and may lag, so it is not used as a real-time power or availability signal.

The connector does not infer a purchase date from AD object timestamps. Purchase dates remain unknown unless a trusted date is supplied in a validated payload or through the controlled training-data import. Warranty expiry may be in the future; a purchase/acquisition date may not.

### Send one laptop from that laptop

For an enrolled, domain-joined Windows laptop, an administrator can provide `scripts/Send-ThisLaptopInventory.ps1` and the HTTPS endpoint URL. The script looks up that laptop's own AD computer object, reads hardware locally, and sends one record to the same ingestion endpoint. It requires the Active Directory PowerShell module, network access to the domain, and an `AD_SYNC_TOKEN` supplied to the process through an approved secret-management or device-management method.

Run it from PowerShell on the laptop:

```powershell
.\Send-ThisLaptopInventory.ps1 `
  -ApiUrl 'https://inventory.example.com/ics_finalproject/ad_sync_api.php'
```

The script requires HTTPS and sends immediately; it does not save the token or hardware payload to disk. Do not add a token to the script, share a token through email/chat, or expose the shared ingestion token to untrusted users. Restrict who can run the script and provision its token through managed deployment. A computer that is not joined to the directory, or whose AD object cannot be read, cannot be submitted through this AD-GUID-keyed endpoint.

For a Mac with an existing AD computer object, use [`scripts/Send-ThisMacInventory.ps1`](scripts/Send-ThisMacInventory.ps1). It is designed for PowerShell 7 on macOS and collects Apple hardware data using local macOS utilities. The sender requires IT to supply the matching AD GUID, computer name, distinguished name, and enabled state, plus the HTTPS endpoint. It does not query Active Directory itself; PowerShell's Windows ActiveDirectory module is not supported on macOS.

After downloading the script (for example to `~/Downloads`), run it on the Mac using the installed PowerShell executable (or `pwsh` if it is on `PATH`):

```bash
/usr/local/microsoft/powershell/7-preview/pwsh \
  -File "$HOME/Downloads/Send-ThisMacInventory.ps1" \
  -ApiUrl 'https://inventory.example.com/ics_finalproject/ad_sync_api.php' \
  -AdGuid 'a6f33f8b-f62d-4acb-96be-8e1ef6ab0c21' \
  -DirectoryComputerName 'MAC-ACCOUNTING-01' \
  -DistinguishedName 'CN=MAC-ACCOUNTING-01,OU=Macs,DC=example,DC=com' \
  -Enabled $true
```

Provide `-Enabled $true` or `-Enabled $false` to match the AD object. This required value prevents a missing switch from silently marking an enabled Mac as disabled. The default is preview-only and does not require the token. Review the displayed hardware and AD identity. To submit, rerun the same command with `-Send`. Replace the example identity only with values confirmed by IT for this Mac. The script reads `AD_SYNC_TOKEN` from its process environment only when `-Send` is specified; IT must provision it securely and must not place it in the command, script, or a shared download. The application currently accepts the server-configured sync token, not per-device credentials, so access to that token must remain restricted to authorized administrators and devices. Configure VPN/network allowlisting and HTTPS as required by the server policy.

### Secure endpoint configuration

The ingestion endpoint is:

```text
POST /ics_finalproject/ad_sync_api.php
```

It requires HTTPS, an `Authorization: Bearer <token>` header, `Content-Type: application/json`, payloads no larger than 2 MiB, and 1–500 computer records. The token is read only from the PHP server environment variable `AD_SYNC_TOKEN`; the PHP endpoint rejects an unset or shorter-than-32-character token. Generate a high-entropy value locally, for example:

```bash
openssl rand -base64 48
```

Configure the same generated value as an environment variable for Apache/PHP and for the authorized connector process, using the operating system's protected secret-management mechanism. Do not paste it into project files, shell history, a URL, logs, or the connector script. For XAMPP, Apache may need to be restarted after changing its environment. Keep local configuration files private and outside source control.

At the network layer, restrict the endpoint to the connector's fixed source address and configure TLS. For a direct connector, Apache and the host firewall allowlist the connector; behind a proxy, Apache allowlists the proxy and the proxy/firewall must separately allowlist the connector. An Apache 2.4 example is provided in `docs/apache-ad-sync-location.conf.example`; replace its documentation-only IP address with the actual trusted connector address and install it in the applicable VirtualHost. Also restrict the path using the host firewall. Do not expose this endpoint to the public Internet. On this XAMPP installation, the `httpd-vhosts.conf` include is commented out in `xamppfiles/etc/httpd.conf`, while the default TLS VirtualHost is in `xamppfiles/etc/extra/httpd-ssl.conf`. Either enable and configure an HTTPS vhost in `httpd-vhosts.conf`, or place the provided `<LocationMatch>` block inside the active `<VirtualHost _default_:443>` in `httpd-ssl.conf`; replace the sample address, ensure the SSL include is enabled when Apache starts, then restart Apache. Keep environment directives in an untracked, administrator-only Apache configuration file (or use a protected service environment): `SetEnv AD_SYNC_TOKEN "<secret>"`. If running local-only development, add `SetEnv AD_SYNC_ALLOW_INSECURE_LOCAL "1"` only to the local configuration and remove it before deployment. For a trusted TLS proxy, add `SetEnv AD_SYNC_TRUSTED_PROXY_IPS "<proxy-ip>"` and ensure it overwrites the forwarded scheme header rather than passing client input through.

For local-only XAMPP development, HTTP is accepted solely from `127.0.0.1` or `::1` when `AD_SYNC_ALLOW_INSECURE_LOCAL=1` is set. This development exception is not appropriate for a domain connector or production deployment.

If TLS terminates at a reverse proxy, set `AD_SYNC_TRUSTED_PROXY_IPS` to the immediate proxy's fixed IP address or addresses and have it send `X-Forwarded-Proto: https`. The PHP endpoint trusts that header only when `REMOTE_ADDR` exactly matches this explicit list; do not trust forwarded headers from arbitrary clients. The API also limits authenticated calls to 30 requests per immediate peer per minute and accepts at most 500 computers/2 MiB per request. The example Apache location rule allowlists a fixed connector address; apply the same restriction in the host firewall. Rotate any token that was exposed during troubleshooting before production, and restart Apache after changing the environment.

### Preview and send

On the domain-joined Windows host, open PowerShell with the Active Directory module installed. Use an OU distinguished name scoped to the computers approved for inventory:

```powershell
.\scripts\Sync-ActiveDirectoryInventory.ps1 `
  -SearchBase 'OU=Workstations,DC=example,DC=com' `
  -ApiUrl 'https://inventory.example.com/ics_finalproject/ad_sync_api.php'
```

The default is preview-only; it queries the AD scope and endpoints but sends nothing. The sender includes a stable run identifier and scope across API batches; only a complete run with no record failures can mark unseen AD objects missing. After reviewing the preview and confirming the network/TLS configuration, securely prompt for the token and run the send in that same PowerShell session (or provision it with approved device-management secret storage):

```powershell
$secureToken = Read-Host 'AD sync token' -AsSecureString
$tokenPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
try {
    $env:AD_SYNC_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer)
    .\scripts\Sync-ActiveDirectoryInventory.ps1 `
      -SearchBase 'OU=Workstations,DC=example,DC=com' `
      -ApiUrl 'https://inventory.example.com/ics_finalproject/ad_sync_api.php' `
      -Send
} finally {
    Remove-Item Env:AD_SYNC_TOKEN -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($tokenPointer)
}
```


### Unattended scheduling and dashboard requests

On the authorized Windows host, provision the API token in the machine or service account environment through your protected configuration management. Grant the connector identity read access to the approved AD search base and only the remote endpoint permissions needed for CIM/WinRM inventory. Do not use Domain Admin for routine collection. Configure HTTPS trust and firewall allowlisting before starting the service.

Register the startup runner from an elevated PowerShell session:

```powershell
.\scripts\Register-ActiveDirectoryInventoryTask.ps1 `
  -SearchBase 'OU=Workstations,DC=example,DC=com' `
  -ApiUrl 'https://inventory.example.com/ics_finalproject'
```

The task runs the connector loop at startup, schedules a full sync every 15 minutes, and polls the authenticated request queue every 30 seconds. The default scheduled principal is `SYSTEM`; in a domain deployment, grant the computer account the required AD read permissions and endpoint CIM access. For a dedicated gMSA, pass `-ServiceAccount 'DOMAIN\AssetSync$'`; grant it only AD read and endpoint CIM/WinRM permissions. For a regular domain service account, create the task through Windows Task Scheduler using the organization's managed credential process, and set `AD_SYNC_TOKEN` in that account's protected process environment. The web dashboard's **Synchronise Now** button creates a real queued request; the agent claims it and reports the result to the dashboard. It cannot run while the Windows agent is stopped.

The migration creates `ad_sync_runs`, `ad_sync_run_devices`, `ad_sync_requests` and `ad_sync_agent_state`. Run tracking only flags unseen computers after the final batch of a complete, failure-free run. Interrupted/partial runs never mark records missing.

The connector sends batches of up to 100 records. A JSON record has this shape:

```json
{
  "version": 1,
  "computers": [{
    "ad_guid": "a6f33f8b-f62d-4acb-96be-8e1ef6ab0c21",
    "computer_name": "WS-ACCOUNTING-01",
    "distinguished_name": "CN=WS-ACCOUNTING-01,OU=Workstations,DC=example,DC=com",
    "enabled": true,
    "operating_system": "Windows 11 Enterprise",
    "operating_system_version": "10.0.26100",
    "manufacturer": "Example manufacturer",
    "model": "Example model",
    "manufacturer_serial": null,
    "processor": "Example processor",
    "ram_gb": 16,
    "storage_gb": 512,
    "purchase_date": null,
    "warranty_expiry": null
  }]
}
```

The example is illustrative; its values are not a claim about an actual device. Repeated sends update the same laptop by AD object GUID. Each laptop's `serial_number` is generated from its database ID (`IRA-LAP-000123`, with a deterministic collision suffix if needed); the BIOS serial remains separate in nullable, non-unique `manufacturer_serial`. Empty hardware values are preserved as unknown, not invented. The response reports created, updated and failed records plus missing/duplicate manufacturer serials. Sync events are stored in `ad_sync_events`; record-level failures are also written to the PHP server log without recording the bearer token.

Administrators can view readiness, endpoint/account metrics, filtered inventory, recent runs and the connector poll time at `directory_sync.php`. The page does not expose the secret. Manual laptop registration and legacy laptop AJAX creation are disabled; viewing, assignment, maintenance and reporting remain available.

### AD sync tests

Run the dependency-free validation tests with:

```bash
php tests/ad_sync_validation_test.php
bash tests/ad_sync_http_test.sh
```

The HTTP boundary test starts a temporary localhost PHP server and checks method restrictions, invalid/missing tokens, malformed JSON, content type, invalid records, request limits/rate behavior, and transport validation. Record-level validation failures return HTTP 207 while valid records in the same batch continue. Database idempotency/serial-collision, production HTTPS, unauthorized-page, and Windows endpoint scenarios are listed in [`tests/AD_SYNC_TEST_PLAN.md`](tests/AD_SYNC_TEST_PLAN.md); they require an isolated test database and/or a configured Windows domain. Do not run them against production.

## Running the Flask API

The Flask application is defined in [`flask_api/app.py`](flask_api/app.py). It imports the prediction function from `model_loader.py`.

### 1. Create a Python Virtual Environment

From the project root:

```bash
python3 -m venv .venv
```

Activate it on macOS or Linux:

```bash
source .venv/bin/activate
```

On Windows PowerShell:

```powershell
.venv\Scripts\Activate.ps1
```

### 2. Install Python Dependencies

```bash
python -m pip install --upgrade pip
python -m pip install -r requirements.txt
```

### 3. Start the API (optional)

```bash
python flask_api/app.py
```

The application is configured to listen on:

```text
http://127.0.0.1:5000
```

The root endpoint is:

```text
GET http://127.0.0.1:5000/
```

It returns a JSON status response when the service is running. PHP uses this API when available and falls back to the model artefact in `flask_api/maintenance_model.json` if the API cannot be reached or returns a server error. The fallback is logged by PHP, so predictions remain available without keeping Flask running.

### 4. Prediction Endpoint

The prediction endpoint is:

```text
POST http://127.0.0.1:5000/predict
```

It expects a JSON object containing all seven model features.

Example request:

```json
{
  "repair_count": 4,
  "open_repair_count": 1,
  "asset_age_years": 3.5,
  "warranty_days_remaining": 120,
  "active_hours_30d": 95,
  "crash_count_30d": 2,
  "battery_health_percent": 82
}
```

This is an illustrative request only; the values do not describe a verified asset or a tested outcome.

The API checks that the request body is a JSON object and that the required fields are present. It returns an error response for invalid request structures or missing fields. The prediction result depends on the loaded model and the implementation in `model_loader.py`.

For local development, the API binds to `127.0.0.1`. Do not expose it to an external network without implementing and reviewing suitable authentication, access control, network restrictions and deployment security.

High-risk predictions are saved and create an in-app notification for the laptop's active responsible administrator. If no active responsible administrator is assigned, active super administrators are notified. Alerts are sent when an asset transitions into High risk, rather than on every repeated prediction. Saving usage data or a maintenance record triggers a fresh prediction automatically. Notifications appear in the existing **My Laptop** inbox.

## Training the Model

The model-training script accepts a labelled CSV file containing the seven feature columns and the target `maintenance_within_30_days`.

Run the script from the project root:

```bash
python ml/train_maintenance_model.py path/to/training_data.csv
```

The default output path is:

```text
flask_api/maintenance_model.json
```

To choose a different output path:

```bash
python ml/train_maintenance_model.py path/to/training_data.csv \
  --output path/to/output_model.json
```

The script checks that the dataset contains the required columns, numeric values and both target classes. It then standardises the features, trains Logistic Regression and exports the parameters to JSON.

Before using a newly trained model:

- Confirm the dataset's provenance and label-generation method.
- Check missing values, duplicates, class balance and data quality.
- Ensure the target definition matches the intended prediction window.
- Evaluate the model using an appropriate held-out test set.
- Record recall, precision, F1-score and other approved evaluation metrics.
- Confirm that the exported model format is compatible with the Flask model loader.

Training the model does not, by itself, demonstrate predictive effectiveness. Evaluation results must be verified and documented separately.

## Security Considerations

Security is important because the application stores asset information and supports administrative operations.

- Keep database credentials, SMTP passwords, OAuth tokens and API secrets out of Git history.
- Review `.gitignore` before committing new configuration or generated files.
- Use least-privilege database credentials outside a local development environment.
- Restrict initial administrator registration and installation utilities after setup.
- Validate and authorise all requests on the server.
- Use HTTPS and suitable authentication for any remotely accessible API.
- Restrict access to maintenance records, user information and administrative reports according to role.
- Collect only device information required for an approved asset-management purpose.
- Back up the database before migrations and significant data changes.
- Review GitHub secret-scanning and repository security settings where available.

Ignoring a file through `.gitignore` does not remove it if it was committed previously. If a credential has been exposed, revoke or rotate it and follow the appropriate repository-cleanup process.

## Release Archive Contents

The distributable ZIP includes the application source, Composer dependencies, database schema and migrations, connector, tests, documentation and existing machine-learning assets. It excludes local configuration and OAuth credential/token files, local database files, Python caches/virtual environments, temporary office files, and optional demo seed files that contain demo credentials or non-AD laptop fixtures. Create `config.php` from `config.example.php` and configure secrets through protected environment variables after extraction.

## Testing and Evaluation

Testing should distinguish functional correctness from predictive-model performance.

### Functional and Integration Testing

Test the implemented workflows, including:

- Authentication and access control.
- Asset registration and inventory updates.
- Asset assignment and lifecycle status changes.
- Maintenance-record creation and status updates.
- Reporting and audit-related operations.
- PHP communication with the Flask API.
- API handling of valid requests, missing fields and invalid input.

Record the test identifier, preconditions, input, expected result, actual result and pass/fail status.

### Model Evaluation

Use a held-out test set after preprocessing and model selection have been completed using the training and validation data.

Compare the Logistic Regression model with a Dummy Classifier and, where included, a Random Forest model using the same split and target definition.

Recall for the maintenance-required class is particularly important because false negatives may leave at-risk assets without timely attention. Precision, F1-score, the confusion matrix, PR-AUC and ROC-AUC provide additional information about classification performance.

Only report numerical results that can be traced to the saved evaluation output and the documented experimental setup. Do not report an individual prediction probability as model accuracy.

### Reproducibility

Where available, retain:

- Database schema and migration instructions.
- Python and PHP dependency information.
- Model-training scripts and dataset-generation descriptions.
- The model artefact or instructions for regenerating it.
- Redacted API request and response examples.
- Screenshots of important workflows.
- Test logs indexed by the corresponding test identifiers.
- Evaluation metrics and the method used to calculate them.

Do not include secrets, private user data or unredacted credentials in test evidence.

## Development Workflow

Use Git to record meaningful development changes rather than grouping unrelated work into a single commit.

### Recommended Workflow

1. Pull or fetch the latest changes from the remote repository.
2. Create a feature branch for a specific task.
3. Implement and test the change locally.
4. Review the changed files and check for accidental secrets.
5. Commit the relevant files with a descriptive message.
6. Push the branch to GitHub.
7. Review the changes through a pull request before merging into `main`, where practical.

Example:

```bash
git switch -c feature/maintenance-api-validation

# Make and test the intended code changes.

git status
git diff --check
git diff

git add flask_api/app.py
git commit -m "Improve prediction API input validation"
git push -u origin feature/maintenance-api-validation
```

Use `git add` with specific paths when you want to control exactly which files enter a commit.

Avoid committing virtual environments, temporary files, logs, private datasets, local configuration and secrets.

## Limitations and Future Improvements

The current project should be described according to the features and evidence available in the checked-out code.

Potential areas for further development include:

- Automated collection of approved laptop telemetry through a dedicated endpoint agent.
- Stronger authentication and device identity for telemetry submissions.
- Additional testing of failure handling and integration behaviour.
- Improved historical maintenance data quality and validation of target labels.
- Further evaluation of model generalisation on representative real-world data.
- Deployment hardening, backup procedures and documented recovery processes.

Automated endpoint monitoring, production deployment and other proposed enhancements should not be described as implemented capabilities unless their code and test evidence are available.

## Repository

Source code: [https://github.com/kisotu2/ics_finalproject](https://github.com/kisotu2/ics_finalproject)

For development, use the repository's current files as the source of truth and verify setup instructions against the version you are installing.

---

*This README documents the project architecture and intended workflows. Installation steps, feature availability, dependency versions and evaluation results should be verified against the actual repository and runtime environment before use or formal submission.*