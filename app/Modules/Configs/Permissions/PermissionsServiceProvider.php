<?php

declare(strict_types=1);

namespace App\Modules\Configs\Permissions;

use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PermissionsServiceProvider extends ServiceProvider
{
    /**
     * Inicializa el módulo Permissions: registra traducciones, rutas API y el Gate de autorización.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(module_path('Configs/Permissions', 'Lang'), 'permissions');
        $this->mapApiRoutes();
        $this->registerGate();
    }

    /**
     * Registra las rutas API del módulo bajo el prefijo api/v1
     * con el middleware de inicialización de tenancy por header.
     */
    protected function mapApiRoutes(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'tenancy.initialize_by_header'])
            ->group(module_path('Configs/Permissions', 'Routes/api.php'));
    }

    /**
     * Resuelve cualquier habilidad como un permiso, con bypass total para el administrador.
     * Devuelve null en vez de false para que el Gate siga su curso y acabe denegando.
     */
    protected function registerGate(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->isAdmin()) {
                return true;
            }

            return $user->hasPermission($ability) ? true : null;
        });
    }
}
