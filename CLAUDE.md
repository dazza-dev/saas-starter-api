# SaaS Starter API — Development Rules

## Stack

- Laravel 13, PHP 8.3, multitenancy via Stancl Tenancy
- Tenant identified by `X-Tenant` header (`InitializeTenancyByHeaderDomain` middleware)
- Session-based cookie auth (`auth:web`), `StartSession` loaded on API routes
- No `database/` directory — **migrations are managed by `saas-starter-admin`**, never create migration files here

---

## Comments (Comentarios)

- **Language:** every comment is written in **Spanish**. The code itself — names of variables, functions, classes, methods, fields, DB columns, types — stays in **English**. Only the prose of comments is translated.
- **Function / method / class docblocks:** always use the **multi-line block** form spanning **at least 3 lines**, even when the text is a single line. Never collapse a docblock to one line (`/** texto */`):

  ```php
  /**
   * Resuelve una lista de UUIDs a las claves primarias enteras del modelo dado.
   */
  private function idsFromUuids(string $model, array $uuids): array { ... }
  ```

- **Inline comments inside a function body:** a single-line `//` comment is fine — do NOT expand these to blocks.

---

## Module Structure

Every feature lives in `app/Modules/{Domain}/{Feature}/` (or `app/Modules/{Domain}/` for top-level domains):

```
app/Modules/Configs/Groups/
├── Controllers/GroupController.php
├── Models/Group.php
├── Requests/GroupRequest.php
├── Requests/GroupFilterRequest.php
├── Resources/GroupResource.php
├── Routes/api.php
├── Services/GroupService.php
├── Services/GroupDataTableService.php
├── Lang/en/{messages,validation}.php
├── Lang/es/{messages,validation}.php
├── Lang/pt/{messages,validation}.php
└── GroupsServiceProvider.php
```

Modules that ship with the starter:

| Domain    | Contents                                                            |
| --------- | ------------------------------------------------------------------- |
| `Core`    | Traits, helpers, middleware (tenancy, locale), Tenant model         |
| `Auth`    | Login, password recovery, auto-login, logout, profile               |
| `Users`   | **Reference CRUD** — tenant users (`User` model, table `users`)     |
| `Configs` | `Roles`, `Permissions`, `Settings`, `Groups`                        |
| `Files`   | Serves the private disk through an endpoint instead of a real path  |

> `Configs/Groups` is the **smallest complete example** of the module pattern (single field, full CRUD + soft deletes + restore). Copy it when creating a new module. `Users` is the fuller example: relations, filters, sorting, extra validation.

Register every new module in `bootstrap/providers.php`. `CoreServiceProvider` must appear first (registers the `module_path()` helper).

---

## ServiceProvider

Each module registers its own translations and routes:

```php
public function boot(): void
{
    $this->loadTranslationsFrom(module_path('Configs/Groups', 'Lang'), 'groups');
    $this->mapApiRoutes();
}

protected function mapApiRoutes(): void
{
    Route::prefix('api/v1')
        ->middleware(['api', 'tenancy.initialize_by_header'])
        ->group(module_path('Configs/Groups', 'Routes/api.php'));
}
```

- Every module registers under `api/v1`, `Auth` included
- Middleware stack: `['api', 'tenancy.initialize_by_header']` on the prefix, then `auth:web` inside the route group
- `tenancy.initialize_by_header` initialises the tenant context from the `X-Tenant` request header

### Tenancy middleware alias

Registered in `bootstrap/app.php`:

| Alias                          | Class                             | Purpose                                                          |
| ------------------------------ | --------------------------------- | ---------------------------------------------------------------- |
| `tenancy.initialize_by_header` | `InitializeTenancyByHeaderDomain` | Reads `X-Tenant` header, looks up tenant, initialises DB context |
| `permission`                   | `CheckPermission`                 | Denies the request unless the user holds the named permission    |

Never reference the middleware class directly in ServiceProviders — use the string alias. Exception: webhook routes that receive external callbacks must NOT include tenancy middleware.

