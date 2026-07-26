<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // Required: the whole point of collecting it is being able to reach
            // the person behind the account (organizer or team manager) when the
            // email bounces or an event needs a call. Nullable here meant almost
            // nobody filled it in. Existing accounts keep their null.
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            // Optional: callers that say nothing fall back to the column default.
            'default_mode' => ['nullable', Rule::in(['organizer', 'participant'])],
        ];
    }
}
