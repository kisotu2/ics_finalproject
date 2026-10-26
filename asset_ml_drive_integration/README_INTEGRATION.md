# Smart Asset Management: Google Drive + Colab model integration

This integration patch is designed to be copied into the existing `ics_finalproject` project. It does not replace the PHP application or database.

## What it does

- Colab trains Logistic Regression with the same seven ordered features expected by Flask.
- The notebook exports `models/maintenance_model.json` to the Google Drive project folder, including training metadata and evaluation metrics.
- `scripts/sync_from_drive.py` authenticates to Drive using a local Google OAuth Desktop app, downloads the CSV and JSON, validates both, and only then replaces the local copies.
- Flask reloads the JSON model automatically when its modification time changes; restart is not needed after a successful sync.
- Flask provides `/health` and `/model-info` endpoints to confirm model status and display its saved metrics.

## 1. Copy files into your existing project

Copy the contents of this patch into the matching paths under:

`/Applications/XAMPP/xamppfiles/htdocs/ics_finalproject/`

Back up existing files first. Replace `flask_api/app.py` and `flask_api/model_loader.py` with the versions from this patch. Put `scripts/sync_from_drive.py` and `scripts/requirements-drive.txt` at project root under `scripts/`.

Ensure the local CSV is at `dataset/maintenance_training_data.csv`. If your project uses another dataset directory, run the sync script with `--dataset-out`.

## 2. Update and run Colab

Open `Smart_Asset_Maintenance_Training_Drive_Export.ipynb`. It preserves the original training/evaluation cells and fixes the final export cell so it writes the complete model JSON (with metrics and version metadata) to:

`My Drive/Smart_Asset_Management_ML/models/maintenance_model.json`

Run all cells from top to bottom. Confirm the final cell prints the model path and metrics. The notebook trains from:

`My Drive/Smart_Asset_Management_ML/dataset/maintenance_training_data.csv`

If your Drive folder or file name differs, update `PROJECT_DIR` / `DATASET_PATH` in the notebook.

## 3. Set up read-only Google Drive access on your Mac

1. In Google Cloud Console, select or create a project.
2. Enable **Google Drive API**.
3. Configure OAuth consent screen for your account (for personal/testing use, add your Google account as a test user if the app is in Testing mode).
4. Create an **OAuth Client ID** of type **Desktop app** and download the JSON.
5. Save the downloaded file as `scripts/credentials.json` under the project root. Never commit or share this file.
6. In Terminal:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/ics_finalproject
python3 -m venv .venv-drive
source .venv-drive/bin/activate
python -m pip install --upgrade pip
python -m pip install -r scripts/requirements-drive.txt
python scripts/sync_from_drive.py
```

A browser window opens for Google sign-in. Approve read-only Drive access. A local `scripts/token.json` is created; keep it private. The script searches the exact folder/file names first, then falls back to exact file-name search. It downloads only ordinary CSV/JSON files, not Google Sheets or Docs.

If the dataset is the specific file from your shared link, you can provide its file ID explicitly:

```bash
python scripts/sync_from_drive.py --dataset-id 'YOUR_DRIVE_CSV_FILE_ID'
```

If Drive contains multiple files named `maintenance_model.json`, pass the correct model file ID with `--model-id`.

## 4. Install Flask requirements and start the API

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/ics_finalproject/flask_api
python3 -m venv .venv
source .venv/bin/activate
python -m pip install -r requirements.txt
python app.py
```

Keep the API bound to `127.0.0.1`; the PHP code already calls `http://127.0.0.1:5000/predict`. Do not expose the development Flask server to the public internet.

In a second Terminal window, check:

```bash
curl http://127.0.0.1:5000/health
curl http://127.0.0.1:5000/model-info
```

After syncing a newly trained model, check `/model-info` again. The API reloads the JSON automatically.

## 5. Repeat after retraining

1. Update/append labeled data in Drive.
2. Run the Colab notebook from top to bottom.
3. Run `python scripts/sync_from_drive.py` on the Mac.
4. Verify `http://127.0.0.1:5000/model-info` shows the expected model version/training metadata.

This sync is manual; it does not silently poll Drive. It can later be scheduled with macOS `launchd` if you want regular syncs.

## Important notes

- The current CSV has 1,000 rows and the expected eight columns, including `maintenance_within_30_days`; the label is what the model learns to predict.
- The target label must represent a real, consistently defined outcome. If the dataset is synthetic, evaluation metrics measure performance on that dataset, not guaranteed real-world accuracy.
- The PHP integration derives inputs from records in MySQL. Missing usage data currently may be represented by defaults; ensure those defaults are clearly shown/handled before making operational decisions.
- Keep OAuth credentials and tokens private. Revoke old exposed credentials and rotate any API keys or app passwords previously committed in project files.
