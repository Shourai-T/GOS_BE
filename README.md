# G-Scores Backend Setup

## Prerequisites

- PHP 8.2+
- Composer
- PostgreSQL (Supabase)

## Installation

1. Install dependencies:

```bash
composer install
```

2. Configure environment:

```bash
cp .env.example .env
```

3. Update `.env` with your Supabase credentials:

```env
DB_CONNECTION=pgsql
DB_HOST=db.tnivjpgewudvgkeqcdrw.supabase.co
DB_PORT=6543
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=<YOUR_PASSWORD>
```

4. Run migrations:

```bash
php artisan migrate
```

5. Import CSV data:

```bash
php artisan app:import-scores ../dataset/diem_thi_thpt_2024.csv
```

6. Start development server:

```bash
php artisan serve
```

## API Endpoints

- `POST /api/search` - Search student by SBD
- `GET /api/reports` - Get score distribution reports
- `GET /api/top-10-group-a` - Get top 10 students (Group A)
