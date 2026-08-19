<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Core\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ejemplo del patrón: no toca datos, solo escribe en el log.
 */
class ExampleTenantJob extends TenantJob
{
    protected function handleTenant(Tenant $tenant): void
    {
        $users = DB::table('users')->count();

        Log::info('[tenant-example] job ejecutado', [
            'tenant' => $tenant->getTenantKey(),
            'database' => DB::connection()->getDatabaseName(),
            'users' => $users,
        ]);
    }
}
