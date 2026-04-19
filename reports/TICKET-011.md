# Hackathon Technical Progress Report

**Task Completed:** Ticket #11 - Submissions Page Data Scoping Incident
**Technical Action Taken:** Removed request-context mutation from collaborator permission checks, preserved the logged-in viewer identity for sidebar queries, and added a regression test to ensure related forms remain scoped to the active user.

- **Diagnosis:** The failure mapped to checklist point 6, Wildcard / Core PHP Logic. A helper used during the submissions permission path was mutating `RequestContext::$currentUserId`, which caused downstream queries on the same request to run under the wrong user scope. That created a brief cross-account data exposure on the submissions page.
- **Action:** Refactored `backend/src/Helpers/functions.php` so the form-access helper is read-only and no longer rewrites global request state. Updated `backend/src/Controllers/SubmissionController.php` to keep the original session user ID for the `related_forms` sidebar query. Added a regression test in `backend/tests/SubmissionTest.php` to verify shared-access submissions pages still show the viewer’s own forms.
- **Triage Justification:** Priority 1 was appropriate because the issue exposed another account’s data, even if briefly and inconsistently. Any cross-tenant data leakage is a high-severity security defect and requires immediate containment.
- **Impact:** The submissions page now preserves correct user scoping across the full request lifecycle, eliminating the accidental cross-account sidebar leak. This improves data isolation, reduces security risk, and removes a class of hard-to-reproduce state contamination bugs.

Ready for the next update.
