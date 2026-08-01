<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

class FaqSeeder extends Seeder
{
    public function run(): void
    {
        // The seven questions the landing page used to hardcode.
        $faqs = [
            [
                'question' => 'Paket paling murah mulai dari berapa?',
                'answer' => 'Paket Starter Rp 150.000, sekali bayar untuk satu event — bukan langganan bulanan. Kamu dapat 1 kategori berisi maksimal 32 peserta, lengkap dengan pendaftaran online, jadwal, klasemen, bracket, dan tiket QR. Tidak ada masa berlaku: event yang berjalan lintas bulan tidak dikenai biaya tambahan.',
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'question' => 'Cabang olahraga apa saja yang didukung?',
                'answer' => 'Sembilan cabang: sepak bola, mini soccer, futsal, voli, basket, badminton, tenis, tenis meja, dan padel — masing-masing dengan aturan skor, statistik, dan klasemen yang sesuai. Cabang raket juga mendukung nomor tunggal, ganda, dan beregu berpartai.',
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'question' => 'Bagaimana cara kerja generator sertifikat?',
                'answer' => 'Kamu upload desain sertifikatmu sendiri (JPG/PNG), atur posisi tiap elemen — nama, tim, penghargaan, logo, tanda tangan — lalu generate batch. Setiap sertifikat dapat nomor unik dan QR verifikasi, bisa di-download ZIP atau dikirim via email. Tersedia di paket Professional.',
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'question' => 'Kalau event berikutnya butuh paket yang lebih besar?',
                'answer' => 'Tinggal beli paket itu saat membuat event berikutnya. Paket dibeli per event dan menempel di event tersebut, jadi tidak ada upgrade atau downgrade yang tiba-tiba mengunci fitur di tengah turnamen yang sedang berjalan. Kamu bahkan boleh menjalankan dua event dengan paket berbeda di waktu yang sama. Kalau terlanjur salah pilih, hubungi kami selama paketnya belum dipakai untuk event.',
                'is_active' => true,
                'sort_order' => 40,
            ],
            [
                'question' => 'Metode pembayaran apa yang tersedia?',
                'answer' => 'Lewat Midtrans: Virtual Account semua bank besar, QRIS, e-wallet (GoPay/OVO/DANA/ShopeePay), serta kartu kredit/debit. Berlaku untuk pembelian paket, biaya registrasi, dan pembelian tiket.',
                'is_active' => true,
                'sort_order' => 50,
            ],
            [
                'question' => 'Bagaimana kalau payment gateway sedang bermasalah?',
                'answer' => 'Event kamu tetap bisa jualan. Kalau gateway sedang terganggu, kami mengalihkan seluruh platform ke transfer manual: pembeli melihat rekening organisasimu, transfer langsung ke sana, lalu mengunggah bukti untuk kamu verifikasi dari halaman event. Uangnya masuk ke rekeningmu sendiri dan kami tidak memotong fee sepeser pun. Pastikan rekening penarikanmu sudah terisi supaya jalur ini siap saat dibutuhkan.',
                'is_active' => true,
                'sort_order' => 55,
            ],
            [
                'question' => 'Apakah boleh mengadakan event perjudian atau hadiah dari uang pendaftaran?',
                'answer' => 'Tidak. flo-event melarang segala bentuk perjudian, termasuk hadiah yang dikumpulkan (pooling) dari biaya pendaftaran peserta. Biaya pendaftaran hanya untuk operasional penyelenggaraan; hadiah harus bersumber dari sponsor atau dana penyelenggara. Pelanggaran dapat berujung penghapusan event dan penangguhan akun. Selengkapnya di halaman Ketentuan Layanan.',
                'is_active' => true,
                'sort_order' => 60,
            ],
            [
                'question' => 'Apakah data turnamen saya aman?',
                'answer' => 'Setiap organizer terisolasi sebagai tenant terpisah. Kami pakai HTTPS, enkripsi data, audit trail di setiap aksi penting, serta patuh UU PDP Indonesia. Uptime platform dijaga di 99,9%.',
                'is_active' => true,
                'sort_order' => 70,
            ],
        ];

        foreach ($faqs as $faq) {
            Faq::updateOrCreate(
                ['question' => $faq['question']],
                $faq,
            );
        }
    }
}
