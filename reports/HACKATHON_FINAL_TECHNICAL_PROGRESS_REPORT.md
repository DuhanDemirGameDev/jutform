# Hackathon Final Technical Progress Report

## TICKET-012 - Advanced Search SQL Injection
**Task Completed:** Advanced Search Review

**Technical Action Taken:** Replaced direct SQL string construction in the advanced search flow with strict field allowlisting, PDO prepared statements, wildcard escaping for `LIKE`, bounded result counts, and short-lived Redis caching for repeat requests.

**Diagnosis:** The failure mapped to checklist point 1, MySQL Security. The advanced search `field` parameter was concatenated directly into the SQL query, which exposed a severe SQL injection path. Input validation on the column selector was also insufficient.

**Action:** Refactored `backend/src/Controllers/SearchController.php` so only approved columns are accepted, the search term is bound through PDO, wildcard characters are escaped safely, and the result set is capped. Added Redis-backed caching for repeated searches and regression coverage for injected field payloads.

**Triage Justification:** Priority 1 was appropriate because this was a direct database compromise risk with immediate exploit potential and a high blast radius.

**Impact:** The endpoint is now resistant to unauthorized SQL execution and data extraction, while repeat requests are cheaper and more stable under load.

## TICKET-010 - Webhook SSRF Vulnerability
**Task Completed:** Webhook URL Handling Review

**Technical Action Taken:** Hardened webhook URL validation against SSRF targets, disabled redirect following in outbound webhook calls, and replaced internal endpoint source-trust checks with explicit admin authentication.

**Diagnosis:** The failure mapped to checklist point 5, Middleware/Security. User-supplied webhook URLs were not being validated deeply enough, which allowed requests toward loopback, private network ranges, and non-HTTP(S) schemes. An internal admin endpoint also trusted request origin instead of enforcing authentication and authorization.

**Action:** Refactored `backend/src/Helpers/security.php` to reject unsafe schemes, embedded credentials, localhost, and private or reserved IP destinations. Updated `backend/src/Services/WebhookService.php` to avoid redirect-based bypasses. Changed `backend/src/Controllers/AdminController.php` so `/internal/admin/config` requires a logged-in admin user rather than loopback trust. Added regression tests for private webhook URLs, unsupported schemes, and admin-only access.

**Triage Justification:** Priority 1 was justified because the defect exposed an SSRF path into internal infrastructure and a separate trust-boundary bypass on an internal endpoint.

**Impact:** The webhook flow is now significantly safer against internal network access and redirect-based abuse, and the internal admin endpoint is no longer reachable based solely on source IP.

## TICKET-011 - Cross-Account Data Leak
**Task Completed:** Submissions Page Data Scoping Incident

**Technical Action Taken:** Removed request-context mutation from collaborator permission checks, preserved the logged-in viewer identity for sidebar queries, and added a regression test to ensure related forms remain scoped to the active user.

**Diagnosis:** The failure mapped to checklist point 6, Wildcard / Core PHP Logic. A helper in the submissions permission path was mutating `RequestContext::$currentUserId`, which caused downstream queries in the same request to run under the wrong user scope. That created a brief cross-account data exposure on the submissions page.

**Action:** Refactored `backend/src/Helpers/functions.php` so the form-access helper is read-only and no longer rewrites global request state. Updated `backend/src/Controllers/SubmissionController.php` to keep the original session user ID for the `related_forms` sidebar query. Added a regression test in `backend/tests/SubmissionTest.php` to verify shared-access submissions pages still show the viewer's own forms.

**Triage Justification:** Priority 1 was appropriate because the issue exposed another account's data, even if briefly and inconsistently. Any cross-tenant data leakage is a high-severity security defect and required immediate containment.

**Impact:** The submissions page now preserves correct user scoping across the full request lifecycle, eliminating the accidental cross-account sidebar leak and reducing the risk of state contamination bugs.

## TICKET-009 - Peak-Hour Outages
**Task Completed:** Intermittent Outages During Peak Hours

