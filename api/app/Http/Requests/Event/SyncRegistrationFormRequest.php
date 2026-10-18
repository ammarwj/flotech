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
 * A third is added by `visible_if`: the key it triggers on must be defined
 * EARLIER IN THE SAME LIST. "Earlier" rather than "anywhere" is what closes the
 * cycle (A shown only when B answered, B only when A) without a graph walk and
 * a second rule to keep in step — and it falls out of how the forms work
 * anyway, since the trigger has to be on screen and answered before this field
 * can be decided. Cross-list is refused for the same reason keys are unique
 * per list: a player row cannot see what the team typed.
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

                    // `$seen` at this point holds exactly the keys ABOVE this
                    // row, which is the whole rule: a trigger defined later
                    // (or on this very row) is one the form cannot have an
                    // answer for yet, and a cycle needs at least one backward
                    // reference to exist at all.
                    $trigger = is_array($row) && is_array($row['visible_if'] ?? null)
                        ? ($row['visible_if']['field'] ?? null)
                        : null;

                    if (is_string($trigger) && $trigger !== '' && ! isset($seen[$trigger])) {
                        $validator->errors()->add(
                            "{$section}.{$i}.visible_if.field",
                            'Field pemicu harus salah satu field di daftar yang sama dan berada di atas baris ini.',
                        );
                    }

                    $seen[$key] = true;
                }
            }
        });
    }
}
