<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\Team;
use App\Services\TeamRosterService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A badminton event in the demo organization (owner@flo-event.id) with four
 * categories whose entrant lists are already full and approved — the state an
 * organizer is in the morning they sit down to draw the schedule.
 *
 * Deliberately stops there: no fixtures, no results. The four categories cover
 * the four formats and all three entrant shapes, so undian/jadwal, hybrid group
 * config and the knockout plan can each be exercised from a clean start. For an
 * event that is already *played* — including a worked partai example — use
 * BadmintonDemoSeeder instead.
 *
 *   Tunggal Putra    single  league           8 pemain
 *   Tunggal Putri    single  knockout_single  8 pemain
 *   Ganda Campuran   double  hybrid           8 pasangan (2 grup × 4)
 *   Beregu Campuran  team    knockout_double  4 regu, 3 partai per tie
 *
 * Entrants go in through TeamRosterService, the same write path the public
 * registration form uses, so singles/doubles names are *derived* from the
 * roster rather than typed here.
 *
 * Idempotent: keyed on the event slug, and an entrant that already exists is
 * left alone. Run it explicitly — like the other demo seeders it is not part of
 * DatabaseSeeder:
 *
 *   php artisan db:seed --class=BadmintonOpenSeeder
 */
class BadmintonOpenSeeder extends Seeder
{
    /** The tie every beregu match is played over. */
    private const RUBBER_FORMAT = [
        ['label' => 'Ganda Putra', 'type' => 'double'],
        ['label' => 'Tunggal Putri', 'type' => 'single'],
        ['label' => 'Ganda Campuran', 'type' => 'double'],
    ];

    /** @var list<string> */
    private array $men = [
        'Rangga Wicaksono', 'Bimo Aditya', 'Fajar Nurhuda', 'Yoga Pratama',
        'Reza Alfarizi', 'Satria Mahendra', 'Ilham Kurniawan', 'Panji Ramadhan',
    ];

    /** @var list<string> */
    private array $women = [
        'Anindya Larasati', 'Kirana Puspita', 'Salma Aulia', 'Mutiara Hapsari',
        'Nadia Safitri', 'Rania Kusuma', 'Zahra Maulida', 'Intan Permatasari',
    ];

    /**
     * Mixed doubles: one man, one woman. The pairs reuse the singles fields on
     * purpose — a real open draw has the same faces in several nomor.
     *
     * @var list<array{0: string, 1: string}>
     */
    private array $mixedPairs = [
        ['Rangga Wicaksono', 'Anindya Larasati'],
        ['Bimo Aditya', 'Kirana Puspita'],
        ['Fajar Nurhuda', 'Salma Aulia'],
        ['Yoga Pratama', 'Mutiara Hapsari'],
        ['Reza Alfarizi', 'Nadia Safitri'],
        ['Satria Mahendra', 'Rania Kusuma'],
        ['Ilham Kurniawan', 'Zahra Maulida'],
        ['Panji Ramadhan', 'Intan Permatasari'],
    ];

