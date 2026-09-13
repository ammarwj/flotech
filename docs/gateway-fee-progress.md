# Progress: fee gateway & fee platform dibebankan ke pembeli

`platform_fee_percent` (potongan dari dompet organizer, plan-tiered 3%/2%/1%) diganti dua
komponen buyer-paid dihitung per channel Midtrans sebelum Snap dibuat: **fee gateway**
(`config/payment_fees.php`, angka riil per channel) + **fee platform**
(`PlatformSettings::service_fee_percent`, margin admin-editable). Berlaku juga untuk
pembelian **paket event** (§11), bukan cuma tiket/pendaftaran.

- **Branch**: `master`
- **Plan lengkap**: `/Users/ammar/.claude/plans/di-projek-ini-menggunakan-bright-zephyr.md`
  (12 langkah, §1–§11 = rancangan, §12 = verifikasi akhir).

> ⚠️ DB dev `flo_event` adalah **salinan produksi**. Jangan pernah `migrate:fresh`,
> `migrate:refresh`, atau `db:wipe`. `php artisan migrate` (maju) aman; `php artisan test`
> aman (sqlite in-memory).

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer
`Co-Authored-By:` atau `Claude-Session:`.

---

## Selesai (Langkah 1–9)

- [x] `config/payment_fees.php` (VA + ewallet `enabled: true`, retail `enabled: false`)
- [x] `PlatformSettings::service_fee_percent` (+ `config/payments.php` default, `/admin/settings`
      input desimal + label `%`)
- [x] **Fee platform dipecah dua** (permintaan menyusul, bukan bagian rencana asli):
      `service_fee_percent` = "Fee platform ke peserta" (tiket & pendaftaran — peserta bayar
      ke organizer), `plan_service_fee_percent` = "Fee platform ke organizer" (pembelian
      paket — organizer bayar ke platform). Dua pihak berbeda, jadi dua tuas berbeda; salah
      satu boleh 0 sementara yang lain tidak.
      **`PaymentFeeCalculator::forChannel()/allChannels()` menerima `$audience` tanpa
      default** (`AUDIENCE_PARTICIPANT`/`AUDIENCE_ORGANIZER`) — alur pembayaran baru
      terpaksa menyatakan ia di sisi mana, tidak bisa diam-diam mewarisi tarif sisi lain.
      Fee gateway-nya sendiri **tidak** dibedakan (itu biaya bank, bukan margin kita).
      Frontend: prop `audience` di `ChannelPicker` juga required, dan ikut masuk query key.
- [x] `PaymentFeeCalculator` (`forChannel()`, `allChannels()`) — tanpa `round()` di mana pun
- [x] `PublicPaymentController::channels()` + route `GET public/payment-channels`
- [x] `PublicEventResource::requires_payment_channel`
- [x] Migration `payment_channel`/`gateway_fee`/`service_fee` di `ticket_orders` **dan** `teams`
      + `$fillable` + accessor `gross_amount` (kedua model)
- [x] `MidtransService::createSnapTransaction()` param ke-4 `$enabledPayments`
- [x] `TicketService::purchase()` — hapus `platformFee()`, breakdown per channel,
      `PublicTicketController::purchase()` validasi `payment_channel` (required saat gateway
      + amount > 0)
- [x] `RegistrationService::startPayment()` — pola sama, dipanggil dari
      `PublicEventController::register()` **dan** `MyTeamController::pay()`
- [x] Migration data hapus baris `platform_fee_percent` dari `plan_features` +
      `feature_definitions`; `FeatureDefinitionSeeder`/`PlanSeeder` — entri dihapus;
      `PlanGate::LOWER_IS_BETTER` → `[]` (dikonversi jadi `private static array $lowerIsBetter`
      supaya bisa di-reflect di test); `pricing.tsx` — `feeFootnote()` dihapus
