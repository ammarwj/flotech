<?php

namespace App\Http\Requests\PlanOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
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
        return [
            // Restricted to active plans: retired rows stay in the table so old
            // orders keep pointing at a real plan and their invoices still read
            // correctly, but nobody may buy one.
            'plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')->where('is_active', true)],
            // Whether this is actually required depends on the rail (gateway vs
            // manual/outage), which only EventPlanOrderService::resolvePayment()
            // knows — same reason PurchaseTicketRequest leaves it nullable here.
            'payment_channel' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.exists' => 'Paket itu sudah tidak tersedia.',
        ];
    }
}
