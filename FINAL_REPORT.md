# Final Report

## TICKET-012 - Advanced Search SQL Injection

**Technical Action:** Replaced raw SQL concatenation with field allowlisting, PDO prepared statements, escaped `LIKE` search patterns, bounded result counts, and Redis-cached repeat queries.

**Outcome:** SQL injection was blocked at the query layer and advanced search now returns user-scoped results without exposing the database to arbitrary SQL execution.

## TICKET-010 - Webhook SSRF Vulnerability

**Technical Action:** Added strict URL validation for webhook destinations, blocked loopback/private/reserved ranges and non-HTTP(S) schemes, disabled redirect-based bypasses, and enforced real admin authentication on internal configuration access.

**Outcome:** SSRF paths into internal infrastructure were neutralized and internal admin endpoints no longer trust network origin alone.

## TICKET-011 - Cross-Account Data Leak

**Technical Action:** Removed request-context mutation from collaborator access checks and bound related-form queries to the authenticated viewer instead of the form owner.

**Outcome:** Tenant isolation was restored and the submissions page no longer leaks another user's sidebar data.

## TICKET-009 - Peak-Hour Outages

**Technical Action:** Replaced the dashboard's N+1 query pattern with aggregate PDO queries, added supporting MySQL indexes, and layered Redis caching with explicit invalidation on writes.

**Outcome:** Peak-hour query fan-out was eliminated, dashboard latency dropped materially, and site-wide availability stabilized under concurrent load.

## TICKET-004 - Duplicate Notification Emails

**Technical Action:** Added atomic Redis claim logic in the email worker so only one process can send a scheduled notification before status is updated.

**Outcome:** The race condition was removed and duplicate notification sends under burst traffic were prevented.

## TICKET-008 - Revenue Totals Mismatch

**Technical Action:** Replaced fragile string parsing with canonical revenue aggregation from the `payments` table using PDO, plus Redis caching and a supporting index.

**Outcome:** Finance totals now align with the source-of-truth payment records instead of drifting from malformed parsed values.

## FEATURE-002 - Payment Gateway Integration

**Technical Action:** Built the backend payment flow scaffold around signed gateway communication, API key lookup, persistence to `payments`, and explicit approved/declined/error response handling.

**Outcome:** The application now has a structured foundation for secure gateway charging and auditable payment result storage, with remaining hardening captured in the roadmap.

## FEATURE-004 - Rate Limit Public Form Submissions

**Technical Action:** Implemented Redis throttling for `POST /api/forms/{id}/submissions` using per-IP counters, window expiry, and `Retry-After` response headers.

**Outcome:** Public submission abuse is now rate-limited, reducing spam pressure and protecting downstream processing during bursts.

## TICKET-006 - Form Search Performance

**Technical Action:** Moved search off the broad config scan and onto a user-scoped forms query with full-text indexing, Redis result caching, and cache invalidation on writes.

**Outcome:** Search latency and tail degradation were reduced substantially while keeping results scoped to the current user.

## TICKET-007 - "My Forms" List Load Time

**Technical Action:** Added pagination, bounded list queries, and cached total-count lookup for the forms dashboard.

**Outcome:** Large accounts no longer pay the cost of loading the entire dataset on every page view, improving response size and page-load time.

## TICKET-002 - Form Settings Truncation

**Technical Action:** Migrated `form_settings.value` from `VARCHAR(255)` to `TEXT` and aligned the persistent schema/bootstrap path with that storage change.

**Outcome:** Complex settings such as long HTML notification templates now round-trip without silent truncation, delivering 0% schema-driven data clipping for large values.

## TICKET-015 - Form Logo Wrong Image

**Technical Action:** Reworked upload storage to use collision-resistant unique naming inside form-scoped directories and updated stored paths accordingly.

**Outcome:** File collisions were eliminated and one user's logo upload can no longer overwrite another asset with the same original filename.

## TICKET-014 - Duplicate Paging Rows

**Technical Action:** Introduced deterministic ordering with `submitted_at` plus `id`, then added Redis-backed pagination snapshots to keep page windows stable during live inserts.

**Outcome:** Adjacent submission pages no longer overlap when new rows arrive mid-browse, restoring deterministic pagination.

## TICKET-013 - CSV Export Garbled Characters

**Technical Action:** Added UTF-8 BOM handling to CSV responses and verified export-safe encoding for multilingual submission content.

**Outcome:** Accented characters, emoji, and non-Latin scripts now open correctly in spreadsheet tools instead of degrading into garbled text.

## TICKET-005 - Edit Newly Created Form

**Technical Action:** Ensured default form resources are created synchronously at form creation time and added self-healing resource initialization on immediate edit/load.

**Outcome:** Newly created forms can be edited immediately without transient `404` failures while background setup catches up.

## TICKET-003 - "Require Login" Toggle Does Not Stick

**Technical Action:** Normalized stored boolean values for `require_login` on both read and write, converting mixed legacy values such as `1` and `0` into canonical true/false behavior.

**Outcome:** The login requirement toggle now persists consistently across forms and sessions without reverting due to value-shape mismatch.

## TICKET-001 - Scheduled Emails Arrive Late

**Technical Action:** Normalized scheduled-email timestamps from the user's local timezone into UTC before persistence so the worker and scheduler use the same time base.

**Outcome:** Scheduled emails now align with user intent instead of arriving with consistent multi-hour delays caused by timezone drift.

## FEATURE-001 - PDF Export for Form Submissions

**Technical Action:** Implemented authorized PDF export with a binary-safe response path, DOMPDF rendering, lightweight template generation, and memory-conscious submission formatting.

**Outcome:** Authorized owners receive valid `%PDF-` downloads with correct headers, while unauthorized users are still rejected with `404` or `401` as appropriate.

## Future Roadmap (Unfinished Features)

### FEATURE-002 - Advanced Payment Hardening

- **Technical Path:** Complete the implementation of the HMAC-SHA256 signature verification for inbound webhooks from the payment gateway to prevent spoofing.
- **Goal:** Ensure 100% financial integrity for high-volume transactions.

### FEATURE-003 - Submission Analytics Dashboard

- **Technical Path:** Develop an analytics engine using aggregate MySQL queries grouped by day and week, then layer Redis caching on top so submission trends can be served without repeatedly hitting the main database.
- **Goal:** Provide users with real-time data visualization.
