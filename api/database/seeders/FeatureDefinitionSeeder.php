<?php

namespace Database\Seeders;

use App\Models\FeatureDefinition;
use Illuminate\Database\Seeder;

class FeatureDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        // Keys must match the ones written by PlanSeeder and read by PlanGate.
        $definitions = [
            [
                'feature_key' => 'max_active_events',
                'feature_label' => 'Event aktif',
                'feature_group' => 'event',
                'feature_type' => 'numeric',
                'description' => 'Jumlah event yang bisa berjalan bersamaan.',
                'sort_order' => 10,
            ],
            [
                'feature_key' => 'max_teams_per_event',
                'feature_label' => 'Tim per event',
                'feature_group' => 'event',
                'feature_type' => 'numeric',
                'description' => 'Batas tim yang bisa terdaftar di satu event.',
                'sort_order' => 20,
            ],
            [
                'feature_key' => 'payment_gateway',
                'feature_label' => 'Payment gateway',
                'feature_group' => 'ticket',
                'feature_type' => 'boolean',
                'description' => 'Terima pembayaran tiket dan biaya pendaftaran secara online.',
                'sort_order' => 25,
            ],
            [
                'feature_key' => 'qr_tickets',
                'feature_label' => 'Tiket QR',
                'feature_group' => 'ticket',
                'feature_type' => 'boolean',
                'description' => 'Jual tiket online dengan QR code dan scan di lokasi.',
                'sort_order' => 30,
            ],
            [
                'feature_key' => 'max_tickets_per_event',
                'feature_label' => 'Tiket per event',
                'feature_group' => 'ticket',
                'feature_type' => 'numeric',
                'description' => 'Batas tiket yang bisa diterbitkan per event.',
                'sort_order' => 40,
            ],
            [
                'feature_key' => 'ticket_fee_percent',
                'feature_label' => 'Fee tiket (%)',
                'feature_group' => 'ticket',
                'feature_type' => 'numeric',
                'description' => 'Potongan platform dari setiap penjualan tiket.',
                'sort_order' => 50,
            ],
            [
                'feature_key' => 'registration_fee_percent',
                'feature_label' => 'Fee pendaftaran (%)',
                'feature_group' => 'ticket',
                'feature_type' => 'numeric',
                'description' => 'Potongan platform dari setiap biaya pendaftaran tim.',
                'sort_order' => 60,
            ],
            [
                'feature_key' => 'certificate_generator',
                'feature_label' => 'Generator sertifikat',
                'feature_group' => 'certificate',
                'feature_type' => 'boolean',
                'description' => 'Buat sertifikat peserta dan juara dari template.',
                'sort_order' => 70,
            ],
            [
                'feature_key' => 'certificate_email',
                'feature_label' => 'Kirim sertifikat via email',
                'feature_group' => 'certificate',
                'feature_type' => 'boolean',
                'description' => 'Kirim sertifikat otomatis ke email peserta.',
                'sort_order' => 80,
            ],
            [
                'feature_key' => 'export_data',
                'feature_label' => 'Export Excel & PDF',
                'feature_group' => 'platform',
                'feature_type' => 'boolean',
                'description' => 'Unduh data peserta, jadwal, dan laporan sebagai Excel atau PDF.',
                'sort_order' => 85,
            ],
            [
                'feature_key' => 'custom_domain',
                'feature_label' => 'Custom domain',
                'feature_group' => 'platform',
                'feature_type' => 'boolean',
                'description' => 'Pakai domain sendiri untuk halaman event.',
                'sort_order' => 90,
            ],
            [
                'feature_key' => 'api_access',
                'feature_label' => 'API access',
                'feature_group' => 'platform',
                'feature_type' => 'boolean',
                'description' => 'Akses API untuk integrasi dengan sistem lain.',
                'sort_order' => 100,
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
