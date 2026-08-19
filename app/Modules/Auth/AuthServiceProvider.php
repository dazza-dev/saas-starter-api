<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Inicializa el módulo Auth: registra traducciones y rutas API.
     * Se ejecuta automáticamente durante el arranque de la aplicación.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(module_path('Auth', 'Lang'), 'auth');
        $this->mapApiRoutes();
    }

    /**
     * Registra las rutas API del módulo bajo el prefijo api/v1
     * con el middleware de inicialización de tenancy por header.
     */
    protected function mapApiRoutes(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'tenancy.initialize_by_header'])
            ->group(module_path('Auth', 'Routes/api.php'));
    }
}
