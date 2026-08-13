<?php

namespace App\Support;

/**
 * Pencarian teks "mengandung", tanpa peduli besar-kecil huruf.
 *
 * Ada di satu tempat karena aturannya dua-duanya mudah ditulis setengah:
 *
 *  - **`LIKE` polos case-sensitive di Postgres** (prod) tapi tidak di sqlite
 *    (tes), jadi kotak pencarian yang mengabaikan ini gagal **hanya di
 *    produksi**, dengan seluruh suite hijau. Itu persis yang terjadi di
 *    `/admin/users`: mencari "Kaboax" tidak menemukan kaboax@gmail.com.
 *  - **`ilike` memperbaiki huruf tapi cuma ada di Postgres**, jadi query yang
 *    memakainya tidak bisa diuji sama sekali di sqlite. `LOWER(...) LIKE`
 *    berjalan benar di keduanya, yang membuat bentuk query ini punya tes.
 *
 * Dan apa yang diketik user adalah **teks, bukan pola**: tanpa `ESCAPE`, satu
 * `%` mengembalikan seluruh tabel — hasil yang terbaca seperti filternya mati.
 *
 * Nama kolom di sini selalu datang dari kode (bukan dari request); nilainya
 * yang di-bind. Jangan pernah mengoper kolom dari input user.
 */
class Search
{
    /**
     * Cocokkan `$term` ke salah satu dari `$columns` (OR), sebagai satu grup
     * yang aman digabung dengan kondisi lain di sebelahnya.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  array<int, string>  $columns
     */
    public static function anyColumn($query, array $columns, string $term)
    {
        $needle = self::needle($term);

        return $query->where(function ($w) use ($columns, $needle) {
            foreach ($columns as $column) {
                $w->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$needle]);
            }
        });
    }

    /** Pola untuk perbandingan `LOWER(kolom) LIKE ? ESCAPE '\'`. */
    public static function needle(string $term): string
    {
        return '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
    }
}
