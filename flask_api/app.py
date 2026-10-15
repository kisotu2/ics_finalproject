import os

from flask import Flask, jsonify, request
from model_loader import predict_maintenance

app = Flask(__name__)


@app.route("/", methods=["GET"])
def home():
    return jsonify({
        "status": "running",
        "service": "Smart Asset Management Predictive Maintenance API"
    })


@app.route("/predict", methods=["POST"])
def predict():

    try:
        data = request.get_json(silent=True)

        if not isinstance(data, dict):
            return jsonify({"error": "Request body must be a JSON object."}), 400

        required_fields = [
            "repair_count",
            "open_repair_count",
            "asset_age_years",
            "warranty_days_remaining",
            "active_hours_30d",
            "crash_count_30d",
            "battery_health_percent"
        ]

        missing = [
            field for field in required_fields
            if field not in data
        ]

        if missing:
            return jsonify({
                "error": "Missing required fields",
                "fields": missing
            }), 400

        result = predict_maintenance(data)

        return jsonify(result)

    except (TypeError, ValueError):
        return jsonify({"error": "All feature values must be numeric."}), 400


if __name__ == "__main__":
    app.run(
        host="127.0.0.1",
        port=5000,
        debug=os.getenv("FLASK_DEBUG") == "1"
    )
