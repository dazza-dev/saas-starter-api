<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExampleTenantJob;
use App\Jobs\TenantJob;
use App\Modules\Core\Models\Tenant;

/**
 * Ejemplo de tarea sobre todos los tenants. Cópialo y borra este.
 */
class TenantExample extends TenantBatchCommand
{
    protected $signature = 'tenant:example';

    protected $description = 'Ejemplo: despacha un job por tenant que solo escribe en el log';

    protected function batchName(): string
    {
        return 'tenant:example';
    }

    protected function makeJob(Tenant $tenant): TenantJob
    {
        return new ExampleTenantJob($tenant->getTenantKey());
    }
}
