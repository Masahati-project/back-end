# AGENTS.md

## Stack
- **Framework**: Laravel 12 (PHP ^8.2), API-first with Sanctum auth
- **Storage**: Cloudinary for uploads (files/images); `FILESYSTEM_DISK=cloudinary` by default
- **DB**: SQLite for tests (phpunit.xml sets `DB_CONNECTION=sqlite`), MySQL in prod/dev (`.env` shows mysql). Migrations in `database/migrations/`
- **Frontend**: Vite + Tailwind CSS 4 (assets in `resources/`), but API is primary

## Key Commands
- **Setup**: `composer run setup` (installs deps, copies `.env` if missing, generates key, migrates, `npm install && npm run build`)
- **Dev**: `composer run dev` (runs `php artisan serve`, queue listener, `pail`, and `npm run dev` concurrently)
- **Test**: `composer run test` (clears config, runs `php artisan test`). Tests use SQLite in-memory per `phpunit.xml`. For MySQL testing: `php scripts/test-mysql.php` (creates `masahati_testing`, migrates, runs tests there)
- **Build assets**: `npm run build`
- **Migrate**: `php artisan migrate --force` (Docker CMD chains this)

## Architecture Notes
- **Routes**: API routes in `routes/api.php` (majority of endpoints). Admin routes also registered in `routes/api.php` under `/admin` prefix with `admin` middleware; `routes/admin.php` exists but not obviously loaded by default routing config. Check `bootstrap/app.php` for routing setup.
- **Middleware** (global/api): `SecurityHeaders` appended globally; `ForceJsonResponse` prepended to API group. Aliases: `admin` (`EnsureAdmin`), `active-account` (`EnsureAccountIsActive`), `throttle-verified` (`ThrottleVerifiedAttempts`), `throttle-space-creation` (`ThrottleSpaceCreation`).
- **Auth**: Sanctum tokens. Roles: `customer`, `space_owner`, `admin`. User statuses: `pending`, `active`, `suspended` (see `users` migration). `EnsureAccountIsActive` blocks non-admins with non-active status except specific read-only routes (logout, profile, spaces catalogue reads, notifications index). Admins exempt.
- **Workspace statuses**: `pending`, `active`, `suspended`, `rejected` (normalized in migration `2026_10_11_090000_normalise_workspace_status_vocabulary.php`; `approved` was migrated to `active`).
- **Storage/Uploads**: Documents/images go to Cloudinary. `SpaceDocumentStorage` handles uploads with special handling for Cloudinary SDK vs local/fake disks. `ImageService` does base64 image validation (5MB limit, strict MIME types). Document uploads validated for type/size (see `CHANGES_MADE.md`).
- **Rate limiting**: Named limiters registered in `AppServiceProvider` (verify-otp, resend-otp, reset-password use `ThrottleVerifiedAttempts` with identifier-based keys; space creation limited per owner). `bootstrap/app.php` customizes 429 responses.

## Important Conventions/Gotchas
- **ForceJsonResponse** is critical: prepended to API group to ensure JSON responses/errors (validation/auth) even without `Accept: application/json` header.
- **Route shadowing**: Literal owner routes (e.g., `/api/owner/spaces/open`, `/api/owner/spaces/stats`, `/api/owner/ads/open`, `/api/owner/ads/published`, `/api/owner/ads/stats`) must be declared before parameterized routes like `/{space}`, `/{ad}`. Numeric constraints (`whereNumber`) used on parameterized routes. See `tests/Feature/OwnerRouteShadowingTest.php`.
- **Space edit contract**: `PUT/PATCH/POST /api/owner/spaces/{space}` accepts specific fields only; unknown fields return 422. Payload normalization handles multipart/string booleans/JSON arrays. See `OwnerSpaceController`.
- **Booking**: Customer-only; unit-centric internally but frontend sends `space_id`. Idempotency supported via `Idempotency-Key` header. Slot conflicts return 409. Time validation against workspace open/close hours (supports overnight). See `BookingController` and `tests/Feature/BookingTest.php`.
- **Ads expiry**: Stored in `ads.schedule` (JSON) with multiple possible keys; use `ResolvesAdExpiry` trait. `OwnerAdController::open()` returns running (non-expired) published ads; `published()` returns all published ads regardless of expiry.
- **Two notification tables**: `notifications` is per-user (customer bell, broadcasts); `inbox_notifications` is the global admin inbox. Don't mix them. Broadcasts also write per-recipient delivery rows to `broadcast_recipients`.
- **Admin user**: Seeded by migration `2026_09_27_124201_create_admin_user.php` with email `masahati@outlook.com` (password from `ADMIN_SEED_PASSWORD` env var or generated). Role `admin`, status `active`.
- **CORS**: Configured in `config/cors.php` for specific origins including localhost ports and deployed frontends.

## Testing Notes
- Default test DB is SQLite in-memory (`phpunit.xml`). Some tests may fail in environments missing SQLite PHP extension.
- `tests/TestCase.php` uses base Laravel TestCase with no special setup.
- Many tests use `RefreshDatabase`. Factories only exist for `User` (`database/factories/UserFactory.php`).
- When a test switches tokens between users, call `$this->app['auth']->forgetGuards()` before the next request. Sanctum's guard caches the first resolved user for the whole test app instance, so the second token otherwise authenticates as the first user.
- `ModelTableTest` validates all models map to existing tables.

## Files to Know
- `bootstrap/app.php` - middleware, exceptions, rate limit 429 handling
- `routes/api.php` - all API routes
- `app/Providers/AppServiceProvider.php` - rate limiters, mail transport, events
- `config/auth.php` - auth config (guards/providers)
- `phpunit.xml` - test environment config
- `.env.example` - env vars reference (Cloudinary, mail, frontend URL, admin seed password)
