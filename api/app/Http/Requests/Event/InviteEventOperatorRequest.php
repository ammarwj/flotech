<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Inviting someone to operate an event: an email, and at least one of the
 * four permission categories. "All akses" in the UI is a derived checkbox
 * that sends all four true — there is no fifth field here for it.
 */
class InviteEventOperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // org.admin middleware already proved who may invite
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'can_ticketing' => ['sometimes', 'boolean'],
            'can_scheduling' => ['sometimes', 'boolean'],
            'can_registration' => ['sometimes', 'boolean'],
            'can_documents' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->boolean('can_ticketing')
                && ! $this->boolean('can_scheduling')
                && ! $this->boolean('can_registration')
                && ! $this->boolean('can_documents')) {
                $validator->errors()->add(
                    'can_ticketing',
                    'Pilih minimal satu kategori akses untuk operator ini.',
                );
            }
        });
    }

    /**
     * @return array<string, bool>
     */
    public function permissions(): array
    {
        return [
            'ticketing' => $this->boolean('can_ticketing'),
            'scheduling' => $this->boolean('can_scheduling'),
            'registration' => $this->boolean('can_registration'),
            'documents' => $this->boolean('can_documents'),
        ];
    }
}
