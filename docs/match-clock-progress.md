# Progress: babak & jam pertandingan

Menambah **babak** (babak/kuarter) dan **jam pertandingan** ke papan skor publik, dengan
kontrol di dua pintu (organizer + petugas pertandingan).

- **Branch**: `master`
- **Rencana**: `/Users/ammar/.claude/plans/piped-sauteeing-lemur.md`

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer
`Co-Authored-By:` atau `Claude-Session:`.

> ⚠️ DB dev `flo_event` adalah **salinan produksi**. Jangan pernah `migrate:fresh`,
> `migrate:refresh`, atau `db:wipe`. `php artisan migrate` (maju) aman; `php artisan test`
> aman (sqlite in-memory).

---

## Invarian

> **1. Menit diturunkan, tidak pernah disimpan.** Yang tersimpan adalah *anchor*:
> `clock_started_at` (instant UTC saat jam terakhir jalan) + `clock_elapsed_seconds` (yang
> sudah terkumpul sebelum jeda terakhir). Kolom "menit ke-67" basi persis saat papan
> ditinggal — satu-satunya keadaan papan ini dipakai. Kelas bug yang sama dengan larangan
> bermain tersimpan di `DisciplineService`.

> **2. Jam berjalan + status bukan `ongoing` adalah keadaan yang tidak boleh ada di DB.**
> Empat pintu menulis status/jam; menitipkan "jangan lupa bekukan" ke masing-masing berarti
> salah satunya lupa. Pembekuannya dipasang sekali di `GameMatch::booted()` →
> `static::saving()`. Caveat `WithoutModelEvents` sama dengan hook `created` di sebelahnya.

> **3. Payload membawa `server_time`.** Laptop venue yang jamnya meleset 4 menit akan
> menampilkan menit salah kalau klien memakai `Date.now()` mentah. Klien menghitung skew
> sekali lalu mengoreksi tiap detak.

> **4. Gate `scoring === 'goal'` dari katalog, bukan daftar slug.** Alasan yang sama dengan
> `tracksDiscipline()`. Hidup sekali di `MatchClockRules::enabled` + cerminannya di
> `web/lib/scoring.ts`.

> **5. Tidak ada kontrol jam di papan skor publik.** URL-nya dibagikan ke penonton.

---

## Tahapan

### Backend
- [x] Migrasi `add_clock_to_matches_table` (3 kolom)
- [x] Migrasi `add_period_config_to_sports_table` (turunan `default_match_minutes`)
- [x] `App\Support\MatchClockRules` + `Catalog::periodConfig()` + `SportSeeder`
- [x] `App\Exceptions\MatchClockException` + render di `bootstrap/app.php`
- [x] `App\Services\MatchClockService`
- [x] Hook `saving()` di `GameMatch` + `$fillable`
- [x] `MatchResource` blok `clock` (+ eager-load `category` di 3 endpoint koleksi)
- [x] Dua route + controller
- [x] Validasi `period_config` di `SportRequest` + `rules_config.clock` di event requests

### Frontend
- [x] `web/lib/match-clock.ts` + `tracksClock()` di `scoring.ts`
- [x] Tipe `MatchClock` di `web/types/api.ts`
- [x] Papan skor: `.sb-period` / `.sb-clock`
- [x] `match-clock-controls.tsx` di `MatchCardHeader`
- [x] Gateway di `match-doors.ts` + halaman petugas
- [x] `event-form.tsx` namespace `clock`
- [x] `/admin/sports` blok `period_config`

### Verifikasi
- [x] `MatchClockTest` (16 uji pembanding) — hijau, dan suite penuh 698 hijau
- [x] `web/lib/match-clock.test.ts` (skew) — 7 hijau
- [x] `bun run lint` · `npx tsc --noEmit` — bersih (3 error `set-state-in-effect` yang tersisa sudah ada sebelumnya, di halaman peserta)
- [ ] Manual: `LiveMatchSeeder` MBU CUP II 2026

---

## Catatan sambil jalan

- Controller jam **tidak jadi kelas sendiri** seperti tertulis di rencana
  (`MatchClockController`). Dua pintu itu berbeda **hanya** pada cara menemukan
  laganya, dan aturan itu sudah dimiliki masing-masing controller dengan benar
  (`tenant` vs `event_id`). Kelas ketiga berarti salinan ketiga aturan scoping.
  Yang justru tidak boleh menyimpang — daftar aksi, kalimat penolakan, pesan
  sukses — ditaruh di `Api\Concerns\AppliesMatchClock`, preseden `HasManualPayment`.
- **Halaman petugas yang dipakai bukan `officiating/matches/[id]`** seperti
  tertulis di rencana — itu halaman wasit (mengesahkan susunan pemain). Pintu
  skor petugas ada di `officiating/events/[id]/page.tsx`, dan kontrol jam
  dipasang di sana di balik gate `scoring` yang sudah ada (`assignment.kind ===
  "staff"`). Wasit mengesahkan susunan, bukan menjalankan jam — dan
  `event.staff` di server menolaknya betapapun kartunya dirender.
- `SportResource` ternyata **tidak** menerbitkan `period_config` (hanya
  `discipline_config`), jadi `/admin/sports` akan bind ke `undefined` sementara
  `Catalog::sports()` sudah lama mengirimnya. Ditambahkan sebagai `null` — bukan
  `{}` — untuk cabang berskor set: bedanya dibaca form.
- `useMatchClock()` menurunkan menitnya **saat render**, bukan menyimpannya di
  state; `setInterval` cuma menaikkan penghitung detak. Versi pertama menulis
  `setSeconds()` di dalam effect dan `react-hooks/set-state-in-effect`
  menolaknya dengan benar: angka yang disimpan basi satu detik setelah ditulis,
  dan menjaganya tetap sejalan dengan `clock` dari polling butuh effect kedua.
  Alasan yang sama dengan larangan bermain yang diturunkan, bukan disimpan.
- `PublicEventController::match()` ikut di-eager-load `category.event`: blok
  `clock` membaca `rules_config` lewat kategori, dan `preventLazyLoading` tidak
  menyala — N+1-nya akan jalan tanpa suara. Endpoint inilah yang dipolling papan
  skor tiap 10 detik.
