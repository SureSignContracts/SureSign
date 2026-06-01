# SureSign

White-label construction contract administration & AI automation platform.

## Stack
- **Backend**: Laravel 11 + MySQL + Sanctum + Spatie Permissions
- **Frontend**: Next.js 16 + TypeScript + TailwindCSS + Zustand + React Query
- **Docker**: MySQL, Redis, Nginx

## Quick Start (Local)

### Backend
```bash
cd backend
composer install
cp .env.example .env   # configure DB credentials
php artisan migrate --seed
php artisan serve --port=8000
```

### Frontend
```bash
cd frontend
npm install
npm run dev
```

## Access
| URL | Purpose |
|-----|---------|
| http://localhost:3000 | Frontend |
| http://localhost:8000/api | API |

## Default Login
| Field | Value |
|-------|-------|
| Email | admin@suresign.app |
| Password | Admin@2024! |

## MySQL Workbench Connection
| Setting | Value |
|---------|-------|
| Host | 127.0.0.1 |
| Port | 3306 |
| Database | suresign |
| Username | suresign |
| Password | SET_IN_ENV_FILE |

## Docker
```bash
docker-compose up -d
```

## Modules
- Authentication & Role-Based Access Control
- Multi-Tenant Organizations & White-Label Branding
- Project Workspace System (auto folder creation)
- Contract Administration
- Commercial Administration (Payment Apps, Variations, Pay Less Notices)
- Site Administration (RFIs, Instructions, Diaries, Meeting Minutes, EOTs)
- Document Management & Version Control
- AI Automation Layer
- Workflow Engine
- Dashboard & Reporting
- Audit Logging
