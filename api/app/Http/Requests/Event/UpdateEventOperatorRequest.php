<?php

namespace App\Http\Requests\Event;

use App\Models\EventOperator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Editing an existing operator's four permission categories. Same
 * "at least one must stay true" rule as inviting — an operator with none
 * granted is a row that answers no gate at all; removing the last one is
 * what `remove()` is for.
 */
class UpdateEventOperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // org.admin middleware already proved who may edit
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'can_ticketing' => ['sometimes', 'boolean'],
            'can_scheduling' => ['sometimes', 'boolean'],
            'can_registration' => ['sometimes', 'boolean'],
            'can_documents' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // The route is NOT model-bound — EventOperatorController's method
            // signature takes `string $operator` and resolves the model itself
            // in `findOperator()`, so `$this->route('operator')` is a bare
            // UUID string, never an EventOperator instance. Resolving it here
            // instead of reading `->can_*` off that string directly.
            $operator = EventOperator::find($this->route('operator'));

            // Not found isn't this validator's problem — the controller's own
            // `findOrFail()` scoped to the org will 404 it. Skip the merge
            // check rather than crash on a null operator.
            if (! $operator) {
                return;
            }

            $resulting = [
                $this->has('can_ticketing') ? $this->boolean('can_ticketing') : $operator->can_ticketing,
                $this->has('can_scheduling') ? $this->boolean('can_scheduling') : $operator->can_scheduling,
                $this->has('can_registration') ? $this->boolean('can_registration') : $operator->can_registration,
                $this->has('can_documents') ? $this->boolean('can_documents') : $operator->can_documents,
            ];

            if (! in_array(true, $resulting, true)) {
                $validator->errors()->add(
                    'can_ticketing',
                    'Pilih minimal satu kategori akses, atau hapus operator ini.',
                );
            }
        });
    }

    /**
     * @return array<string, bool>
     */
    public function permissions(): array
    {
        $permissions = [];

        foreach (['ticketing', 'scheduling', 'registration', 'documents'] as $category) {
            if ($this->has('can_'.$category)) {
                $permissions[$category] = $this->boolean('can_'.$category);
            }
        }

        return $permissions;
    }
}
