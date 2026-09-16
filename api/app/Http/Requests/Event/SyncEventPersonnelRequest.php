<?php

namespace App\Http\Requests\Event;

use App\Models\EventPersonnel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The whole referee-and-staff list of an event, sent at once.
 *
 * Same sync contract as a team's bench, and `personnel.*.id` is load-bearing for
 * the same reason `officials.*.id` is: without it every row is read as new,
 * recreated, and the photo already uploaded for the original is orphaned.
 *
 * `role_label` has no Rule::in because there is no catalogue to check it
 * against — see the create_event_personnel_table migration.
 */
class SyncEventPersonnelRequest extends FormRequest
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
            // Present but empty means "clear the list"; the route is a PUT of the
            // whole list, so there is no partial form to protect here.
            'personnel' => ['present', 'array', 'max:200'],
            'personnel.*.id' => ['nullable', 'string'],
            'personnel.*.full_name' => ['required', 'string', 'max:255'],
            // Nullable because a crew member who never logs in is still a name
            // on an ID card — the feature this table was built for. `email`
            // validation is not cosmetic here: this address is what an account
            // gets provisioned against, so a typo mails a stranger a password.
            'personnel.*.email' => ['nullable', 'email', 'max:255'],
            'personnel.*.kind' => ['required', Rule::in(EventPersonnel::KINDS)],
            'personnel.*.role_label' => ['nullable', 'string', 'max:60'],
            'personnel.*.photo_url' => ['nullable', 'string'],
        ];
    }
}