**Technical Action Taken:** Replaced the N+1 dashboard query pattern with a single aggregate query, added Redis-backed caching with explicit invalidation, and created supporting MySQL indexes for the forms and submissions access paths.

**Diagnosis:** The failure mapped primarily to checklist point 2, MySQL Performance, with point 3, Redis/Cache, as an amplifier. The forms dashboard was issuing repeated per-row queries for submission counts, latest submission timestamps, and owner lookups. Under peak load, that multiplied database work enough to stall requests across the application.

**Action:** Refactored `backend/src/Models/Form.php` to fetch dashboard data in one aggregate query, cache the result in Redis with a short TTL, and invalidate the cache when forms or submissions change. Updated `backend/src/Controllers/FormController.php` and `backend/src/Controllers/SubmissionController.php` to use the optimized path and keep cache freshness aligned with writes. Added `backend/migrations/0002_add_dashboard_indexes.php` with composite indexes on `forms(user_id, updated_at)` and `submissions(form_id, submitted_at)`.

**Triage Justification:** Priority 1 was appropriate because the issue affected the availability of the entire site, not just one user flow. Brief but recurring outages during business hours indicated a production-wide capacity problem with immediate customer impact.

**Impact:** The dashboard now generates far fewer MySQL queries, reduces lock and CPU pressure during busy periods, and returns faster under load. Redis caching smooths repeated requests, while the new indexes improve the cold-cache execution plan and help keep the application responsive during peak traffic.

## TICKET-004 - Duplicate Notification Emails
**Task Completed:** Duplicate Notification Email Investigation

**Technical Action Taken:** Added an atomic Redis claim per scheduled email in the worker so only one process can send a given notification, and added regression coverage for the duplicate-send race.

**Diagnosis:** The failure mapped to checklist point 3, Redis/Cache. The worker was reading pending email rows, sending them, and only then updating status. With multiple worker replicas running during busy periods, two processes could pick up the same pending row before either one marked it complete, which produced duplicate emails.

**Action:** Refactored `backend/src/Workers/EmailWorker.php` to claim each scheduled email in Redis before sending, skip already-claimed rows, and release the claim after delivery completes. Added a regression test in `backend/tests/EmailWorkerTest.php` that pre-claims a job and verifies the worker does not process it twice.

**Triage Justification:** Priority 2 was appropriate because the bug directly impacted customer-facing email delivery reliability and became much more visible under high traffic, but it did not expose data or security boundaries.

**Impact:** The notification pipeline is now race-safe under concurrent worker execution, which prevents duplicate sends and stabilizes email behavior during load spikes.

## TICKET-008 - Revenue Totals Mismatch
**Task Completed:** Revenue Reporting Correction

**Technical Action Taken:** Replaced brittle string parsing of payment amounts in the admin revenue endpoint with a PDO sum over the canonical `payments` table, added Redis-backed caching with versioned invalidation, and added a supporting MySQL index.

**Diagnosis:** The failure mapped to checklist point 6, Wildcard / Core PHP Logic. The admin revenue calculation was extracting numbers from `app_config` using substring operations against serialized text rather than reading actual payment records. That made the totals drift from finance data and caused line-item values to be misread.

**Action:** Refactored `backend/src/Controllers/AdminController.php` to sum `payments.amount` for approved transactions using PDO, cache the result briefly in Redis, and invalidate naturally when the payments table changes. Added `backend/migrations/0003_add_payments_status_index.php` for `payments(status, paid_at)` and a regression test in `backend/tests/AdminTest.php` that verifies only approved payments are counted.

**Triage Justification:** Priority 2 was appropriate because the issue affected a finance-facing reporting path and blocked accurate reporting, but it did not expose a direct security boundary or application-wide outage.

**Impact:** Revenue totals now come from the canonical payments table, so the dashboard aligns with finance records. The query is also cheaper to serve under repeated admin access thanks to Redis caching and the new index.

