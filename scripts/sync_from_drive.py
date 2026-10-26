#!/usr/bin/env python3
"""Sync the latest CSV and Colab-exported model from Google Drive to the local project.

Requires a Google OAuth Desktop app credentials JSON saved as credentials.json beside
this script (never commit it). On first run, a browser login creates token.json.
"""
import argparse
import io
import os
from pathlib import Path

from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from googleapiclient.http import MediaIoBaseDownload

SCOPES = ["https://www.googleapis.com/auth/drive.readonly"]
ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DATASET = ROOT / "dataset" / "maintenance_training_data.csv"
DEFAULT_MODEL = ROOT / "flask_api" / "maintenance_model.json"
DEFAULT_DATASET_FILE_ID = "1wftpZAsgW2tEgH4rGrgA3JqOBShVa2qM"
SCRIPT_DIR = Path(__file__).resolve().parent


def drive_service():
    creds = None
    token_file = SCRIPT_DIR / "token.json"
    client_file = Path(os.getenv("GOOGLE_OAUTH_CLIENT_FILE", SCRIPT_DIR / "credentials.json"))
    if token_file.exists():
        creds = Credentials.from_authorized_user_file(str(token_file), SCOPES)
    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
        else:
            if not client_file.exists():
                raise FileNotFoundError(
                    f"OAuth client file not found: {client_file}. Create a Google Cloud OAuth Desktop app, "
                    "download its JSON, and save it as scripts/credentials.json."
                )
            flow = InstalledAppFlow.from_client_secrets_file(str(client_file), SCOPES)
            creds = flow.run_local_server(port=0)
        token_file.write_text(creds.to_json(), encoding="utf-8")
        try:
            token_file.chmod(0o600)
        except OSError:
            pass
    return build("drive", "v3", credentials=creds, cache_discovery=False)


def find_named_file(service, name, folder_id=None):
    q = "trashed = false and name = '{}'".format(name.replace("'", "\\'"))
    if folder_id:
        q += " and '{}' in parents".format(folder_id.replace("'", "\\'"))
    response = service.files().list(
        q=q, spaces="drive", orderBy="modifiedTime desc",
        pageSize=100, fields="files(id,name,mimeType,modifiedTime,size,parents)"
    ).execute()
    files = response.get("files", [])
    if not files:
        return None
    return files[0]


def find_folder(service, name, parent_id=None):
    q = "trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '{}'".format(name.replace("'", "\\'"))
    if parent_id:
        q += " and '{}' in parents".format(parent_id.replace("'", "\\'"))
    response = service.files().list(q=q, spaces="drive", pageSize=100, fields="files(id,name,modifiedTime)").execute()
    matches = response.get("files", [])
    return matches[0]["id"] if matches else None


def resolve_folder_path(service, names):
    parent = None
    for name in names:
        parent = find_folder(service, name, parent)
        if not parent:
            return None
    return parent


