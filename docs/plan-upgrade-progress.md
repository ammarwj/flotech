# Progress: upgrade paket

Menambah **upgrade paket** ke model billing per-event. Downgrade **tidak** disediakan.

- **Branch**: `feat/per-event-billing` (lanjutan; billing per-event-nya sudah selesai — lihat `per-event-billing-progress.md`)
- **Baseline sebelum perubahan** (2026-08-02): backend `437 passed / 0 failed`, e2e `39 + 2 passed`.

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer `Co-Authored-By:` atau `Claude-Session:`.

> ⚠️ DB dev `flo_event` adalah **salinan produksi**. Jangan pernah `migrate:fresh`, `migrate:refresh`, atau `db:wipe`. `php artisan migrate` (maju) aman; `php artisan test` aman (sqlite in-memory).

---

## Keputusan produk (dikonfirmasi user 2026-08-02)

| | Pilihan |
|---|---|
| Harga | **Bayar selisihnya saja** — `target.price − amount yang sudah dibayar` |
| Cakupan | **Event yang sudah jalan _dan_ kredit lunas yang belum dipakai** |

Selisih dipilih supaya beli-lalu-upgrade **sama persis** dengan beli target langsung
(Starter 150rb + upgrade 200rb = Pro 350rb) — tidak ada celah arbitrase, dan tidak ada
insentif untuk menahan diri lalu menabrak batas paket.

---

## Invarian

> **1. Upgrade wajib monoton.** Paket target harus memberi **≥** setiap fitur yang
> diberi paket sekarang: boolean `true` harus tetap `true`, numerik harus ≥ (dengan `-1`
> = unlimited sebagai nilai tertinggi). **Urutan harga saja tidak cukup** — katalog bisa
> diedit super_admin di `/admin/plans`, jadi paket yang lebih mahal bisa saja kehilangan
> satu fitur, dan "upgrade" yang diam-diam mencabut galeri dari event yang sudah punya 15
> foto adalah kelas bug yang sama dengan mengunci `participant_type`. Pemeriksaan ini
> sekaligus yang **membuat downgrade mustahil** — bukan aturan terpisah yang bisa lupa
> dipasang.

> **2. Order yang sudah di-upgrade bukan kredit lagi.** Organizer cuma membayar
> selisihnya, jadi uang order lama sudah habis terpakai untuk event/kredit ini.
> `scopeUnconsumed()` wajib mengecualikannya. Diturunkan dari **baris penerusnya**
> (`whereDoesntHave('upgrade')`), bukan flag di baris itu sendiri, supaya keduanya tidak
> mungkin berselisih.

> **3. Beda dengan `reassign-plan`, dan bedanya penting.** Reassign menukar dua kredit
> yang **sama-sama sudah dibayar penuh**, jadi melepas yang lama kembali ke kolam memang
> benar. Upgrade tidak — melepasnya kembali berarti memberi satu event gratis. Jangan
> menyalin pola reassign ke sini.

> **4. Dokumen tidak boleh berubah isinya.** `plan_id` dan `amount` order lama **tidak**
> disentuh: invoice-nya tetap jujur berbunyi "Starter Rp 150.000". Order upgrade adalah
> dokumen terpisah dengan nomor invoice/kwitansinya sendiri, berisi paket target dan
> **selisihnya**. Karena itu tidak ada kolom snapshot nama paket yang perlu ditambahkan.

> **5. Penerus yang memegang entitlement.** Saat lunas, order upgrade yang menjadi
> pemegang event (atau kredit); yang lama pensiun. Ini yang membuat kasus "event sudah
> jalan" dan "kredit belum dipakai" jadi satu jalur kode, bedanya cuma memindahkan
> `event_id` atau tidak.

---

## Skema

Satu kolom: `event_plan_orders.upgrade_of_id` — nullable uuid, FK ke dirinya sendiri
(`nullOnDelete`), ber-index. Diisi **saat checkout** (niatnya sudah pasti sejak awal),
bukan saat lunas.

---

## Tahapan

### Tahap 1 — Skema + inti service  ✅
- [x] Migrasi `add_upgrade_of_to_event_plan_orders_table`
- [x] `EventPlanOrder`: fillable, relasi `upgradeOf()` / `upgrade()`, `scopeUnconsumed()` + `whereDoesntHave('upgrade', paid)`
- [x] `PlanGate::planCovers(?Plan $current, Plan $target): bool` — pemeriksaan monoton (invarian 1)
- [x] `EventPlanOrderService::checkoutUpgrade(EventPlanOrder $order, Plan $target)` — hitung selisih, tolak ≤ 0, tolak non-monoton, tolak order yang sudah punya penerus
- [x] `activate()` menerapkan upgrade saat lunas (pindahkan `event_id`, set `events.plan_id`)

