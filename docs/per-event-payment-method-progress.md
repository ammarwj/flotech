# Progress: metode pembayaran per event

Rail pembayaran tidak lagi ditentukan satu switch global. **Tiap event memilih sendiri**
gateway (Midtrans) atau transfer manual; switch super_admin berubah peran dari *penentu*
jadi **override** — dimatikan, semua event dipaksa manual.

- **Branch**: `master`
- **Baseline sebelum perubahan** (2026-09-12): backend `521 passed / 0 failed`,
  e2e `38 + 1 pre-existing failure`.

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer
`Co-Authored-By:` atau `Claude-Session:`.

> ⚠️ DB dev `flo_event` adalah **salinan produksi**. Jangan pernah `migrate:fresh`,
> `migrate:refresh`, atau `db:wipe`. `php artisan migrate` (maju) aman; `php artisan test`
> aman (sqlite in-memory).

---

## Keputusan produk (dikonfirmasi user 2026-09-12)

| | Pilihan |
|---|---|
| Cakupan | **Per event**, bukan per organisasi — sejalan dengan invarian paket-milik-event |
| Pilih gateway tanpa entitlement `payment_gateway` | **Tetap ditolak**, tidak ada fallback diam-diam ke manual |
| Pembelian paket | **Tidak berubah** — `platformDestination()` tetap planless, hanya membaca switch global |
| Pilih manual tanpa rekening primer | **Ditolak dua kali**: 422 saat menyimpan event + guard existing di `destinationFor()` |

> **Konsekuensi bisnis yang disadari.** CLAUDE.md sebelumnya menolak pilihan organizer
> dengan alasan: manual tidak bisa dipotong fee, jadi kalau boleh memilih tidak ada yang
> mau memakai gateway. Perubahan ini membuat pendapatan `platform_fee_percent` bergantung
> pada organizer yang memilih gateway. CLAUDE.md ditulis ulang agar **menyatakannya
> terbuka**, bukan agar terdengar seperti bukan masalah.

---

## Invarian

> **1. Switch global menimpa, tidak berdamai.** Kombinasi kedua aturan hidup **sekali**,
> di `PaymentRails::methodFor(Event)`. Event yang memilih gateway tidak dapat pengecualian
> dari outage.

> **2. `EventResource` menerbitkan hasilnya**, `effective_payment_method`, di samping
> `payment_method` mentah — klien tidak pernah menggabungkan sendiri. Dua pembaca aturan
> yang sama akan berselisih dan selisihnya tak terlihat (bentuk bug yang sama dengan dua
> pembaca `stage`).

> **3. Snapshot per order bikin kolomnya tidak perlu dikunci.** Beda dari
> `participant_type`: tiap order membawa `payment_method`, `platform_fee`,
> `payment_deadline_at`, dan rekening tujuannya sendiri, jadi berpindah rail tidak
> menyentuh apa pun yang sudah terbit.

> **4. Kolomnya non-null `default 'gateway'`, bukan nullable.** "Ikut default" harus ikut
> *sesuatu*, dan tidak ada setelan "metode default event" — switch global itu override.
> `null` dan `'gateway'` akan berperilaku identik selamanya: state ketiga yang tiap
> pembaca baru harus menjawabnya (kelas bug `stage IS NULL`). Bonus: event lama
> berperilaku persis sama tanpa backfill.

---

## Tahap 1 — Migration + model  ✅

- [x] `2026_09_12_100000_add_payment_method_to_events_table` — `string(10) default 'gateway'`
      setelah `plan_id`, tanpa index (tidak ada query yang memfilter `events` atasnya)
- [x] `Event::$fillable`
- [x] Dibuktikan mereproduksi perilaku hari ini: `ManualPaymentTest|ManualPlanOrderTest|EventTest|PerEventPlanTest`
      **52 lulus sebelum satu pun file test disentuh**

## Tahap 2 — `PaymentRails`  ✅

- [x] `isManual()` → **rename** `gatewayIsDown()`. Bukan estetika: `$rails->isManual()`
      tanpa argumen terbaca "apakah pembayaran ini manual?" dan jawabannya **salah** untuk
      event yang memilih manual saat gateway nyala
- [x] `methodFor(Event)` publik — satu-satunya tempat kedua aturan digabung
- [x] `destinationFor()` memakainya; cabang manual melempar **dua pesan berbeda**
      (outage = sementara & bukan salah siapa-siapa, rail terpilih tanpa rekening = setup
      yang organizer masih berutang padanya)
- [x] `platformDestination()` hanya ganti nama method — tidak ada perubahan perilaku
- [x] Deskripsi `PlatformSettings::DEFINITIONS['payment_gateway_enabled']`