## FEATURE-002 - Payment Gateway Integration
**Task Completed:** Payment Gateway Integration

**Technical Action Taken:** Implemented the `/api/payments` backend flow with authenticated gateway salt retrieval, SHA-256 request signing, payment persistence, Redis-cached API key lookup, and explicit HTTP status handling for approved, declined, and gateway-unavailable outcomes.

**Diagnosis:** The failure mapped to checklist point 6, Wildcard / Core PHP Logic. The payment endpoint was a `501 Not implemented` stub, so there was no business logic to fetch the gateway salt, sign the request, submit the charge, or persist the result.

**Action:** Built `backend/src/Services/PaymentGatewayService.php` to call the gateway using the Docker service name `http://payment-gateway`, added `backend/src/Models/Payment.php` for persistence, and implemented `backend/src/Controllers/FeatureController.php` to validate form ownership, compute the signed hash, handle approved and declined gateway responses, and write each outcome to `payments`. Added regression tests in `backend/tests/PaymentTest.php` and a supporting payments index migration.

**Triage Justification:** Priority 2 was appropriate because this was a new finance integration path that blocks payment processing if absent, but it was not a security defect or system-wide outage.

**Impact:** The application can now charge through the gateway reliably, persist the exact result for accounting, and return the correct HTTP contract to the frontend. Redis caching reduces repeated API key lookups without changing gateway behavior.

## FEATURE-004 - Rate Limit Public Form Submissions
**Task Completed:** Submission Rate Limiting

**Technical Action Taken:** Added a submission-only Redis rate limiter keyed by client IP, enforced a 10-requests-per-5-seconds ceiling, and returned `429` responses with a computed `Retry-After` header when the window is exceeded.

**Diagnosis:** The failure mapped to checklist point 3, Redis/Cache, with a supporting HTTP communication concern from point 4. The public submission endpoint had no request throttling, so automated clients could spam `POST /api/forms/{id}/submissions` without constraint. The API also needed to return a standards-compliant `429` response with retry guidance once the limit was hit.

**Action:** Added `backend/src/Middleware/SubmissionRateLimitMiddleware.php` to track requests in Redis using a fixed 5-second window per IP and atomically increment counters before the submission controller runs. Wired the middleware only to the public submission route in `backend/config/routes.php`, extended `backend/src/Core/Response.php` to support custom headers on JSON responses, and added regression tests in `backend/tests/SubmissionRateLimitTest.php` to verify the 11th request is blocked while other routes remain unaffected.

**Triage Justification:** Priority 2 was appropriate because this was a targeted abuse-prevention feature rather than a data breach or outage, but it still protects an important public entry point from bot traffic and uncontrolled load.

**Impact:** Public submissions are now constrained to a predictable request rate per IP, which reduces spam, protects downstream processing from bursts, and preserves normal behavior on unrelated endpoints. The `Retry-After` header also gives clients a clear recovery window instead of failing blindly.

## TICKET-006 - Form Search Performance
**Task Completed:** Form Search Performance Investigation

**Technical Action Taken:** Replaced the dashboard search path's broad `app_config` scan with a user-scoped forms query, added Redis caching and cache invalidation for search results, and introduced a MySQL full-text index to keep form lookups fast as data volume grows.

**Diagnosis:** The failure mapped primarily to checklist point 2, MySQL Performance, with point 3, Redis/Cache, as an amplifier. The search endpoint was querying `app_config` with a broad `LIKE '%term%'` pattern, which forced expensive scans over unrelated configuration data and did not benefit from a targeted index or result cache. As traffic and stored data grew, repeated searches and refreshes compounded the load and pushed tail latency higher.

**Action:** Refactored `backend/src/Controllers/SearchController.php` so `/api/search` now searches only the authenticated user's forms instead of global config records. Added `Form::searchByUser()` in `backend/src/Models/Form.php` to use a cached, user-scoped search path with Redis-backed result caching and versioned invalidation on form create and update. Added `backend/migrations/0004_add_forms_fulltext_index.php` so title and description searches can use a full-text index, with a safe fallback `LIKE` path for short terms. Extended `backend/tests/SearchTest.php` to cover positive search results and user scoping.

