# Tech-aid Query Lab (Laravel 12 · PostgreSQL · Memurai)

A practice project for Eloquent, the Query Builder, raw SQL, Debugbar and Redis caching. It's built on a realistic tech-aid dataset: 100,000 service requests, ~295,000 quotes, 52,000 reviews and 88,000 payments, with deliberate edge cases.

What's inside:

- **10 tables** with migrations, models, relationships, enums and check constraints
- **Deterministic seeders** in two sizes: `small` (600 requests, for checking by eye) and `large` (100,000, for performance work). Seeding takes about 25 seconds.
- **12 query labs** at `/lab`: each question answered 2–4 ways (slow vs fast), with live metrics, the code that ran and `EXPLAIN ANALYZE`
- **3 cache labs**: dashboard (`remember` vs `flexible`), technician profile (repository decorator + tags + counter), OTP (`put`/`pull`/`add`/`increment`)
- **Command-line labs** for chunking and race conditions (`Cache::lock`)
- **41 exercises** (`EXERCISES.md`) with answers to check against (`CHECK_VALUES.md`)

Tested on Laravel 12.69, PHP 8.4, PostgreSQL 16 and Redis 7 (Memurai speaks the same protocol).

---

## 1. Requirements (Windows)

- PHP 8.2+ with `pdo_pgsql` and `pgsql` enabled. In `php.ini`, remove the `;` from `extension=pdo_pgsql` and `extension=pgsql`, then check with `php -m | findstr pgsql`.
- Composer
- PostgreSQL 14+ (pgAdmin is fine for creating the database)
- Memurai running on port 6379 (`memurai-cli ping` → `PONG`)

## 2. Create the project and copy the lab in

```bash
composer create-project laravel/laravel techaid-query-lab "^12.0"
cd techaid-query-lab
```

Unzip this kit **into the project folder**, overwriting when asked. It replaces 4 default files (`app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `database/seeders/DatabaseSeeder.php`, `routes/web.php`) and adds everything else.

```bash
composer require predis/predis
composer require fruitcake/laravel-debugbar --dev
```

## 3. Database and `.env`

Create an empty database called `techaid_lab` (pgAdmin → Databases → Create, or `createdb -U postgres techaid_lab`). Then set these in `.env`:

```env
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=techaid_lab
DB_USERNAME=postgres
DB_PASSWORD=your_password

CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

LAB_SIZE=small
```

## 4. Migrate and seed

```bash
php artisan migrate --seed
```

Check a few answers against `CHECK_VALUES.md` on the small dataset. When you're ready for performance work, set `LAB_SIZE=large` in `.env` and run:

```bash
php artisan config:clear
php artisan db:seed
```

Re-seeding is safe at any time: it truncates the lab tables, resets the id sequences, runs `ANALYZE` and flushes the cache.

## 5. Debugbar

```bash
php artisan vendor:publish
```

Pick the Debugbar provider from the list. In `config/debugbar.php`, find the `collectors` list and set `'cache' => true`, so cache hits and misses appear next to your queries. (Option names can vary slightly between Debugbar versions. Look for the `cache` collector.)

## 6. Run it

```bash
php artisan serve
```

Open **http://127.0.0.1:8000/lab**. In a second terminal, run `memurai-cli monitor` to watch every cache command live.

Command-line labs (Debugbar doesn't run in the console, so these print their own numbers):

```bash
php artisan lab:chunk-benchmark
php artisan lab:chunk-bug
php artisan lab:race --mode=nolock
php artisan lab:race --mode=lock
php artisan lab:race --mode=atomic
```

---

## Things the lab will teach you the hard way

- **PostgreSQL does not index foreign keys.** `foreignId()->constrained()` adds the constraint, not an index. This project adds `->index()` explicitly and leaves two out on purpose (`service_requests.scheduled_at`, `quotes.technician_id`). The index migration for those lives in `database/migrations/lab_optional`.
- **`whereDate()` / `whereYear()` can't use an index** on PostgreSQL, because they cast the column. Use a range instead.
- **`DESC` puts NULLs first in PostgreSQL.** Technicians with no reviews jump to the top of "best rated" unless you write `NULLS LAST`.
- **`APP_DEBUG=true` keeps the first 100 queries and their bindings in memory** (for Laravel's error page). Bulk-inserting 1,000-row chunks made the seeder use ~150 MB until it detached query events. See `LabSeeder`.
- **`cursor()` on PostgreSQL looks memory-free but isn't.** pdo_pgsql downloads the whole result into libpq memory, which PHP doesn't count. `lazyById()` is the safer default.
- **`Cache::remember()` never caches `null`.** See `CachedTechnicianRepository::findWithProfile()` for the `false` sentinel.
- **`Cache::flexible()` refreshes after the response is sent**, but `php artisan serve` can't finish a response early, so the stale load still waits there. Laravel Herd or Nginx + PHP-FPM show the real behaviour.
- **Redis cache tags leave stale entries** in their tracking sets. In a real app, schedule `php artisan cache:prune-stale-tags` hourly.

## Where things are

```
app/Models/                         Area, Category, User, Technician, ServiceRequest, Quote, Review, Payment, Setting
app/Enums/                          ServiceRequestStatus, PaymentStatus
app/Repositories/                   Interface, EloquentTechnicianRepository, CachedTechnicianRepository (decorator)
app/Services/DashboardStatsService  service-layer caching (remember vs flexible)
app/Observers/ReviewObserver        clears a technician's cache tag when a review changes
app/Http/Controllers/Lab/           QueryLabController (12 labs), CacheLabController
app/Console/Commands/               lab:chunk-benchmark, lab:chunk-bug, lab:race (+ lab:accept-job worker)
app/Support/Lab/QueryMeter          measures queries, time, memory, models, cache hits
database/seeders/Lab/               LabDataGenerator: the deterministic data builder
database/migrations/lab_optional/   the "missing" indexes, for before/after experiments
config/lab.php                      size, seed, "today", on/off switch
```

The lab routes and commands refuse to run when `APP_ENV=production` or `LAB_ENABLED=false`.
