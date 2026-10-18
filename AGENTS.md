# AGENTS.md — System Rules & Guidelines

## Core Directives for this Repository

### 1. Database-Driven Architecture & Zero Hardcoded Data
- **NEVER use hardcoded data** for display, simulation, testing, or fallbacks in application code or UI pages.
- **Always fetch from the database**: Every table, metric card, list, modal, and filter must query the MySQL database dynamically using `$conn->query` or prepared statements.
- **No mock/fallback arrays**: If a query returns empty, render the natural empty state UI instead of falling back to fake in-memory arrays.
- **All seed/test data belongs in the database**: Use `app/shared/setup.php` and SQL migrations to initialize realistic records in the database tables so the system can practice authentic database querying.
- **Maintain this rule across all conversations and on any device** where this repository is pulled or cloned.

### 2. Role Isolation & Multi-Tier Workflows
- Always respect the 4 system roles: `student`, `club_adviser`, `ssc`, `admin`.
- When changes are requested for a specific role (e.g. SSC role only), strictly isolate the changes using `$sess_role === '...'` without affecting other roles.
- Ensure all multi-tier approval stages (Stage 1: Adviser Endorsement $\rightarrow$ Stage 2: SSC Review $\rightarrow$ Stage 3: Admin Clearance) are maintained with proper database columns and status transitions.
