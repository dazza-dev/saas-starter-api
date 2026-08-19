<?php

declare(strict_types=1);

namespace App\Modules\Configs\Permissions\Support;

use Illuminate\Support\Str;

trait TranslatesNames
{
    /**
     * Traduce una clave del catálogo. Sin traducción devuelve el nombre legible,
     * porque los permisos creados desde el panel aún no tienen entrada.
     */
    protected function translateName(string $bucket, ?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $key = 'permissions::names.'.$bucket.'.'.$name;
        $label = __($key);

        return $label === $key ? Str::headline($name) : $label;
    }
}
