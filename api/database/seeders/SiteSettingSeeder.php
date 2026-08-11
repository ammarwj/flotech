<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class SiteSettingSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate, not updateOrCreate: this runs on production via
        // `migrate --seed` and must never overwrite what super_admin typed.
        //
        // Only sales_email is seeded — it preserves the address the Professional
        // plan CTA used to hardcode in components/landing/pricing.tsx, so that
        // button keeps working the moment this ships. contact_email and
        // contact_phone stay null on purpose: inventing an address that bounces
        // is worse than a footer that shows nothing until someone fills it in.
        SiteSetting::firstOrCreate([], [
            'sales_email' => 'sales@floevent.id',
        ]);
    }
}