**Triage Justification:** Priority 2 was appropriate because the issue caused severe user-facing latency in a common workflow and worsened under concurrent use, but it did not cross a security boundary or take down the full application.

**Impact:** Search now executes against the correct dataset, returns only the current user's forms, and avoids repeated full scans under normal usage. Redis caching smooths repeated queries, while the new full-text index materially reduces query cost as the forms table continues to grow.

## TICKET-007 - "My Forms" List Load Time
**Task Completed:** My Forms List Performance Investigation

**Technical Action Taken:** Introduced pagination on the `GET /api/forms` dashboard path, added a cached total-count lookup, and limited each request to a bounded slice of forms instead of loading the entire dataset for large accounts.

**Diagnosis:** The failure mapped primarily to checklist point 2, MySQL Performance, with point 3, Redis/Cache, as a supporting factor. The `My forms` endpoint returned the full form list for the authenticated user on every request, which meant users with large datasets paid the cost of querying, hydrating, serializing, and transferring every row even when they only needed the first visible page. Existing caching reduced some repeat cost, but it did not address the fact that response size and query work still scaled linearly with account size.

**Action:** Refactored `backend/src/Controllers/FormController.php` so the forms list accepts `page` and `limit`, calculates an offset, and returns pagination metadata alongside the forms array. Updated `backend/src/Models/Form.php` to fetch only the requested slice, added a cached `countByUser()` path for total records, and kept existing dashboard cache versioning so create and update operations continue to invalidate stale list data. Extended `backend/tests/FormCrudTest.php` to verify paginated responses and stable pagination metadata.

**Triage Justification:** Priority 2 was appropriate because the defect heavily impacted a common customer workflow and degraded in proportion to account growth, but it was confined to one major page rather than becoming a full-site availability incident.

**Impact:** Large accounts now load only the forms needed for the current page, which reduces database work, response payload size, and serialization overhead. Cached counts help keep repeated loads responsive, and the API now gives the frontend explicit pagination metadata for predictable navigation.

## TICKET-002 - Form Settings Truncation
**Task Completed:** Form Settings Storage Correction

**Technical Action Taken:** Expanded the `form_settings.value` storage column from `VARCHAR(255)` to `TEXT`, aligned the bootstrap schema with that change, and added a regression test that round-trips a long HTML notification template through the form update and load endpoints.

**Diagnosis:** The failure mapped to checklist point 6, Wildcard / Core PHP Logic. The controller and persistence calls were functioning as designed, but the underlying schema defined `form_settings.value` as `VARCHAR(255)`. Larger notification templates were therefore being silently truncated by the database layer, which made complex settings appear to save successfully while coming back chopped off on later loads.

**Action:** Added `backend/migrations/0005_expand_form_settings_value.php` to widen the `form_settings.value` column to `TEXT` and updated `docker/mysql/init/00-schema.sql` so fresh environments do not revert to the narrow type. Added a regression test in `backend/tests/FormCrudTest.php` that saves a template longer than 255 characters through `PUT /api/forms/1` and verifies the exact string is returned unchanged from `GET /api/forms/1`.

**Triage Justification:** Priority 2 was appropriate because the defect corrupted customer-facing settings on a production workflow and caused broken email content to be sent to end users, but it did not create a security breach or a system-wide outage.

**Impact:** Complex form settings now persist without truncation, which restores correctness for branded notification templates and other larger values. The change improves data integrity and prevents silent content corruption during normal form administration.

## TICKET-015 - Form Logo Wrong Image
**Task Completed:** File Upload Collision Remediation

**Technical Action Taken:** Reworked file upload storage so each uploaded asset is written to a unique form-scoped on-disk path rather than reusing the original filename, and added regression coverage for same-name uploads.

