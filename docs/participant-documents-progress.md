# Progress: invoice & kwitansi untuk peserta

Peserta (pembeli tiket & manajer tim) kini dapat invoice dan kwitansi seperti organizer:
dikirim sebagai lampiran email, dan bisa diunduh sendiri.

- **Branch**: `master`
- **Rencana lengkap**: `/Users/ammar/.claude/plans/di-projek-ini-menggunakan-bright-zephyr.md`

> ⚠️ DB dev `flo_event` adalah **salinan produksi**. Jangan pernah `migrate:fresh`,
> `migrate:refresh`, atau `db:wipe`.

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer
`Co-Authored-By:` atau `Claude-Session:`.

---

## Selesai

- [x] Migration `2026_09_14_100000` — `invoice_number`/`receipt_number` (nullable, unique) di
      `ticket_orders` **dan** `teams`.
- [x] Migration `2026_09_14_110000` — **backfill** nomor untuk pembayaran lama. Awalnya
      sengaja dilewat dengan alasan "menerbitkan dokumen bertanggal mundur"; itu keliru di
      titik yang penting: uangnya **benar-benar berpindah**, jumlah & tanggalnya tercatat,
      dan pembayarnya bisa melihat pembayaran itu di dashboard. Membiarkannya kosong bukan
      menghindari mengarang dokumen — ia menahan dokumen untuk pembayaran yang sungguh
      terjadi. Garis batasnya `payment > 0`, **bukan** sebelum/sesudah deploy; entri gratis
      tetap tidak dapat apa-apa. Nomornya memakai periode `created_at`/`paid_at` baris itu
      sendiri (bukan hari ini), dan melanjutkan deret yang sudah terpakai supaya tidak
      bentrok. Idempoten.
- [x] `DocumentNumberService` — logika penomoran diangkat dari
      `EventPlanOrderService::nextNumber()`, yang sekarang **mendelegasikan** ke sana (bukan
      menyalin: dua penomor terpisah akan menyimpang, dan selisihnya tak terlihat sampai ada
      nomor ganda). `DB::transaction` + `lockForUpdate` pada baris tertinggi, **bukan
      `max()`** — Postgres menolak `FOR UPDATE` dengan agregat.
- [x] Prefix terpisah per aliran di `config/billing.php` (`INV-T`/`KW-T` tiket, `INV-R`/`KW-R`
      pendaftaran) supaya tiga ledger tidak berbagi satu deret nomor.
- [x] `_document.blade.php` dipecah jadi `@yield('issuer')`, `@yield('billed-to')`,
      `@yield('items')`, `@yield('footer-note')` — semuanya **punya default**, jadi dokumen
      paket tidak berubah sama sekali. Baris fee & Total tetap di layout (satu rumus, satu
      tempat).
- [x] `ParticipantDocumentService` + view `participant-invoice`/`participant-receipt`.
      **Penerbitnya organizer**, bukan platform — uang tiket/pendaftaran masuk ke dompet
      mereka. `organizations` cuma punya nama/email/telepon, jadi bloknya lebih ringkas
      daripada blok platform (tidak ada alamat/NPWP).
- [x] Nomor terbit di `TicketService::purchase()`/`markPaid()` dan
      `RegistrationService::startPayment()`/`markPaid()`. `startPayment()` memakai
      `?? ` supaya retry pembayaran **tidak membakar nomor invoice kedua** untuk tagihan yang
      sama; `markPaid()` memakainya supaya webhook Midtrans yang dikirim ulang tidak
      menerbitkan kwitansi kedua.
- [x] Endpoint: `GET ticket-orders/{order}/invoice|receipt` (**publik**) dan
      `GET my-teams/{team}/invoice|receipt` (`auth:api`, kepemilikan lewat `scope()` → 404).
- [x] Lampiran email: `TicketPurchasedMail::attachments()` (Mailable — pembeli belum tentu
      user) dan `RegistrationPaid::toMail()` (Notification). Invoice dulu, baru kwitansi.