- [x] **9 file test** disentuh (rencana awal 8 + 1 ditemukan saat suite penuh):
      `ManualPaymentTest`, `PerEventPlanTest`, `RefundTest`, `WalletLedgerTest`,
      `WalletReleaseTest`, `WalletTest`, `PlanUpgradeTest` (+ test baru khusus
      `PlanGate::$lowerIsBetter` via `ReflectionProperty`, key sintetis `discount_percent`),
      `CreatesPlannedEvents`, dan **`RegistrationTest`** (tidak ada di daftar rencana —
      `test_paid_event_registration_awaits_payment` baru ketahuan butuh `payment_channel`
      saat suite penuh dijalankan, bukan saat file itu sendiri di-grep untuk
      `platform_fee_percent`)
- [x] **Suite penuh hijau**: `535 passed (3339 assertions)`, 0 gagal (2026-09-13)

**Belum disentuh dari §10** (test baru, bukan file lama): `PaymentFeeCalculatorTest`,
`GatewayFeeTest` (bandingkan VA vs ewallet, wallet net = `total_price` persis, manual
null/0, channel disabled → 422). Formula sudah dibuktikan tidak langsung lewat test lama
di atas (mis. `WalletTest`), tapi belum ada test yang membandingkan dua channel secara
langsung atau menguji channel disabled.

## Selesai (Langkah 10 — Frontend channel picker)

- [x] `web/lib/api/payments.ts` (baru) — `getPaymentChannels(amount)`
- [x] `web/components/payment/channel-picker.tsx` (baru) — button list bergaya sama dengan
      picker kategori tiket (tidak ada primitive `RadioGroup` di `components/ui`), fetch
      channel sendiri lewat `useQuery(["payment-channels", amount], ...)`
- [x] Dipasang di 3 tempat: `tickets/page.tsx`, `register/page.tsx`,
      `participant/teams/[id]/page.tsx` — semuanya reset channel terpilih saat kategori/
      pilihan lain berubah, dan tombol utama disabled sampai channel dipilih saat
      `requires_payment_channel && amount > 0`
- [x] `web/types/api.ts` — `requires_payment_channel: boolean` di **kedua** `PublicEvent`
      dan `SportEvent`
- [x] `web/lib/api/tickets.ts` (`PurchasePayload`), `web/lib/api/events.ts`
      (`RegisterTeamPayload`, `payRegistration(teamId, channel?)`) — `payment_channel?: string`
- [x] `/admin/plans` — dikonfirmasi via grep: baris "Fee platform (%)" hanya tersisa di
      migration seed lama (histori) dan migration hapusnya; tidak ada di seeder aktif
- [x] `bunx tsc --noEmit` bersih di `web/`

**Ditemukan saat implementasi, tidak ada di rencana awal §10**: `SportEvent` (dipakai
`Team.event` → halaman "bayar ulang" partisipan) diserialisasi lewat `EventResource`, **bukan**
`PublicEventResource` — jadi `requires_payment_channel` juga ditambahkan ke
`api/app/Http/Resources/EventResource.php`, bukan cuma resource publik. Dua resource,
dua jalur serialisasi terpisah untuk perhitungan `PaymentRails::methodFor()` yang sama;
keduanya sekarang menjawab pertanyaan yang sama secara independen.

### Langkah 11 — `EventPlanOrder` (pembelian paket ikut kena fee)
- [x] Migration `payment_channel`/`gateway_fee`/`service_fee` di `event_plan_orders` +
      accessor `getGrossAmountAttribute()`
- [x] `EventPlanOrderService::checkout()`/`checkoutUpgrade()`/`pay()` — param `?string $channel`,
      breakdown saat rail gateway, `openSnap()` pakai `gross_amount` + `enabled_payments`
      — **`amount` mentah TIDAK berubah** (dipakai `paidTowardsPlan()`, tidak menagih fee
      gateway checkout pertama lagi di upgrade berikutnya)
- [x] `PlanOrderController` — validasi `payment_channel` hanya saat `! $rails->gatewayIsDown()`
- [x] `web/lib/api/organizations.ts` — `checkoutPlan()`/`payPlanOrder()`/`upgradePlanOrder()`
      tambah `payment_channel?: string`