def download_file(service, file_id, destination):
    destination = Path(destination)
    destination.parent.mkdir(parents=True, exist_ok=True)
    request = service.files().get_media(fileId=file_id)
    temp = destination.with_suffix(destination.suffix + ".download")
    with temp.open("wb") as out:
        downloader = MediaIoBaseDownload(out, request)
        done = False
        while not done:
            _, done = downloader.next_chunk()
    if temp.stat().st_size == 0:
        temp.unlink(missing_ok=True)
        raise RuntimeError(f"Drive returned an empty file for {destination.name}")
    temp.replace(destination)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset-id", default=os.getenv("ASSET_DRIVE_DATASET_FILE_ID", DEFAULT_DATASET_FILE_ID), help="Optional exact Google Drive CSV file ID")
    parser.add_argument("--model-id", default=os.getenv("ASSET_DRIVE_MODEL_FILE_ID", ""), help="Optional exact Google Drive model JSON file ID")
    parser.add_argument("--dataset-out", type=Path, default=DEFAULT_DATASET)
    parser.add_argument("--model-out", type=Path, default=DEFAULT_MODEL)
    args = parser.parse_args()

    service = drive_service()
    dataset = None
    model = None
    if args.dataset_id:
        dataset = service.files().get(fileId=args.dataset_id, fields="id,name,mimeType,modifiedTime").execute()
    else:
        folder_id = resolve_folder_path(service, ["Smart_Asset_Management_ML", "dataset"])
        dataset = find_named_file(service, "maintenance_training_data.csv", folder_id)
        if not dataset:
            dataset = find_named_file(service, "maintenance_training_data.csv")
    if args.model_id:
        model = service.files().get(fileId=args.model_id, fields="id,name,mimeType,modifiedTime").execute()
    else:
        folder_id = resolve_folder_path(service, ["Smart_Asset_Management_ML", "models"])
        model = find_named_file(service, "maintenance_model.json", folder_id)
        if not model:
            model = find_named_file(service, "maintenance_model.json")

    if not dataset:
        raise SystemExit("Dataset not found. Put maintenance_training_data.csv in Smart_Asset_Management_ML/dataset or pass --dataset-id.")
    if not model:
        raise SystemExit("Model not found. Run the final export cell in Colab so models/maintenance_model.json exists, or pass --model-id.")
    if dataset.get("mimeType", "").startswith("application/vnd.google-apps.") or model.get("mimeType", "").startswith("application/vnd.google-apps."):
        raise SystemExit("Dataset/model must be ordinary CSV and JSON files, not Google Docs/Sheets native documents.")

    # Download to temporary files, validate both before replacing destinations.
    tmp_dataset = args.dataset_out.with_suffix(args.dataset_out.suffix + ".candidate")
    tmp_model = args.model_out.with_suffix(args.model_out.suffix + ".candidate")
    download_file(service, dataset["id"], tmp_dataset)
    download_file(service, model["id"], tmp_model)
    import csv, json
    with tmp_dataset.open(newline="", encoding="utf-8-sig") as f:
        headers = next(csv.reader(f), [])
    required_headers = {"repair_count", "open_repair_count", "asset_age_years", "warranty_days_remaining", "active_hours_30d", "crash_count_30d", "battery_health_percent", "maintenance_within_30_days"}
    if not required_headers.issubset(set(headers)):
        tmp_dataset.unlink(missing_ok=True); tmp_model.unlink(missing_ok=True)
        raise SystemExit("Drive CSV columns do not match the training pipeline. No files were replaced.")
    try:
        model_data = json.loads(tmp_model.read_text(encoding="utf-8"))
        expected = ["repair_count", "open_repair_count", "asset_age_years", "warranty_days_remaining", "active_hours_30d", "crash_count_30d", "battery_health_percent"]
        if model_data.get("features") != expected:
            raise ValueError("Model feature order mismatch")
        for key in ("coefficients", "scaler_mean", "scaler_scale"):
            if len(model_data.get(key, [])) != len(expected):
                raise ValueError(f"Invalid model field {key}")
    except Exception as exc:
        tmp_dataset.unlink(missing_ok=True); tmp_model.unlink(missing_ok=True)
        raise SystemExit(f"Downloaded model failed validation; no files were replaced: {exc}")

    args.dataset_out.parent.mkdir(parents=True, exist_ok=True)
    args.model_out.parent.mkdir(parents=True, exist_ok=True)
    tmp_dataset.replace(args.dataset_out)
    tmp_model.replace(args.model_out)
    print(f"Dataset synced: {args.dataset_out} (Drive modified {dataset.get('modifiedTime', 'unknown')})")
    print(f"Model synced:   {args.model_out} (Drive modified {model.get('modifiedTime', 'unknown')})")
    print(f"Model version:  {model_data.get('model_version', 'unknown')}")
    print("The Flask API reloads the model automatically after the file changes.")

if __name__ == "__main__":
    main()
