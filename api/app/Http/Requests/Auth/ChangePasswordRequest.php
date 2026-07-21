<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Guard against a hijacked-but-unattended session: knowing the token
            // must not be enough to lock the real owner out of their account.
            // `:api` picks the JWT guard — the default `web` guard has no user
            // here and the rule would fail for everyone.
            'current_password' => ['required', 'current_password:api'],
            // Same strength rule as ResetPasswordRequest, kept in sync by hand;
            // the web form mirrors it again in zod.
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Password saat ini wajib diisi.',
            'current_password.current_password' => 'Password saat ini salah.',
            'password.different' => 'Password baru harus berbeda dari password saat ini.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ];
    }
}
