<?php

namespace App\Http\Requests\Admin;

use App\Services\PlatformSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bounds come from PlatformSettings::DEFINITIONS so the API and the admin UI
 * can never disagree about what a legal payout rule is. A fat-fingered admin
 * fee of Rp 5.000.000 must not be storable.
 */
class UpdatePlatformSettingsRequest extends FormRequest
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
        $rules = [];

        foreach (PlatformSettings::DEFINITIONS as $key => $definition) {
            $rules[$key] = [
                'sometimes',
                $definition['type'] === 'int' ? 'integer' : 'numeric',
                'min:'.$definition['min'],
                'max:'.$definition['max'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_map(
            fn (array $d) => strtolower($d['label']),
            PlatformSettings::DEFINITIONS,
        );
    }
}
