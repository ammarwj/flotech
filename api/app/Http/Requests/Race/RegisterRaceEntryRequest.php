<?php

namespace App\Http\Requests\Race;

use App\Support\RegistrationForm;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One runner entering a fun run from the public page.
 *
 * What is deliberately NOT here:
 *
 * - `race_period_id`. The window is picked by the server's clock, never by the
 *   payload — see the invariant on RacePricing. Accepting it would make
 *   "charge me the early-bird price" a request anyone could send.
 * - `price`. Same reason, one step further on: the amount is resolved from the
 *   period and stored, so a client that sent one would be ignored at best and
 *   believed at worst.
 * - whether the category is on sale, has room, or belongs to this event. All
 *   three need the stored rows, so they live in PublicRaceController::register()
 *   — the same division ValidatesTicketDays documents.
 * - `payment_channel` being present. Required only when the entry turns out to
 *   be a paid gateway one, which only the controller knows.
 */
class RegisterRaceEntryRequest extends FormRequest
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
            'race_category_id' => ['required', 'uuid'],

            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            // A free-text ceiling rather than an enum of two: the organizer's
            // own form may ask it differently, and a race that classifies by
            // age group has no use for it at all. Which values are offered is
            // the form's business, not the column's.
            'gender' => ['nullable', 'string', 'max:10'],
            'birth_date' => ['nullable', 'date'],
            // The one group that exists for safety rather than for admin. Not
            // `required` here even so: whether to demand it is the organizer's
            // call, and they make it on their registration form.
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            // Who they are to the runner. Free text for the same reason
            // `gender` above is: the list of relations nobody has agreed on.
            'emergency_contact_relation' => ['nullable', 'string', 'max:50'],

            // Whatever else this event's form asks for. The keys are validated
            // against the stored schema by the controller, which is the only
            // place that has it.
            'custom_fields' => ['nullable', 'array'],

            'documents' => ['nullable', 'array', 'max:20'],
            'documents.*.document_type' => ['required', 'string', 'max:50'],
            'documents.*.file_url' => ['required', 'string', 'url', 'max:2048'],
            'documents.*.file_name' => ['nullable', 'string', 'max:255'],
            'documents.*.file_size_bytes' => ['nullable', 'integer', 'min:0'],

            'payment_channel' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'nama lengkap',
            'race_category_id' => 'kategori',
        ];
    }
}
