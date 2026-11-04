<?php

namespace App\Http\Requests\Race;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The organizer correcting one runner's details.
 *
 * Deliberately NOT an update mode on RegisterRaceEntryRequest: the two accept
 * different sets, and folding them into one request with `sometimes` on
 * everything would mean a single list where no reader can tell which door may
 * send what.
 *
 * What is NOT here, and each absence is load-bearing:
 *
 * - `race_category_id`. The category decides the price (already snapshotted on
 *   the row), the quota (`race_categories.taken`), and the email condition.
 *   Moving an entry would have to shift `taken` on two categories and leave the
 *   runner sitting in a category at another one's price — three answers this
 *   screen has no way to ask for. A wrong category is a cancel-and-re-enter.
 * - `price`, `period_name`, `discount_*`, every fee column. Snapshots, for the
 *   reason `withdrawals` copies the bank account: a bill already issued must
 *   not change what it says. Editing the amount here would also leave
 *   `wallet_transactions` disagreeing with the row it was credited from.
 * - `status`, `paid_at`, `qr_code`, `checked_in_at`. Each has exactly one
 *   writer (RaceEntryService, PaymentVerificationController, RefundService),
 *   and a second way to move money state is the drift MatchResultService was
 *   written to avoid.
 *
 * `documents` is absent too, and that is the one gap worth saying out loud: the
 * runner's uploads are replaced through their own form, not retyped by an
 * organizer reading a scan over the phone.
 */
class UpdateRaceEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every rule is `sometimes`, which is the contract: a key left out of the
     * payload leaves the stored value alone, and that is what lets a client
     * that predates a field keep working. `nullable` where the column is —
     * sending null really does clear it.
     *
     * The ceilings are copied from RegisterRaceEntryRequest rather than
     * loosened: two doors onto one column that disagree about `max` means a
     * value the public form refuses can be typed in here and then fail to
     * render in the PDF that has to fit it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:10'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'emergency_contact_relation' => ['sometimes', 'nullable', 'string', 'max:50'],

            // Whatever else this event's form asks for. Which keys are allowed
            // is checked against the stored schema by the controller, the only
            // place that has it — see RaceEntryService::cleanAnswers().
            'custom_fields' => ['sometimes', 'nullable', 'array'],

            // The one column this endpoint is the FIRST writer of. It had none
            // at all before: the migration left it for the organizer to decide,
            // and this is where they decide it. Free text rather than an
            // integer — "A-014" and "5K-07" are both bib numbers, and a race
            // that prefixes by distance is the common case, not the odd one.
            //
            // Uniqueness is deliberately NOT enforced: the sequence is the
            // organizer's to run, two categories legitimately both start at 1,
            // and a unique index would refuse the renumbering halfway through
            // (swapping 7 and 8 needs one of them to exist twice for an
            // instant). The list shows the number beside every name, which is
            // where a duplicate is visible.
            'bib_number' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'nama lengkap',
            'bib_number' => 'nomor BIB',
        ];
    }
}
