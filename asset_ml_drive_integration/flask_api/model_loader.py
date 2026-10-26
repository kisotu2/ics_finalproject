"""Load the latest locally synchronized Colab-exported maintenance model."""
import json
import os
import threading
from pathlib import Path

import numpy as np

MODEL_PATH = Path(os.getenv("ASSET_ML_MODEL_PATH", Path(__file__).resolve().with_name("maintenance_model.json")))
FEATURES_EXPECTED = [
    "repair_count", "open_repair_count", "asset_age_years",
    "warranty_days_remaining", "active_hours_30d", "crash_count_30d",
    "battery_health_percent",
]
_lock = threading.Lock()
_cache = {"mtime_ns": None, "data": None}


def _load_model():
    try:
        mtime = MODEL_PATH.stat().st_mtime_ns
    except FileNotFoundError as exc:
        raise RuntimeError(f"Model file not found: {MODEL_PATH}. Run scripts/sync_from_drive.py first.") from exc
    with _lock:
        if _cache["data"] is None or _cache["mtime_ns"] != mtime:
            with MODEL_PATH.open("r", encoding="utf-8") as f:
                data = json.load(f)
            required = {"features", "coefficients", "intercept", "scaler_mean", "scaler_scale"}
            missing = required - set(data)
            if missing:
                raise RuntimeError(f"Model JSON is missing fields: {sorted(missing)}")
            if data["features"] != FEATURES_EXPECTED:
                raise RuntimeError("Model feature order does not match the Flask API contract.")
            n = len(FEATURES_EXPECTED)
            for key in ("coefficients", "scaler_mean", "scaler_scale"):
                if len(data[key]) != n:
                    raise RuntimeError(f"Model field '{key}' must contain {n} values.")
            if any(float(x) == 0 for x in data["scaler_scale"]):
                raise RuntimeError("Model scaler contains a zero scale value.")
            _cache.update(mtime_ns=mtime, data=data)
        return _cache["data"]


def model_info():
    data = _load_model()
    return {
        "model_version": data.get("model_version", "unknown"),
        "model_type": data.get("model_type", "unknown"),
        "target": data.get("target", "unknown"),
        "trained_rows": data.get("trained_rows"),
        "test_rows": data.get("test_rows"),
        "metrics": data.get("metrics", {}),
        "trained_at_utc": data.get("trained_at_utc"),
        "features": data["features"],
    }


def predict_maintenance(data):
    model = _load_model()
    values = np.array([float(data[name]) for name in FEATURES_EXPECTED], dtype=float)
    if not np.isfinite(values).all():
        raise ValueError("All feature values must be finite numbers.")
    means = np.asarray(model["scaler_mean"], dtype=float)
    scales = np.asarray(model["scaler_scale"], dtype=float)
    coefficients = np.asarray(model["coefficients"], dtype=float)
    scaled = (values - means) / scales
    z = float(np.dot(coefficients, scaled) + float(model["intercept"]))
    # Numerically stable sigmoid
    probability = (1.0 / (1.0 + np.exp(-z))) if z >= 0 else (np.exp(z) / (1.0 + np.exp(z)))
    prediction = int(probability >= 0.5)
    risk = "High" if probability >= 0.70 else "Medium" if probability >= 0.40 else "Low"
    return {
        "prediction": prediction,
        "probability": round(float(probability), 4),
        "risk_level": risk,
        "model_version": model.get("model_version", "unknown"),
    }
