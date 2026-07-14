# Mentora LMS

Mentora LMS is a modern Learning Management System (LMS) built with CodeIgniter 4, Tailwind CSS, and Vite.

---

# Tech Stack

| Layer | Technology |
|--------|------------|
| Backend | PHP 8+, CodeIgniter 4, CodeIgniter Shield |
| Database | MySQL / MariaDB |
| Frontend | Tailwind CSS v4, Vite, Vanilla JavaScript (ES Modules) |
| Tools | Composer, Node.js, npm, Git, GitHub Actions |

---

# Project Setup

## Clone Repository

```bash
git clone <repository-url>
cd mentora
```

## Install Dependencies

```bash
composer install
npm install
```

## Configure Environment

```bash
cp env .env
```

Update:

- `CI_ENVIRONMENT`
- `app.baseURL`
- Database credentials

## Run Migrations

```bash
php spark migrate --all
php spark db:seed DatabaseSeeder # run only if you want to start with demo data
```

## Start Development

Terminal 1

```bash
php spark serve
```

Terminal 2

```bash
npm run dev
```

Application

```
http://localhost:8080
```

---

# Production Build

```bash
npm run build
```

---

# Project Structure

```text
app/
├── Controllers/
├── Models/
├── Services/
├── Helpers/
└── Views/
    ├── layouts/
    ├── pages/
    └── components/

resources/
├── css/
└── js/

public/
├── assets/
├── images/
└── build/

writable/
```

---

# Git Workflow

## Branch Strategy

```text
main
│
├── staging
│
├── feature/<feature-name>
├── bugfix/<issue-name>
└── hotfix/<issue-name>
```

### Branch Purpose

| Branch | Purpose |
|---------|---------|
| `main` | Production |
| `staging` | Integration, QA, Demo Deployment |
| `feature/*` | New features |
| `bugfix/*` | Bug fixes |
| `hotfix/*` | Urgent production fixes |

---

## Development Flow

### 1. Update staging

```bash
git checkout staging
git pull origin staging
```

### 2. Create a feature branch

```bash
git checkout -b feature/course-management
```

### 3. Develop

Commit your work regularly.

### 4. Push

```bash
git push origin feature/course-management
```

### 5. Create Pull Request

```
feature/*
        │
        ▼
     staging
```

After approval:

- GitHub Actions builds the project
- Deploys automatically to the Demo/Staging VPS
- Team performs QA and validation

### 6. Production Release

```
staging
        │
        ▼
      main
```

GitHub Actions deploys automatically to Production.

---

# Git Commit Convention

Follow the **50/72 Rule**.

## Format

```text
<type>(<scope>): <description>

- Body message 1
- Body message 2

[Optional footer]
```

### Types

| Type | Description |
|------|-------------|
| feat | New feature |
| fix | Bug fix |
| refactor | Code improvement |
| style | Formatting or styling |
| docs | Documentation |
| test | Tests |
| chore | Maintenance |
| build | Build configuration |
| ci | CI/CD updates |
| perf | Performance improvements |
| revert | Revert previous commit |

### Common Scopes

```text
auth
user
course
module
assessment
submission
gradebook
attendance
discussion
announcement
notification
message
dashboard
file
admin
institution
integration
analytics
ui
ux
api
database
deployment
project
```

### Example

```text
feat(auth): implement login validation

- Added login service
- Added request validation
- Added session handling
```

```text
fix(course): resolve enrollment validation

- Prevent duplicate enrollments
```

```text
docs(project): update README
```

---

# Branch Naming

```
feature/course-management

feature/student-dashboard

feature/instructor-dashboard

feature/attendance

feature/authentication

bugfix/login-session

bugfix/gradebook

hotfix/login
```

Keep branches **small**, **focused**, and **short-lived**.

---

# CI/CD Pipeline

```text
feature/*
        │
        ▼
Pull Request
        │
        ▼
staging
        │
        ▼
GitHub Actions
        │
        ▼
Build → Test → Deploy
        │
        ▼
Demo / QA VPS
        │
        ▼
Approval
        │
        ▼
main
        │
        ▼
GitHub Actions
        │
        ▼
Production Deployment
```

---

# Notes

Never commit:

```text
node_modules/
vendor/
public/build/
.env
```

Commit only source code and project files.