<?php

// Sin tareas programadas. Para añadir una que recorra los tenants, crea un comando
// que extienda TenantBatchCommand (`tenant:example` es la plantilla) y prográmalo aquí:
//
//   Schedule::command('tenant:example')->dailyAt('03:00')->withoutOverlapping();
//
// En el servidor, una sola línea de cron las ejecuta todas:
//
//   * * * * * cd /ruta && php artisan schedule:run >> /dev/null 2>&1
