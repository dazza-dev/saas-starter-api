<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByHeaderDomain
{
    public static string $header = 'X-Tenant';

    public static $onFail;

    protected Tenancy $tenancy;

    public function __construct(Tenancy $tenancy)
    {
        $this->tenancy = $tenancy;
    }

    /**
     * Identifica el tenant por el header X-Tenant e inicializa su contexto.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->method() === 'OPTIONS') {
            return $next($request);
        }

        $domain = $request->header(static::$header);

        if (! $domain) {
            return $this->fail(
                new TenantCouldNotBeIdentifiedOnDomainException('No X-Tenant header provided'),
                $request,
                $next
            );
        }

        $tenant = config('tenancy.tenant_model')::query()
            ->whereHas('domains', function (Builder $query) use ($domain) {
                $query->where('domain', $domain);
            })
            ->first();

        if (! $tenant) {
            return $this->fail(
                new TenantCouldNotBeIdentifiedOnDomainException($domain),
                $request,
                $next
            );
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }

    /**
     * Maneja el fallo al identificar el tenant.
     */
    protected function fail($exception, Request $request, Closure $next)
    {
        $onFail = static::$onFail ?? function ($e) {
            throw $e;
        };

        return $onFail($exception, $request, $next);
    }
}
