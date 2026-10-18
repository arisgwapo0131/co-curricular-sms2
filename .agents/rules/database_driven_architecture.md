# Database-Driven Architecture & Zero Hardcoded Data Policy

## Mandatory Core Directive
**NEVER USE HARDCODED DATA FOR DISPLAY, SIMULATION, TESTING, OR FALLBACKS.**

1. **Database as Single Source of Truth**:
   - All modules (Budget, Events, Elections, Rosters, Attendance, Achievements, Clubs, Reports, System Accounts) MUST fetch and persist all data directly through the MySQL relational database using legitimate queries (`SELECT`, `INSERT`, `UPDATE`, `DELETE`, `$conn->query`, `$conn->prepare`).
   - Mock arrays, in-memory dummy collections (e.g. `$mock_events = [...]`, `$dummy_budget = [...]`), or simulation fallbacks embedded in PHP or JavaScript are strictly prohibited.

2. **Authentic Empty States**:
   - If a database query returns zero records, the application must display a clean, authentic empty state (e.g., "No budget requisitions found", "No upcoming events scheduled") with proper call-to-actions, NEVER falling back to fake/simulation arrays.

3. **Practice Real Fetching**:
   - Every UI component, table, filter, and KPI metric card must dynamically aggregate and compute values from fetched database rows or SQL aggregation queries (`COUNT`, `SUM`, `AVG`, `DATE_FORMAT`).

4. **Database Seeding via `setup.php` & SQL Migrations**:
   - All test records, verified accounts, operational data, and multi-stage workflow samples must be seeded directly into the relational MySQL database using `app/shared/setup.php` and tracked SQL scripts in `database/`.
   - When deploying to another device, running `setup.php` must install the complete schema and populate the database with authentic seed records so the system is immediately ready for database fetching and testing.

5. **Cross-Device & Future Conversation Permanence**:
   - This rule is permanent across all conversations, all roles, and all repository clones on any device.
