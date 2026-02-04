# G-Scores Backend (Laravel 11)

Laravel backend API for the G-Scores student scoring system.

> **⚠️ NOTE**: Demo deployment uses Supabase Free Tier. Expect slower response times (>30s) due to storage limits. For best performance, use dedicated PostgreSQL instance.

---

## 🚀 Quick Start

### Option 1: Docker (Recommended)

```bash
# From project root
docker compose up -d

# Run migrations
docker compose exec backend php artisan migrate

# Import CSV data
docker cp data.csv gscores_backend:/var/www/backend/storage/app/data.csv
docker compose exec backend php artisan import:scores storage/app/data.csv
```

See [../DOCKER.md](../DOCKER.md) for full Docker documentation.

### Option 2: Local Development

#### Prerequisites

- PHP 8.2+
- Composer
- PostgreSQL 15+

#### Setup

```bash
cd backend

# Install dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Update .env with your database credentials
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=gscores
# DB_USERNAME=postgres
# DB_PASSWORD=your_password

# Run migrations
php artisan migrate

# Start server
php artisan serve
```

---

## 📁 Project Structure

```
backend/
├── app/
│   ├── Console/Commands/
│   │   └── ImportScoresCommand.php    # CSV import
│   ├── Http/Controllers/Api/
│   │   ├── SearchController.php       # Student search
│   │   └── ReportController.php       # Statistics
│   ├── Models/
│   │   ├── Student.php
│   │   ├── Subject.php
│   │   ├── Score.php
│   │   └── ImportJob.php
│   └── Services/
│       └── CsvRowValidator.php        # Validation logic
├── database/
│   ├── migrations/                    # Database schema
│   └── seeders/                       # Sample data
├── routes/
│   └── api.php                        # API endpoints
└── tests/
    ├── Feature/                       # Integration tests
    └── Unit/                          # Unit tests
```

---

## 🔌 API Endpoints

### Search Student Scores

```http
POST /api/search
Content-Type: application/json

{
  "sbd": "01000001"
}
```

**Response**:

```json
{
    "success": true,
    "data": {
        "sbd": "01000001",
        "ma_ngoai_ngu": "N1",
        "group_a_score": "24.75",
        "scores": {
            "toan": 8.5,
            "ngu_van": 7.0,
            "vat_li": 8.25,
            "hoa_hoc": 8.0
        }
    }
}
```

### Get Score Distribution

```http
GET /api/reports/distribution
```

### Get Top Students (Group A)

```http
GET /api/reports/top-group-a?limit=10
```

---

## 🛠️ Development Commands

### Artisan Commands

```bash
# Import CSV data
php artisan import:scores path/to/file.csv

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Run tests
php artisan test
php artisan test --filter SearchApiTest

# Database
php artisan migrate:fresh --seed
php artisan db:seed
```

### Testing

```bash
# Run all tests
php artisan test

# With coverage
php artisan test --coverage

# Specific test
php artisan test --filter=ImportScoresCommandTest
```

---

## 🐳 Docker Commands

```bash
# Start services
docker compose up -d

# View logs
docker compose logs -f backend

# Run artisan
docker compose exec backend php artisan [command]

# Access shell
docker compose exec backend sh

# Run tests in container
docker compose exec backend php artisan test
```

---

## 📊 Performance

- **CSV Import**: <10s for 100k rows (batch insert + idempotency)
- **Search API**: <100ms (indexed queries)
- **Report API**: <1s (with caching)

---

## 🔒 Security

- ✅ Input validation (SBD format, CSV structure)
- ✅ SQL injection prevention (Eloquent ORM)
- ✅ CORS configured
- ✅ Rate limiting
- ✅ Environment secrets (.env)

---

## 📝 Environment Variables

```env
# Application
APP_NAME="G-Scores"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=gscores
DB_USERNAME=postgres
DB_PASSWORD=secret

# Cache
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
```

---

## 🧪 Testing

Test coverage: **90%+**

- ✅ Unit tests (CsvRowValidator, Models)
- ✅ Feature tests (API endpoints, import command)
- ✅ Database transactions (no test pollution)

---

## 📚 Additional Documentation

- [API Tests](../docs/API_TESTS.md)
- [Code Review](../docs/CODE_REVIEW_BACKEND.md)
- [Troubleshooting](../docs/TROUBLESHOOTING.md)
- [Docker Setup](../DOCKER.md)

---

**Tech Stack**: Laravel 11, PostgreSQL 16, PHP 8.2