    /**
     * Clubs, each fielding enough players for the three partai above: two men
     * for the ganda putra, two women, and the pair that takes the ganda
     * campuran is drawn from those four.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private array $clubs = [
        'PB Djarum Kudus' => [
            ['Rangga Wicaksono', 'singles'], ['Bimo Aditya', 'doubles'],
            ['Anindya Larasati', 'singles'], ['Kirana Puspita', 'doubles'],
        ],
        'PB Jaya Raya Jakarta' => [
            ['Fajar Nurhuda', 'singles'], ['Yoga Pratama', 'doubles'],
            ['Salma Aulia', 'singles'], ['Mutiara Hapsari', 'doubles'],
        ],
        'PB Exist Jakarta' => [
            ['Reza Alfarizi', 'singles'], ['Satria Mahendra', 'doubles'],
            ['Nadia Safitri', 'singles'], ['Rania Kusuma', 'doubles'],
        ],
        'PB Mutiara Cardinal Bandung' => [
            ['Ilham Kurniawan', 'singles'], ['Panji Ramadhan', 'doubles'],
            ['Zahra Maulida', 'singles'], ['Intan Permatasari', 'doubles'],
        ],
    ];

    public function __construct(
        private readonly TeamRosterService $roster = new TeamRosterService,
    ) {}

    public function run(): void
    {
        $org = Organization::where('slug', 'demo-organizer')->first();

        if (! $org) {
            $this->command?->warn('BadmintonOpenSeeder dilewati: organisasi "demo-organizer" belum ada (jalankan UserSeeder dulu).');

            return;
        }

        $event = $this->makeEvent($org);

        $ms = $this->makeCategory($event, 'Tunggal Putra', 'single', 'league', 75_000, 8, 0);
        $ws = $this->makeCategory($event, 'Tunggal Putri', 'single', 'knockout_single', 75_000, 8, 1);
        $xd = $this->makeCategory($event, 'Ganda Campuran', 'double', 'hybrid', 120_000, 8, 2, [
            'groups' => 2,
            'teams_per_group' => 4,
            'qualification' => ['top_per_group' => 2],
            'draw_method' => 'random',
        ]);
        $team = $this->makeCategory($event, 'Beregu Campuran', 'team', 'knockout_double', 400_000, 4, 3);

        foreach ($this->men as $name) {
            $this->makeEntrant($ms, [[$name, 'singles']]);
        }

        foreach ($this->women as $name) {
            $this->makeEntrant($ws, [[$name, 'singles']]);
        }

        foreach ($this->mixedPairs as [$man, $woman]) {
            $this->makeEntrant($xd, [[$man, 'doubles'], [$woman, 'doubles']]);
        }

        foreach ($this->clubs as $club => $players) {
            $this->makeEntrant($team, $players, $club);
        }

        $this->command?->info("Seeded event badminton \"{$event->name}\" untuk \"{$org->name}\".");
        $this->command?->table(
            ['Kategori', 'Jenis peserta', 'Format', 'Peserta', 'Kuota'],
            collect([$ms, $ws, $xd, $team])->map(fn (EventCategory $c) => [
                $c->name,
                match ($c->participant_type) {
                    'single' => 'Tunggal',
                    'double' => 'Ganda',
                    default => 'Beregu ('.count(self::RUBBER_FORMAT).' partai)',
                },
                $c->tournament_format,
                $c->teams()->count(),
                $c->max_teams,
            ])->all(),
        );
        $this->command?->info('Jadwal sengaja belum dibuat — undian & plan knockout dijalankan dari dashboard.');
        $this->command?->info('Login: owner@flo-event.id / password');
    }

    private function makeEvent(Organization $org): Event
    {
        return Event::updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'flo-badminton-open-2026'],
            [
                'name' => 'Flo Badminton Open 2026',
                'sport_type' => 'badminton',
                // Pendaftaran sudah ditutup dan kuota penuh, tapi belum main:
                // ini kondisi tepat sebelum undian.
                'status' => 'registration_closed',
                'start_date' => now()->addDays(7),
                'end_date' => now()->addDays(10),
                'registration_open' => now()->subDays(45),
                'registration_close' => now()->subDays(3),
                'location_name' => 'GOR Among Rogo',
                'location_address' => 'Jl. Kenari No. 1, Yogyakarta',
                'description' => 'Turnamen badminton terbuka dengan empat nomor: tunggal putra, tunggal putri, '
                    .'ganda campuran, dan beregu campuran. Nomor beregu dimainkan atas tiga partai — '
                    .'hasil regu dihitung dari partai yang dimenangkan.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $bracketConfig
     */
    private function makeCategory(
        Event $event,
        string $name,
        string $participantType,
        string $format,
        int $fee,
        int $maxTeams,
        int $sort,
        ?array $bracketConfig = null,
    ): EventCategory {
        return EventCategory::updateOrCreate(
            ['event_id' => $event->id, 'slug' => Str::slug($name)],
            [
                'name' => $name,
                'participant_type' => $participantType,
                // Only the beregu category plays over partai; the other three
                // are a single run of games, exactly as usesRubbers() decides.
                'rubber_format' => $participantType === 'team' ? self::RUBBER_FORMAT : null,
                'tournament_format' => $format,
                'bracket_config' => $bracketConfig,
                'registration_fee' => $fee,
                'max_teams' => $maxTeams,
                'sort_order' => $sort,
            ],
        );
    }

    /**
     * Register one entrant with the given players.
     *
     * For tunggal/ganda the name goes in as a placeholder on purpose:
     * TeamRosterService derives the real one from the roster ("Rangga
     * Wicaksono / Anindya Larasati"), and letting it do so here is what keeps
     * the seeded data identical to what the registration form produces. A regu
     * keeps the club name it was given.
     *
     * @param  list<array{0: string, 1: string}>  $players  [full_name, position]
     */
    private function makeEntrant(EventCategory $category, array $players, ?string $name = null): ?Team
    {
        $name ??= implode(' / ', array_column($players, 0));

        if ($category->teams()->where('name', $name)->exists()) {
            return null;
        }

        $registeredAt = Carbon::parse($category->event->registration_open)->addDays(random_int(1, 30));

        $team = Team::create([
            'event_id' => $category->event_id,
            'category_id' => $category->id,
            'name' => $name,
            'contact_name' => $players[0][0],
            'contact_phone' => '08'.random_int(11_000_0000, 13_999_9999),
            'status' => 'approved',
            'registered_at' => $registeredAt,
            'approved_at' => $registeredAt->copy()->addHours(6),
            'payment_status' => 'paid',
            'payment_amount' => (float) $category->registration_fee,
            // Seeded straight into the table rather than through
            // RegistrationService::markPaid(), so no wallet ledger is written
            // and there is no platform fee to snapshot. Demo data, not money.
            'platform_fee' => 0,
            'paid_at' => $registeredAt->copy()->addHours(2),
        ]);

        // The category is needed to size the roster and derive the name, and the
        // event to validate positions — attach both rather than re-querying.
        $team->setRelation('category', $category);
        $team->setRelation('event', $category->event);

        $this->roster->syncPlayers($team, array_map(
            fn (array $p, int $i) => [
                'full_name' => $p[0],
                'position' => $p[1],
                // A squad wears numbers; a tunggal/ganda entrant does not.
                'jersey_number' => $category->rosterSize() === null ? (string) ($i + 1) : null,
            ],
            $players,
            array_keys($players),
        ));

        return $team;
    }
}
