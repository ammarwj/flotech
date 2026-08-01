<?php

namespace Database\Seeders;

use App\Models\FeatureDefinition;
use Illuminate\Database\Seeder;

/**
 * The human-readable catalogue of features. This is the only source of labels
 * anyone reads: the pricing cards and the upgrade page render `feature_details`,
 * which is this table joined against each plan's values.
 *
 * Keys must match the ones written by PlanSeeder and read by PlanGate. A key
 * with a value but no definition never appears on a pricing card at all — which
 * is the most common way a newly added gate ends up invisible.
 *
 * Like PlanSeeder, this never deletes: pruning a retired key on a live database
 * is a migration's job.
 */
class FeatureDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'feature_key' => 'online_registration',
                'feature_label' => 'Pendaftaran online',
                'feature_group' => 'event',
                'feature_type' => 'boolean',
                'description' => 'Peserta mendaftar sendiri lewat halaman event.',
                'sort_order' => 10,
            ],
            [
                'feature_key' => 'max_categories',
                'feature_label' => 'Kategori',
                'feature_group' => 'event',
                'feature_type' => 'numeric',
                'description' => 'Jumlah kategori pertandingan di dalam satu event.',
                'sort_order' => 20,
            ],
            [
                'feature_key' => 'max_teams_per_category',
                'feature_label' => 'Peserta per kategori',
                'feature_group' => 'event',
                'feature_type' => 'numeric',
                // Spelled out because "peserta" reads as *people* to an
                // organizer, while what is counted is entries: a 20-man football
                // squad and one badminton singles player are both 1.
                'description' => '1 tim / 1 pemain tunggal / 1 pasangan ganda dihitung 1 peserta.',
                'sort_order' => 30,
            ],
            [
                'feature_key' => 'payment_gateway',
                'feature_label' => 'Payment gateway',
                'feature_group' => 'payment',
                'feature_type' => 'boolean',
                'description' => 'Terima pembayaran tiket dan biaya pendaftaran secara online.',
                'sort_order' => 40,
            ],
            [
                'feature_key' => 'platform_fee_percent',
                'feature_label' => 'Fee platform (%)',
                'feature_group' => 'payment',
                'feature_type' => 'numeric',
                'description' => 'Potongan platform dari tiap penjualan tiket dan biaya pendaftaran.',
                'sort_order' => 50,
            ],
            [
                'feature_key' => 'qr_tickets',
                'feature_label' => 'Tiket penonton online',
                'feature_group' => 'ticket',
                'feature_type' => 'boolean',
                'description' => 'Jual tiket online dengan QR code dan scan di lokasi.',
                'sort_order' => 60,
            ],
            [
                'feature_key' => 'export_data',
                'feature_label' => 'Export data Excel & PDF',
                'feature_group' => 'platform',
                'feature_type' => 'boolean',
                'description' => 'Unduh data peserta, pembeli tiket, klasemen, dan statistik.',
                'sort_order' => 70,
            ],
            [
                'feature_key' => 'sponsor_logos',
                'feature_label' => 'Logo sponsor',
                'feature_group' => 'media',
                'feature_type' => 'boolean',
                'description' => 'Tampilkan logo sponsor di halaman publik event.',
                'sort_order' => 80,
            ],
            [
                'feature_key' => 'organizer_profile',
                'feature_label' => 'Profil penyelenggara',
                'feature_group' => 'platform',
                'feature_type' => 'boolean',
                'description' => 'Halaman profil publik berisi deskripsi, kontak, dan sosial media.',
                'sort_order' => 90,
            ],
            [
                'feature_key' => 'certificate_generator',
                'feature_label' => 'Generator sertifikat',
                'feature_group' => 'certificate',
                'feature_type' => 'boolean',
                'description' => 'Buat sertifikat peserta dan juara dari template.',
                'sort_order' => 100,
            ],
            [
                'feature_key' => 'certificate_email',
                'feature_label' => 'Kirim sertifikat via email',
                'feature_group' => 'certificate',
                'feature_type' => 'boolean',
                'description' => 'Kirim sertifikat otomatis ke email peserta.',
                'sort_order' => 110,
            ],
            [
                'feature_key' => 'event_gallery',
                'feature_label' => 'Galeri foto',
                'feature_group' => 'media',
                'feature_type' => 'boolean',
                'description' => 'Album foto di halaman publik event.',
                'sort_order' => 120,
            ],
            [
                'feature_key' => 'max_gallery_photos',
                'feature_label' => 'Maks foto galeri',
                'feature_group' => 'media',
                'feature_type' => 'numeric',
                'description' => 'Total foto yang bisa diunggah untuk satu event.',
                'sort_order' => 130,
            ],
        ];

        foreach ($definitions as $definition) {
            FeatureDefinition::updateOrCreate(
                ['feature_key' => $definition['feature_key']],
                $definition,
            );
        }
    }
}
