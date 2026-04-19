# Hackathon Technical Progress Report

## TICKET-012 - Advanced Search SQL Injection
**Task Completed:** TICKET-012 - Advanced Search Review  
**Technical Action Taken:** Replaced direct SQL string construction in the advanced search controller with strict field allowlisting, PDO prepared statements, and short-lived Redis caching for repeat searches.

- **Diagnosis:** The failure mapped to checklist point 1, MySQL Security. The advanced search `field` parameter was concatenated directly into the SQL query, creating a severe SQL injection vulnerability. Input validation on the column selector was also insufficient.
- **Action:** Refactored `backend/src/Controllers/SearchController.php` to accept only approved columns, bind the search term through PDO, escape wildcard characters for `LIKE`, cap the result set, and cache successful responses in Redis with `setex`. Added regression coverage for injected field values.
- **Triage Justification:** Priority 1 was appropriate because this was a direct database compromise risk with immediate exploit potential and high blast radius.
- **Impact:** The endpoint is now resistant to unauthorized SQL execution and data extraction, while repeat requests are cheaper and more stable under load.

## TICKET-010 - Webhook SSRF Vulnerability
**Task Completed:** TICKET-010 - Webhook URL Handling Review  
**Technical Action Taken:** Hardened webhook URL validation against SSRF targets, disabled redirect following in outbound webhook calls, and replaced internal endpoint source-trust checks with explicit admin authentication.

- **Diagnosis:** The failure mapped to checklist point 5, Middleware/Security. User-supplied webhook URLs were not being validated deeply enough, allowing requests toward loopback, private network ranges, and non-HTTP(S) schemes. An internal admin endpoint also trusted request origin instead of enforcing authentication and authorization.
- **Action:** Refactored `backend/src/Helpers/security.php` to reject unsafe schemes, embedded credentials, localhost, and private/reserved IP destinations. Updated `backend/src/Services/WebhookService.php` to avoid redirect-based bypasses. Changed `backend/src/Controllers/AdminController.php` so `/internal/admin/config` requires a logged-in admin user rather than loopback trust. Added regression tests for private webhook URLs, unsupported schemes, and admin-only access.
- **Triage Justification:** Priority 1 was justified because the defect exposed an SSRF path into internal infrastructure and a separate trust-boundary bypass on an internal endpoint.
- **Impact:** The webhook flow is now significantly safer against internal network access and redirect-based abuse, and the internal admin endpoint is no longer reachable based solely on source IP.

## TICKET-011 - Cross-Account Data Leak
**Task Completed:** TICKET-011 - Submissions Page Data Scoping Incident  
**Technical Action Taken:** Removed request-context mutation from collaborator permission checks, preserved the logged-in viewer identity for sidebar queries, and added a regression test to ensure related forms remain scoped to the active user.

- **Diagnosis:** The failure mapped to checklist point 6, Wildcard / Core PHP Logic. A helper in the submissions permission path was mutating `RequestContext::$currentUserId`, causing downstream queries in the same request to run under the wrong user scope. That created a brief cross-account data exposure on the submissions page.
- **Action:** Refactored `backend/src/Helpers/functions.php` so the form-access helper is read-only and no longer rewrites global request state. Updated `backend/src/Controllers/SubmissionController.php` to keep the original session user ID for the `related_forms` sidebar query. Added a regression test in `backend/tests/SubmissionTest.php` to verify shared-access submissions pages still show the viewer's own forms.
- **Triage Justification:** Priority 1 was appropriate because the issue exposed another account's data, even if briefly and inconsistently. Any cross-tenant data leakage is a high-severity security defect and requires immediate containment.
- **Impact:** The submissions page now preserves correct user scoping across the full request lifecycle, eliminating the accidental cross-account sidebar leak and reducing the risk of state contamination bugs.

