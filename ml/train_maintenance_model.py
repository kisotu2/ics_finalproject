#!/usr/bin/env python3
"""Train and export the predictive-maintenance model used by the Flask API."""

import argparse
import csv
import json
from pathlib import Path

import numpy as np
from sklearn.linear_model import LogisticRegression
from sklearn.preprocessing import StandardScaler


FEATURES = [
    "repair_count",
    "open_repair_count",
    "asset_age_years",
    "warranty_days_remaining",
    "active_hours_30d",
    "crash_count_30d",
    "battery_health_percent",
]
TARGET = "maintenance_within_30_days"
DEFAULT_OUTPUT = Path(__file__).resolve().parents[1] / "flask_api" / "maintenance_model.json"


def load_training_data(path: Path) -> tuple[np.ndarray, np.ndarray]:
    with path.open(newline="", encoding="utf-8") as source:
        reader = csv.DictReader(source)
        missing = set(FEATURES + [TARGET]) - set(reader.fieldnames or [])
        if missing:
            raise ValueError(f"Missing CSV columns: {', '.join(sorted(missing))}")
        rows = list(reader)

    if len(rows) < 30:
        raise ValueError("At least 30 labelled historical rows are required before training.")

    try:
        features = np.array([[float(row[name]) for name in FEATURES] for row in rows])
        target = np.array([int(row[TARGET]) for row in rows])
    except (TypeError, ValueError) as error:
        raise ValueError(f"Invalid numeric value in training CSV: {error}") from error

    if not np.isfinite(features).all() or not np.isfinite(target).all():
        raise ValueError("Training CSV contains non-finite values.")
    if not set(target).issubset({0, 1}) or len(set(target)) < 2:
        raise ValueError(f"{TARGET} must contain both 0 and 1 values.")

    return features, target


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("training_data", type=Path, help="CSV containing the seven features and target")
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT, help="Model JSON output path")
    args = parser.parse_args()

    if not args.training_data.is_file():
        parser.error(f"Training file not found: {args.training_data}")

    try:
        features, target = load_training_data(args.training_data)
    except ValueError as error:
        parser.error(str(error))

    scaler = StandardScaler()
    scaled_features = scaler.fit_transform(features)
    model = LogisticRegression(random_state=42, max_iter=1000)
    model.fit(scaled_features, target)

    model_data = {
        "model_version": "logistic-regression-v1",
        "model_type": "Logistic Regression",
        "target": TARGET,
        "features": FEATURES,
        "coefficients": model.coef_[0].tolist(),
        "intercept": float(model.intercept_[0]),
        "scaler_mean": scaler.mean_.tolist(),
        "scaler_scale": scaler.scale_.tolist(),
        "trained_rows": int(len(target)),
    }

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(model_data, indent=2) + "\n", encoding="utf-8")
    print(f"Model trained on {len(target)} rows and saved to: {args.output}")


if __name__ == "__main__":
    main()
