# LavaLust Framework

> A lightweight, fast PHP framework built for developers who want clean MVC architecture without unnecessary complexity or performance overhead.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF)](https://www.php.net/)
[![GitHub Stars](https://img.shields.io/github/stars/ronmarasigan/lavalust?style=flat)](https://github.com/ronmarasigan/lavalust/stargazers)

---

## Overview

**LavaLust** is an open-source PHP framework that follows the **MVC (Model–View–Controller)** architectural pattern. It is designed for developers who need a structured, maintainable, and scalable foundation — without the bloat of heavier modern frameworks.

Whether you are building a simple web application, a REST API, or a teaching project, LavaLust provides the right tools with minimal friction.

---

## Features

| Feature | Description |
|---|---|
| **MVC Architecture** | Clean separation of Models, Views, and Controllers for organized, maintainable code |
| **Built-in Routing** | Flexible URL routing that maps requests to controllers with minimal configuration |
| **Libraries & Helpers** | Reusable components for sessions, forms, validation, and database access |
| **Modular Design** | Scalable structure that supports clean organization as your application grows |
| **REST API Support** | First-class support for building RESTful APIs using LavaLust conventions |
| **ORM-like Models** | Simplified, readable database interaction without a heavy abstraction layer |

---

## Requirements

- PHP 7.4 or higher
- A web server with URL rewriting support (Apache `.htaccess` or Nginx config)
- Composer (optional, for dependency management)

---

## Installation

**Clone the repository:**

```bash
git clone https://github.com/ronmarasigan/lavalust.git
cd lavalust
```

**Or download a release directly:**

```bash
wget https://github.com/ronmarasigan/lavalust/archive/refs/heads/main.zip
unzip main.zip
```

Configure your web server to point to the project root and ensure `mod_rewrite` (Apache) or equivalent is enabled.

---

## Quick Start

### 1. Define a Route

**File:** `app/config/routes.php`

```php
$router->get('/', 'Welcome::index');
$router->get('/about', 'Welcome::about');
$router->post('/users/store', 'Users::store');
```

### 2. Create a Controller

**File:** `app/controllers/Welcome.php`

```php
<?php

class Welcome extends Controller
{
    public function index()
    {
        $data['title'] = 'Home';
        $this->call->view('welcome', $data);
    }

    public function about()
    {
        $this->call->view('about');
    }
}
```

### 3. Create a View

**File:** `app/views/welcome.php`

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
</head>
<body>
    <h1>Welcome to LavaLust Framework</h1>
    <p>Lightweight. Fast. MVC.</p>
</body>
</html>
```

### 4. Create a Model

**File:** `app/models/User_model.php`

```php
<?php

class User_model extends Model
{
    protected $table = 'users';

    public function getAll()
    {
        return $this->db->table($this->table)->get()->getResult();
    }

    public function findById(int $id)
    {
        return $this->db->table($this->table)
                        ->where('id', $id)
                        ->get()
    }
}
```

---

## Project Structure

```
lavalust/
├── app/
│   ├── config/          # Application configuration (database, routes, etc.)
│   ├── controllers/     # Controller classes
│   ├── models/          # Model classes
│   ├── views/           # View templates
│   └── libraries/       # Custom libraries and helpers
├── scheme/              # Core framework files (do not modify)
├── public/              # Publicly accessible entry point
│   └── index.php
└── runtime/            # Cache, logs, and uploads (must be writable)
```

---

## Configuration

### Database

**File:** `app/config/database.php`

```php
$database['main'] = array(
    'driver'	=> '',
    'hostname'	=> getenv('DB_HOST') ?: '',
    'port'		=> getenv('DB_PORT') ?: '',
    'username'	=> getenv('DB_USERNAME') ?: '',
    'password'	=> getenv('DB_PASSWORD') ?: '',
    'database'	=> getenv('DB_NAME') ?: '',
    'charset'	=> '',
    'dbprefix'	=> '',
    // Optional for SQLite
    'path'      => ''
);
```

### Base URL

**File:** `app/config/config.php`

```php
$config['base_url'] = 'http://localhost:3000/';
```

## React Frontend

The React source is in the sibling `frontend/` directory, outside the
`LavaLust/` backend directory and separate from `app/views/`. Login, session
validation, logout, and the dashboard use LavaLust controllers and models:
`Auth` and `Dashboard` controllers call the authentication and dashboard
models, and return JSON responses. `GET /api/dashboard` requires a bearer
token and applies server-side barangay scope for Barangay Personnel.

From the folder containing both `frontend/` and `LavaLust/`, build the frontend:

```bash
cd frontend
npm install
npm run build
```

For a regular production build, Vite writes the bundle to
`LavaLust/public/react/` so the backend can serve it. On Vercel, set the project
Root Directory to `frontend`; Vite detects Vercel and writes to `frontend/dist`
with root-relative assets. React source remains outside the backend. No React
files are placed in `app/views/`.

For local development, start the PHP server from the backend directory:

```powershell
php -S 127.0.0.1:8081 -t public public\index.php
```

In another terminal, create `.env.local` in `frontend/` from `.env.example`
and set `LAVALUST_BACKEND_URL` to the backend URL (default:
`http://127.0.0.1:8081`), then start Vite:

```powershell
cd ..\frontend
npm run dev
```

Regular production builds are served by LavaLust from `public/react/`; Vercel
builds use `frontend/dist`. No React files are placed in `app/views/`.

## AGRI-TRACK Phase 1: Database and Backend Foundation

The AGRI-TRACK Phase 1 foundation adds normalized MySQL tables for users,
roles, permissions, barangays, farmers, farms, rice, crops, livestock,
production, notifications, announcements, messages, calendar events, import
history, reports, audit logs, and system settings. The existing LavaLust
`users` table is extended without replacing its migration; legacy roles map to
the new roles, and legacy barangay accounts must receive a barangay assignment
before they can sign in.

Barangay-owned tables carry `barangay_id`. Foreign keys also bind farm and
farmer references to the same barangay. Authenticated requests derive a
barangay assignment from the server-validated access token; the API must not
take a user's effective barangay from a request parameter. Access tokens are
random, expire after 12 hours, and are stored only as SHA-256 hashes. The
`auth` and role middleware can be applied to future protected routes.

### Configure and run

1. In phpMyAdmin (`http://localhost/phpmyadmin/`), create or select a
   development database named `agritrack` using `utf8mb4_unicode_ci`. Do not
   drop or reuse an existing database if you are unsure what it contains.
2. Copy `.env.example` to `.env` if you have not already done so. Configure
   `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, and `DB_NAME=agritrack`
   to match the MySQL server and database created/selected in phpMyAdmin. The
   database account needs permission to create and alter tables. Do not
   commit `.env`.
3. Verify `DB_DRIVER=mysql`, `DB_CHARSET=utf8mb4`, and `APP_TIMEZONE=Asia/Manila`.
   Set `CORS_ALLOWED_ORIGINS` to the exact comma-separated origins used by
   cross-origin clients; the example allows local `localhost` and `127.0.0.1`
   Vite origins. Same-origin requests from the LavaLust-served production
   frontend are handled without adding its host to the CORS allowlist.
   For Aiven MySQL, set `DB_SSL_CA=app/config/aiven-ca.pem` to enable TLS and
   verify the server certificate.
4. Run the migrations from the backend directory:

   ```powershell
   php public\index.php __cli/migrate
   ```

   The command uses LavaLust's migration library. It is CLI-only; requests to
   the same route through a web server return 404. Migrations are enabled for
   this command, so keep database backups and do not roll back production
   schema by dropping tables.
5. Configure `AGRI_ADMIN_EMAIL`, `AGRI_ADMIN_PASSWORD` (at least 12
   characters), and `AGRI_ADMIN_NAME` in `.env`, then create the initial CAO
   Administrator account:

   ```powershell
   php public\index.php __cli/seed-admin
   ```

   The seeder does not print or reset the password. If this exact configured
   email already belongs to a CAO Staff account, the seeder promotes that
   account to CAO Administrator and records the change in the audit log. It
   will not promote accounts with another role.

### Phase 2 API and pages

- `POST /api/auth/login` with JSON `{"email":"...","password":"..."}` returns
  an opaque bearer token. Login attempts are limited by identity and IP.
- `GET /api/auth/me` requires `Authorization: Bearer <token>`.
- `POST /api/auth/logout` requires the same header and revokes that token.
- `GET /api/dashboard` requires authentication and returns scoped metric
  counts, a six-month harvest trend grouped by unit, and upcoming planting
  activities. CAO Administrator and CAO Staff see city-wide data; Barangay
  Personnel are scoped to the barangay assigned to their authenticated account.
- `GET /api/farmer/dashboard` is available only to Farmer accounts linked to
  an active farmer profile. It returns that farmer's profile and own farm,
  planting, upcoming activity, and harvest records.
- `GET /api/barangays` and `GET /api/farmers` require a CAO or Barangay
  Personnel account. Barangay Personnel receive only their assigned barangay's
  records; CAO Staff and CAO Administrators receive city-wide records.
- `POST /api/farmers`, `GET /api/farmers/{id}`, `PATCH /api/farmers/{id}`, and
  `DELETE /api/farmers/{id}` provide validated create, view, update, and
  archive operations. Archiving a farmer also deactivates the linked Farmer
  account and revokes its active sessions.
- `GET /api/rice-varieties`, `GET /api/rice-stages`, and
  `GET /api/rice-plantings` provide reference and scoped rice planting data.
  `POST /api/rice-plantings` is available to CAO and Barangay Personnel; the
  backend validates that the farmer, farm, barangay, and verified variety
  belong together and calculates expected maturity from the variety duration.
- `GET /api/rice-monitoring` and `POST /api/rice-monitoring` provide
  role-scoped monitoring history and pending progress submissions. Farmer
  submissions are limited to that farmer's own planting records; Barangay
  Personnel are limited to their assigned barangay.
- `PATCH /api/rice-monitoring/{id}` is limited to CAO Administrator and CAO
  Staff. Review is restricted to pending submissions, requires a rejection
  reason when rejecting, records an audit event, and notifies the linked
  farmer account.
- `GET /api/admin/users`, `GET /api/admin/users/options`, `POST /api/admin/users`,
  and `PATCH /api/admin/users/{id}` are CAO Administrator-only. Account
  creation can create CAO Staff, Barangay Personnel, or link a Farmer account
  to an existing unlinked farmer profile. Account status changes and password
  resets are audited; resets revoke active sessions. This API does not create
  additional CAO Administrators.
- `GET /api/farms`, `GET /api/farms/options`, `POST /api/farms`, and
  `DELETE /api/farms/{id}` list, create, and archive farms. Farm creation and
  archiving are limited to agriculture staff; the backend verifies farmer and
  barangay ownership and refuses to archive farms that still have linked
  records.
- `GET /api/crop-plantings` and `POST /api/crop-plantings` list and create
  scoped crop plantings; `DELETE /api/crop-plantings/{id}` archives them.
  `GET /api/agriculture/references` supplies crop and livestock reference data.
- `GET /api/livestock`, `POST /api/livestock`,
  `DELETE /api/livestock/{id}`, and `GET`/`POST /api/livestock-health` provide
  scoped livestock and health-care records.
- `GET`/`POST /api/production` and `GET`/`POST /api/harvests` provide
  production and harvest records. `GET /api/production/references` and
  `/api/production/options` supply allowed types and authorized record options.
  `GET /api/reports/harvests` returns a scoped annual harvest summary; the
  React report page can download that summary as CSV.
- `GET /api/analytics/harvest-trend?years=5` returns scoped actual harvest
  totals by year, barangay, commodity, and unit, plus an ordinary least-squares
  estimate only when three consecutive years through the current year exist.
  This is a limited historical trend, not an agronomic forecast.
- `PATCH /api/auth/profile` lets an authenticated user update their name or
  email. Email changes and password changes require the current password.
  Password changes revoke all active sessions. Farmer registry details remain
  managed by authorized agriculture staff.
- `POST /api/auth/google` verifies a Google ID token and signs in only an
  existing, active Farmer account whose email is verified and matches a
  registered farmer. It does not create accounts. Configure
  `VITE_GOOGLE_CLIENT_ID` in the frontend and `GOOGLE_CLIENT_ID` in the
  backend to the same OAuth client ID.
- `POST /api/auth/password-reset/request` and
  `POST /api/auth/password-reset/complete` provide rate-limited, one-time
  password-reset links. Requests do not reveal whether an email is registered;
  configure `FRONTEND_BASE_URL` and outbound mail.
- `GET` and `PATCH /api/auth/email-preferences` read or update the signed-in
  user's announcement-email opt-in setting.
- `GET /api/exports/{farmers|farms|harvests|livestock}` generates scoped CSV
  for download, limited to 5,000 rows. Farmer exports are limited to
  agriculture staff; farm and harvest exports are filtered by the authenticated
  role's existing barangay/farmer scope. The frontend can also create a Google
  Sheet using the narrow `drive.file` permission and writes only these
  server-scoped export rows.
- `POST /api/imports/farmers` imports farmer records from the supplied CSV
  template (at most 500 rows / 2 MB). Each row is validated and imported
  independently; response and history include row-level errors. Barangay IDs
  must refer to active, verified CAO data. Template columns are
  `barangayId,registryNumber,firstName,middleName,lastName,suffix,sex,birthDate,phone,email,address,primaryLivelihood`.
  Barangay Personnel may leave `barangayId` blank because the server assigns
  their account's barangay. Uploaded CSV contents are not retained.
  `POST /api/imports/{farms|harvests|livestock}` imports those records from
  their corresponding templates. The frontend accepts CSV or XLSX files;
  `GET /api/imports` returns scoped import history.
- `GET /api/notifications` and `PATCH /api/notifications/{id}/read` expose
  each authenticated user's own notifications. `GET`/`POST
  /api/announcements`, `GET`/`POST /api/messages`, and `GET`/`POST
  /api/calendar` support published announcements, a general conversation,
  and scoped events. Read access is role-protected and barangay-filtered;
  creation routes are further restricted to agriculture staff as configured
  in `app/config/routes.php`.
- `POST` and `DELETE /api/calendar/feed-token` create or revoke the signed-in
  user's private iCalendar feed. `GET /api/calendar/feed/{token}.ics` returns
  calendar events visible to that user; treat the feed URL as a secret.
- Configure Gmail-compatible SMTP in `.env` and run the CLI-only
  `php public\index.php __cli/send-mail` job to deliver queued account and
  password-reset emails and opted-in announcement messages. Delivery attempts
  and retries are recorded in the mail outbox and audit log.
- `GET /api/admin/audit-logs` is restricted to CAO Administrators.
- React routes `/login`, `/dashboard`, `/farmers`, `/rice`,
  `/farms`, `/crops`, `/livestock`, `/production`, `/reports`, `/analytics`,
  `/settings`, `/imports`, `/communications`, `/calendar`, `/map`, `/presentation-demo`, `/admin/audit-logs`,
  `/admin/accounts`, `/farmer/dashboard`, and `/forbidden` are served by
  LavaLust and remain usable on a browser refresh. The map page plots saved
  coordinates or, when none exist, displays a clearly labeled Calapan City
  presentation preview with illustrative markers and conceptual zones. The
  preview is not persisted and is not official barangay/farm data; replace
  `frontend/src/pages/demoMapData.js` with verified CAO data later. An
  free interactive OpenStreetMap basemap is available after viewer opt-in and
  transmits map tile requests to the OpenStreetMap tile provider. It does not
  need a Google API key or billing account. The login
  page supports CAO Administrator, CAO Staff, Barangay Personnel, and Farmer roles.
  Farmers are sent to their separate personal dashboard.

  Migration `006_separate_cao_administrator_role.php` adds the CAO Administrator
  role, grants it the existing permission set, and removes `users.manage` and
  `settings.manage` from ordinary CAO Staff. It preserves current user and
  agricultural records. Seed the configured administrator after running the
  migration; if `AGRI_ADMIN_EMAIL` is not configured, administrator account
  management remains unavailable until an authorized bootstrap account is set up.

Successful responses use `{"data": ...}`; errors use
`{"error":{"code":"...","message":"..."}}`. CORS does not allow wildcard
origins.

The Vite development server proxies `/api` requests to
`LAVALUST_BACKEND_URL`. For a regular production build, run `npm run build` in
`frontend/`; assets are written to `public/react/` and served by LavaLust. On
Vercel, use `frontend` as the Root Directory and configure
`VITE_API_BASE_URL` to the deployed API base if the API is hosted separately.
For the current Render Docker setup, append `/public` to the backend origin
(for example, `https://<backend-host>/public`) so API requests reach LavaLust's
front controller.

### Sample-data policy and CAO inputs still required

The seed migration uses only the crop names and rice growth stages in the
requirements. Barangay names, geographical coordinates/boundaries, rice
variety names and growth durations, and livestock categories were not
provided; their rows are explicitly marked as samples/placeholders, with
unknown coordinates and durations left `NULL`. Replace those rows with
verified CAO data before production. Also obtain the official farmer
registration form, role/permission approvals, report templates, and database
deployment credentials. No administrator password or database secret is
committed in source.

### Remaining implementation and production readiness

The current React application includes the Google sign-in and password-reset
flows, map location editing and marker clustering, Google Sheets export,
calendar feed controls, CSV/XLSX imports and scoped exports, and print-to-PDF
harvest reports. These features require appropriate OAuth, SMTP, and CAO GIS
configuration where noted above. Trend estimates are explicitly not agronomic
predictions. OpenStreetMap tiles load only after the viewer opts in; without
opt-in, the local coordinate plot remains available. Replace sample CAO
reference rows, test every role-specific workflow with authorized accounts,
and complete deployment security/backup review before treating this
application as production-ready.

---

## Building a REST API

LavaLust supports REST API development out of the box. Controllers can return JSON responses for API endpoints.

```php
<?php

class Api extends Controller
{
    $this->call->library('api');

    public function users()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt(); 

        $this->call->model('User_model');
        $users = $this->User_model->getAll();

        $this->api->respond(['data' => $users]);
    }
}
```

Route definition:

```php
$router->get('/api/users', 'Api::users');
```

---

## Philosophy

LavaLust is built on a single principle: **minimal core, maximum control.**

Modern frameworks often add layers of abstraction that benefit large enterprise teams but get in the way of developers who want to understand exactly what their code is doing. LavaLust provides structure and utilities without hiding the underlying logic — making it an excellent choice for:

- **Rapid prototyping** — Get an application running in minutes
- **Learning MVC** — Understand how each architectural layer works
- **Lightweight production apps** — Deploy without dragging in unused dependencies
- **Teaching PHP development** — Clear conventions, readable source code

---

## Documentation

Full documentation is available at **[https://lavalust.netlify.app](https://lavalust.netlify.app)**

Topics covered include:

- Installation and server configuration
- Routing: static, dynamic, and grouped routes
- Controllers and request handling
- Models and query builder
- Views, layouts, and partials
- Built-in libraries (sessions, form validation, file upload)
- Helper functions
- REST API development
- Security best practices

---

## Contributing

Contributions are welcome. To contribute:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature-name`
3. Commit your changes: `git commit -m "Add your feature description"`
4. Push to your branch: `git push origin feature/your-feature-name`
5. Open a pull request against `main`

Please ensure your code follows the existing style conventions and includes relevant documentation or comments where appropriate.

---

## Roadmap

- [ ] CLI tool for generating controllers, models, and migrations
- [ ] Middleware support
- [ ] Improved query builder with relationship support
- [ ] Enhanced error handling and debugging tools

---

## License

LavaLust Framework is open-source software licensed under the **[MIT License](https://opensource.org/licenses/MIT)**.

---

## Links

- **GitHub Repository:** [https://github.com/ronmarasigan/lavalust](https://github.com/ronmarasigan/lavalust)
- **Documentation:** [https://lavalust.netlify.app](https://lavalust.netlify.app)
- **Report an Issue:** [https://github.com/ronmarasigan/lavalust/issues](https://github.com/ronmarasigan/lavalust/issues)