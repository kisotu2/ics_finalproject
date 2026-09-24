# Project datasets

`demo_seed.sql` is a **synthetic, privacy-safe** dataset designed for the Smart Asset Management System. It supplies realistic records for demonstrations, reports, and initial feature testing.

| Data | What it supports |
|---|---|
| Users, laptops and assignment history | registration, role access, allocation and asset reports |
| Software and licences | software inventory and licence assignment |
| Maintenance and usage | predictive-maintenance / lifecycle-risk demonstrations |
| Approved areas and consented check-ins | Google Maps last-known-location, history, and area-alert demonstration |

## Importing it

1. Import `database.sql` first.
2. In phpMyAdmin, select `ira_assets`, choose **Import**, and upload `datasets/demo_seed.sql`.
3. Or run: `mysql -u YOUR_USER -p ira_assets < datasets/demo_seed.sql`

The seed is safe to re-run: it uses stable asset tags and email addresses with `INSERT IGNORE`. It does **not** delete existing data.

## Demo credentials

All seeded accounts use the password `DemoPass!2026` for local demonstrations only. Change it before deployment.

- `admin.demo@ira.example` — Administrator
- `user.demo@ira.example` — Standard user

## Ethical use

All people, serial numbers, device details, and coordinates are synthetic. The coordinates are approximate campus demonstration points, not a person's real location. In production, collect a location only with clear notice and explicit permission; retain only what is necessary.
