<?php

/**
 * Seed the UAD fun run event for the "Temporary Organizer" organization, by
 * calling the same HTTP endpoints the dashboard calls.
 *
 * Deliberately NOT a batch of model writes: creating an event spends a plan
 * credit (EventController::store claims an unconsumed order inside a
 * transaction), the registration schema goes through its own validation, and a
 * direct insert would prove none of that works. Run with:
 *
 *   docker compose exec -T api php artisan tinker < seed-uad-funrun.php
 *
 * Idempotent on the event slug: re-running reuses the event it already made
 * rather than burning a second credit.
 */

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;

$org = Organization::where('slug', 'temporaryorganizer')->firstOrFail();
$owner = User::where('email', 'flobamora.tech@gmail.com')->firstOrFail();
$token = auth('api')->login($owner);

$base = 'http://127.0.0.1:8000/api/v1';
$api = fn () => Http::withToken($token)->acceptJson();

$call = function (string $method, string $path, array $body = []) use ($api, $base) {
    $res = $api()->{$method}($base.$path, $body);

    if ($res->failed()) {
        echo "FAILED {$method} {$path}\n".$res->body()."\n";
        exit(1);
    }

    return $res->json('data');
};

// ---- the event ----

$slug = 'uad-fun-run-2026';
$existing = $org->events()->where('slug', $slug)->first();

if ($existing) {
    $event = $existing;
    // The registration window too — same reason as the periods below.
    $call('put', "/organizations/{$org->id}/events/{$existing->id}", [
        'registration_open' => '2026-10-15',
        'registration_close' => '2026-11-28',
    ]);
    echo "event exists: {$event->id}\n";
} else {
    $event = $call('post', "/organizations/{$org->id}/events", [
        'name' => 'UAD Fun Run 2026',
        'sport_type' => 'fun_run',
        'description' => 'Fun run 3K & 5K terbuka untuk mahasiswa UAD dan umum.',
        'location' => 'Kampus 4 Universitas Ahmad Dahlan, Yogyakarta',
        'start_date' => '2026-12-06',
        'end_date' => '2026-12-06',
        'registration_open' => '2026-10-15',
        'registration_close' => '2026-11-28',
        'payment_method' => 'gateway',
    ]);

    // Published so the public registration page answers at all; a draft 404s.
    $call('patch', "/organizations/{$org->id}/events/{$event['id']}/status", [
        'status' => 'open',
    ]);

    $event = Event::findOrFail($event['id']);
    echo "event created: {$event->id} slug={$event->slug}\n";
}

$eventId = $event->id;

// ---- sale periods ----
//
// The windows belong to the event, the prices to each category. Early bird is
// already open at the seeding date so the public form has a live price.

$periods = [
    ['Early Bird', '2026-10-15T00:00:00+07:00', '2026-10-31T23:59:59+07:00'],
    ['Pre Sale 1', '2026-11-01T00:00:00+07:00', '2026-11-14T23:59:59+07:00'],
    ['Pre Sale 2', '2026-11-15T00:00:00+07:00', '2026-11-28T23:59:59+07:00'],
];

$periodIds = [];

foreach ($periods as $i => [$name, $from, $to]) {
    // updateOrCreate, not create-if-missing: re-running with edited dates has
    // to move the window, otherwise the script silently keeps whatever the
    // first run wrote and the event looks closed for no visible reason.
    $row = $event->racePeriods()->updateOrCreate(
        ['name' => $name],
        ['starts_at' => $from, 'ends_at' => $to, 'sort_order' => $i],
    );

    $periodIds[$name] = $row->id;
}

echo 'periods: '.implode(', ', array_keys($periodIds))."\n";

// ---- categories ----
//
// Four, because distance AND student status both change the price, and the
// price lives on the category. "Jarak 3K/5K" and "Status UAD/UMUM" are
// therefore the category choice, not form fields.

$categories = [
    ['3K Mahasiswa UAD', ['Early Bird' => 75_000, 'Pre Sale 1' => 90_000, 'Pre Sale 2' => 105_000], 300],
    ['3K Umum', ['Early Bird' => 95_000, 'Pre Sale 1' => 110_000, 'Pre Sale 2' => 125_000], 300],
    ['5K Mahasiswa UAD', ['Early Bird' => 95_000, 'Pre Sale 1' => 110_000, 'Pre Sale 2' => 125_000], 400],
    ['5K Umum', ['Early Bird' => 120_000, 'Pre Sale 1' => 135_000, 'Pre Sale 2' => 150_000], 400],
];

foreach ($categories as $i => [$name, $prices, $quota]) {
    $slug = \Illuminate\Support\Str::slug($name);
    $category = $event->raceCategories()->where('slug', $slug)->first();

    if (! $category) {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => str_contains($name, 'UAD')
                ? 'Wajib melampirkan NIM dan bukti status mahasiswa aktif.'
                : 'Terbuka untuk peserta umum.',
            'quota' => $quota,
            'is_active' => true,
            'sort_order' => $i,
        ]);
    }

    foreach ($prices as $periodName => $price) {
        $category->prices()->updateOrCreate(
            ['race_period_id' => $periodIds[$periodName]],
            ['price' => $price],
        );
    }
}

echo 'categories: '.$event->raceCategories()->count()."\n";

// ---- the registration form ----
//
// Everything in the brief that is not already a built-in column on
// `race_entries` (name, email, phone, gender, birth date, and the emergency
// contact pair) and not the category choice.
//
// Ordering is load-bearing in one place: `status_mahasiswa` must sit ABOVE the
// three fields conditioned on it, which is exactly what the API enforces.

