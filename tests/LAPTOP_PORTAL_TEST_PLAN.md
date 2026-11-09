# Laptop Portal Test Plan

Run these checks only against an isolated test database after applying
`migrations/20261027_virtual_laptop_portal.sql` and
`migrations/20261109_software_expiry_notifications.sql` when needed. Configure
SMTP in `config.php` before running the licence reminder cases. Do not run
destructive checks against production data.

| ID | Scenario | Expected result |
|---|---|---|
| INV-01 | Manual laptop registration | An administrator registers a laptop with valid identifiers and inventory details; the dashboard shows the new asset. Duplicate asset tags and serial numbers are rejected. |
| INV-02 | Automatic asset tags | Register laptops repeatedly, including with a gap in existing `IRA-LAP-###` values; the next saved laptop receives the first unused tag and no duplicate is created. |
| INV-03 | Manual laptop update | An administrator edits an existing laptop; inventory details update while its assignment and lifecycle status remain unchanged. |
| VP-01 | Employee device privacy | An employee sees only assigned laptops; requesting another employee's laptop by ID returns an unavailable response and reveals no asset details. |
| VP-02 | Virtual power state | A permitted user toggles the virtual display; the state persists after reload, an audit/history entry is recorded, and the laptop's actual status and assignment remain unchanged. Invalid CSRF tokens are rejected. |
| VP-03 | Issue report | An assigned employee submits a valid report; it enters `pending_admin_review`, the employee and responsible administrator are notified, and the laptop status remains unchanged. |
| VP-04 | Unauthorized report | An employee attempts to submit against an unassigned laptop ID; the request is rejected and no issue is created. |
| VP-05 | Approval idempotency | An administrator approves an issue once; exactly one maintenance row is linked. A repeated approval is refused and does not duplicate the job. |
| VP-06 | Review decisions | Reject, request-information, and duplicate actions require the expected reason or reference, preserve the original issue, notify the reporter, and record an issue update and audit event. |
| VP-07 | Maintenance completion | A linked maintenance job can be started and resolved; other active maintenance work and retired/disposed statuses are not overwritten. |
| VP-08 | Physical-device boundary | Toggling the virtual display updates only the stored simulation state; it does not claim to monitor or control the physical laptop. |
| LIC-01 | One-week expiry notice | Set an active software licence to expire in seven days and run `scripts/send_software_expiry_reminders.php`; configured active administrators receive an email. |
| LIC-02 | Daily reminder idempotency | Run the reminder job multiple times on the same day; each recipient gets at most one reminder for that licence and expiry date that day. Run again the next day; another reminder is sent. |
| LIC-03 | Renewal/deactivation stops old notices | Update the expiry date beyond the seven-day window or set the licence to Suspended/Retired; later runs do not send reminders for the old expiry date. |

These are manual integration cases, not automated passes. Record actual
outcomes and the test environment when running them.
