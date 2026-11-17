import json
import numpy as np
import os

MODEL_PATH = os.path.join(
    os.path.dirname(__file__),
    "maintenance_model.json"
)

with open(MODEL_PATH, "r") as file:
    model_data = json.load(file)


FEATURES = [
    "repair_count",
    "open_repair_count",
    "asset_age_years",
    "warranty_days_remaining",
    "active_hours_30d",
    "crash_count_30d",
    "battery_health_percent",
]
if model_data.get("features") != FEATURES:
    raise ValueError("The maintenance model feature order is incompatible with the API.")

COEFFICIENTS = np.asarray(model_data["coefficients"], dtype=float)
INTERCEPT = float(model_data["intercept"])
SCALER_MEAN = np.asarray(model_data["scaler_mean"], dtype=float)
SCALER_SCALE = np.asarray(model_data["scaler_scale"], dtype=float)
if (
    COEFFICIENTS.shape != (len(FEATURES),)
    or SCALER_MEAN.shape != (len(FEATURES),)
    or SCALER_SCALE.shape != (len(FEATURES),)
    or not np.isfinite(COEFFICIENTS).all()
    or not np.isfinite(SCALER_MEAN).all()
    or not np.isfinite(SCALER_SCALE).all()
    or not np.isfinite(INTERCEPT)
    or (SCALER_SCALE <= 0).any()
):
    raise ValueError("The maintenance model artifact contains invalid coefficients or scaler data.")


def predict_maintenance(data):

    values = np.asarray([data[name] for name in FEATURES], dtype=float)
    if not np.isfinite(values).all():
        raise ValueError("All feature values must be finite numbers.")

    # Apply the same scaling used during training
    scaled_values = (
        values - SCALER_MEAN
    ) / SCALER_SCALE

    # Logistic regression calculation
    z = np.dot(COEFFICIENTS, scaled_values) + INTERCEPT

    probability = 1 / (1 + np.exp(-z))

    prediction = 1 if probability >= 0.5 else 0

    if probability >= 0.70:
        risk_level = "High"
    elif probability >= 0.40:
        risk_level = "Medium"
    else:
        risk_level = "Low"

    return {
        "prediction": prediction,
        "probability": round(float(probability), 4),
        "risk_level": risk_level,
        "model_version": model_data.get("model_version", "logistic-regression-v1"),
    }