**Do NOT add `PreventAccessFromCentralDomains`** — it filters by HTTP Host header and blocks `localhost` in development. This app uses header-based tenancy: tenant context comes from `X-Tenant`, not from the domain.

A missing or unknown `X-Tenant` renders as a `404 {"message": "Tenant not found."}` (mapped in `bootstrap/app.php`), never a 500.

### Middleware priority

`InitializeTenancyByHeaderDomain` is registered in `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` so it runs before `auth:web`. Without this, Laravel's default priority puts `Authenticate` before any middleware without an explicit priority — causing the session guard to query the `users` table on the central DB before the tenant connection is established.

---

## Routes

```php
// Routes/api.php
Route::middleware('auth:web')->group(function () {
    Route::post('groups/{uuid}/restore', [GroupController::class, 'restore'])->middleware('permission:update-groups');

    Route::get('groups', [GroupController::class, 'index'])->middleware('permission:read-groups');
    Route::post('groups', [GroupController::class, 'store'])->middleware('permission:create-groups');
    // ...
});
```

**Never use implicit route model binding.** `SubstituteBindings` runs before the tenancy middleware initialises the tenant DB connection, causing "Table not found" on the central DB. Always use `string $uuid` parameters and resolve manually via the service.

Every route declares its permission with the `permission:` middleware. Permission names follow `{verb}-{resource}` (`read-users`, `create-groups`) and must exist in `saas-starter-admin/database/data/Permissions.json`.

---

## Models

```php
declare(strict_types=1);

#[Fillable(['name'])]
class Group extends Model
{
    use HasUuid, SoftDeletes;
}
```

Rules:

- `declare(strict_types=1)` on every file
- Use PHP 8 attribute `#[Fillable([...])]` — never `protected $fillable`
- Use `HasUuid` trait **only if** the table has a `uuid` column — verify in `saas-starter-admin` migrations before adding
- Use `SoftDeletes` **only if** the table has a `deleted_at` column
- Central DB models (tables in `saas-starter-admin/database/migrations/`, e.g. `Permission`) **must** declare `protected $connection = 'mysql'`
- No class-level PHPDoc blocks on models

### HasUuid trait

Located at `app/Modules/Core/Traits/HasUuid.php`. Auto-generates a UUID on model creation. Apply to every model whose records are exposed to the frontend.

---

## Services

Controllers never access models directly — all DB logic goes in the service.

Rules:

- Every public method has a single-line PHPDoc comment explaining what it does. The constructor is
  exempt: a docblock that only repeats the class name is noise
- UUID-based lookup: `findByUuid` (nullable) + `findByUuidOrFail` (aborts 404)
- `findByUuidOrFail` uses `abort(404, __('module::messages.not_found'))` — never throws an exception manually
- For restore operations, add `findTrashedByUuidOrFail` using `onlyTrashed()`. `restore()` receives a model, not a UUID — the controller resolves it first
- The frontend only ever sends UUIDs. Translating them into foreign keys is the **service's** job (see `UserService::roleIdsFromUuids`), never the controller's

### Auth in services

Services must **never** call `Auth::id()`, `Auth::guard(...)`, `auth()->user()`, or `request()->user()`. The controller extracts the authenticated user and passes it (or its ID) as a method parameter. Using `Auth::` in a **controller** is allowed — controllers are the auth boundary.

---

## Controllers

Rules:

- Inject services via constructor property promotion
- Route parameters are always `string $uuid` (never model injection)
- Every public method has a single-line PHPDoc comment, except the constructor
- Responses always wrap in `['data' => ..., 'message' => ...]`; store returns HTTP 201
- **Always extract `findByUuidOrFail` / `findTrashedByUuidOrFail` to a local variable** before passing to the service — never nest the call inline

---

## JSON Resources

Resources never expose `id` — only `uuid` and business fields.

