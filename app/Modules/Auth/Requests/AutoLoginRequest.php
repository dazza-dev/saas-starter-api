<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutoLoginRequest extends FormRequest
{
    /**
     * El auto-login es público.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas de validación para el intercambio del token de auto-login.
     * El token es obligatorio y debe ser una cadena de texto.
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    /**
     * Mensajes de validación personalizados para el auto-login.
     * Cada mensaje se obtiene del archivo de traducciones del módulo.
     */
    public function messages(): array
    {
        return [
            'token.required' => __('auth::validation.token.required'),
            'token.string' => __('auth::validation.token.string'),
        ];
    }
}
