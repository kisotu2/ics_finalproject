from flask import Flask, request, jsonify
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
        data = request.get_json()

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

    except Exception as error:

        return jsonify({
            "error": str(error)
        }), 500


if __name__ == "__main__":
    app.run(
        host="127.0.0.1",
        port=5000,
        debug=True
    )