- `index` returns `Resource::collection($paginator)` — Laravel wraps it in `{ data: [], meta: { ... } }` automatically
- `show` / `store` / `update` return `['data' => Resource::make($model)]`
- Every controller action that returns Eloquent model data **must** use a Resource — never return `$model->toArray()` or inline arrays built from model fields
- Responses from raw DB queries or computed aggregates (stats, charts) do not require Resources
- **All response array keys must be `snake_case`** — the frontend's `axios-case-converter` middleware converts `snake_case` → `camelCase` on every response, so TypeScript types stay camelCase while the wire format is snake_case

---

## Form Requests

Every module has **two** Request classes:

- `XxxRequest` — create/update validation
- `XxxFilterRequest` — index/datatable validation (search, pagination, sort, filters)

`XxxFilterRequest` uses three global helpers from `app/Modules/Core/Helpers/datatable.php`, which merge base pagination/search/sort rules with any module-specific additions:

```php
public function rules(): array
{
    return dataTableFilterRules([
        'only_trashed' => ['nullable', 'boolean'],
    ]);
}

public function attributes(): array { return dataTableFilterAttributes([]); }
public function messages(): array { return dataTableFilterMessages([]); }
```

Base translations live in `app/Modules/Core/Lang/{en,es,pt}/validation.php` under the `core` namespace.

---

## DataTable Service

Every module has a dedicated `XxxDataTableService` separate from `XxxService`. The controller injects **both** and uses the DataTableService for `index()`.

Any column the client can sort by must be checked against an allow-list before it reaches `orderBy` — see `UserDataTableService::SORTABLE`. Passing the raw key through is an SQL injection hole.

---

## Permissions

- The catalog is a **fixed list** in the central DB (`modules` + `permissions`), the same for every tenant. It is not customisable per tenant: adding a permission means editing `saas-starter-admin/database/data/Permissions.json` and re-seeding
- The matrix has two levels: `permissions.module_id` (nullable, the tab) and `permissions.group` (the row). A permission with no module falls into the `general` tab
- The pivots that assign them (`permission_role`, `permission_user`) live in the **tenant** DB. Eloquent cannot join across connections, so `PermissionService` resolves the crossing in two explicit steps
- `PermissionsServiceProvider::registerGate()` resolves every ability as a permission, so `$user->can('read-roles')`, the `permission:` middleware and policies share one source of truth
- Users hold roles through the `role_user` pivot and can have several; their permissions add up
- The `admin` role has a full bypass via `Gate::before`. It is not a role with every checkbox ticked — un-ticking one could otherwise lock everyone out of the permissions screen

---

## Queues and scheduled tasks

Queues run on Redis through Horizon. Three supervisors, one queue each, so a long job never delays a
short one: `supervisor-fast` (`default`, `mail`, `notifications`), `supervisor-heavy` (`reports`) and
`supervisor-tenants` (`tenants`).

A task that must run for every tenant extends `TenantBatchCommand`. It walks the tenant list on the
central DB and dispatches **one job per tenant inside a single batch**, so progress, failures and
cancellation are visible together in Horizon. `tenant:example` is the template — it touches no data,
it only logs one line per tenant. Schedule new ones in `routes/console.php`, which ships with **no
active task**: the starter kit must not run anything on its own.

- The command runs in **central** context and stays there. The batch is dispatched in one go, so the
  jobs inherit no tenant — each one opens its own from the id it was given
- Jobs extend `TenantJob`, which resolves the tenant, initialises tenancy and closes it in a
  `finally`. `job_batches` and `failed_jobs` live on the central connection: without that `finally` a
  failing job would leave the connection on the tenant DB and the batch bookkeeping would write there
- `Tenant::run()` does **not** wrap its callback in `try/finally`, so it does not revert on an
  exception. That is why `TenantJob` handles the context itself instead of using it
- Batches use `allowFailures()` — one tenant failing must not stop the rest
- `TenantJob::$tenantId` is deliberately not `readonly`: on unserialize Laravel reassigns it from the
  subclass scope, and PHP only allows initialising a `readonly` property where it is declared
