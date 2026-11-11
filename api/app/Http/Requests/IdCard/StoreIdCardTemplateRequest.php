<?php

namespace App\Http\Requests\IdCard;

use App\Http\Requests\Concerns\ChecksTemplateFieldKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreIdCardTemplateRequest extends FormRequest
{
    use ChecksTemplateFieldKeys;

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
        $validator->after(function (Validator $v) {
            IdCardTemplateRules::check($v, $this->input('fields'));
            $this->checkFieldKeys($v, $this, 'id_card');
        });
    }
}
