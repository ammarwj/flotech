<?php

namespace App\Http\Requests\Bib;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBibTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant middleware already proved membership
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return BibTemplateRules::make('required');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => BibTemplateRules::check($v, $this->input('fields')));
    }
}