- [x] Frontend: `ParticipantDocumentButtons` (satu komponen, **empat** surface) +
      `lib/api/participant-documents.ts`. Sisi peserta: `/tickets/{orderId}` dan
      `/participant/teams/{id}`. Sisi organizer: `…/tickets/buyers` dan
      `…/registrations` — merekalah yang menerbitkan dokumennya, jadi mereka juga yang
      bisa menjawab "tolong kirim ulang kwitansi saya" tanpa menyuruh peserta login.
      Endpoint mana yang dipanggil ditentukan `DocumentSubject`, bukan komponennya.
- [x] `GET events/{event}/registrations/{team}/invoice|receipt` — **route terpisah** dari
      `my-teams/{team}/…`, bukan dipakai ulang: yang ini di-scope lewat `tenant` (event),
      yang itu lewat sesi manajernya. Satu endpoint tidak bisa menjawab keduanya tanpa
      memilih salah satu. Dokumen tiket tidak perlu route baru — sudah publik.
      Beda kode penolakan, dan itu memang benar: organizer luar kena **403** dari
      middleware `tenant` (ditolak di level organisasi, sebelum barisnya dicari),
      sedangkan tim milik orang lain di `/my-teams` kena **404** (tidak ada dalam
      scope-nya, jadi keberadaannya tidak dikonfirmasi).
- [x] `ParticipantDocumentTest` (8 test) + suite penuh **551 passed**, `tsc`/`eslint`/`build`
      bersih.

---

## Keputusan yang menentukan bentuknya (jangan dibalik tanpa alasan)

- **Dokumen tiket sengaja publik.** Pembeli tiket tidak pernah mendaftar akun — order id yang
  tak tertebak adalah kredensialnya, persis seperti halaman e-tiket dan endpoint unggah bukti
  yang sudah ada. Memasang `auth:api` di sana akan mengunci dokumen dari orang yang paling
  butuh menyimpannya. Ada test khusus (`test_a_guest_buyer_can_download_their_own_documents`)
  yang gagal begitu `auth` menyelinap masuk.
- **Tidak ada halaman "Tiket Saya".** Sempat direncanakan, lalu dibatalkan: halaman itu hanya
  bisa menampilkan pembelian yang kebetulan dilakukan sambil login (`buyer_user_id`), jadi
  mayoritas pembeli justru tidak akan menemukan apa pun di sana.
- **Order gratis tidak pernah dapat nomor**, jadi tidak pernah dapat dokumen — kwitansi Rp 0
  mengklaim transaksi yang tidak pernah terjadi. Endpoint-nya 404. Ujilah dengan
  **membandingkan** order gratis vs berbayar di event yang sama.
- **PPN dipecah, setelah kolomnya ditambahkan** (migration `2026_09_14_120000`). Awalnya
  digabung jadi satu baris "Biaya pembayaran" karena `ticket_orders`/`teams` tidak punya
  `gateway_tax` — tapi itu memperlakukan gejala, bukan sebabnya:
  `PaymentFeeCalculator` **selalu** mengembalikan `gateway_tax`, nilainya cuma dibuang di
  jalan masuk. Sekarang di-snapshot seperti di `event_plan_orders`, dengan alasan yang sama:
  tarif PPN akan berubah, dan dokumen yang menghitung ulang saat dibuka akan diam-diam
  menyatakan ulang pajak di **semua** kwitansi lama.
  `$taxSplit` sekarang **diturunkan** dari `gateway_tax > 0`, bukan dipaku `false` — jadi
  baris lama (yang melapor 0) tetap merender satu baris gabungan seperti sebelumnya, tanpa
  "PPN Rp 0". Email `ticket-purchased`/`registration-paid` ikut sejalan; badan email yang
  menjumlah beda dari lampirannya sendiri lebih buruk daripada tanpa rincian.
- **Tim entri offline tetap dapat dokumen, tanpa email.** Tidak punya `manager_user_id`, dan
  `teams` menyimpan telepon bukan email. `markPaid()` tidak boleh melempar karenanya.

## Belum dikerjakan

- [ ] Verifikasi manual end-to-end dengan sandbox Midtrans (`bun run dev`): beli tiket tanpa
      login → cek tombol di `/tickets/{orderId}` + dua PDF di email; buka di jendela samaran;
      beli tiket gratis → tidak ada tombol & endpoint 404; event rail manual → invoice terbit
      sebelum lunas, kwitansi menyusul setelah organizer meng-acc bukti.
