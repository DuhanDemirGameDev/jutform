# Hackathon Technical Progress Report

**Task Completed:** TICKET-012 - Advanced Search Review
**Technical Action Taken:** Replaced direct SQL string construction in the advanced search controller with a whitelist-based field resolver, PDO prepared statements, short-lived Redis caching, and regression coverage for invalid field input.

- **Diagnosis:** The failure was rooted in checklist point 1, MySQL Security. The endpoint interpolated the `field` query parameter directly into the SQL statement, creating an SQL injection risk. The request also lacked strong input validation on the search field, which reinforced the exposure under checklist point 5.
- **Action:** Refactored `backend/src/Controllers/SearchController.php` to accept only approved search columns, bind the search term through PDO, escape wildcard characters for `LIKE`, cap the result set, and cache successful responses in Redis with `setex` to reduce repeat query load. Added a negative test in `backend/tests/SearchTest.php` for injected field values.
- **Triage Justification:** Priority 1 was appropriate because the defect exposed a direct database injection vector on an authenticated API endpoint. This is a security issue with immediate exploit potential and a high blast radius compared to ordinary functional bugs.
- **Impact:** The endpoint is now resistant to column-based SQL injection, safer for user-supplied input, and less expensive to serve under repeated searches. The Redis TTL prevents stale cache persistence while still smoothing load on MySQL.

Ready for the next update.
