<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Core\Models\Tenant;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base de los jobs que trabajan dentro de un tenant.
 */
abstract class TenantJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // No puede ser readonly: Laravel la reasigna al deserializar el job.
    public function __construct(public string $tenantId) {}

    /**
     * El trabajo, ya con la conexión en la base del tenant.
     */
    abstract protected function handleTenant(Tenant $tenant): void;

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $tenant = Tenant::find($this->tenantId);

        // Pudo borrarse entre el despacho y la ejecución.
        if (! $tenant) {
            return;
        }

        $previous = tenant();

        try {
            tenancy()->initialize($tenant);

            $this->handleTenant($tenant);
        } finally {
            // El batch lleva su contabilidad en la central: hay que soltar el tenant siempre.
            if ($previous && $previous->isNot($tenant)) {
                tenancy()->initialize($previous);
            } elseif (! $previous) {
                tenancy()->end();
            }
        }
    }
}
