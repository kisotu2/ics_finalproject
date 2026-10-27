# Active Directory sync test plan

The validation test script covers record shape, missing fields, malformed JSON decoding, missing manufacturer serials, and strict purchase-date checks:

```bash
php tests/ad_sync_validation_test.php
```

Database and Windows-domain scenarios below require an isolated test database, a configured test endpoint, and (for the offline case) a domain-connected Windows host. Do not run integration checks against production data.

| ID | Scenario | Steps | Expected result |
|---|---|---|---|
| AD-01 | Valid sync | Submit a valid record through the endpoint with the test bearer token. | One laptop is created, receives an `IRA-LAP-<database id>` inventory serial, and the response/event log reports one create. |
| AD-02 | Repeated sync | Submit the same `ad_guid` twice, changing a hardware field on the second request. | The same laptop row and inventory serial are retained; its hardware values and last-sync timestamp update; no duplicate laptop is created. |
| AD-03 | Invalid token | Submit an otherwise valid request with a wrong bearer token. | HTTP 401; no laptop or successful sync event is written; rejection is recorded in the PHP server log. |
| AD-04 | Malformed JSON | Submit a truncated JSON body with the correct token and HTTPS. | HTTP 400 with a JSON error; no database changes. |
| AD-05 | Missing fields | Remove `ad_guid` or another required field from one computer record. | HTTP 422 identifies the missing field; no records from that payload are synchronized. |
| AD-06 | Duplicate inventory serial | In the isolated database, read the next auto-increment ID `N`, insert a blocker row with serial `IRA-LAP-(N+1)`, then sync a new computer (which receives ID `N+1`). | The new row receives the deterministic `IRA-LAP-(N+1)-1` suffix; the reserved row is unchanged. |
| AD-07 | Offline computer | Include an offline AD computer in the connector's search OU, then run preview and send. | PowerShell reports it as unreachable and omits it; the API does not delete or mark an existing laptop as retired. |
| AD-08 | Future purchase date | Submit a purchase date later than the server date, then submit a future warranty-expiry date. | Future purchase date returns HTTP 422; future warranty expiry is accepted. |
| AD-09 | Unauthorized UI | Request `directory_sync.php` with no session or a non-admin account. | The existing role guard redirects unauthenticated users and denies non-admin roles; no sync details are disclosed. |
| AD-10 | Missing/duplicate manufacturer serial | Sync one record without a BIOS serial and two records sharing a BIOS serial. | Records are still synchronized, missing values remain `NULL`, and the response lists missing/duplicate serials without fabricating or merging them. |
| AD-11 | Single-laptop connector | Run `Send-ThisLaptopInventory.ps1` on an authorized domain-joined test laptop with the managed token and HTTPS API URL. | It reads that laptop's AD identity and local hardware, submits one record, and the response reports a create or update for its AD GUID. Confirm no token or payload file is written to disk. |
| AD-12 | Single-Mac connector | On a managed Mac with PowerShell 7, run `Send-ThisMacInventory.ps1` in preview mode with IT-confirmed AD identity fields, then rerun with `-Send` and a securely provisioned process token. | Preview sends nothing; the send collects Mac hardware via macOS utilities and submits one record for the supplied AD GUID. Confirm missing disk/model values remain unknown and no token or payload file is written. |

Record actual outcomes and environment when running the database/domain cases. They are not considered passed merely because the test plan exists.

## Virtual laptop portal workflow checks

Run these checks only after applying `migrations/20261027_virtual_laptop_portal.sql` to an isolated database:

| ID | Scenario | Expected result |
|---|---|---|
| VP-01 | Employee device privacy | An employee sees only assigned laptops; changing the `id` parameter to another employee's laptop returns an unavailable response and reveals no asset details. |
| VP-02 | Virtual power state | A permitted user toggles the virtual display; the state persists after reload, an audit/history entry is recorded, and the laptop's actual status/assignment remain unchanged. CSRF failure is rejected. |
| VP-03 | Issue report | An assigned employee submits a valid report; it is `pending_admin_review`, the employee and responsible administrator are notified, and the laptop status remains unchanged. |
| VP-04 | Unauthorized report | An employee attempts to submit against an unassigned laptop ID; the request is rejected and no issue is created. |
| VP-05 | Approval idempotency | An administrator approves an issue once; exactly one maintenance row is linked. A repeated approval is refused and does not duplicate the job. |
| VP-06 | Review decisions | Reject, request-information, and duplicate actions require the expected reason/reference, preserve the original issue, notify the reporter, and record an issue update/audit event. |
| VP-07 | Maintenance completion | A linked maintenance job can be started and resolved; other active maintenance work and retired/disposed statuses are not overwritten. |
| VP-08 | Monitoring limitations | With no monitoring agent configured, the portal displays `Not configured` and does not claim live connectivity or health information. |

These are manual integration cases, not automated passes. No issue workflow or migration has been run against the configured application database in this development environment.
