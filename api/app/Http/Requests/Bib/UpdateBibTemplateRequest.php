<?php

namespace App\Http\Requests\Bib;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBibTemplateRequest extends FormRequest
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
        return BibTemplateRules::make('sometimes');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Only when the client actually sent the list. A PATCH that changes
            // nothing but the name must not be told its (absent) fields are
            // malformed.
            if ($this->has('fields')) {
                BibTemplateRules::check($v, $this->input('fields'));
            }
        });
    }
}
