<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring the landing copy in line with per-event billing.
 *
 * `components/landing/pricing.tsx` was rewritten when the model changed, but
 * the FAQ and testimonials on the same page were not — they still quoted
 * "Basic Rp 49.000/bulan", a yearly discount, and an upgrade/downgrade flow
 * that no longer exists. That copy lives in `faqs`/`testimonials`, so it needs
 * a migration exactly like the plan catalogue did: production may never have
 * had a seeder run against it, and FaqSeeder is keyed by `question` — so a
 * question whose *wording* changed would be inserted as a second row and leave
 * the stale one answering visitors.
 *
 * Every statement matches on the old text. A super admin who has already
 * edited a row at /admin/faqs keeps their wording rather than having it
 * overwritten: nothing here is authoritative over a human who looked at it
 * more recently than we did.
 */
return new class extends Migration
{
    /**
     * [table, key column, old key, updates] — `old key` is both what identifies
     * the row and what proves it is still the untouched original.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: array<string, string>}>
     */
    private function rewrites(): array
    {
        return [
            ['faqs', 'question', 'Paket paling murah mulai dari berapa?', [
                'answer' => 'Paket Starter Rp 150.000, sekali bayar untuk satu event — bukan langganan bulanan. Kamu dapat 1 kategori berisi maksimal 32 peserta, lengkap dengan pendaftaran online, jadwal, klasemen, bracket, dan tiket QR. Tidak ada masa berlaku: event yang berjalan lintas bulan tidak dikenai biaya tambahan.',
            ]],

            // Not billing, but false on the same page: basket and tenis shipped
            // (SportSeeder carries nine sports), and mini soccer and tenis meja
            // were never listed at all.
            ['faqs', 'question', 'Cabang olahraga apa saja yang didukung?', [
                'answer' => 'Sembilan cabang: sepak bola, mini soccer, futsal, voli, basket, badminton, tenis, tenis meja, dan padel — masing-masing dengan aturan skor, statistik, dan klasemen yang sesuai. Cabang raket juga mendukung nomor tunggal, ganda, dan beregu berpartai.',
            ]],

            // Certificates are Professional-only now; "Pro ke atas" would sell a
            // feature the Pro plan does not carry.
            ['faqs', 'question', 'Bagaimana cara kerja generator sertifikat?', [
                'answer' => 'Kamu upload desain sertifikatmu sendiri (JPG/PNG), atur posisi tiap elemen — nama, tim, penghargaan, logo, tanda tangan — lalu generate batch. Setiap sertifikat dapat nomor unik dan QR verifikasi, bisa di-download ZIP atau dikirim via email. Tersedia di paket Professional.',
            ]],

            // The only one whose *question* changes: there is no upgrading or
            // downgrading left to ask about.
            ['faqs', 'question', 'Apakah saya bisa upgrade atau downgrade paket?', [
                'question' => 'Kalau event berikutnya butuh paket yang lebih besar?',
                'answer' => 'Tinggal beli paket itu saat membuat event berikutnya. Paket dibeli per event dan menempel di event tersebut, jadi tidak ada upgrade atau downgrade yang tiba-tiba mengunci fitur di tengah turnamen yang sedang berjalan. Kamu bahkan boleh menjalankan dua event dengan paket berbeda di waktu yang sama. Kalau terlanjur salah pilih, hubungi kami selama paketnya belum dipakai untuk event.',
            ]],

            ['faqs', 'question', 'Metode pembayaran apa yang tersedia?', [
                'answer' => 'Lewat Midtrans: Virtual Account semua bank besar, QRIS, e-wallet (GoPay/OVO/DANA/ShopeePay), serta kartu kredit/debit. Berlaku untuk pembelian paket, biaya registrasi, dan pembelian tiket.',
            ]],

            ['testimonials', 'name', 'Hendra Wijaya', [
                'quote' => 'Turnamen bulanan kami cukup pakai Starter, yang tahunan baru ambil Professional. Bayar per event bikin biayanya nempel ke event yang memang besar. Worth it.',
            ]],
        ];
    }

    public function up(): void
    {
        foreach ($this->rewrites() as [$table, $column, $old, $updates]) {
            DB::table($table)->where($column, $old)->update($updates);
        }
    }

    /**
     * Deliberately empty. Rolling back would put "Basic Rp 49.000/bulan" back on
     * a public page selling a plan that is retired — a worse state than the one
     * it came from, and the schema is untouched either way.
     */
    public function down(): void {}
};
