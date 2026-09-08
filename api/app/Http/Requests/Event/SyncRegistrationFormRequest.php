<?php

namespace App\Http\Requests\Event;

use App\Support\RegistrationForm;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The organizer defining what their registration form asks for.
 *
 * The shape rules come from RegistrationForm so the value object stays the only
 * thing that knows them. What is added here is the two rules a flat rule array
 * cannot express: keys must be unique inside their own list, and a `select`
 * without options is a dead field nobody could ever fill in.
 *
 * The remaining rule — a key that already has answers may be relabelled but not
 * renamed or deleted — needs the event's teams and lives in EventController,
 * the same split SportController::syncPositions uses.
 */
class SyncRegistrationFormRequest extends FormRequest
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
        return RegistrationForm::validationRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (RegistrationForm::SECTIONS as $section) {
                $rows = $this->input($section, []);

                if (! is_array($rows)) {
                    continue;
                }

                $seen = [];

                foreach ($rows as $i => $row) {
                    $key = is_array($row) ? ($row['key'] ?? null) : null;

                    // Unique inside its own list, not across lists: a team and a
                    // player may both be asked for an `alamat`, and they are
                    // stored on different rows.
                    if ($key !== null && isset($seen[$key])) {
                        $validator->errors()->add("{$section}.{$i}.key", 'Key ini sudah dipakai di daftar yang sama.');
                    }

                    $seen[$key] = true;

                    // A select with no options renders an empty dropdown — and if
                    // it is also required, a form nobody can submit.
                    if (is_array($row) && ($row['type'] ?? null) === 'select') {
                        $options = array_filter(
                            is_array($row['options'] ?? null) ? $row['options'] : [],
                            fn ($o) => is_scalar($o) && trim((string) $o) !== '',
                        );

                        if ($options === []) {
                            $validator->errors()->add("{$section}.{$i}.options", 'Pilihan wajib diisi untuk tipe select.');
                        }
                    }
                }
            }
        });
    }
}
