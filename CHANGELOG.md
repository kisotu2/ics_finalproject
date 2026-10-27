# Changelog

## 2026-10-27

- Restored super-admin user registration as a protected, CSRF-validated flow with hashed passwords and audit logging.
- Refined the shared application styles for a calmer, more spacious and responsive interface with clearer keyboard focus states.

## 2026-10-26

- Added an authenticated Active Directory synchronization page and a token-protected JSON ingestion endpoint.
- Added an authorized Windows PowerShell connector that reads computer identity from Active Directory and queries hardware from online endpoints.
- Added a one-laptop PowerShell sender for authorized domain-joined devices to submit their own AD identity and locally collected hardware.
- Added a macOS PowerShell 7 sender for Macs that already have an AD computer object, with a download link on the administrator sync page.
- Added idempotent laptop synchronization keyed by AD object GUID, generated internal inventory serials, separate manufacturer serials, and sync-event logging.
- Clarified the dashboard and asset-details views' internal versus manufacturer serial labels and documented release-archive exclusions.
- Added the AD inventory schema migration and updated the fresh-install database schema.
- Retired manual laptop registration/edit routes and blocked legacy laptop creation through the asset AJAX endpoint.
- Limited the training-data importer to existing AD-synchronized laptops and validated imported purchase dates.
- Added validation tests, an integration test plan, safe configuration and Apache access-control examples, and setup documentation.
