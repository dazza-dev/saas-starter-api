<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\TenantJob;
use App\Modules\Core\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;

/**
 * Base de las tareas que corren sobre todos los tenants, un job por tenant dentro de un batch.
 */
abstract class TenantBatchCommand extends Command
{
    /**
     * Cola donde se encolan los jobs del batch.
     */
    protected string $queue = 'tenants';

    /**
     * Nombre con el que aparece el batch en Horizon.
     */
    abstract protected function batchName(): string;

    /**
     * Crea el job que atenderá a un tenant.
     */
    abstract protected function makeJob(Tenant $tenant): TenantJob;

    /**
     * Acota los tenants a procesar.
     */
    protected function modifyQuery(Builder $query): Builder
    {
        return $query;
    }

    public function handle(): int
    {
        $tenants = $this->modifyQuery(Tenant::query()->where('status', 'active'))->get();

        $jobs = $tenants->map(fn (Tenant $tenant) => $this->makeJob($tenant))->all();

        if (! $jobs) {
            $this->info('No hay tenants que procesar.');

            return self::SUCCESS;
        }

        $batch = Bus::batch($jobs)
            ->allowFailures()
            ->name($this->batchName().': '.now()->toDateTimeString())
            ->onQueue($this->queue)
            ->dispatch();

        $this->info(sprintf('Batch %s despachado con %d job(s).', $batch->id, count($jobs)));

        return self::SUCCESS;
    }
}
