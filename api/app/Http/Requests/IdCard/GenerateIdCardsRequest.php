<?php

namespace App\Http\Requests\IdCard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateIdCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The recipient list is always explicit, mirroring
     * GenerateCertificatesRequest. "Select all" is a frontend affordance, not a
     * server mode: one code path, and the cap actually means something.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_card_template_id' => ['required', 'uuid'],

            'recipients' => ['required', 'array', 'min:1', 'max:'.(int) config('id_card.max_recipients')],
            'recipients.*.type' => ['required', Rule::in(['player', 'official', 'personnel'])],
            'recipients.*.id' => ['required', 'uuid'],
        ];
    }
}