Kontrak **"rekening non-null = manual" utuh**, jadi `PublicTicketController` dan
`RegistrationService` **nol perubahan**.

## Tahap 3 — Validasi  ✅

- [x] `payment_method` di `StoreEventRequest` + `UpdateEventRequest`, keduanya `sometimes`
- [x] `EventController::paymentMethodFor()` — 422 + field path, **tanpa** `errors.feature`
      (dengan itu `isPlanLimitError()` menelannya jadi toast dan select-nya tidak pernah merah)
- [x] Dipasang di `store()` **di dalam transaksi** setelah `claimOrder()` — paketnya baru
      diketahui di sana, dan penolakan mem-rollback sehingga kredit tidak terbakar
- [x] Dipasang di `update()` di balik `array_key_exists`

> **`array_key_exists` load-bearing, bukan kehati-hatian.** Tanpanya, org yang memilih
> manual lalu menghapus rekeningnya di `/organizer/wallet` (`BankAccountController::destroy`
> hanya memblokir saat ada penarikan berjalan — ia tidak tahu apa-apa soal event) **tidak
> bisa lagi mengganti nama eventnya**: 422 di field yang tidak ada di layar yang sedang ia isi.

## Tahap 4 — `EventResource`  ✅

- [x] `payment_method` + `effective_payment_method`
- [x] **Tidak** ditambahkan `has_bank_account` (sudah di `OrganizationResource`, dan
      `useActiveOrg()` ada di tiap halaman organizer — satu query per event untuk boolean
      yang identik di semua baris)
- [x] **Tidak** ditambahkan ke `PublicEventResource`, sengaja: halaman publik tahu rail dari
      **snapshot order**. Menerbitkan niat event ke publik menambah pembaca kedua yang bisa
      berselisih dengan snapshot tepat saat switch dibalik di tengah checkout

## Tahap 5 — Test backend  ✅ (9 test baru)

`ManualPaymentTest` (helper `event()` dapat parameter kedua `$method = 'gateway'`, jadi
**enam test existing lolos tanpa diubah sebaris pun**):

- [x] dua event satu org **di paket yang sama** turun rail berbeda — test utama; bandingkan
      `payment_method`, `bank_account`, `platform_fee`, `payment_deadline_at`, dan
      `wallet_transactions` org = **tepat 1**
- [x] switch global menimpa event yang memilih gateway (event yang sama, dibeli dua kali)
- [x] gateway tanpa entitlement → 403, sementara event manual **di paket yang sama** terjual
- [x] manual tanpa rekening primer → 422 meski gateway nyala

`EventTest`:

- [x] simpan manual tanpa rekening → 422; buat rekening, **payload identik** → 201
- [x] penolakan tidak membakar kredit paket
- [x] gateway di paket tanpa entitlement → 422 (**bukan** 403, **tanpa** `errors.feature`)
- [x] ganti rail tidak menyentuh order yang sudah diambil
- [x] edit field lain tidak memvalidasi ulang `payment_method`

> **Jebakan PHP yang menggigit di sini.** `orgWithPlan()` menggabung dengan
> `$features = [...] + $features` — `+` **menyimpan operand kiri**, jadi override
> `['payment_gateway' => 'false']` diam-diam dibuang dan testnya 201 alih-alih 403.
> Sekarang plan-nya diupdate langsung, dengan komentar yang menyebut jebakannya.

## Tahap 6 — Frontend  ✅

- [x] `types/api.ts` — dua field di `SportEvent`
- [x] `lib/api/events.ts` — `payment_method` di `EventInput`
- [x] `lib/plan.ts` — `planAllowsGateway(PlanSummary)`, **plan-keyed** (cermin
      `PlanGate::planAllows(?Plan)`): pemanggilnya form yang gating sebelum event ada
- [x] `event-form.tsx` — `<Select>` dua opsi di kartu "Detail Event", tepat setelah
      "Zona waktu"; empat keadaan (normal / gateway mati / paket tanpa gateway / tanpa rekening)
- [x] `plan ?? initial?.plan` — halaman edit tidak mengoper prop `plan` padahal `ev.plan` ada
      di payload. Efek samping yang diinginkan: cap kategori/peserta mulai berlaku proaktif
      di halaman edit juga, yang selama ini diam

> **Jebakan paling berbahaya di seluruh perubahan ini.** Saat gateway mati, select yang
> disabled **menampilkan** "Transfer manual" tapi `handleSubmit` tetap mengirim
> `v.payment_method` yang **tersimpan**. Kalau nilai tampilan ikut terkirim, satu outage
> menulis ulang setiap event yang disimpan selama itu jadi manual — permanen, tanpa error,
> baru ketahuan setelah gateway menyala dan fee berhenti masuk.

