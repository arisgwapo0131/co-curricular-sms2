# GEMINI.md — Repository Directives

## Mandatory Behavioral Constraints

### 1. Database-Driven Architecture (Zero Hardcoded Data)
- **NO HARDCODED DATA**: Never use hardcoded arrays, mock datasets, or simulation fallbacks in PHP or JavaScript files.
- **DATABASE FETCHING**: All UI tables, components, and statistics must be fetched dynamically from the MySQL database tables.
- **AUTHENTIC EMPTY STATES**: If a query returns no rows, display an authentic "No records found" state. Never substitute hardcoded test rows.
- **SEED DATA IN DATABASE**: When creating initial or sample data for development/testing, write database seeders in `app/shared/setup.php` so records are physically stored in MySQL tables and fetched cleanly.
- **MULTI-DEVICE PERSISTENCE**: This instruction is tracked in version control to ensure it persists when the codebase is deployed or pulled onto any other device.

### 2. Role Isolation & Multi-Tier Workflows
- Maintain clean role isolation for `student`, `club_adviser`, `ssc`, and `admin`.
- Changes requested for a specific role must be strictly scoped to that role.
