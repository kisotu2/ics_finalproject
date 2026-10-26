import os
from flask import Flask, jsonify, request
from model_loader import model_info, predict_maintenance

app = Flask(__name__)
REQUIRED_FIELDS = [
    "repair_count", "open_repair_count", "asset_age_years",
    "warranty_days_remaining", "active_hours_30d", "crash_count_30d",
    "battery_health_percent",
]

@app.get("/")
def home():
    return jsonify({"status": "running", "service": "Smart Asset Management Predictive Maintenance API"})

@app.get("/health")
def health():
    try:
        info = model_info()
        return jsonify({"status": "ok", "model_loaded": True, "model_version": info["model_version"]})
    except (RuntimeError, OSError, ValueError) as exc:
        return jsonify({"status": "error", "model_loaded": False, "error": str(exc)}), 503

@app.get("/model-info")
def model_status():
    try:
        return jsonify(model_info())
    except (RuntimeError, OSError, ValueError) as exc:
        return jsonify({"error": str(exc)}), 503

@app.post("/predict")
def predict():
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        return jsonify({"error": "Request body must be a JSON object."}), 400
    missing = [name for name in REQUIRED_FIELDS if name not in data]
    if missing:
        return jsonify({"error": "Missing required fields", "fields": missing}), 400
    try:
        features = {name: float(data[name]) for name in REQUIRED_FIELDS}
        return jsonify(predict_maintenance(features))
    except (TypeError, ValueError) as exc:
        return jsonify({"error": f"All feature values must be finite numeric values: {exc}"}), 400
    except RuntimeError as exc:
        return jsonify({"error": str(exc)}), 503

if __name__ == "__main__":
    # Keep bound to loopback: the PHP app and API run on the same machine.
    app.run(host=os.getenv("ASSET_ML_HOST", "127.0.0.1"), port=int(os.getenv("ASSET_ML_PORT", "5000")), debug=False)
