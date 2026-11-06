# Active Directory sync test plan

The validation test script covers record shape, missing fields, endpoint-status validation, AD/endpoint status separation, UTC metadata timestamps, empty-scope final batches, missing manufacturer serials, and strict purchase-date checks:

```bash
php tests/ad_sync_validation_test.php
```

Database and Windows-domain scenarios below require an isolated test database, a configured test endpoint, and (for the offline case) a domain-connected Windows host. The API applies a 30-request-per-peer-per-minute limit; run rate-limit tests with a unique source or allow the window to expire. Do not run integration checks against production data.

| ID | Scenario | Steps | Expected result |
|---|---|---|---|
| AD-01 | Valid sync | Submit a valid record through the endpoint with the test bearer token. | One laptop is created, receives an `IRA-LAP-<database id>` inventory serial, and the response/event log reports one create. |
| AD-02 | Repeated sync | Submit the same `ad_guid` twice, changing a hardware field on the second request. | The same laptop row and inventory serial are retained; its hardware values and last-sync timestamp update; no duplicate laptop is created. |
| AD-03 | Invalid token | Submit an otherwise valid request with a wrong bearer token. | HTTP 401; no laptop or successful sync event is written; rejection is recorded in the PHP server log. |
| AD-04 | Malformed JSON | Submit a truncated JSON body with the correct token and HTTPS. | HTTP 400 with a JSON error; no database changes. |
| AD-05 | Missing fields | Remove `ad_guid` or another required field from one computer record in a batch with a valid peer. | HTTP 207 reports the record-level validation error and still processes the valid peer. An all-invalid batch returns HTTP 422 and failure details without changing inventory; mixed valid/invalid batches are logged with partial-failure counts. |
| AD-06 | Duplicate inventory serial | In the isolated database, read the next auto-increment ID `N`, insert a blocker row with serial `IRA-LAP-(N+1)`, then sync a new computer (which receives ID `N+1`). | The new row receives the deterministic `IRA-LAP-(N+1)-1` suffix; the reserved row is unchanged. |
| AD-07 | Offline computer | Include an offline AD computer in the connector's search OU, then run preview and send. | PowerShell still sends its AD identity with `offline` or `unreachable` status and a reason; previous database hardware values remain, and lifecycle state is unchanged. |
| AD-08 | Future purchase date | Submit a purchase date later than the server date, then submit a future warranty-expiry date. | Future purchase date returns HTTP 422; future warranty expiry is accepted. |
| AD-09 | Unauthorized UI | Request `directory_sync.php` with no session or a non-admin account. | The existing role guard redirects unauthenticated users and denies non-admin roles; no sync details are disclosed. |
| AD-10 | Missing/duplicate manufacturer serial | Sync one record without a BIOS serial and two records sharing a BIOS serial. | Records are still synchronized, missing values remain `NULL`, and the response lists missing/duplicate serials without fabricating or merging them. |
| AD-11 | Single-laptop connector | Run `Send-ThisLaptopInventory.ps1` on an authorized domain-joined test laptop with the managed token and HTTPS API URL. | It reads that laptop's AD identity and local hardware, submits one record, and the response reports a create or update for its AD GUID. Confirm no token or payload file is written to disk. |
| AD-12 | Single-Mac connector | On a managed Mac with PowerShell 7, run `Send-ThisMacInventory.ps1` in preview mode with IT-confirmed AD identity fields, then rerun with `-Send` and a securely provisioned process token. | Preview sends nothing; the send collects Mac hardware via macOS utilities and submits one record for the supplied AD GUID. Confirm missing disk/model values remain unknown and no token or payload file is written. |

Record actual outcomes and environment when running the database/domain cases. They are not considered passed merely because the test plan exists.

## Laptop portal workflow checks

Run these checks after applying the portal and AD endpoint monitoring migrations to an isolated database:

| ID | Scenario | Expected result |
|---|---|---|
| VP-01 | Employee device privacy | An employee sees only assigned laptops; changing the `id` parameter to another employee's laptop returns an unavailable response and reveals no asset details. |
| VP-02 | Legacy power endpoint | POST a request to `api/laptop_power_state.php`. | HTTP 410; the application does not change virtual state, asset lifecycle state, assignment, history, or audit data. |
| VP-03 | Issue report | An assigned employee submits a valid report; it is `pending_admin_review`, the employee and responsible administrator are notified, and the laptop status remains unchanged. |
| VP-04 | Unauthorized report | An employee attempts to submit against an unassigned laptop ID; the request is rejected and no issue is created. |
| VP-05 | Approval idempotency | An administrator approves an issue once; exactly one maintenance row is linked. A repeated approval is refused and does not duplicate the job. |
| VP-06 | Review decisions | Reject, request-information, and duplicate actions require the expected reason/reference, preserve the original issue, notify the reporter, and record an issue update/audit event. |
| VP-07 | Maintenance completion | A linked maintenance job can be started and resolved; other active maintenance work and retired/disposed statuses are not overwritten. |
| VP-08 | Monitoring limitations | With no monitoring agent configured, the portal displays `Not configured` and does not claim live connectivity or health information. |

These are manual integration cases, not automated passes. No issue workflow or migration has been run against the configured application database in this development environment.

## AD monitoring integration checks

| ID | Scenario | Expected result |
|---|---|---|
| AD-M01 | New domain computer | Complete run creates one row keyed by AD GUID and a unique generated inventory serial. |
| AD-M02 | Repeated run / changed hardware | Same row and inventory serial are updated; assignment, lifecycle state and history remain unchanged. |
| AD-M03 | Account disabled while endpoint responds | `ad_enabled=0` while endpoint remains `online`; neither state overwrites the other. |
| AD-M04 | Endpoint does not respond | Identity is ingested with `offline` or `unreachable`, reason and check timestamp; existing hardware remains unchanged. |
| AD-M05 | Complete empty scope | Final empty batch completes; previously known objects in that exact scope are flagged missing, never deleted. |
| AD-M06 | Interrupted/partial run | Run is failed/partial and no objects are marked missing. |
| AD-M07 | Manual dashboard request | Admin creates queued request; authenticated agent claims it; dashboard status progresses and final result is stored. |
| AD-M08 | Unauthorized queue access | Non-admin cannot create/read requests; missing/incorrect connector token cannot claim or fail a request. |
| AD-M09 | Scheduled Windows connector | Startup task runs under approved least-privilege identity, runs every 15 minutes, polls the queue, and reports failures without stopping later cycles. |

These scenarios require an isolated MySQL database and an authorized Windows AD test environment. This development environment has no reachable AD/Windows endpoint, so they have not been represented as executed.