$field = fn (array $row) => [
    'required' => false,
    'is_public' => false,
    'in_album' => false,
    'options' => [],
    'help_text' => null,
    'help_image_url' => null,
    'visible_if' => null,
    ...$row,
];

$uadOnly = ['field' => 'status_mahasiswa', 'values' => ['Mahasiswa UAD']];

$teamFields = [
    $field([
        'key' => 'nama_bib', 'label' => 'Nama BIB', 'type' => 'short_text', 'required' => true,
        'help_text' => 'Maksimal 10 karakter, dicetak di nomor punggung.',
        'is_public' => true,
    ]),
    $field([
        'key' => 'kota_domisili', 'label' => 'Kota Domisili', 'type' => 'short_text',
    ]),
    $field([
        'key' => 'status_mahasiswa', 'label' => 'Status', 'type' => 'select', 'required' => true,
        'options' => ['Mahasiswa UAD', 'UMUM'],
        'help_text' => 'Pilih "Mahasiswa UAD" hanya jika kamu mahasiswa aktif UAD.',
    ]),
    // The three that only exist for UAD students.
    $field([
        'key' => 'nim', 'label' => 'NIM', 'type' => 'short_text', 'required' => true,
        'visible_if' => $uadOnly,
        'help_text' => 'Nomor Induk Mahasiswa, sesuai KTM.',
    ]),
    $field([
        'key' => 'fakultas', 'label' => 'Fakultas', 'type' => 'short_text', 'required' => true,
        'visible_if' => $uadOnly,
    ]),
    $field([
        'key' => 'prodi', 'label' => 'Program Studi', 'type' => 'short_text', 'required' => true,
        'visible_if' => $uadOnly,
    ]),
    $field([
        'key' => 'ukuran_jersey', 'label' => 'Ukuran Jersey', 'type' => 'select', 'required' => true,
        'options' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
        'help_text' => 'Cek tabel ukuran di gambar sebelum memilih. Ukuran tidak bisa diubah setelah pendaftaran ditutup.',
    ]),
    $field([
        'key' => 'kontak_darurat_hubungan', 'label' => 'Hubungan dengan Peserta', 'type' => 'select',
        'required' => true,
        'options' => ['Orang Tua', 'Saudara', 'Pasangan', 'Teman', 'Lainnya'],
        'help_text' => 'Hubungan orang yang nomornya kamu isi di Kontak Darurat.',
    ]),
    $field([
        'key' => 'kondisi_medis', 'label' => 'Kondisi Medis / Alergi', 'type' => 'long_text',
        'help_text' => 'Opsional, tapi sangat membantu tim medis di lintasan. Contoh: asma, alergi obat tertentu.',
    ]),
    $field([
        'key' => 'golongan_darah', 'label' => 'Golongan Darah', 'type' => 'select',
        'options' => ['A', 'B', 'AB', 'O', 'Tidak tahu'],
    ]),
    $field([
        'key' => 'asal_ormawa', 'label' => 'Asal Ormawa', 'type' => 'short_text',
        'help_text' => 'Organisasi mahasiswa, kalau ada.',
    ]),
    // The consents. Each stores the instant it was ticked — the server stamps
    // its own clock, so the recorded time is not the entrant's to choose.
    $field([
        'key' => 'pernyataan_sehat', 'label' => 'Saya menyatakan dalam kondisi sehat dan layak mengikuti lomba ini',
        'type' => 'consent', 'required' => true,
    ]),
    // Labels are capped at 100 characters, which a legal waiver outgrows — so
    // the short statement is the label and the full wording is the guidance
    // text rendered above the box.
    $field([
        'key' => 'consent_risiko', 'label' => 'Saya memahami risiko lomba dan membebaskan penyelenggara dari tuntutan cedera',
        'type' => 'consent', 'required' => true,
        'help_text' => 'Saya memahami bahwa lari jarak jauh memiliki risiko cedera dan gangguan kesehatan. Saya tidak akan menuntut penyelenggara atas cedera yang terjadi di luar kelalaian panitia.',
    ]),
    $field([
        'key' => 'consent_data', 'label' => 'Saya setuju data saya digunakan penyelenggara untuk keperluan lomba ini',
        'type' => 'consent', 'required' => true,
    ]),
    $field([
        'key' => 'consent_dokumentasi', 'label' => 'Saya setuju foto dan video saya dipakai untuk dokumentasi dan publikasi lomba',
        'type' => 'consent', 'required' => true,
    ]),
];

$teamDocuments = [
    [
        'key' => 'bukti_mahasiswa',
        'label' => 'Bukti Mahasiswa Aktif UAD (KTM / KRS)',
        'required' => false,
        'accept' => ['pdf', 'jpg', 'png'],
    ],
];

$call('put', "/organizations/{$org->id}/events/{$eventId}/registration-form", [
    'team_fields' => $teamFields,
    'player_fields' => [],
    'team_official_fields' => [],
    'team_documents' => $teamDocuments,
    'player_documents' => [],
    'team_official_documents' => [],
]);

echo 'form saved: '.count($teamFields)." fields, ".count($teamDocuments)." document\n";

$fresh = Event::findOrFail($eventId);
echo "PUBLIC URL: /{$org->slug}/{$fresh->slug}\n";
echo "REGISTER URL: /{$org->slug}/{$fresh->slug}/race\n";
