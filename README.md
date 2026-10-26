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
- [Running the Flask API](#running-the-flask-api)
- [Training the Model](#training-the-model)
- [Security Considerations](#security-considerations)
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
- Estimate the likelihood of maintenance being required within the next 30 days.
- Present a risk classification of Low, Medium or High where supported by the application workflow.

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
- PHP with the required database extensions.
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

The repository's `.gitignore` excludes `config.php`, environment files and selected local credentials. Create the necessary local configuration using your own database settings and mail credentials.

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

### 3. Start the API

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

It returns a JSON status response when the service is running.

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