> **E2E menangkap satu regresi yang test unit tidak akan lihat.** Fallback `activePlan`
> membuat hint cap di form merender "Paket Pro: maks …" di halaman edit — tepat di bawah
> `EventPlanPanel` yang sudah menyebut hal yang sama, jadi `getByText("Paket Pro")` kena
> dua elemen. Gating tetap memakai `activePlan`; **paragraf hint-nya** dikembalikan ke prop
> `plan` supaya cuma muncul di halaman Buat Event.

- [x] `manual-mode-banner.tsx` **tidak diubah** — banner itu tentang **outage**, dan saat
      outage pesannya justru makin tepat. Memberinya pengetahuan per-event berarti mem-fetch
      daftar event di setiap halaman untuk satu banner, dan satu banner dengan dua arti
      berhenti bisa dipercaya
- [x] `/organizer/events/{id}/payments` **tidak diubah** — teks cabang "gateway nyala" tidak
      menyebut penyebab, jadi sudah benar untuk event yang memilih manual

## Tahap 7 — `/admin/settings`  ✅

- [x] Hanya paragrafnya. Judul kartu `"Semua organizer akan memakai transfer manual"`
      **verbatim** — `e2e/specs/platform-settings.spec.ts` meng-assert-nya di L32 & L37

## Tahap 8 — CLAUDE.md  ✅

- [x] Section di-rename → `## Pola: metode pembayaran per event & transfer manual`
- [x] Paragraf "Ini bukan pilihan org." ditulis ulang; konsekuensi fee dinyatakan terbuka
- [x] Invarian baru: *switch global menimpa, tidak berdamai*
- [x] Paragraf snapshot per order dapat klausa kenapa kolomnya tidak perlu dikunci
- [x] Bullet `PaymentRails` diperbarui (dua pesan, event-keyed dua kali, `gatewayIsDown` vs `methodFor`)
- [x] Bullet baru: penolakan ganda yang disengaja

**Dibiarkan (sengaja):** entri `FaqSeeder` L46 dan footnote `pricing.tsx` L119 menjelaskan
**outage** dan masih benar — jadi tidak lengkap, bukan salah. Memperbaruinya butuh migrasi
copy bergaya `2026_08_02_110001` (match teks lama supaya suntingan super_admin tidak
tertimpa); layak saat fiturnya dipasarkan, tidak layak masuk diff ini.

---

## Verifikasi penutup (2026-09-12)

| | Hasil |
|---|---|
| Backend | **530 lulus / 0 gagal** (3329 assertions) |
| `ManualPlanOrderTest` | hijau **tanpa file-nya disentuh** — bukti `platformDestination()` tidak tersentuh |
| `bunx tsc --noEmit` + `eslint` | bersih |
| `bun run build` | sukses |
| E2E | **38 lulus / 1 gagal** |

> **Satu kegagalan e2e itu pre-existing**, bukan dari perubahan ini:
> `plan-order-manual.spec.ts:27` mencari `/Uangnya masuk ke rekening flo-event/` di
> `/admin/plan-orders`, dan string itu **tidak ada di `web/` sama sekali**. Diverifikasi
> dengan menjalankan spec yang sama di `git stash` — gagal identik di tree bersih.

> **18 kegagalan pada percobaan pertama itu palsu**: `MIDTRANS_SERVER_KEY` tidak ada di
> environment shell. Kredit paket disetel lewat webhook Midtrans dan signature-nya butuh
> key itu — sudah tercatat di `e2e/README.md`. Jalankan dengan
> `MIDTRANS_SERVER_KEY=$(grep '^MIDTRANS_SERVER_KEY=' api/.env | cut -d= -f2-) bun run test`.

## Belum dikerjakan

- [ ] **Manual di paket tanpa `payment_gateway` sekarang bisa jualan.** Sebelum ini paket
      tanpa key itu = event tidak bisa menerima uang sama sekali; sesudah ini ia bisa
      memilih manual dan menjual dengan fee 0. Praktisnya nol dampak — ketiga paket di
      `PlanSeeder` memberi `payment_gateway`. Kalau mau ditutup: tiga baris di cabang manual
      yang menolak **hanya saat bukan outage** (organizer tidak boleh dihukum karena
      Midtrans mati).
- [ ] Verifikasi manual end-to-end di stack yang jalan (beli tiket di dua event satu org,
      unggah bukti, acc, cek dompet) — langkah 1–8 di bagian Verifikasi rencana.
