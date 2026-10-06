<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One manual point adjustment appended to a category's table.
 *
 * There is no update counterpart, and that is a decision rather than an
 * omission — see StandingAdjustmentController.
 */
class StoreStandingAdjustmentRequest extends FormRequest
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
        return [
            // Membership of *this* category is checked in the controller: the
            // rule would have to name the category to say it here, and a team
            // from a sibling category is a 422 about the pair, not the field.
            'team_id' => ['required', 'uuid'],
            // Signed on purpose — a bonus and a sanction are one column.
            //
            // `not_in:0` because a zero row moves no total and says nothing a
            // reason alone could not: it is a comment wearing a ledger entry's
            // clothes, and it would still show up in the table's Adj column.
            //
            // Bounded because a realistic house rule is ±1..±5, and a typo'd
            // 2000 buries every result in the table under one entry.
            'points' => ['required', 'integer', 'min:-99', 'max:99', 'not_in:0'],
            // Required, where wallet_transactions.description is nullable: this
            // number has no source document to fall back on, and an unexplained
            // ±2 on a public table is worse than no adjustment at all.
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points.not_in' => 'Penyesuaian 0 poin tidak mengubah apa pun.',
            'reason.required' => 'Alasan wajib diisi — angka ini tampil di klasemen publik.',
        ];
    }
}