## TICKET-009 - Peak-Hour Outages
**Task Completed:** TICKET-009 - Intermittent Outages During Peak Hours  
**Technical Action Taken:** Replaced the N+1 dashboard query pattern with a single aggregate query, added Redis-backed caching with explicit invalidation, and created supporting MySQL indexes for the forms and submissions access paths.

- **Diagnosis:** The failure mapped primarily to checklist point 2, MySQL Performance, with point 3, Redis/Cache, as an amplifier. The forms dashboard was issuing repeated per-row queries for submission counts, latest submission timestamps, and owner lookups. Under peak load, that multiplied database work enough to stall requests across the application.
- **Action:** Refactored `backend/src/Models/Form.php` to fetch dashboard data in one aggregate query, cache the result in Redis with a short TTL, and invalidate the cache when forms or submissions change. Updated `backend/src/Controllers/FormController.php` and `backend/src/Controllers/SubmissionController.php` to use the optimized path and keep cache freshness aligned with writes. Added a migration for composite indexes on `forms(user_id, updated_at)` and `submissions(form_id, submitted_at)`.
- **Triage Justification:** Priority 1 was appropriate because the issue affected the availability of the entire site, not just one user flow. Brief but recurring outages during business hours indicate a production-wide capacity problem with immediate customer impact.
- **Impact:** The dashboard now generates far fewer MySQL queries, reduces lock and CPU pressure during busy periods, and returns faster under load. Redis caching smooths repeated requests, while the new indexes improve the cold-cache execution plan and help keep the application responsive during peak traffic.

## TICKET-004 - Duplicate Notification Emails
**Task Completed:** ticket 4  
**Technical Action Taken:** Added an atomic Redis claim per scheduled email in the worker so only one process can send a given notification, and added regression coverage for the duplicate-send race.

- **Diagnosis:** The failure mapped to checklist point 3, Redis/Cache. The worker was reading pending email rows, sending them, and only then updating status. With multiple worker replicas running during busy periods, two processes could pick up the same pending row before either one marked it complete, which produced duplicate emails.
- **Action:** Refactored `backend/src/Workers/EmailWorker.php` to claim each scheduled email in Redis before sending, skip already-claimed rows, and release the claim after delivery completes. Added a regression test in `backend/tests/EmailWorkerTest.php` that pre-claims a job and verifies the worker does not process it twice.
- **Triage Justification:** Priority 2 was appropriate because the bug directly impacted customer-facing email delivery reliability and became much more visible under high traffic, but it did not expose data or security boundaries.
- **Impact:** The notification pipeline is now race-safe under concurrent worker execution, which prevents duplicate sends and stabilizes email behavior during load spikes.

## TICKET-008 - Revenue Totals Mismatch
**Task Completed:** ticket 8  
**Technical Action Taken:** Replaced brittle string parsing of payment amounts in the admin revenue endpoint with a PDO sum over the canonical `payments` table, added Redis-backed caching with versioned invalidation, and added a supporting MySQL index.

- **Diagnosis:** The failure mapped to checklist point 6, Wildcard / Core PHP Logic. The admin revenue calculation was extracting numbers from `app_config` using substring operations against serialized text, rather than reading the actual payment records. That made the totals drift from finance data and caused line-item values to be misread.
- **Action:** Refactored `backend/src/Controllers/AdminController.php` to sum `payments.amount` for approved transactions using PDO, cache the result briefly in Redis, and invalidate naturally when the payments table changes. Added a migration for `payments(status, paid_at)` and a regression test in `backend/tests/AdminTest.php` that verifies only approved payments are counted.
- **Triage Justification:** Priority 2 was appropriate because the issue affected a finance-facing reporting path and blocked accurate reporting, but it did not expose a direct security boundary or application-wide outage.
- **Impact:** Revenue totals now come from the canonical payments table, so the dashboard aligns with finance records. The query is also cheaper to serve under repeated admin access thanks to Redis caching and the new index.

Ready for the next update.
