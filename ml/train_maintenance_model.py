#!/usr/bin/env python3
"""
Train a Logistic Regression maintenance prediction model.

The model predicts whether an ICT asset is likely to require
maintenance within the next 30 days.

CSV columns:
repair_count,
open_repair_count,
asset_age_years,
warranty_days_remaining,
active_hours_30d,
crash_count_30d,
battery_health_percent,
maintenance_within_30_days
"""

import csv
import json
import math
import sys
from pathlib import Path


FEATURES = [
    "repair_count",
    "open_repair_count",
    "asset_age_years",
    "warranty_days_remaining",
    "active_hours_30d",
    "crash_count_30d",
    "battery_health_percent"
]

SCALE = [
    10,
    5,
    12,
    730,
    720,
    30,
    100
]


def sigmoid(value):
    return 1 / (1 + math.exp(-max(-30, min(30, value))))


if len(sys.argv) != 2:
    raise SystemExit(
        f"Usage: {Path(sys.argv[0]).name} training_data.csv"
    )


training_file = Path(sys.argv[1])

if not training_file.exists():
    raise SystemExit(
        f"Training file not found: {training_file}"
    )


with open(
    training_file,
    newline="",
    encoding="utf-8"
) as source:

    rows = list(csv.DictReader(source))


if len(rows) < 30:
    raise SystemExit(
        "At least 30 labelled historical rows are required before training."
    )


try:

    X = [
        [
            float(row[name]) / scale
            for name, scale in zip(FEATURES, SCALE)
        ]
        for row in rows
    ]

    y = [
        float(row["maintenance_within_30_days"])
        for row in rows
    ]

except (KeyError, ValueError) as error:

    raise SystemExit(
        f"Invalid training CSV: {error}"
    )


if len(set(y)) < 2:

    raise SystemExit(
        "Training data must contain both maintenance outcomes (0 and 1)."
    )


weights = [0.0] * len(FEATURES)
intercept = 0.0


for _ in range(2500):

    errors = [
        sigmoid(
            intercept +
            sum(
                w * x
                for w, x in zip(weights, row)
            )
        ) - target

        for row, target in zip(X, y)
    ]

    rate = 0.12 / len(rows)

    intercept -= rate * sum(errors)

    weights = [
        w -
        rate *
        sum(
            error * row[i]
            for error, row in zip(errors, X)
        )

        for i, w in enumerate(weights)
    ]


model = {

    "version": "trained-logistic-v1",

    "algorithm": "Logistic Regression",

    "features": FEATURES,

    "intercept": intercept,

    "weights": dict(
        zip(FEATURES, weights)
    ),

    "trained_rows": len(rows),

    "target": "maintenance required within 30 days"

}


model_path = Path(__file__).with_name(
    "maintenance_model.json"
)


model_path.write_text(
    json.dumps(
        model,
        indent=2
    ) + "\n",

    encoding="utf-8"
)


print(
    f"Model trained on {len(rows)} rows."
)

print(
    f"Model saved to: {model_path}"
)