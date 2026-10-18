# SMS Modular Database Architecture

This directory contains component-specific SQL schema modules for the Student Management System (SMS). Every core subsystem has its own dedicated schema definition containing its tables, constraints, indexes, and seed records.

## Component Modules

| Order | Module File | Primary Subsystem | Managed Tables |
|---|---|---|---|
| **01** | `01_auth_and_users.sql` | Identity & Authentication | `users` |
| **02** | `02_student_records.sql` | Student Records & Academic Mapping | `students` |
| **03** | `03_organizations_and_clubs.sql` | Organizations, Memberships, & Applications | `clubs`, `club_memberships`, `club_applications` |
| **04** | `04_events_and_registrations.sql` | Events Lifecycle & Registrations | `events`, `event_registrations` |
| **05** | `05_budget_and_finance.sql` | Budget Proposals & Multi-Tier Approvals | `budget_requests` |
| **06** | `06_attendance_and_tracking.sql` | Attendance Tracking (QR/RFID/Self-scan) | `attendance_logs` |
| **07** | `07_elections_and_voting.sql` | Digital Ballot & Campus Elections | `elections`, `election_candidates`, `election_votes` |
| **08** | `08_achievements_and_awards.sql` | Student Recognitions & Portfolio Verification | `achievements` |
| **09** | `09_announcements_and_notifications.sql` | Internal Communications & Targeted Alerts | `org_announcements`, `notifications` |
| **10** | `10_system_settings_and_audit.sql` | System Settings, Security Audit, & AI Analytics | `system_settings`, `audit_logs`, `ai_recommendation_logs` |

---

## Canonical Master Schema

For full automated database initialization or disaster recovery, use the master schema:
- Master SQL: `../sms_db.sql` (`c:\xamppp\htdocs\sms\database\sms_db.sql`)
- Setup Wizard: `../../app/shared/setup.php`

---

## System Roles Specification
All authentication and access control logic adheres strictly to the 4 canonical roles:
- `student`: Standard student member and voter
- `club_adviser`: Faculty adviser managing club memberships and event/budget endorsements
- `ssc`: Supreme Student Council reviewing inter-club events, budgets, achievements, and elections
- `admin`: Full system administration and institutional governance
