<?php

namespace App\Http\Requests\IdCard;

use App\Http\Requests\Concerns\ChecksTemplateFieldKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateIdCardTemplateRequest extends FormRequest
{
    use ChecksTemplateFieldKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return IdCardTemplateRules::make('sometimes');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Only when the client actually sent the list. A PATCH that changes
            // nothing but the name must not be told its (absent) fields are
            // malformed.
            if ($this->has('fields')) {
                IdCardTemplateRules::check($v, $this->input('fields'));
                $this->checkFieldKeys($v, $this, 'id_card');
            }
        });
    }
}
