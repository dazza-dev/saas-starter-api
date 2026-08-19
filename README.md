# saas-starter-api

API multi-tenant del starter kit. Sirve los datos de cada tenant a la SPA, resolviendo el tenant a
partir de la cabecera `X-Tenant` de cada petición.

Laravel 13 · stancl/tenancy · Horizon · MySQL

---

## Qué trae

- Autenticación por sesión con cookies (login, recuperación de contraseña, auto-login, logout, perfil)
- Multitenancy por cabecera: una base de datos por tenant, conmutada por request
- Autorización por permisos con `Gate::before`, middleware `permission:` y bypass del rol `admin`
- Arquitectura modular: cada feature es un módulo autocontenido con sus rutas, servicios y traducciones
- CRUD completo de usuarios, roles, permisos, grupos y ajustes
- Colas con Redis y Horizon, con supervisores separados por tipo de trabajo
- Base para tareas programadas que recorren todos los tenants en batches
- Traducciones en `en`, `es` y `pt`

## Requisitos

- PHP 8.3+
- Composer
- MySQL 8+ (la **misma** base de datos central que usa `saas-starter-admin`)
- Redis y la extensión `phpredis` (para las colas y Horizon)

## Puesta en marcha

Este proyecto **no** tiene migraciones: el esquema lo crea `saas-starter-admin`. Levanta ese proyecto
primero y crea al menos un tenant.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Apunta `DB_DATABASE` a la misma base central que `saas-starter-admin` y arranca:

```bash
npm ci && npm run build   # assets de la vista de bienvenida; la API en sí no los necesita
php artisan storage:link  # publica el disco público en public/storage
php artisan serve         # http://localhost:8000
php artisan horizon       # procesa las colas; panel en /horizon
```

Comprobación rápida, con un tenant cuyo dominio sea `acme`:

```bash
curl -H "X-Tenant: acme" http://localhost:8000/api/v1/settings
```

## Peticiones

Toda petición necesita la cabecera `X-Tenant` con el dominio del tenant. Sin ella —o con un tenant
desconocido— la respuesta es `404 {"message": "Tenant not found."}`.

Todos los módulos cuelgan de `api/v1/`, Auth incluido.

Las claves de las respuestas viajan en `snake_case`; el `axios-case-converter` de la SPA las convierte
a `camelCase` al recibirlas y de vuelta a `snake_case` al enviar.

## Correo

El enlace para recuperar la contraseña se arma con el tenant en la ruta
(`{FRONTEND_URL}/{tenant}/auth/reset-password`), que es de donde la SPA deduce a qué cuenta pertenece
la pantalla. Configura `FRONTEND_URL` y un `MAIL_MAILER` real antes de desplegar.

En local, `brew install mailpit && brew services start mailpit` levanta un SMTP en el 1025 y una
bandeja en `http://localhost:8025`, que es a donde apunta el `.env.example`.

La notificación se envía sin encolar a propósito: recuperar el acceso no debería depender de que haya
un worker vivo. Encolarla sí funcionaría —`QueueTenancyBootstrapper` está activo, así que un job
despachado desde el contexto de un tenant lo lleva en su payload y lo reabre en el worker.

## Ficheros

Hay dos discos, y la diferencia es quién puede leer lo que guardas.

| Disco    | Dónde escribe         | Cómo se sirve                      | Para qué                     |
| -------- | --------------------- | ---------------------------------- | ---------------------------- |
| `public` | `storage/app/public`  | estático en `/storage/...`         | Logos y ficheros de la marca |
| `local`  | `storage/app/private` | `GET api/v1/files/...`, con sesión | Lo que suben los usuarios    |

```php
// Público: la ruta no es secreta. Necesita `php artisan storage:link`.
$path = $file->store('logos', 'public');
Storage::disk('public')->url($path);   // http://localhost:8000/storage/logos/xxx.png

// Privado: solo sale por el endpoint y con sesión.
$path = $file->store('docs', 'local');
Storage::disk('local')->url($path);    // http://localhost:8000/api/v1/files/docs/xxx.pdf
```

`FileController` lo lee por el disco y lo devuelve como stream, así que la URL no dice dónde está el
fichero ni si el disco es local o un bucket. La ruta va con `tenancy.initialize_by_header`, y como
`FilesystemTenancyBootstrapper` está activo, el disco ya apunta al directorio del tenant: un tenant
no puede pedir un fichero de otro aunque acierte el nombre.

## Colas y tareas programadas

