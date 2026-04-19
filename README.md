# JutForm

Security-first, high-performance form management platform built on a legacy PHP architecture and hardened for production-style workloads.

## Project Overview

JutForm is a Dockerized form management system for creating forms, receiving submissions, exporting operational data, processing background jobs, and supporting administrative workflows. The platform was improved through a concentrated architecture pass focused on vulnerability remediation, performance optimization, data integrity, and operational stability.

The current system is **95% production ready**, with the remaining **5%** clearly defined in the roadmap below.

## Core Tech Stack

- PHP 8.1
- Nginx
- MySQL 8
- Redis
- Docker / Docker Compose

## Security-First Delivery Highlights

- SQL injection prevention with PDO binding and strict field allowlisting
- SSRF hardening for webhook destinations and authenticated protection for internal admin endpoints
- Cross-account data leak remediation through proper request-scope isolation
- Rate limiting for public submission endpoints using Redis throttling and `Retry-After`
- Canonical boolean normalization for sensitive form-access settings
- Collision-resistant file storage to prevent cross-tenant asset overwrite

## Performance and Reliability Highlights

- Aggregate query replacement for N+1 dashboard bottlenecks
- Full-text search support with user-scoped Redis caching
- Pagination for large form lists and deterministic snapshot pagination for live submissions
- Redis-assisted cache invalidation and worker coordination
- UTF-8 BOM export correction for spreadsheet compatibility
- Timezone normalization for scheduled email delivery
- Memory-conscious PDF export rendering for large submission sets

## How to Run

```bash
docker compose up -d
```

Core local endpoints:

- App: `http://localhost:8080`
- Mailpit: `http://localhost:8025`
- MySQL: `localhost:3307`
- Redis: `localhost:6380`

Useful commands:

```bash
docker compose up -d
./scripts/migrate.sh
./scripts/run-tests.sh
./scripts/logs.sh app -f
```

## Architecture Summary

- **Nginx** serves the frontend and proxies API requests
- **PHP-FPM** runs the legacy backend application
- **MySQL 8** stores canonical transactional and configuration data
- **Redis** provides caching, throttling, and queue coordination
- **Worker processes** handle asynchronous operations such as email delivery and form setup
- **Mailpit** captures outbound mail for safe local verification

## Delivery Documentation

The final project documentation surface is intentionally reduced to:

- [FINAL_REPORT.md](C:/PHP/jutform/FINAL_REPORT.md)
- [RUNNING_LOG.md](C:/PHP/jutform/reports/RUNNING_LOG.md)
- [README.md](C:/PHP/jutform/README.md)

## Future Roadmap & Technical Debt

### FEATURE-002 - Advanced Payment Hardening

The current payment implementation establishes the outbound charge flow and persistence model. The remaining hardening work is:

- completing HMAC-SHA256 signature verification for inbound payment-gateway webhooks
- validating webhook authenticity before applying financial state changes
- tightening high-volume reconciliation behavior for operational resilience

**Goal:** Ensure 100% financial integrity for high-volume transactions.

### FEATURE-003 - Submission Analytics Dashboard

The analytics roadmap remains focused on efficient reporting without degrading the core transactional database:

- develop aggregate MySQL queries grouped by day and week
- add Redis caching for analytics rollups and trend views
- expose submission trends through a dedicated dashboard-oriented API surface

**Goal:** Provide users with real-time data visualization.