**Diagnosis:** The failure mapped to checklist point 6, Wildcard / Core PHP Logic. The upload controller was saving files under a shared filename-based path, which meant two different uploads with the same filename could overwrite the same physical file on disk. The database rows remained distinct, but the stored file contents could be replaced by another upload, causing a form logo to render the wrong image.

**Action:** Refactored `backend/src/Controllers/FileUploadController.php` to generate a unique random storage filename for every upload inside a form-specific subdirectory while preserving the file extension. The upload flow still stores the original display name in the database, but the physical file path is now collision-resistant and scoped by form. Added a regression test in `backend/tests/FileDownloadTest.php` to verify that uploads with the same original filename produce different stored paths and segregated form directories.

**Triage Justification:** Priority 2 was appropriate because the defect caused cross-account asset confusion and undermined customer trust, but it did not expose direct authentication bypass or full application outage behavior.

**Impact:** Uploaded logos are now isolated per file and can no longer overwrite one another based on a shared filename. This restores correctness for logo rendering, protects asset integrity across forms and users, and removes a subtle cross-tenant data mix-up risk.

## TICKET-014 - Duplicate Paging Rows
**Task Completed:** Submissions Pagination Stabilization

**Technical Action Taken:** Stabilized submissions pagination with deterministic ordering and a short-lived Redis-backed pagination snapshot so later pages continue reading from the same result window even while new submissions arrive.

**Diagnosis:** The failure mapped to checklist point 6, Wildcard / Core PHP Logic. The submissions list was using offset pagination with `ORDER BY submitted_at DESC` only, which meant rows with identical timestamps could reorder nondeterministically between requests. New submissions arriving between page loads also shifted the offset window, causing adjacent pages to overlap even though the underlying records were not true duplicates.

**Action:** Refactored `backend/src/Models/Submission.php` to enforce deterministic ordering with `ORDER BY submitted_at DESC, id DESC` and to support querying against a snapshot boundary. Updated `backend/src/Controllers/SubmissionController.php` to create a snapshot from page 1, cache it briefly in Redis per user and form, and reuse it for later pages so the browse session remains stable while new submissions are inserted. Added regression coverage in `backend/tests/SubmissionTest.php` for both tied timestamps and mid-pagination inserts.

**Triage Justification:** Priority 2 was appropriate because the defect directly affected a common user workflow and undermined trust in submission data, but it did not expose data across tenants or cause a full-service outage.

**Impact:** Submission pagination is now consistent across adjacent pages, even on active forms receiving new entries. The deterministic sort removes tie-based shuffling, and the snapshot window prevents offset drift, making the UI more reliable and easier to reason about for operators reviewing live submission queues.

## TICKET-013 - CSV Export Shows Garbled Characters
**Task Completed:** CSV Export Encoding Correction

**Technical Action Taken:** Updated the CSV response layer to prepend a UTF-8 BOM to exports and added regression coverage for multilingual submission content containing accented characters, emoji, and non-Latin scripts.

**Diagnosis:** The failure mapped to checklist point 4, HTTP Communication. The underlying submission data remained UTF-8, but the CSV export response did not include a UTF-8 BOM. Spreadsheet clients such as Excel could therefore mis-detect the encoding and render accented names, emoji responses, and non-Latin scripts as question marks or corrupted symbols.

**Action:** Refactored `backend/src/Core/Response.php` so `Response::csv()` prepends the UTF-8 BOM bytes `EF BB BF` when the content does not already include them. Added a regression test in `backend/tests/SubmissionTest.php` that inserts multilingual submission content and verifies the CSV export begins with the BOM and preserves strings such as `José 😀`, `İstanbul`, and `東京`.

**Triage Justification:** Priority 2 was appropriate because the issue corrupted exported customer data in a common sharing workflow, but it did not create a security boundary failure or a system-wide outage.

**Impact:** CSV exports now open correctly in spreadsheet tools that rely on BOM-based encoding detection, restoring readable multilingual data for customers and reducing manual cleanup or re-export work.
