# Hackathon Technical Progress Report

**Task Completed:** Ticket 9 - Intermittent Outages During Peak Hours
**Technical Action Taken:** Replaced the N+1 dashboard query pattern with a single aggregate query, added Redis-backed caching with explicit invalidation, and created supporting MySQL indexes for the forms and submissions access paths.

- **Diagnosis:** The failure mapped primarily to checklist point 2, MySQL Performance, with point 3, Redis/Cache, as an amplifier. The forms dashboard was issuing repeated per-row queries for submission counts, latest submission timestamps, and owner lookups. Under peak load, that multiplied database work enough to stall requests across the application.
- **Action:** Refactored `backend/src/Models/Form.php` to fetch dashboard data in one aggregate query, cache the result in Redis with a short TTL, and invalidate the cache when forms or submissions change. Updated `backend/src/Controllers/FormController.php` and `backend/src/Controllers/SubmissionController.php` to use the optimized path and keep cache freshness aligned with writes. Added a migration for composite indexes on `forms(user_id, updated_at)` and `submissions(form_id, submitted_at)`.
- **Triage Justification:** Priority 1 was appropriate because the issue affected the availability of the entire site, not just one user flow. Brief but recurring outages during business hours indicate a production-wide capacity problem with immediate customer impact.
- **Impact:** The dashboard now generates far fewer MySQL queries, reduces lock and CPU pressure during busy periods, and returns faster under load. Redis caching smooths repeated requests, while the new indexes improve the cold-cache execution plan and help keep the application responsive during peak traffic.

Ready for the next update.
