<?php

namespace App\Http\Requests\Admin;

use App\Models\ConfigOption;
use App\Support\Engines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConfigOptionRequest extends FormRequest
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
        $id = $this->route('config_option')?->id ?? $this->route('config_option');
        $creating = $this->isMethod('post');

        return [
            'group' => [$creating ? 'required' : 'sometimes', Rule::in(ConfigOption::GROUPS)],
            'key' => [
                $creating ? 'required' : 'sometimes',
                'string', 'max:40', 'alpha_dash',
                Rule::unique('config_options', 'key')
                    ->where('group', $this->input('group', $this->route('config_option')?->group))
                    ->ignore($id),
            ],
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
            'meta.engine' => ['nullable', Rule::in(Engines::FORMATS)],
            'meta.comparator' => ['nullable', Rule::in(Engines::TIEBREAKERS)],
            'meta.strategy' => ['nullable', Rule::in(Engines::DRAW_METHODS)],
            'meta.size' => ['nullable', 'integer', 'min:2', 'max:128'],
            'meta.defaults' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * A row that drives behaviour must say which behaviour. Without this, an
     * admin could create a "format" with no engine — organizers would then be
     * able to pick it and every schedule generation would fail.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $group = $this->input('group', $this->route('config_option')?->group);
            $meta = $this->input('meta', $this->route('config_option')?->meta ?? []);

            $required = match ($group) {
                'tournament_format' => 'engine',
                'tiebreaker' => 'comparator',
                'draw_method' => 'strategy',
                'knockout_round' => 'size',
                default => null,
            };

            if ($required !== null && empty($meta[$required])) {
                $v->errors()->add("meta.{$required}", "Wajib diisi untuk grup {$group}.");
            }
        });
    }
}
