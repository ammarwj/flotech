<?php

namespace App\Http\Requests\Event;

use App\Models\EventDiscountCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One class for both doors that write an event discount code.
 *
 * Shaped on PayoutDestinationRequest, and for the same reason: store and update
 * differ by one word (`required` vs `sometimes`), and two copies are how one
 * door ends up accepting a code the other refuses.
 */
class EventDiscountCodeRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        // `discount_type` may be absent on an update, in which case the stored
        // one still decides whether the 100 ceiling applies — without this a
        // percent code could be edited to 150% through the door that does not
        // resend its type.
        $percent = ($this->input('discount_type') ?? $this->stored()?->discount_type) === 'percent';

        return [
            // Uppercased before validation (prepareForValidation) so the unique
            // check cannot be fooled by case. Scoped to the event, NOT global:
            // the code belongs to this event, and two organizers are both
            // entitled to run an `EARLYBIRD`.
            'code' => [
                $presence, 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('event_discount_codes', 'code')
                    ->where('event_id', $this->eventId())
                    ->ignore($this->stored()?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => [$presence, Rule::in(EventDiscountCode::TYPES)],
            // A percent above 100 would be refunding money; the clamp in
            // discountFor() stops it mattering, but accepting it here would
            // print "diskon 150%" on the organizer's own screen.
            'discount_value' => [$presence, 'numeric', 'min:0', ...($percent ? ['max:100'] : [])],
            // Only meaningful for percent codes; a fixed code's value already is
            // its ceiling.
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'once_per_user' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => EventDiscountCode::normalize((string) $this->input('code'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya boleh huruf kapital, angka, garis bawah, dan tanda hubung (contoh: EARLYBIRD).',
            'code.unique' => 'Kode itu sudah dipakai di event ini.',
            'discount_value.max' => 'Diskon persentase tidak boleh lebih dari 100%.',
            'ends_at.after' => 'Tanggal berakhir harus setelah tanggal mulai.',
        ];
    }

    /** The event the code belongs to: from the route on store, from the row on update. */
    protected function eventId(): ?string
    {
        return $this->route('event') ?? $this->stored()?->event_id;
    }

    /**
     * The row being edited, read once.
     *
     * Reading stored state in a request has a precedent here —
     * UpdateBankAccountRequest::isEwallet() does it so an e-wallet edited
     * without resending its type is still validated as one. Scoping to the
     * organization is the controller's job; this only needs the row's shape.
     */
    protected function stored(): ?EventDiscountCode
    {
        static $row = false;

        if ($row === false) {
            $id = $this->route('eventDiscountCode');
            $row = $id ? EventDiscountCode::find($id) : null;
        }

        return $row;
    }
}