Las colas van por Redis y las gestiona Horizon (`php artisan horizon`, panel en `/horizon`). Hay tres
supervisores, cada uno con su cola, para que un trabajo largo no retrase a los cortos:

| Supervisor            | Colas                            | Para qué                                  |
| --------------------- | -------------------------------- | ----------------------------------------- |
| `supervisor-fast`     | `default`, `mail`, `notifications` | Correos y notificaciones                  |
| `supervisor-heavy`    | `reports`                        | Importaciones e informes                  |
| `supervisor-tenants`  | `tenants`                        | Mantenimientos que recorren los tenants   |

El panel (`/horizon`) se protege con autenticación básica mediante el middleware `horizon.auth`, con
las credenciales de `HORIZON_BASIC_AUTH_USER` y `HORIZON_BASIC_AUTH_PASSWORD`. Si alguna queda vacía
el panel no se abre, así que un despliegue sin configurarlas no lo expone.

No usa la sesión de la aplicación porque los usuarios viven en las bases de los tenants y la central
no tiene con quién autenticar. Publícalo **solo por HTTPS**: la autenticación básica manda las
credenciales en cada petición, y sin TLS viajan legibles.

### Tareas que recorren todos los tenants

Un comando que deba correr en cada tenant extiende `TenantBatchCommand`: recorre el listado de la
base central y despacha **un job por tenant dentro de un mismo batch**, así el progreso, los fallos y
la cancelación se ven juntos en Horizon en vez de quedar sueltos por la cola.

`tenant:example` es la plantilla: no toca datos, solo escribe una línea en el log por tenant para
que puedas comprobar que el circuito funciona. Para uno nuevo hacen falta dos ficheros:

```php
// app/Jobs/MiTareaJob.php — el trabajo, ya dentro del tenant
class MiTareaJob extends TenantJob
{
    protected function handleTenant(Tenant $tenant): void
    {
        // La conexión ya apunta a la base del tenant.
    }
}

// app/Console/Commands/TenantMiTarea.php — el despachador
class TenantMiTarea extends TenantBatchCommand
{
    protected $signature = 'tenant:mi-tarea';

    protected function batchName(): string { return 'tenant:mi-tarea'; }

    protected function makeJob(Tenant $tenant): TenantJob
    {
        return new MiTareaJob($tenant->getTenantKey());
    }
}
```

Prográmalo en `routes/console.php`, que viene **sin ninguna tarea activa** a propósito. En el
servidor basta una entrada de cron para todas:

```
* * * * * cd /ruta && php artisan schedule:run >> /dev/null 2>&1
```

Detalles que conviene no tocar:

- El comando corre en contexto **central** y no lo abandona; cada job abre el suyo con el
  identificador que recibe. El batch se despacha de una vez, así que los jobs no heredan ningún tenant
- `TenantJob::handle()` cierra el contexto en un `finally`. La contabilidad del batch
  (`job_batches`, `failed_jobs`) vive en la base central, y sin ese cierre un job que falla la dejaría
  escribiendo en la del tenant
- El batch usa `allowFailures()`: que un tenant falle no frena a los demás
- La propiedad `tenantId` de `TenantJob` no es `readonly` — al deserializar, Laravel la reasigna desde
  la subclase y PHP solo permite inicializar una propiedad `readonly` donde se declara

## Estructura

```
app/
├── Console/Commands/   TenantBatchCommand + los comandos que lo extienden
├── Jobs/               TenantJob + los jobs que corren dentro de un tenant
└── Modules/
    ├── Core/       Traits, helpers, middleware (tenancy, locale)
    ├── Auth/       Login, recuperación de contraseña, auto-login, perfil
    ├── Files/      Sirve el disco privado del tenant por un endpoint
    ├── Users/      CRUD de usuarios del tenant  ← ejemplo completo
    └── Configs/
        ├── Groups/      ← el ejemplo más pequeño del patrón; cópialo para módulos nuevos
        ├── Roles/
        ├── Permissions/
        └── Settings/
```

Cada módulo se registra en `bootstrap/providers.php`. Las reglas de desarrollo, el patrón de módulo
y las convenciones están en [`CLAUDE.md`](./CLAUDE.md).

## Antes de commitear

```bash
./vendor/bin/pint
php artisan test
```

## Proyectos relacionados

| Repo                 | Qué es                                         |
| -------------------- | ---------------------------------------------- |
| `saas-starter-admin` | Dueño del esquema; provisiona los tenants      |
| `saas-starter-app`   | La SPA en Vue 3 que consume esta API           |
| `vuetify-app-kit`    | Layouts, tema, componentes y utilidades de app |
