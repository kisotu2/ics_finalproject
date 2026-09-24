# Smart Asset Management System

A PHP and MySQL application for managing organisational laptops throughout their lifecycle. It combines asset registration, needs-based laptop assignment, maintenance planning, lifecycle history, and auditable administration in one place.

## Features

- **Asset lifecycle management** — register, assign, return, update, and review assets.
- **Role-based access** — separate administrator and user experiences with controlled actions.
- **Maintenance prioritisation** — record usage and health signals, then prioritise assets by predicted failure risk.
- **Predictive maintenance** — use an explainable baseline immediately, or train a local logistic-regression model from historical outcomes.
- **Needs-based laptop assignment** — ranks available laptops using department, organisational rank, work type, RAM, storage and processor tier before an administrator assigns one.
- **Asset lifecycle management** — records active, assigned, maintenance, retired and disposed states in the asset history.
- **Audit trail** — records registration, assignment, status and maintenance actions.
- **Security controls** — prepared database statements and CSRF protection for state-changing forms.

## Built with

- PHP
- MySQL / MariaDB
- XAMPP for local development
- Python 3 (optional maintenance-model training)

## Getting started

### Prerequisites

- XAMPP with Apache and MySQL running
- PHP with the MySQLi extension enabled
- A browser pointed at `http://localhost/Asset/`

### Installation

1. Place the project in XAMPP's `htdocs` directory as `Asset`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Visit [http://localhost/Asset/install.php](http://localhost/Asset/install.php), enter your local MySQL credentials, and install the schema.
4. Create a local `config.php` file in the project root. It is intentionally ignored by Git. Database credentials may alternatively be supplied through the `ASSET_DB_HOST`, `ASSET_DB_USER`, `ASSET_DB_PASSWORD`, and `ASSET_DB_NAME` environment variables.
5. Open [http://localhost/Asset/create_super_admin.php](http://localhost/Asset/create_super_admin.php) once to create the first administrator, then sign in.

Use this minimal `config.php` when configuration values are needed locally:

```php
<?php

return [
    'db_host' => '127.0.0.1',
    'db_user' => 'root',
    'db_password' => '',
    'db_name' => 'ira_assets',

    // Optional email integration
    'mail_host' => '',
    'mail_username' => '',
    'mail_password' => '',
    'mail_port' => 587,
    'mail_from' => '',
    'admin_alert_email' => '',
];
```

## Needs-based laptop assignment

Run [`migrations/20260902_needs_based_assignment.sql`](migrations/20260902_needs_based_assignment.sql) once for an existing installation. It adds organisational rank to users and processor tier, RAM and storage fields to laptops. Administrators then use **Laptop assignment** to enter the employee's department, rank and work type. The recommendation logic ranks available laptops using clear, inspectable suitability rules; an administrator reviews the score and makes the final assignment.

## Predictive maintenance

For an existing database, import [`migrations/20260812_add_predictive_maintenance.sql`](migrations/20260812_add_predictive_maintenance.sql) with phpMyAdmin.

The Maintenance page accepts daily active hours, crash counts, and battery health. It estimates maintenance failure risk using repair history, asset age, warranty remaining, and usage data. New installations begin with an explainable baseline, so the feature is useful before historical data is available.

After collecting at least 30 labelled historical records, train a local model with a CSV containing a `failed_within_90_days` column set to `0` or `1`:

```bash
python3 ml/train_maintenance_model.py your_training_data.csv
```

## Software licences

Administrators can use **Software licences** from the application navigation to create software inventory records, assign available seats to active users, revoke access, review utilisation, and export a report. Licence usage is calculated from active assignment records, so revoking a user immediately returns a seat to the available pool.

For an existing database, run [`migrations/20260821_add_software_licensing.sql`](migrations/20260821_add_software_licensing.sql) once in phpMyAdmin before opening the software pages. New installations receive the full software schema from `database.sql`.

## Project structure

```text
Asset/
├── assets/                         # Stylesheets and browser JavaScript
├── migrations/                     # Database upgrades
├── ml/                             # Local maintenance-model training script
├── database.sql                    # Base MySQL schema
├── install.php                     # Local schema installer
├── maintenance.php                 # Maintenance management and risk workflow
├── laptop_assignment.php            # Needs-based laptop recommendation and assignment
└── config.php                      # Local secrets and integrations (not committed)
```

## Security and deployment notes

- Keep database and SMTP credentials out of source control; use `config.php` or environment variables.
- Restrict or remove access to `install.php` and `create_super_admin.php` once setup is complete.
- Review the audit log regularly, especially before approving maintenance or assignment decisions.