- The Horizon panel is guarded by the `horizon.auth` middleware (basic auth, `HORIZON_BASIC_AUTH_*`), not by the app session — tenant users live in tenant DBs, so the central app has nobody to authenticate. It closes when either credential is empty, and it must only be served over HTTPS
- Verified against the tenancy docs: the queue's Redis connection is not in `tenancy.redis.prefixed_connections` and `RedisTenancyBootstrapper` stays off, so queue keys are never tenant-prefixed. `job_batches` and `failed_jobs` point at an explicit central connection, not `default`

---

## Files

Two disks, split by who may read the file:

| Disk     | Written to            | Served by                            | For                    |
| -------- | --------------------- | ------------------------------------ | ---------------------- |
| `public` | `storage/app/public`  | static under `/storage/...`          | Logos, branding assets |
| `local`  | `storage/app/private` | `GET api/v1/files/...`, with session | User uploads           |

- The public disk needs `php artisan storage:link`; the private one must never be symlinked
- The private disk sets `url` to `FILES_BASE_URL`, so `Storage::url()` already returns the masked URL.
  Always name the disk when storing (`$file->store('docs', 'local')`)
- The route runs under `tenancy.initialize_by_header`. With `FilesystemTenancyBootstrapper` enabled the
  disk already points at the tenant's own directory, so one tenant cannot read another's files
- Both path segments are checked against `^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$` before touching the
  disk. A segment starting with a dot or equal to `..` would escape the storage root

## Password recovery

- `POST auth/forgot-password` and `POST auth/reset-password` are public; the emailed token is the credential
- `User::sendPasswordResetNotification()` is overridden so the link carries the tenant in the path (`{FRONTEND_URL}/{tenant}/auth/reset-password`). Laravel's default link has no tenant, and the SPA resolves the account from that first path segment
- Reset tokens live in each tenant's own `password_reset_tokens` table, never in the central DB
- `forgotPassword` answers the same whether or not the email exists — the endpoint must not reveal which accounts are registered
- The notification is **not** queued on purpose: regaining access should not depend on a worker being alive. Queueing it would work — `QueueTenancyBootstrapper` is enabled, so a job dispatched from tenant context carries the tenant in its payload and re-opens it in the worker

---

## Translations

- **Three languages are mandatory: `en`, `es`, `pt`.** Every translation file must exist in all three locales with the **exact same keys** — never add a key to one locale without adding it to the other two. The frontend sends the user's language via the `Accept-Language` header, which `SetLocale` middleware reads to set the app locale; a missing key silently falls back to `en`
- `Lang/{en,es,pt}/messages.php` — CRUD success/error messages used in controllers
- `Lang/{en,es,pt}/validation.php` — validation error messages used in FormRequests
- Namespace registered in ServiceProvider = feature name only (e.g. `'groups'`, `'users'`)
- `pt` is Brazilian Portuguese (e.g. role → "função", delete → "excluído"). Keep placeholders (`:max`, `:min`, `:attribute`) and array keys identical across locales — translate only the string values

---

## Settings — Select-Input Endpoints

`SettingsController` exposes dedicated endpoints for populating select/autocomplete inputs. These return **unpaginated** arrays and are separate from the CRUD datatable endpoints. Never use datatable endpoints (`v1/users`, `v1/groups`) with a large `per_page` to fill selects.

| Endpoint               | Response                          | Purpose                            |
| ---------------------- | --------------------------------- | ---------------------------------- |
| `GET settings/roles`   | `{ data: [{uuid,name,slug}] }`    | All roles (`name` = display_name)  |
| `GET settings/groups`  | `{ data: [{uuid,name}] }`         | All groups                         |

On the frontend, consume these via `useOptions()` in `saas-starter-app` (`src/core/composables/useOptions.ts`).

---

## Related projects

- `saas-starter-admin` — owns every migration and seeder, both central and tenant. Change the schema **there**, never here
- `saas-starter-app` — the Vue 3 SPA that consumes this API