- [x] Pasang `channel-picker.tsx` di tiga alur organizer, semuanya gate pakai
      `org.payment_gateway_enabled` dari `useActiveOrg()` (bukan endpoint publik baru),
      dialog "pilih channel dulu" baru submit saat manual langsung fire tanpa dialog:
      - `organizer/plans/page.tsx` — checkout paket baru
      - `organizer/billing/page.tsx` — retry bayar tagihan `past_due`
      - `components/subscription/plan-upgrade-dialog.tsx` — upgrade (dipakai dari
        `organizer/billing` **dan** `event-plan-panel.tsx`; komponen ini panggil
        `useActiveOrg()` sendiri karena salah satu pemanggilnya cuma punya `orgId`)
      - `components/event/plan-purchase-notice.tsx` — checkout inline di halaman
        **Buat Event** (`/organizer/events/new`). **Ketinggalan di sapuan pertama**:
        `checkoutPlan` punya *empat* pemanggil, bukan tiga, dan yang ini tidak ada di
        rencana §11. Gejalanya toast "Pilih metode pembayaran." tanpa satu pun pilihan
        di layar — validasi server jalan, pickernya yang tidak ada. Kalau menambah
        surface pembayaran baru, grep `checkoutPlan|payPlanOrder|upgradePlanOrder` dulu.
- [x] `api/resources/views/pdf/_document.blade.php` — baris "Biaya layanan" / "Biaya payment
      gateway" / "PPN" masing-masing kondisional (`> 0`), urut sama dengan channel picker,
      Total pakai `gross_amount`; baris item paket tetap `amount` (harga paket murni)
- [x] Kolom `event_plan_orders.gateway_tax` (migration `2026_09_13_140000`) — **PPN
      di-snapshot, bukan dihitung ulang saat render**. `gateway_fee` sudah termasuk pajak,
      jadi baris "Biaya payment gateway" di PDF = `gateway_fee - gateway_tax`. Kalau PPN
      dihitung ulang dari `config/payment_fees.php` saat PDF dibuka, mengganti tarif akan
      diam-diam menulis ulang PPN di **semua** invoice lama — alasan yang sama kenapa
      `payment_method`/`platform_fee` di-snapshot per order. Baris lama = 0 → barisnya
      tidak dirender sama sekali (tampilan identik dengan sebelumnya).
      **`gross_amount` TIDAK menambahkan `gateway_tax`** (itu bagian dari `gateway_fee`,
      bukan di atasnya) — ada assert khusus untuk itu di `PlanOrderBillingTest`.
- [x] `PlanOrderBillingTest` — 2 test baru (`test_gateway_checkout_charges_the_buyer_a_fee_while_manual_does_not`,
      `test_an_upgrades_gateway_fee_does_not_compound_into_the_next_upgrades_price`), plus
      test lama disesuaikan (`payment_channel: 'va'` param) di `OrganizationTest` &
      `IdlePlanCreditTest`

### Tambahan — visibilitas super admin (di luar rencana asli)
- [x] **Pembelian paket lewat gateway sebelumnya tidak terlihat di halaman admin manapun.**
      `/admin/plan-orders` punya tiga tab yang **semuanya** bersandar pada kolom khusus
      transfer manual (`awaitingVerification()` dan `whereNotNull('verified_at')`) — order
      gateway tidak pernah mengisi keduanya, jadi masuk ke nol daftar. Ditambah
      `GET admin/plan-orders/purchases` (`whereNotNull('paid_at')`, opsional `?method=`)
      + tab "Semua pembelian". Judul halaman **dan menu sidebar** diganti dari "Verifikasi
      Pembelian" jadi "Pembelian Paket" — halamannya bukan cuma antrean verifikasi lagi.
      Tab ini **berpaginasi** (pola `Paginated<T>` yang sama dengan `/admin/users`), beda
      dari tiga tab lain yang sengaja di-cap tanpa halaman: tab lain itu antrean yang
      dikerjakan habis, yang ini ledger yang cuma bertambah. Filter: cari (nomor invoice/
      kwitansi/order Midtrans/nama organisasi lewat `Search::anyColumn` — **bukan `LIKE`
      polos**, itu case-sensitive di Postgres tapi tidak di sqlite, jadi bug-nya cuma
      muncul di produksi), metode, channel, dan rentang `paid_at`. Dropdown channel
      di-disable saat metode = manual (channel cuma ada di rail gateway).
      Test-nya **membandingkan** dengan endpoint `history` pada data yang sama — daftar
      yang cuma mengembalikan baris manual tetap terlihat terisi kalau diuji sendirian.
