<?php

namespace Database\Seeders;

use App\Models\Testimonial;
use Illuminate\Database\Seeder;

class TestimonialSeeder extends Seeder
{
    public function run(): void
    {
        // The five testimonials the landing page used to hardcode. Presets map to
        // the gradients in web/lib/landing.ts.
        $testimonials = [
            [
                'name' => 'Rizky Pratama',
                'role' => 'Ketua Liga Futsal Bandung',
                'quote' => 'Dulu rekap klasemen liga futsal kami makan waktu berjam-jam tiap pekan. Sekarang otomatis begitu skor dikonfirmasi. Game changer buat EO kecil.',
                'initials' => 'RP',
                'avatar_preset' => 'brand',
                'rating' => 5,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'Sari Wulandari',
                'role' => 'Event Organizer, Surabaya',
                'quote' => 'Fitur sertifikatnya juara. Kami upload desain sendiri, atur posisi sekali, generate 200 sertifikat pemain dalam hitungan menit.',
                'initials' => 'SW',
                'avatar_preset' => 'purple',
                'rating' => 5,
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'name' => 'Dimas Aryo',
                'role' => 'PB Garuda Mas, Yogyakarta',
                'quote' => 'Tiket QR + scan check-in bikin pintu masuk turnamen badminton kami nggak antre lagi. Validasi instan, anti tiket palsu.',
                'initials' => 'DA',
                'avatar_preset' => 'pink',
                'rating' => 5,
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'name' => 'Nadia Fitri',
                'role' => 'Panitia Voli Antar-Kampus',
                'quote' => 'Landing page per event-nya rapi banget. Peserta tinggal scan, lihat jadwal & klasemen tanpa harus tanya-tanya admin lagi.',
                'initials' => 'NF',
                'avatar_preset' => 'success',
                'rating' => 5,
                'is_active' => true,
                'sort_order' => 40,
            ],
            [
                'name' => 'Hendra Wijaya',
                'role' => 'Padel Community Jakarta',
                'quote' => 'Naik dari Basic ke Pro pas turnamen tahunan kami membesar. Upgrade-nya mulus, datanya aman semua. Worth it.',
                'initials' => 'HW',
                'avatar_preset' => 'amber',
                'rating' => 5,
                'is_active' => true,
                'sort_order' => 50,
            ],
        ];

        foreach ($testimonials as $testimonial) {
            Testimonial::updateOrCreate(
                ['name' => $testimonial['name']],
                $testimonial,
            );
        }
    }
}
