<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The upgrade FAQ answered "no" until upgrades shipped.
 *
 * It told visitors to buy the bigger plan on their *next* event and to write in
 * if they had picked wrong — which was true when the only way up was a super
 * admin running reassign-plan. It is now a self-serve button, and leaving the
 * old answer up would talk people out of the thing the feature exists for.
 *
 * Matches on the old text, so a super admin who has already rewritten it at
 * /admin/faqs keeps their wording. FaqSeeder carries the same copy for fresh
 * installs; this is for databases that already ran it.
 */
return new class extends Migration
{
    private const OLD_QUESTION = 'Kalau event berikutnya butuh paket yang lebih besar?';

    private const NEW_QUESTION = 'Kalau event saya butuh paket yang lebih besar?';

    private const OLD_ANSWER = 'Tinggal beli paket itu saat membuat event berikutnya. Paket dibeli per event dan menempel di event tersebut, jadi tidak ada upgrade atau downgrade yang tiba-tiba mengunci fitur di tengah turnamen yang sedang berjalan. Kamu bahkan boleh menjalankan dua event dengan paket berbeda di waktu yang sama. Kalau terlanjur salah pilih, hubungi kami selama paketnya belum dipakai untuk event.';

    private const NEW_ANSWER = 'Bisa dinaikkan kapan saja, dan kamu hanya membayar selisihnya — Starter yang naik ke Pro cukup menambah Rp 200.000, sama saja dengan langsung membeli Pro. Berlaku untuk paket yang belum dipakai maupun event yang sudah berjalan; batasan barunya langsung aktif. Yang tidak bisa adalah menurunkan paket, supaya fitur tidak pernah dicabut dari event yang sedang memakainya.';

    public function up(): void
    {
        DB::table('faqs')
            ->where('question', self::OLD_QUESTION)
            ->where('answer', self::OLD_ANSWER)
            ->update([
                'question' => self::NEW_QUESTION,
                'answer' => self::NEW_ANSWER,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('faqs')
            ->where('question', self::NEW_QUESTION)
            ->where('answer', self::NEW_ANSWER)
            ->update([
                'question' => self::OLD_QUESTION,
                'answer' => self::OLD_ANSWER,
                'updated_at' => now(),
            ]);
    }
};
