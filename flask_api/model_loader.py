import json
import numpy as np
import os

MODEL_PATH = os.path.join(
    os.path.dirname(__file__),
    "maintenance_model.json"
)

with open(MODEL_PATH, "r") as file:
    model_data = json.load(file)


FEATURES = model_data["features"]
COEFFICIENTS = np.array(model_data["coefficients"])
INTERCEPT = model_data["intercept"]

SCALER_MEAN = np.array(model_data["scaler_mean"])
SCALER_SCALE = np.array(model_data["scaler_scale"])


def predict_maintenance(data):

    values = np.array([
        data["repair_count"],
        data["open_repair_count"],
        data["asset_age_years"],
        data["warranty_days_remaining"],
        data["active_hours_30d"],
        data["crash_count_30d"],
        data["battery_health_percent"]
    ], dtype=float)

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
        "risk_level": risk_level
    }