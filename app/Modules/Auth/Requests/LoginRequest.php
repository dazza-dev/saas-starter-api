<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determina si el usuario está autorizado a realizar esta petición.
     * El inicio de sesión es público, por lo que siempre se permite.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas de validación para autenticar con usuario y contraseña.
     * Ambos campos son obligatorios y deben ser cadenas de texto.
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Mensajes de validación personalizados para el inicio de sesión.
     * Cada mensaje se obtiene del archivo de traducciones del módulo.
     */
    public function messages(): array
    {
        return [
            'username.required' => __('auth::validation.username.required'),
            'username.string' => __('auth::validation.username.string'),
            'password.required' => __('auth::validation.password.required'),
            'password.string' => __('auth::validation.password.string'),
        ];
    }

    /**
     * Nombres legibles de los atributos usados en los mensajes de error.
     * Se obtienen del archivo de traducciones del módulo.
     */
    public function attributes(): array
    {
        return [
            'username' => __('auth::validation.attributes.username'),
            'password' => __('auth::validation.attributes.password'),
        ];
    }
}
