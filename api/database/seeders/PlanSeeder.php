<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

/**
 * The plan catalogue: three plans, each bought once for one event.
 *
 * There is no free tier and no subscription. The cheapest way to run an event
 * is Starter at Rp 150.000, paid once; an event that spans a month boundary
 * costs exactly the same, because nothing here is measured in time.
 *
 * Features a plan does *not* include are deliberately left out rather than
 * written as `'false'`. PlanResource renders a missing value struck through
 * exactly like an explicit false, so storing one only adds a plan_features row
 * that means what its absence already meant.
 *
 * Keys must match the ones written by FeatureDefinitionSeeder and read by
 * PlanGate. A key with a value but no definition never appears on the pricing
 * card; a definition with no value appears struck through, which is the point.
 *
 * This seeder never deletes. Retiring a key or a plan on a database that is
 * already running is a migration's job — a seeder that pruned would also wipe
 * the custom keys a super admin added at /admin/plans.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Turnamen internal atau komunitas — liga kantor, antar-kelas, fun match.',
                'price' => 150000,
                'sort_order' => 1,
                'features' => [
                    'online_registration' => 'true',
                    'max_categories' => '1',
                    'max_teams_per_category' => '32',
                    'payment_gateway' => 'true',
                    'platform_fee_percent' => '3',
                    'qr_tickets' => 'true',
                ],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Kejuaraan antar-klub atau antar-sekolah tingkat kota dan kabupaten.',
                'price' => 350000,
                'sort_order' => 2,
                'features' => [
                    'online_registration' => 'true',
                    'max_categories' => '4',
                    'max_teams_per_category' => '128',
                    'payment_gateway' => 'true',
                    'platform_fee_percent' => '2',
                    'qr_tickets' => 'true',
                    'export_data' => 'true',
                    'sponsor_logos' => 'true',
                    'organizer_profile' => 'true',
                ],
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'Kejuaraan tingkat provinsi & nasional, atau event multi-cabang.',
                'price' => 800000,
                'sort_order' => 3,
                'features' => [
                    'online_registration' => 'true',
                    'max_categories' => '-1',
                    'max_teams_per_category' => '-1',
                    'payment_gateway' => 'true',
                    'platform_fee_percent' => '1',
                    'qr_tickets' => 'true',
                    'export_data' => 'true',
                    'sponsor_logos' => 'true',
                    'organizer_profile' => 'true',
                    'certificate_generator' => 'true',
                    'certificate_email' => 'true',
                    // The boolean is what denies and the number is what caps.
                    // A numeric key on its own would grant the other two plans an
                    // uncapped gallery, because PlanGate reads an absent limit as
                    // unlimited.
                    'event_gallery' => 'true',
                    'max_gallery_photos' => '15',
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
