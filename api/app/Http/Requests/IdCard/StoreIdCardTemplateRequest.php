<?php

namespace App\Http\Requests\IdCard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreIdCardTemplateRequest extends FormRequest
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
        return IdCardTemplateRules::make('required');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => IdCardTemplateRules::check($v, $this->input('fields')));
    }
}
