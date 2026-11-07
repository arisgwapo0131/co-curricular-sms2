# 🚀 Railway Deployment Guide
## Bestlink College of the Philippines — Co-Curricular Management System

This project is fully configured and optimized for zero-configuration, production-grade deployment on [Railway](https://railway.app). The repository includes an optimized multi-stage `Dockerfile`, `railway.json` build definition, dynamic port binding, automated schema migrations, and edge-proxy HTTPS cookie handling.

---

## 📋 Architectural Overview

- **Runtime**: PHP 8.2 + Apache with `mpm_prefork`, `mod_rewrite`, `remoteip`, `headers`, `deflate`, and `expires`.
- **Extensions**: `mysqli`, `pdo_mysql`, `gd`, `zip`, `bcmath`, `opcache`, `mbstring`, `exif`, `intl`.
- **Database**: Railway MySQL (works over private network `mysql.railway.internal` or via `${{MySQL.MYSQL_URL}}`).
- **Health Check**: Ultra-fast `/healthz.php` responding with HTTP 200 within 1-2 seconds of container boot.
- **Auto-Migration**: Automatically runs database schemas (`sms_db.sql` & `12_rbac_and_master_data.sql`) and seeds authentic system accounts and clubs on first deployment.

---

## 🛠️ Step-by-Step Deployment Instructions

### Method 1: Web Dashboard (Recommended — No CLI Needed)

#### 1. Push Code to GitHub
Ensure this repository is pushed to your GitHub account (`git push origin main`).

#### 2. Create Project in Railway
1. Log in to [Railway](https://railway.app).
2. Click **"+ New Project"** $\rightarrow$ select **"Deploy from GitHub repo"**.
3. Select your repository (`bcp` or `co-curricular-sms2`).
4. Railway will create a web service and detect the `railway.json` and `Dockerfile`.

#### 3. Add a MySQL Database Service
1. Inside the same Railway project canvas, click **"+ New"** (top right) $\rightarrow$ select **"Database"** $\rightarrow$ select **"Add MySQL"**.
2. Railway will instantly provision a dedicated MySQL instance in your project.

#### 4. Link MySQL Variables to Your Web Service
1. Click on your **Web Service** card (not the database card).
2. Go to the **Variables** tab.
3. Click **"New Variable"** $\rightarrow$ **"Add Reference"** (or use the raw values):
   - `MYSQL_URL` $\rightarrow$ Select `${{MySQL.MYSQL_URL}}`
4. *(Optional but recommended for granular private DNS)* add:
   - `MYSQLHOST` $\rightarrow$ `${{MySQL.MYSQLHOST}}`
   - `MYSQLPORT` $\rightarrow$ `${{MySQL.MYSQLPORT}}`
   - `MYSQLUSER` $\rightarrow$ `${{MySQL.MYSQLUSER}}`
   - `MYSQLPASSWORD` $\rightarrow$ `${{MySQL.MYSQLPASSWORD}}`
   - `MYSQLDATABASE` $\rightarrow$ `${{MySQL.MYSQLDATABASE}}`
   - `DB_NAME` $\rightarrow$ `sms_db`
   - `APP_ENV` $\rightarrow$ `production`
   - `APP_DEBUG` $\rightarrow$ `false`

#### 5. Generate a Public Domain
1. In your Web Service, go to **Settings** $\rightarrow$ scroll to **Networking**.
2. Click **"Generate Domain"** (e.g., `bcp-portal.up.railway.app`).
3. Your deployment will automatically trigger. In ~1-2 minutes, click your domain link to open the live system!

---

### Method 2: Deployment via Railway CLI

If you prefer deploying from your local terminal:

```bash
# 1. Install Railway CLI (if not already installed)
npm install -g @railway/cli

# 2. Authenticate
railway login

# 3. Initialize or link your project
railway init

# 4. Provision MySQL database
railway add --database mysql

# 5. Deploy container
railway up

# 6. Generate public URL
railway domain
```

---

## 🔐 Default System Accounts Cheatsheet

Once deployed, the automated migration engine runs on container start and provisions the following accounts:

| Role | Username | Password | Full Name / Organization |
|---|---|---|---|
| **Super Admin** | `super.admin` | `Bcp@SuperAdmin2026!` | Institutional Core System Authority |
| **System Admin** | `scc.admin` | `Admin@123` | Institutional Administrator |
| **OSA Staff** | `osa.staff` | `Staff@123` | Office of Student Affairs |
| **SSC Officer** | `ssc.officer` | `Ssc@123` | Supreme Student Council |
| **Club Adviser** | `bsit.adviser` | `Adviser@123` | Danilo Santos (Information Technology Society #82) |
| **Student** | `bsit.student` | `Bcp@Test2026!` | Primary Student Account |

*(All password hashes use standard bcrypt `PASSWORD_DEFAULT` and authenticate immediately)*.

---

## 🩺 Monitoring, Logs & Health Checks

- **Health Check Endpoint**: `https://<your-domain>/healthz.php` or `https://<your-domain>/health`
- **Application Logs**:
  - In the Railway Dashboard $\rightarrow$ Web Service $\rightarrow$ **Deployments** $\rightarrow$ **View Logs**.
  - Background database setup log: `/tmp/db_setup.log`
  - Apache error log: `/var/log/apache2/error.log`
  - Apache access log: `/var/log/apache2/access.log`

---

## ❓ Frequently Asked Questions & Troubleshooting

### Q: Does Railway require manual database imports?
**No.** `docker-entrypoint.sh` executes `app/shared/cli_setup.php` as an asynchronous background worker when the container boots. If the database is empty, it automatically executes `database/sms_db.sql` and `database/modules/12_rbac_and_master_data.sql`, seeds system accounts, and provisions all clubs.

### Q: Why is `/healthz.php` used instead of the homepage for health checks?
Railway has a strict health check timeout. Serving `/healthz.php` returns HTTP 200 within 200ms without waiting for database warm-up. This guarantees Railway never marks your deployment as "Crashed" while MySQL establishes its private network DNS.

### Q: How is HTTPS reverse proxy handled?
The container includes Apache `mod_remoteip` and checks `HTTP_X_FORWARDED_PROTO === 'https'`. When traffic passes through Railway's SSL edge proxy, session cookies are automatically marked `Secure` and `SameSite=Lax`.
