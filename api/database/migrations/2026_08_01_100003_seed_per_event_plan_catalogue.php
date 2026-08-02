<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rewrite the plan catalogue for per-event billing.
 *
 * This migration seeds its own data rather than deferring to PlanSeeder /
 * FeatureDefinitionSeeder, following the precedent set by
 * create_sport_official_roles_table. Two reasons: a production database may
 * never have had a seeder run against it, and a seeder is a moving target —
 * replaying a migration that called today's seeder a year from now would
 * produce whatever the catalogue looks like then, not what this migration
 * meant. The seeders carry the identical catalogue so a fresh install lands in
 * the same place; the cross-check is to diff `plan_features` between a
 * `migrate` on an existing database and a `migrate:fresh --seed`.
 *
 * It also does the one thing a seeder structurally cannot: prune. Seeders
 * upsert, so a key that is retired here would live on forever in every database
 * that ever had it. Pruning belongs in a migration rather than in the seeder
 * because a seeder that deleted would also sweep away the custom keys a super
 * admin added at /admin/plans.
 */
return new class extends Migration
{
    /**
     * Keys that no longer mean anything under per-event billing.
     *
     * `max_active_events` — every event is bought separately, so there is no
     *   cross-event quota left to enforce.
     * `max_teams_per_event` — superseded by `max_teams_per_category`. Keeping
     *   both would be two gates that can disagree about one registration.
     * `max_tickets_per_event` — not in the catalogue any more.
     * `ticket_fee_percent` / `registration_fee_percent` — merged into a single
     *   `platform_fee_percent`. They already held identical values in every
     *   plan, so nothing is lost; two keys that must always agree are exactly
     *   the drift this codebase keeps single-sourcing to avoid.
     * `custom_domain` / `api_access` — defined and sold, never enforced
     *   anywhere. Removing them stops the pricing card promising them.
     */
    private const RETIRED = [
        'max_active_events',
        'max_teams_per_event',
        'max_tickets_per_event',
        'ticket_fee_percent',
        'registration_fee_percent',
        'custom_domain',
        'api_access',
    ];

    /** @return array<int, array<string, mixed>> */
    private function definitions(): array
    {
        return [
            ['feature_key' => 'online_registration', 'feature_label' => 'Pendaftaran online', 'feature_group' => 'event', 'feature_type' => 'boolean', 'description' => 'Peserta mendaftar sendiri lewat halaman event.', 'sort_order' => 10],
            ['feature_key' => 'max_categories', 'feature_label' => 'Kategori', 'feature_group' => 'event', 'feature_type' => 'numeric', 'description' => 'Jumlah kategori pertandingan di dalam satu event.', 'sort_order' => 20],
            ['feature_key' => 'max_teams_per_category', 'feature_label' => 'Peserta per kategori', 'feature_group' => 'event', 'feature_type' => 'numeric', 'description' => '1 tim / 1 pemain tunggal / 1 pasangan ganda dihitung 1 peserta.', 'sort_order' => 30],
            ['feature_key' => 'payment_gateway', 'feature_label' => 'Payment gateway', 'feature_group' => 'payment', 'feature_type' => 'boolean', 'description' => 'Terima pembayaran tiket dan biaya pendaftaran secara online.', 'sort_order' => 40],
            ['feature_key' => 'platform_fee_percent', 'feature_label' => 'Fee platform (%)', 'feature_group' => 'payment', 'feature_type' => 'numeric', 'description' => 'Potongan platform dari tiap penjualan tiket dan biaya pendaftaran.', 'sort_order' => 50],
            ['feature_key' => 'qr_tickets', 'feature_label' => 'Tiket penonton online', 'feature_group' => 'ticket', 'feature_type' => 'boolean', 'description' => 'Jual tiket online dengan QR code dan scan di lokasi.', 'sort_order' => 60],
            ['feature_key' => 'export_data', 'feature_label' => 'Export data Excel & PDF', 'feature_group' => 'platform', 'feature_type' => 'boolean', 'description' => 'Unduh data peserta, pembeli tiket, klasemen, dan statistik.', 'sort_order' => 70],
            ['feature_key' => 'sponsor_logos', 'feature_label' => 'Logo sponsor', 'feature_group' => 'media', 'feature_type' => 'boolean', 'description' => 'Tampilkan logo sponsor di halaman publik event.', 'sort_order' => 80],
            ['feature_key' => 'organizer_profile', 'feature_label' => 'Profil penyelenggara', 'feature_group' => 'platform', 'feature_type' => 'boolean', 'description' => 'Halaman profil publik berisi deskripsi, kontak, dan sosial media.', 'sort_order' => 90],
            ['feature_key' => 'certificate_generator', 'feature_label' => 'Generator sertifikat', 'feature_group' => 'certificate', 'feature_type' => 'boolean', 'description' => 'Buat sertifikat peserta dan juara dari template.', 'sort_order' => 100],
            ['feature_key' => 'certificate_email', 'feature_label' => 'Kirim sertifikat via email', 'feature_group' => 'certificate', 'feature_type' => 'boolean', 'description' => 'Kirim sertifikat otomatis ke email peserta.', 'sort_order' => 110],
            ['feature_key' => 'event_gallery', 'feature_label' => 'Galeri foto', 'feature_group' => 'media', 'feature_type' => 'boolean', 'description' => 'Album foto di halaman publik event.', 'sort_order' => 120],
            ['feature_key' => 'max_gallery_photos', 'feature_label' => 'Maks foto galeri', 'feature_group' => 'media', 'feature_type' => 'numeric', 'description' => 'Total foto yang bisa diunggah untuk satu event.', 'sort_order' => 130],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function plans(): array
    {
        return [
            [
                'name' => 'Starter', 'slug' => 'starter', 'price' => 150000, 'sort_order' => 1,
                'description' => 'Turnamen internal atau komunitas — liga kantor, antar-kelas, fun match.',
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
                'name' => 'Pro', 'slug' => 'pro', 'price' => 350000, 'sort_order' => 2,
                'description' => 'Kejuaraan antar-klub atau antar-sekolah tingkat kota dan kabupaten.',
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
                'name' => 'Professional', 'slug' => 'professional', 'price' => 800000, 'sort_order' => 3,
                'description' => 'Kejuaraan tingkat provinsi & nasional, atau event multi-cabang.',
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
                    'event_gallery' => 'true',
                    'max_gallery_photos' => '15',
                ],
            ],
        ];
    }

    /**
     * Insert with a fresh id, or update the existing row in place.
     *
     * Not `updateOrInsert()`: that would send the generated `id` on the UPDATE
     * branch too, and repointing a plan's primary key is both a foreign key
     * violation (plan_features references it) and the one thing this migration
     * must never do — every historical order points at these ids.
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     */
    private function upsert(string $table, array $match, array $values): void
    {
        $now = now();
        $existing = DB::table($table)->where($match)->first();

        if ($existing) {
            DB::table($table)->where($match)->update($values + ['updated_at' => $now]);

            return;
        }

        DB::table($table)->insert($values + $match + [
            'id' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function up(): void
    {
        $now = now();
        $managed = array_column($this->definitions(), 'feature_key');

        // 1. Retired keys, values first so no plan_features row is ever orphaned
        //    of its definition even for an instant.
        DB::table('plan_features')->whereIn('feature_key', self::RETIRED)->delete();
        DB::table('feature_definitions')->whereIn('feature_key', self::RETIRED)->delete();

        // 2. Definitions.
        foreach ($this->definitions() as $definition) {
            $this->upsert(
                'feature_definitions',
                ['feature_key' => $definition['feature_key']],
                $definition,
            );
        }

        // 3. Plans, matched on slug so existing rows keep their id and every
        //    historical order still points at a real plan.
        foreach ($this->plans() as $data) {
            $features = $data['features'];
            unset($data['features']);

            $this->upsert('plans', ['slug' => $data['slug']], $data + [
                'is_active' => true,
                'is_public' => true,
            ]);

            $planId = DB::table('plans')->where('slug', $data['slug'])->value('id');

            // Replace, not upsert. Production stores "not included" as an
            // explicit 'false' while the catalogue above simply omits it, and
            // both render struck through — so an upsert would leave two spellings
            // of one meaning lying around. Scoped to the managed keys, so any
            // custom key a super admin added at /admin/plans survives.
            DB::table('plan_features')
                ->where('plan_id', $planId)
                ->whereIn('feature_key', $managed)
                ->delete();

            foreach ($features as $key => $value) {
                DB::table('plan_features')->insert([
                    'id' => (string) Str::uuid(),
                    'plan_id' => $planId,
                    'feature_key' => $key,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 4. Retire `basic` if this database has one. Production does not — the
        //    catalogue there was already trimmed by hand at /admin/plans — so
        //    this is a no-op there and matters only for databases seeded from an
        //    older PlanSeeder.
        //
        //    Deactivated, never deleted: historical orders point at it, plan_id
        //    is nullOnDelete, and losing the row would make old invoices read
        //    "Paket dihapus". Same reasoning rename_free_plan_to_basic used when
        //    it kept the id.
        $basicId = DB::table('plans')->where('slug', 'basic')->value('id');

        if ($basicId !== null) {
            DB::table('plans')->where('id', $basicId)
                ->update(['is_active' => false, 'is_public' => false, 'updated_at' => $now]);

            DB::table('plan_features')->where('plan_id', $basicId)->delete();
        }
    }

    /**
     * Irreversible by design.
     *
     * Down would have to invent the retired keys' values back out of nothing —
     * the fee split in particular cannot be recovered once the two percentages
     * have been merged into one. Rolling this back means restoring the dump
     * taken before the deploy, which is the honest answer rather than a
     * down() that silently reconstructs a catalogue nobody ever had.
     */
    public function down(): void
    {
        // no-op
    }
};
