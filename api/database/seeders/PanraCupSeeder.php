<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The SMA/SMK/MA draw of Panra Cup 2026, thirty-six schools, copied off the
 * organizer's own wall chart.
 *
 * It exists to exercise what that chart made obvious and the app could not do:
 * referring to a fixture before its teams exist. Thirty-six is the number that
 * makes it visible — padded to a bracket of 64, round two is nothing but
 * "Pemenang P1 vs Pemenang P2" all the way down, and nothing on the chart can
 * be named without the match numbers.
 *
 * Idempotent, keyed on the event slug. Run it explicitly; like the other demo
 * seeders it is not part of DatabaseSeeder:
 *
 *   php artisan db:seed --class=PanraCupSeeder
 */
class PanraCupSeeder extends Seeder
{
    /** The thirty-six schools, in the order they appear on the chart. */
    private const SCHOOLS = [
        'SMKN 1 Sumedang (B)',
        'SMAN 1 Talaga',
        'SMKS Inovasi Mandiri (A)',
        'SMAN 2 Majalengka',
        'MA PUI Kepuh (B)',
        'MAN 1 Majalengka (Biru)',
        'SMKN 1 Lemahsugih (A)',
        'SMAN 1 Majalengka',
        'SMKN Situraja (B)',
        'SMAN 9 Garut (A)',
        'SMAN Bantarujeg (Putih)',
        'SMAN 1 Sumedang',
        'MA Al-Maʼsum (A)',
        'SMK Yappri',
        'SMAN Darmaraja (B)',
        'SMAN Situraja (A)',
        'SMAN Jatinunggal (B)',
        'SMKN 7 Garut (A)',
        'SMK Darul Maʼarif',
        'SMK Al-Maʼmun',
        'SMAN Jatinunggal (A)',
        'MA Nurakhman Howara Hadad',
        'SMAN Darmaraja (A)',
        'SMKN 1 Indramayu',
        'SMKN Situraja (A)',
        'SMK Al-Akram',
        'SMAN Bantarujeg (Biru)',
        'MAN 1 Majalengka (Merah)',
        'SMAN 9 Garut (B)',
        'SMKS Inovasi Mandiri (B)',
        'SMAN Situraja (B)',
        'SMKN 1 Sumedang (A)',
        'SMKN 1 Lemahsugih (B)',
        'MA Al-Maʼsum (B)',
        'MA PUI Kepuh (A)',
        'SMKN 7 Garut (B)',
    ];

    public function run(): void
    {
        $owner = User::where('email', 'admin@flotech.id')->first()
            ?? User::orderBy('created_at')->first();

        if (! $owner) {
            $this->command?->warn('PanraCupSeeder dilewati: belum ada user sama sekali.');

            return;
        }

        $org = $this->makeOrg($owner);
        $event = $this->makeEvent($org);

        $category = $this->makeCategory($event, 'SMA/SMK/MA', 0);

        foreach (self::SCHOOLS as $i => $school) {
            $this->makeTeam($category, $school, $i);
        }

        $this->command?->info("Seeded \"{$event->name}\" untuk \"{$org->name}\".");
        $this->command?->table(
            ['Kategori', 'Peserta'],
            [[$category->name, $category->teams()->count()]],
        );
        $this->command?->info('Jadwal sengaja belum dibuat — jalankan "Buat Jadwal" dari dashboard untuk melihat bentuk bagannya.');
    }

    private function makeOrg(User $owner): Organization
    {
        $org = Organization::firstOrCreate(
            ['slug' => 'flobamora-event'],
            [
                'name' => 'Flobamora Event',
                'owner_id' => $owner->id,
                'contact_email' => $owner->email,
            ],
        );

        OrganizationMember::firstOrCreate(
            ['organization_id' => $org->id, 'user_id' => $owner->id],
            ['role' => 'admin', 'invited_by' => $owner->id],
        );

        return $org;
    }

    private function makeEvent(Organization $org): Event
    {
        // A plan is attached directly rather than bought: this is a local
        // fixture, and walking it through checkout would spend a credit that
        // nothing here is testing.
        $plan = Plan::where('slug', 'professional')->first() ?? Plan::first();

        return Event::updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'panra-cup-2026'],
            [
                'plan_id' => $plan?->id,
                'name' => 'Panra Cup 2026',
                'sport_type' => 'futsal',
                // Entries closed and the field full: the morning of the draw,
                // which is the only state this fixture is about.
                'status' => 'registration_closed',
                'start_date' => now()->addDays(7),
                'end_date' => now()->addDays(21),
                'registration_open' => now()->subDays(60),
                'registration_close' => now()->subDays(2),
                'location_name' => 'GOR Panra',
                'location_address' => 'Majalengka, Jawa Barat',
                'description' => 'Turnamen antar sekolah kategori SMA/SMK/MA, 36 tim, sistem gugur.',
            ],
        );
    }

    private function makeCategory(Event $event, string $name, int $sort): EventCategory
    {
        return EventCategory::updateOrCreate(
            ['event_id' => $event->id, 'slug' => Str::slug($name)],
            [
                'name' => $name,
                'participant_type' => 'team',
                'tournament_format' => 'knockout_single',
                'bracket_config' => ['third_place' => true],
                'registration_fee' => 350_000,
                'max_teams' => count(self::SCHOOLS),
                'sort_order' => $sort,
            ],
        );
    }

    private function makeTeam(EventCategory $category, string $name, int $index): void
    {
        $category->event->teams()->firstOrCreate(
            ['category_id' => $category->id, 'name' => $name],
            [
                'status' => 'approved',
                'contact_name' => 'Pembina '.$name,
                'contact_phone' => '08'.str_pad((string) (1000000 + $index), 10, '0', STR_PAD_LEFT),
            ],
        );
    }
}
