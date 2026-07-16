<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'description' => 'Untuk komunitas kecil yang baru mulai.',
                'price_monthly' => 49000,
                'yearly_discount_percent' => 20,
                'sort_order' => 1,
                'features' => [
                    'max_active_events' => '1',
                    'max_teams_per_event' => '8',
                    'payment_gateway' => 'false',
                    'qr_tickets' => 'false',
                    'certificate_generator' => 'false',
                    'export_data' => 'false',
                ],
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Untuk klub & kampus yang rutin gelar event.',
                'price_monthly' => 149000,
                'yearly_discount_percent' => 20,
                'sort_order' => 2,
                'features' => [
                    'max_active_events' => '3',
                    'max_teams_per_event' => '32',
                    'payment_gateway' => 'true',
                    'qr_tickets' => 'true',
                    'max_tickets_per_event' => '500',
                    'certificate_generator' => 'true',
                    'export_data' => 'true',
                    'ticket_fee_percent' => '3',
                    'registration_fee_percent' => '3',
                ],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Untuk EO profesional & turnamen besar.',
                'price_monthly' => 399000,
                'yearly_discount_percent' => 20,
                'sort_order' => 3,
                'features' => [
                    'max_active_events' => '10',
                    'max_teams_per_event' => '128',
                    'payment_gateway' => 'true',
                    'qr_tickets' => 'true',
                    'max_tickets_per_event' => '5000',
                    'certificate_generator' => 'true',
                    'certificate_email' => 'true',
                    'export_data' => 'true',
                    'ticket_fee_percent' => '2',
                    'registration_fee_percent' => '2',
                ],
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'Untuk federasi & turnamen skala nasional.',
                'price_monthly' => 999000,
                'yearly_discount_percent' => 20,
                'sort_order' => 4,
                'features' => [
                    'max_active_events' => '-1',
                    'max_teams_per_event' => '-1',
                    'payment_gateway' => 'true',
                    'qr_tickets' => 'true',
                    'max_tickets_per_event' => '-1',
                    'certificate_generator' => 'true',
                    'certificate_email' => 'true',
                    'export_data' => 'true',
                    'custom_domain' => 'true',
                    'api_access' => 'true',
                    'ticket_fee_percent' => '1',
                    'registration_fee_percent' => '1',
                ],
            ],
        ];

        foreach ($plans as $data) {
            $features = $data['features'];
            unset($data['features']);

            // Yearly price is derived, never seeded by hand — same rule the admin
            // editor goes through, so the two can't drift apart.
            $data['price_yearly'] = Plan::computeYearlyPrice(
                (float) $data['price_monthly'],
                (float) $data['yearly_discount_percent'],
            );

            $plan = Plan::updateOrCreate(['slug' => $data['slug']], $data);

            foreach ($features as $key => $value) {
                PlanFeature::updateOrCreate(
                    ['plan_id' => $plan->id, 'feature_key' => $key],
                    ['value' => $value],
                );
            }
        }
    }
}