### Tahap 2 — Rute + resource  ✅
- [x] `POST organizations/{org}/plan-orders/{planOrder}/upgrade` di bawah `tenant` + `org.admin`
- [x] `GET .../plan-orders/{planOrder}/upgrade-options` — paket yang lolos uji monoton + selisihnya
- [x] `EventPlanOrderResource`: `upgrade_of_id`, `superseded` (turunan)
- [x] `PlanOrderController` menolak `pay()` untuk order yang sudah pensiun

### Tahap 3 — Test  ✅ *(12 test baru; suite 449 lulus / 0 gagal)*

> **Dua bug tertangkap testnya sendiri, dan keduanya diam.**
> 1. `PlanFeatureException` mewajibkan argumen `$errors`; empat lemparan di
>    `checkoutUpgrade()` cuma mengirim pesan → **500**, bukan 403. Tanpa test
>    komparatif yang meng-assert 403, ini lolos sebagai "penolakan berhasil".
> 2. `hasOne(...)->latestOfMany()` **tidak bisa** dipakai `whereDoesntHave` —
>    relasi one-of-many membawa subquery agregat yang pemeriksaan eksistensi
>    tidak bisa tembus, jadi pengecualiannya diam-diam tidak mencocoki apa pun
>    dan order lama tetap terhitung kredit. Diganti `hasMany`. Inilah kenapa
>    testnya menghitung **jumlah kredit**, bukan sekadar "ada order baru".
- [x] Komparatif: Starter→Pro boleh, Pro→Starter **ditolak**, Pro→Pro ditolak
- [x] Paket lebih mahal tapi kehilangan satu fitur **ditolak** (invarian 1, tidak akan tertangkap uji harga)
- [x] Order lama **tidak muncul lagi** sebagai kredit setelah upgrade lunas (invarian 2) — assert jumlah kredit, bukan cuma "ada order baru"
- [x] Event pindah paket, dan entitlement yang tadinya ditolak jadi diizinkan **di event yang sama**
- [x] Kredit menganggur di-upgrade lalu dipakai → event lahir dengan paket target
- [x] Selisih dihitung dari `amount` yang dibayar, bukan harga katalog lama (order backfill `amount` 0 → bayar penuh)
- [x] `activate()` upgrade idempoten (webhook dikirim ulang)
- [x] operator ditolak

### Tahap 4 — Frontend  ✅
- [x] `lib/api` + tipe
- [x] Tombol upgrade di `/organizer/billing` (kredit) dan di halaman event
- [x] `unconsumedOrders()` ikut mengecualikan order pensiun
- [x] Panel manual transfer ikut jalan untuk tagihan upgrade

> **Dua cacat lagi, keduanya cuma kelihatan di stack yang benar-benar jalan.**
> 3. **`platform_fee_percent` makin kecil makin bagus**, tapi uji monoton
>    memperlakukannya seperti kapasitas — jadi Starter (3%) → Pro (2%) terbaca
>    sebagai kehilangan dan **upgrade paling jelas di katalog ditolak**. Dua
>    belas test hijau tidak bilang apa-apa karena semuanya memakai paket buatan
>    sendiri yang tidak punya key fee. Sekarang ada `PlanGate::LOWER_IS_BETTER`,
>    dan satu test yang memakai **katalog sungguhan**.
> 4. **Upgrade berantai menagih terlalu mahal.** Selisih dihitung dari `amount`
>    order itu sendiri; setelah Starter→Pro pemegangnya cuma membawa top-up
>    200rb, jadi lompat ke Professional ditagih 600rb dan totalnya 950rb untuk
>    paket seharga 800rb. Sekarang `paidTowardsPlan()` menjumlahkan seluruh
>    rantai. Diverifikasi di stack: 150 + 200 + 450 = **800**.

### Tahap 5 — Docs  `[ ]`
- [ ] `CLAUDE.md` — invarian di atas
- [ ] Catatan rilis + FAQ ("Kalau event berikutnya butuh paket lebih besar" sekarang bisa dijawab self-serve)
