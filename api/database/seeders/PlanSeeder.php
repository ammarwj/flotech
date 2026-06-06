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
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Untuk komunitas kecil yang baru mulai.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'sort_order' => 1,
                'features' => [
                    'max_active_events' => '1',
                    'max_teams_per_event' => '8',
                    'qr_tickets' => 'false',
                    'certificate_generator' => 'false',
                ],
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Untuk klub & kampus yang rutin gelar event.',
                'price_monthly' => 149000,
                'price_yearly' => 1430000,
                'sort_order' => 2,
                'features' => [
                    'max_active_events' => '3',
                    'max_teams_per_event' => '32',
                    'qr_tickets' => 'true',
                    'max_tickets_per_event' => '500',
                    'certificate_generator' => 'true',
                    'ticket_fee_percent' => '3',
                ],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Untuk EO profesional & turnamen besar.',
                'price_monthly' => 399000,
                'price_yearly' => 3830000,
                'sort_order' => 3,
                'features' => [
                    'max_active_events' => '10',
                    'max_teams_per_event' => '128',
                    'qr_tickets' => 'true',
                    'max_tickets_per_event' => '5000',
                    'certificate_generator' => 'true',
                    'certificate_email' => 'true',
                    'ticket_fee_percent' => '2',
                ],
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'Untuk federasi & turnamen skala nasional.',
                'price_monthly' => 999000,
                'price_yearly' => 9590000,
                'sort_order' => 4,
                'features' => [
                    'max_active_events' => '-1',
                    'max_teams_per_event' => '-1',
                    'qr_tickets' => 'true',
                    'max_tickets_per_event' => '-1',
                    'certificate_generator' => 'true',
                    'certificate_email' => 'true',
                    'custom_domain' => 'true',
                    'api_access' => 'true',
                    'ticket_fee_percent' => '1',
                ],
            ],
        ];

        foreach ($plans as $data) {
            $features = $data['features'];
            unset($data['features']);

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