- [x] `/admin/payments` (pembayaran peserta) menampilkan `payment_method`/`payment_channel`
      + `gateway_fee`/`service_fee`/`gross_amount`. Sebelumnya cuma `platform_fee`, yang
      untuk order gateway baru **selalu 0** — jadi halaman itu seolah berkata tidak ada fee
      yang dipungut. `platform_fee` tetap dirender khusus untuk baris lama yang nilainya
      masih > 0 ("potongan lama").
- [x] `EventPlanOrderResource` menerbitkan `payment_channel`/`gateway_fee`/`gateway_tax`/
      `service_fee`/`gross_amount`
- [x] **Empat template email masih menampilkan harga tanpa fee** — badan email menyebut
      angka berbeda dari PDF yang dilampirkannya sendiri di pesan yang sama:
      `plan-order-invoice-issued` & `plan-order-paid` (`$order->amount` → baris rincian +
      `gross_amount`, urut & syarat identik dengan `_document.blade.php`),
      `ticket-purchased` (`total_price`) & `registration-paid` (`payment_amount`) →
      "Harga/Biaya … + Biaya pembayaran + Total dibayar". Dua yang terakhir memakai **satu
      baris fee gabungan**, bukan PPN terpisah: `ticket_orders`/`teams` tidak punya kolom
      `gateway_tax` (cuma `event_plan_orders` yang punya), jadi memecahnya di sana berarti
      mencetak angka yang tidak ada sumbernya.
      Test-nya assert **gross ada DAN harga polos tidak dipakai sebagai total** — assert
      "gross muncul" saja akan lolos walau baris totalnya masih salah.

### Langkah 12 — Verifikasi akhir
- [x] `bunx tsc --noEmit` — bersih
- [x] `eslint` (file yang berubah) — bersih
- [x] `bun run build` — bersih
- [x] Suite backend penuh sesudah Langkah 11: **537 passed (3344 assertions)**, 0 gagal
      (2026-09-13)
- [ ] Checklist manual end-to-end lengkap ada di bagian "Verifikasi" plan file (10 langkah,
      dari set `service_fee_percent` di `/admin/settings` sampai matikan
      `payment_gateway_enabled` dan cek checkout paket jatuh ke manual) — butuh
      `bun run dev` + sandbox Midtrans, belum dijalankan

---

## Catatan implementasi penting (biar tidak diulang)

- **`PlanGate::LOWER_IS_BETTER` sekarang `static array`, bukan `const`** — sengaja, supaya
  test bisa `ReflectionProperty::setValue()` key sintetis. `PlanGate::flush()`
  **tidak** menyentuhnya (itu cache per-request produksi); test yang inject wajib
  restore sendiri (lihat `PlanUpgradeTest::test_plancovers_treats_a_declared_lower_is_better_key_as_backwards`).
- **`payment_channel` wajib di payload test** begitu event/registrasi lewat rail gateway —
  endpoint tiket **dan** registrasi publik sama-sama menolak 422
  (`"Pilih metode pembayaran."`) tanpanya. Kalau menambah test baru yang membeli
  tiket/mendaftar tim di event ber-plan `payment_gateway: true` dengan harga > 0, sertakan
  `'payment_channel' => 'va'` dari awal.
- **`platform_fee` kolom tidak dihapus**, selalu ditulis `0` untuk order gateway baru —
  jangan menghapusnya dari migration/model, dan jangan kaget melihatnya masih ada di
  `$fillable`/schema.
