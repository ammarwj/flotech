# Progress: billing per-event

Tracker pengerjaan migrasi dari langganan bulanan org-level ke **pembelian paket sekali bayar per event**.

- **Rencana lengkap**: `~/.claude/plans/no-item-glistening-leaf.md` (referensi §-nya disebut di tiap item)
- **Branch**: `feat/per-event-billing`
- **Baseline test sebelum perubahan** (2026-08-01): `412 passed`, 2–3 gagal yang **sudah gagal sejak awal** dan **tidak** disebabkan perubahan ini.
  - Dua `CatalogTest` (`public catalog lists sports and options`, `a sport added to the catalog can immediately host an event`) ternyata **bukan flaky, melainkan drift yang deterministik** — `SportSeeder` kedatangan `basketball` di commit `3b429a7` (2026-07-23) dan `ConfigOptionSeeder` kedatangan dua tiebreaker partai, tanpa testnya ikut diperbarui. **Sudah diperbaiki 2026-08-02**, lihat Tahap 10.
  - `KnockoutPlanTest > plan is saved in slots and read back with live occupants` memang flaky, **tapi bukan karena alasan yang tertulis selama ini** — dan **sudah diperbaiki 2026-08-02** juga. Lihat Tahap 10.

**Cara pakai.** Centang sambil jalan, commit tiap tahap. Kalau sesi terputus: buka file ini, cari checkbox tercentang terakhir, lanjut dari sana. Tiap item menyebut file konkret supaya posisi bisa diverifikasi dengan `git status` tanpa mengingat konteks apa pun.

Legenda: `[ ]` belum · `[~]` sedang dikerjakan · `[x]` selesai · `[-]` sengaja dilewati (tulis alasannya di sebelahnya)

Perintah: test backend `docker compose exec -T api php artisan test` · build web `cd web && bun run build`

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer `Co-Authored-By:` atau `Claude-Session:`.

> ## ⚠️ DB dev `flo_event` adalah SALINAN DATA PRODUKSI
>
> **Jangan pernah menjalankan `migrate:fresh`, `migrate:refresh`, atau `db:wipe` terhadap `flo_event`.** Itu menghapus salinan prod dan tidak ada undo di sini.
>
> - `php artisan test` **aman** — `phpunit.xml` memaksa `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`, terisolasi total dari Postgres.
> - `php artisan migrate` (maju) terhadap `flo_event` **aman dan memang perlu** — itulah uji sesungguhnya jalur upgrade prod.
> - Jalur `migrate:fresh --seed` untuk verifikasi silang **wajib** dijalankan di database sekali-pakai (mis. `flo_event_scratch`), bukan `flo_event`.
> - Ambil dump `flo_event` sebelum menjalankan migrasi baru untuk pertama kali, supaya bisa dikembalikan.

**Penahapan direvisi: tiap tahap wajib berakhir dengan test hijau.** Perbaikan test dikerjakan **di dalam** tahap yang memecahkannya, bukan ditumpuk ke Tahap 9. Alasannya: regresi jadi ketahuan di tahap penyebabnya. Konsekuensinya `tests/Concerns/CreatesPlannedEvents.php` lahir di **Tahap 3** (saat `PlanGate` benar-benar jadi event-keyed), bukan Tahap 1 — di Tahap 1–2 `organizations.plan_id` masih ada sehingga sebagian besar test lama masih sah. Tahap 9 menyusut jadi **test baru saja** (19 test komparatif + e2e).

---

## Status ringkas

| Tahap | Isi | Status |
|---|---|---|
| 0 | Setup tracker + branch | `[x]` |
| 1 | Skema + rename model | `[x]` |
| 2 | Katalog paket + backfill | `[x]` |
| 3 | `PlanGate` + call site backend | `[x]` |
| 4 | Siklus order + rute + resource | `[x]` (digabung ke Tahap 3) |
| 5 | Drop kolom paket di `organizations` | `[x]` |
| 6 | Exporter Excel/PDF + katup super_admin | `[x]` |
| 7 | Tipe frontend + `lib/plan.ts` + `lib/api` | `[x]` |
| 8 | Halaman frontend | `[x]` |
| 9 | Test backend + e2e | `[x]` *(e2e lulus penuh sejak 2026-08-02)* |
| 10 | Docs (`CLAUDE.md`) + verifikasi manual | `[x]` |

---

## Temuan kondisi DB prod (2026-08-01, sebelum migrasi apa pun)

Diperiksa dari salinan prod di `flo_event`. **Seeder di repo sudah menyimpang dari prod** — admin rupanya sudah menyetel katalognya lewat `/admin/plans`.

| | Seeder di repo | DB prod |
|---|---|---|
| Jumlah paket | 4 (`basic`, `starter`, `pro`, `professional`) | **3** — `basic` **tidak ada** |
| Harga | basic 49rb · starter 149rb · pro 399rb · professional 999rb | **150rb · 350rb · 800rb** — sudah = harga target |
| `feature_definitions` | 12 | 12 |
| `plan_features` | — | 31 baris |

Volume: `events` **100** · `organizations` **196** (176 punya paket) · `subscriptions` **32** (21 `active`).

Matriks fitur prod saat ini:

| key | starter | pro | professional | catatan |
|---|---|---|---|---|
| `max_active_events` | 1 | 1 | -1 | dipensiunkan |
| `max_teams_per_event` | 32 | 128 | -1 | **angkanya sudah = target**, tinggal ganti makna jadi per-kategori |
| `payment_gateway` | true | true | true | tetap |
| `qr_tickets` | true | true | true | tetap |
| `max_tickets_per_event` | 500 | 5000 | -1 | dipensiunkan |
| `ticket_fee_percent` | 3 | 2 | 1 | **sudah identik dengan** `registration_fee_percent` |
| `registration_fee_percent` | 3 | 2 | 1 | → dilebur jadi `platform_fee_percent` |
| `certificate_generator` | `'false'` | `'false'` | true | sudah = target |
| `certificate_email` | *(kosong)* | `'false'` | true | sudah = target |
| `export_data` | `'false'` | true | true | sudah = target |
| `custom_domain` | — | — | true | dipensiunkan |
| `api_access` | — | — | true | dipensiunkan |

**Konsekuensi yang harus ditangani:**

1. **`basic` tidak ada di prod tapi masih dibuat seeder.** Langkah "pensiunkan `basic`" jadi no-op di prod (`where slug='basic'` kena 0 baris — aman), tapi **`migrate:fresh --seed` tetap akan melahirkannya**. Karena itu `basic` juga harus **dihapus dari `PlanSeeder`**, bukan cuma dipensiunkan lewat migrasi — kalau tidak, kedua jalur menghasilkan DB berbeda dan verifikasi silang gagal. Inilah drift yang langkah verifikasi itu memang ada untuk menangkapnya.
2. **Peleburan dua key fee terbukti tidak kehilangan apa pun** — nilainya sudah identik di ketiga paket. Risiko yang ditulis §11.3 ternyata teoretis di data nyata.
3. **`max_teams_per_event` 32/128/-1 sudah sama dengan target `max_teams_per_category`**, tapi **maknanya melonggar**: event dengan 3 kategori yang tadinya dibatasi 32 tim total kini boleh 32 per kategori. Semua event lama di-backfill ke Professional (unlimited) jadi tidak ada yang terdampak, tapi ini perlu disebut di catatan rilis.
4. **Fitur "tidak dapat" disimpan sebagai `'false'` eksplisit di prod**, sementara rencana seeder menghilangkannya. Keduanya dirender dicoret oleh `PlanResource::isIncluded()`, jadi tampilannya sama — tapi supaya tidak ada dua representasi untuk satu arti, migrasi katalog **mengganti seluruh set fitur tiap paket** untuk 13 key terkelola (hapus key di luar set target, bukan cuma upsert).
5. **Key yang belum ada sama sekali** dan harus lahir: `online_registration`, `max_categories`, `platform_fee_percent`, `sponsor_logos`, `organizer_profile`, `event_gallery`, `max_gallery_photos`.

Backup pra-migrasi: `…/scratchpad/flo_event_pre_perevent.sql` (138 MB, `pg_dump` 2026-08-01). Scratchpad bersifat sementara — **ambil dump baru** kalau sesi berganti dan backup masih dibutuhkan.

## Katalog target (sumber kebenaran untuk seeder)

| Item | Starter Rp150.000 | Pro Rp350.000 | Professional Rp800.000 |
|---|:---:|:---:|:---:|
| `online_registration` | ✅ | ✅ | ✅ |
| `max_categories` | 1 | 4 | -1 |
| `max_teams_per_category` | 32 | 128 | -1 |
| `payment_gateway` | ✅ | ✅ | ✅ |
| `platform_fee_percent` | 3 | 2 | 1 |
| `qr_tickets` | ✅ | ✅ | ✅ |
| `export_data` | ❌ | ✅ | ✅ |
| `sponsor_logos` | ❌ | ✅ | ✅ |
| `organizer_profile` | ❌ | ✅ | ✅ |
| `certificate_generator` | ❌ | ❌ | ✅ |
| `certificate_email` | ❌ | ❌ | ✅ |
| `event_gallery` | ❌ | ❌ | ✅ |
| `max_gallery_photos` | — | — | 15 |

Fitur yang tidak didapat **tidak ditulis** ke `plan_features` (baris tanpa nilai dirender dicoret di kartu paket).

Key yang **dipensiunkan** (prune di migrasi, bukan seeder): `max_active_events`, `max_teams_per_event`, `max_tickets_per_event`, `ticket_fee_percent`, `registration_fee_percent`, `custom_domain`, `api_access`.

---

## Tahap 0 — Setup

- [x] Branch `feat/per-event-billing` dibuat dari `master`
- [x] Baseline test dicatat (lihat header)
- [x] File tracker ini dibuat

## Tahap 1 — Skema + rename model  ✅ *(selesai: 412 lulus, 2 flaky baseline)*

Migrasi:
- [x] `2026_08_01_100000_convert_plans_to_one_time_price.php` — rename `price_monthly`→`price` (**dua `Schema::table` terpisah**, Postgres tidak andal rename+drop dalam satu blueprint), drop `price_yearly` + `yearly_discount_percent`
- [x] `2026_08_01_100001_add_plan_to_events_table.php` — `plan_id` nullable + FK `nullOnDelete` + index. **Nullable disengaja** (§1.2)
- [x] `2026_08_01_100002_rename_subscriptions_to_event_plan_orders.php` — rename tabel, tambah `event_id` (**unique**) + `consumed_at`, drop `billing_cycle`/`starts_at`/`expires_at`, `UPDATE status 'active'→'paid'`. Index lama `subscriptions_payment_method_status_index` dibiarkan bernama lama; `down()` menyesuaikan
- [x] `php artisan migrate:fresh` jalan tanpa error

Rename simbol PHP (mekanis, satu commit):
- [x] `app/Models/Subscription.php` → `EventPlanOrder.php` (+ `settledValue(): 'paid'`, `event()`, `scopeUnconsumed()`, fillable/casts)
- [x] `app/Services/SubscriptionService.php` → `EventPlanOrderService.php`
- [x] `app/Http/Resources/SubscriptionResource.php` → `EventPlanOrderResource.php`
- [x] `app/Http/Controllers/Api/SubscriptionController.php` → `PlanOrderController.php`
- [x] `app/Http/Controllers/Api/Admin/SubscriptionController.php` → `Admin/PlanOrderController.php`
- [x] `app/Http/Requests/Subscription/CheckoutRequest.php` → `PlanOrder/CheckoutRequest.php`
- [x] Notifikasi `SubscriptionActivated`/`SubscriptionInvoiceIssued` → `PlanOrderPaid`/`PlanOrderInvoiceIssued` + blade di `resources/views/mail/`
- [x] `app/Console/Commands/ExpireManualSubscriptions.php` → `ExpireManualPlanOrders.php` (signature `plan-orders:expire-manual`) + entri `routes/console.php`
- [x] `Organization::subscriptions()` → `planOrders()`
- [x] `Plan` model: fillable `price`, cast decimal, tambah `events()`, **hapus** `organizations()`
- [x] `Event` model: `plan_id` ke fillable, tambah `plan(): BelongsTo`
- [x] Hapus `Plan::computeYearlyPrice()` + `Admin\PlanController::withYearlyPrice()`
- [x] `grep -rn "Subscription\|billing_cycle\|price_monthly\|price_yearly" api/app api/routes api/resources` → nol hasil
- [x] `grep -rn "'active'" api/app` → tak ada sisa status order lama

Test yang dipecahkan tahap ini (perbaiki **sekarang**, bukan ditunda) — hanya yang kena rename, karena `organizations.plan_id` masih ada sehingga gate lama tetap sah:
- [x] `SubscriptionBillingTest`, `ManualSubscriptionTest` — nama tabel/model/rute/status, `billing_cycle` dilepas dari payload
- [x] `PlanAdminTest` — `price_monthly`/`price_yearly`/`yearly_discount_percent` → `price`
- [x] `MailNotificationTest` — nama kelas notifikasi + blade
- [x] ✅ `php artisan test` hijau (kecuali 2–3 flaky baseline)

> **Prefix Midtrans `SUB-` untuk order lama TIDAK disentuh.** `MidtransWebhookController::handle()` merutekan order paket lewat arm `default`, jadi id `SUB-` yang masih beredar tetap settle. Id baru boleh `PLN-`; **jangan** menambah arm `PLN-` di match — itu justru menelantarkan yang lama.

## Tahap 2 — Katalog + backfill  ✅ *(selesai: 412 lulus, 2 flaky baseline)*

- [x] `2026_08_01_100003_seed_per_event_plan_catalogue.php` — **4 langkah urut**: upsert 3 paket (match `slug`, id lama dipertahankan) → pensiunkan `basic` (`is_active`/`is_public` false + hapus `plan_features`-nya, **jangan delete barisnya**) → **prune** 7 key pensiun dari `plan_features` **dan** `feature_definitions` → tulis 13 definisi + nilai per paket
- [x] `database/seeders/PlanSeeder.php` — **dikerjakan di Tahap 1**: 3 paket, harga tunggal, fitur "tidak dapat" tidak ditulis
- [x] `database/seeders/FeatureDefinitionSeeder.php` — **dikerjakan di Tahap 1**: 13 definisi
- [x] `app/Console/Commands/BackfillEventPlans.php` — `events:backfill-plan {--dry-run}`, idempoten (`whereNull('plan_id')` + `whereNotExists` order), `invoice_number`/`receipt_number` **null**
- [x] `2026_08_01_100004_backfill_event_plans.php` memanggil command itu
- [x] **Verifikasi silang**: `migrate` terhadap `flo_event` (salinan prod) vs `migrate:fresh --seed` di DB **sekali-pakai** `flo_event_scratch` → diff isi `plan_features` **harus identik**. ⚠️ jangan `migrate:fresh` di `flo_event`
- [x] `events:backfill-plan --dry-run` terhadap `flo_event` melaporkan angka masuk akal
- [x] Test yang dipecahkan tahap ini: yang meng-assert key fitur spesifik (`max_active_events`, `max_teams_per_event`, `*_fee_percent`) → ✅ `php artisan test` hijau

> Prune di **migrasi, bukan seeder**: seeder yang menghapus akan ikut menyapu key custom yang ditambahkan super_admin di `/admin/plans`.

## Tahap 3+4 — `PlanGate` event-keyed + siklus order  ✅ *(selesai: 411 lulus, 2 flaky baseline)*

> **Tahap 3 dan 4 digabung.** Begitu `syncCategories` menolak event tanpa paket, `POST /events` harus sudah mengklaim kredit — keduanya satu perubahan atomik, tidak bisa hijau secara terpisah.

- [x] `app/Exceptions/PlanFeatureException.php` (pola `WalletException`) + render di `bootstrap/app.php`
- [x] `app/Services/PlanGate.php` ditulis ulang (§2): `planValue`/`planAllows`/`planLimit`/`planWithinLimit` + pembungkus Event + `orgAllows` + `flush()` + memo per plan id
- [x] `tests/TestCase.php` `setUp()` tambah `PlanGate::flush()` (di sebelah `Catalog::flush()`)
- [x] **Hapus** `app/Http/Middleware/CheckPlanFeature.php`, `CheckPlanLimit.php`, dan kedua alias di `bootstrap/app.php:53-54`

Call site (§5), satu checkbox per titik:
- [x] `EventController::syncCategories()` — gate `max_categories` (sebelum loop, `current: 0, adding: count($categories)`) + cap `max_teams` per kategori (**422 field path**, bukan 403)
- [x] `EventController::store()` — **hapus** blok `max_active_events`
- [x] `RegistrationController::store()` — `max_teams_per_event` → `max_teams_per_category` (403 + feature)
- [x] `Public/PublicEventController::register()` — `max_teams_per_category` (**422** + feature key) **dan** gate `online_registration` (422) di atas `isRegistrationOpen()`
- [x] `EventMediaController` — tambah `__construct(protected PlanGate $gate)` (belum punya constructor); gate `sponsor_logos` di `storeSponsor`; gate `event_gallery` **lalu** `max_gallery_photos` (total event, bukan per-request) di `storePhotos`; `updateSponsor`/`destroySponsor` **tetap** tanpa gate
- [x] `PaymentRails::destinationFor()` → `(Event $event, float $amount)`; `platformDestination()` **tidak disentuh & tetap planless**
- [x] `RegistrationService::startPayment(Team $team)` — buang argumen `$org`; `PublicEventController::register()` tambah `$team->setRelation('event', $event)`; perbaiki pemanggil `MyTeamController`/`RegistrationController`
- [x] `TicketService::platformFee(Event, float)` + `RegistrationService::platformFee(Event, float)` → key `platform_fee_percent`
- [x] `TicketCategoryController::ensureTicketsEnabled(Event)`; **hapus** `ensureWithinTicketLimit()` + 2 call site-nya
- [x] `Public/PublicTicketController::purchase()` — `destinationFor($event,…)`, `platformFee($event,…)`, `qr_tickets` (422 + feature key)
- [x] `CertificateController` — `ensureEnabled` pindah **setelah** `findEvent`; `generate`/`send` gate di event; `download`/`destroy` **tetap terbuka + tulis alasannya di kode**
- [x] `CertificateTemplateController` — `orgAllows($org, 'certificate_generator')` (template org-scoped, tanpa `event_id`)
- [x] `Public/PublicOrganizationController::show()` + `PublicOrganizationResource($org, bool $rich)` — degradasi jadi daftar event, **bukan 404**
- [x] `Admin\PlanController::destroy()` — tolak 422 kalau `$plan->events()->exists()`

Fixture + migrasi test **dikerjakan di tahap ini**, karena di sinilah gate berhenti org-keyed:
- [x] `tests/Concerns/CreatesPlannedEvents.php` — `planWith`/`orgFor`/`creditFor`/`eventOn`
- [x] Migrasi **45 file test** dari `orgWithPlan()` ke trait (mekanis)
- [x] `EventTest::test_plan_limit_blocks_extra_events` **dihapus**; `EventMediaTest` butuh paket yang mengizinkan foto & sponsor
- [x] ✅ `php artisan test` hijau

## Tahap 4 — Siklus order + rute + resource

- [x] `EventPlanOrderService::checkout(Organization, Plan)` — buang aritmetika siklus; `platformDestination()` tetap ditanya **sebelum** baris dibuat (checkout ditolak tidak boleh membakar nomor invoice)
- [x] `EventPlanOrderService::activate()` — **tidak menulis apa pun ke `organizations`**; idempotensi receipt + email dipertahankan
- [x] `pay()` tambah guard menolak order yang sudah dipakai
- [x] `EventController::store()` — `DB::transaction` + `claimOrder()` + **klaim atomik** `whereNull('event_id')` di dalam UPDATE
- [x] `StoreEventRequest` tambah `plan_order_id` nullable uuid (kepemilikan dicek di `claimOrder`, bukan FormRequest)
- [x] `routes/api.php` — rute `plan-orders` (organizer + admin); **`POST events` pindah ke belakang `org.admin`**
- [x] `PlanOrder/CheckoutRequest` — buang `billing_cycle`; `exists` dibatasi `is_active = true`
- [x] `EventPlanOrderResource` — `event_id`, `consumed_at`, `event`
- [x] `PlanSummaryResource` baru; `EventResource` memakainya; `EventController::index/show` `->with('plan.features')`
- [x] `OrganizationResource` — buang `plan_id`/`plan_expires_at`/`plan`, tambah `unconsumed_plan_orders_count`, rename `subscription_awaiting_verification` → `plan_payment_awaiting_verification`
- [x] `resources/views/pdf/_document.blade.php` — "Siklus" → "Event"; blade mail ikut
- [x] `BillingDocumentService` — tipe + `loadMissing('plan','organization','event')`
- [x] `MidtransWebhookController` — tipe baru; **arm `SUB-` tidak disentuh**

## Tahap 5 — Lepas kolom paket org  ✅ *(selesai: 411 lulus, 2 flaky baseline)*

- [x] `2026_08_01_100005_drop_plan_from_organizations_table.php` (**dijalankan terakhir** — backfill & semua tahap sebelumnya harus bisa jalan di DB yang masih punya kolomnya)
- [x] Hapus `Organization::plan()`, fillable, casts
- [x] Hapus `OrganizationController::assignPlan()` + rute `PATCH organizations/{org}/plan`
- [x] Hapus `plan_id` dari `StoreOrganizationRequest`
- [x] Hapus 4× `->with('plan.features')` di `OrganizationController`

## Tahap 6 — Exporter + katup super_admin  ✅ *(selesai & diuji: xlsx/PDF asli, gate komparatif, katup admin)*

> `maatwebsite/excel ^3.1` **sudah ada di `composer.json` tapi belum dipakai satu baris pun**; `barryvdh/laravel-dompdf` sudah dipakai sertifikat & invoice. **Tidak ada dependency baru.**

- [x] `app/Http/Controllers/Api/ExportController.php` — gate `export_data` di **baris pertama** tiap method
- [x] `app/Exports/{Registrations,TicketBuyers,Standings,Leaderboard}Export.php` — **pakai ulang** `StandingService` & `PlayerStatService::leaderboard()`, jangan tulis ulang agregasi (itu cara dua angka di layar yang sama jadi berbeda)
- [x] `resources/views/pdf/export.blade.php` — helper format **dioper sebagai view data**, bukan didefinisikan di layout (Blade menjalankan section anak sebelum layout)
- [x] Rute `GET organizations/{org}/events/{event}/exports/{kind}?format=xlsx|pdf` di bawah `tenant` + `org.admin`
- [x] `POST admin/events/{event}/reassign-plan` (§7) — unique index **tetap**; null-kan `event_id` lama lalu klaim baru dalam satu transaksi

## Tahap 7 — Tipe frontend + lib  ✅ *(selesai: `bun run build` hijau)*

- [x] `web/types/api.ts` — `Plan.price`, `PlanSummary` baru, `SportEvent.plan`, `EventPlanOrder`, `PlanOrderStatus`, `CheckoutResult.plan_order`, `Organization` (§8.2)
- [x] `web/lib/plan.ts` ditulis ulang event-keyed (§8.1) — hapus 9 fungsi siklus/org, tambah `planAllows`/`planLimit`/6 helper boolean/3 helper limit/`anyEventAllows`/`unconsumedOrders`
- [x] `web/lib/api/organizations.ts` — rename 5 fungsi ke `/plan-orders`
- [x] `web/lib/api/exports.ts` baru — `apiClient` + `responseType: "blob"` (**bukan `<a href>`** — token in-memory, akan 401)
- [x] `web/lib/checkout.ts` — `res.plan_order.status === "paid"`
- [x] `web/lib/labels.ts` — hapus `BILLING_CYCLE_LABELS`, tambah `PLAN_ORDER_STATUS_LABELS`
- [x] `cd web && bun run build` → kerjakan error TypeScript ke luar (itulah daftar konsumen yang tersisa)

## Tahap 8 — Halaman frontend  ✅ *(selesai: `bun run build` hijau)*

- [x] `components/event/event-limit-notice.tsx` → `plan-purchase-notice.tsx` (grid paket inline; cabang "batas event aktif" dihapus)
- [x] `organizer/events/new/page.tsx` — cabang 0 / 1 / >1 kredit; kirim `plan_order_id`; tangani `plan_order_required`
- [x] `EventForm` — cap **proaktif** (tombol tambah kategori mati, `max` di input `max_teams`, baris ringkasan paket)
- [x] `organizer/subscription/page.tsx` → `organizer/billing/page.tsx` — 3 blok (§8.3); hapus kartu "Paket saat ini", `daysUntil`, `currentCycle`
- [x] `organizer/upgrade/page.tsx` → `organizer/plans/page.tsx` — tanpa toggle
- [x] `next.config` redirect `/organizer/upgrade` + `/organizer/subscription`
- [x] 7× CTA "Upgrade paket" → "Beli paket"; `components/auth/public-auth-actions.tsx:143`
- [x] `onboarding/page.tsx` — **3 langkah → 1**; hapus `Steps`/`STEP_COPY`/`subsQuery`/`pendingManual`/`BillingCycleToggle`
- [x] `subscription-pending-banner.tsx` → `plan-payment-pending-banner.tsx`; **baru** `unconsumed-plan-banner.tsx`
- [x] `organizer/tickets/page.tsx` — empty state satu-halaman → **per-baris event**
- [x] `organizer/certificates/page.tsx` + `generate/page.tsx` + template `new`/`[id]` (**yang terakhir belum punya gate sama sekali**)
- [x] `organizer/events/[id]/media/page.tsx` — gate sponsor & galeri, tampilkan `n/15`
- [x] Tombol export jadi **Excel + PDF sungguhan** lewat `ExportButtons`; `lib/csv.ts` dihapus. (Dua tombol, bukan dropdown — codebase ini tidak punya primitive dropdown dan formatnya cuma dua.)
- [x] `components/subscription/plan-card.tsx` — hapus `BillingCycle`/`BillingCycleToggle`/prop `cycle`/baris coret/`Ditagih …/tahun`/badge hemat
- [x] `components/landing/pricing.tsx` — tanpa toggle, `/event`, `platform_fee_percent`, **3 CTA self-serve** (mailto sales dilepas)
- [x] `app/layout.tsx:52` — buang `data-billing`
- [x] `app/globals.css` blok PRICING (~965-1050) — hapus 11 rule siklus; **pertahankan** `.price-grid`/`--plan-count`/`.plan`/`.plan.featured`/`.plan-tag`/`.plan-feats`/`.price-foot`
- [x] `components/shared/status-badge.tsx` + `components/dashboard/sidebar-nav.tsx`
- [x] `admin/plans/page.tsx` — satu input harga ("Harga per event")
- [x] `admin/subscriptions/page.tsx` → `admin/plan-orders/page.tsx` — tambah kolom event
- [x] `grep -rn "data-billing\|bill-switch\|billing_cycle\|price_monthly\|BillingCycle" web` → nol hasil

## Tahap 9 — Test baru  ✅ *(19 test komparatif + 5 test backfill lulus; e2e lulus penuh sejak 2026-08-02)*

> Fixture (`CreatesPlannedEvents`) dan migrasi 45 file test sudah selesai di **Tahap 3**; perbaikan test lain sudah dikerjakan di tahap penyebabnya. Tahap ini tinggal **menambah** test yang membuktikan perilaku baru.

19 test baru §9.2 — **semuanya komparatif** (assert satu nilai akan lolos walau fiturnya tidak pernah jalan):
- [x] 1. dua event satu org → entitlement berbeda *(kunci utama)*
- [x] 2. `platform_fee` dari paket **event**, bukan org (3% vs 1%, harga identik, satu test)
- [x] 3. fee pendaftaran key sama + manual = 0 **dengan ledger kosong** di kedua paket
- [x] 4. kredit lunas dipakai **tepat sekali** (assert jumlah event 0→1→**1**)
- [x] 5. organizer bisa memilih kredit mana yang dipakai (assert Starter **masih** utuh)
- [x] 6. event mempertahankan paketnya walau paket lebih besar dibeli belakangan
- [x] 7. `max_categories` menolak **seluruh** create (assert event **dan** `event_id` tak tersentuh)
- [x] 8. `max_teams` kategori tidak boleh lewat cap paket (422 + kasus yang lolos di test yang sama)
- [x] 9. cap per **kategori**, bukan per event (2+2 lolos, ke-3 di A gagal)
- [x] 10. cap galeri menghitung **total event**, bukan satu request (10 lolos, 10 berikutnya ditolak)
- [x] 11. galeri ditolak tanpa boolean-nya *(jebakan "null lolos bebas")*
- [x] 12. logo sponsor ditolak di Starter, diterima di Pro (body identik byte-per-byte)
- [x] 13. profil publik baru kaya setelah ada event yang membawanya
- [x] 14. key pensiun **tidak memberi apa-apa**
- [x] 15. `events:backfill-plan` idempoten (**jalankan dua kali**)
- [x] 16. `activate()` idempoten **dan tidak menulis ke `organizations`**
- [x] 17. `online_registration` & `qr_tickets` bisa dimatikan per event
- [x] 18. export butuh paket (403 vs file non-kosong, satu test)
- [x] 19. operator tidak bisa membuat event
- [x] `php artisan test` hijau (kecuali 2–3 flaky baseline)
- [x] `fixtures/api.ts::grantCredit` **sudah diimplementasikan** (commit `97be71e`) lewat webhook Midtrans yang dihitung sendiri; `createEvent()` memanggilnya, jadi seluruh spec yang butuh event ikut terlayani. Blokir yang dulu ditulis di bawah sudah tidak berlaku.
- [x] **Tiga spec e2e yang masih menulis alur lama sudah diperbaiki** (2026-08-02) dan suite-nya dijalankan bersih: `39 passed` + `2 passed` (`@gateway-off`). Lihat "Sapuan akhir" di bawah.

## Tahap 10 — Docs + verifikasi manual  ✅ *(selesai: backend 437 lulus / 0 gagal, e2e 39+2 lulus)*

- [x] **`KnockoutPlanTest` baseline diperbaiki** — flaky-nya nyata, tapi sebabnya bukan yang tertulis di jebakan no. 00. Test itu mengira tabel klasemen nil-results terurut **alfabetis**; sebenarnya semua tim seri sehingga urutannya jatuh ke **undian** `StandingService::lot()` = `crc32(category_id . team_id)` — permutasi baru tiap run karena uuid-nya baru. Jadi yang acak adalah **`$before`**, bukan hasil undian babak grup. Perbaikannya: `playGroupStage(array $losers = [])` — tim yang disebut kalah dari tim yang tidak disebut, sisanya diputus nama yang lebih akhir; keduanya dibaca dari fixture-nya sendiri sehingga undian grup tetap acak. Testnya kini **memainkan babak grup melawan `$before`**: empat tim yang kebetulan ditunjukkan tabel kosong dibuat kalah, jadi empat yang lolos dijamin berbeda apa pun hasil undiannya, dan assert-nya naik dari "berbeda" jadi **disjoint**. 20× run berturut-turut hijau. Sekalian: `setUp()` masih membuat paket di `organizations.plan_id` (kolom yang sudah didrop) dengan key `max_active_events` (sudah dipensiunkan) — paket mati yang tidak memberi apa-apa; diganti `orgFor()`, entitlement-nya memang datang dari `creditFor()` di `createEvent()`.
- [x] **Dua `CatalogTest` baseline diperbaiki** — ternyata bukan flaky. `SportSeeder` mendapat `basketball` (commit `3b429a7`, 2026-07-23) dan `ConfigOptionSeeder` mendapat `rubber_difference`/`rubber_games`/`rubber_points`, tanpa testnya ikut diperbarui. Tiga hal: hitungan `sports` 8→9 & `tiebreakers` 10→12; sport yang dibuat runtime diganti `basketball`→`handball` (memakai slug yang **sudah** diseed mengubah test jadi uji keunikan dan berhenti membuktikan apa pun); helper lokal `org()` — yang masih menulis `plan_id` ke `organizations` — diganti `orgFor()` + `creditFor()` dari trait. Sisa satu gagal: `KnockoutPlanTest`, yang memang flaky karena undian acak.

- [x] `CLAUDE.md` — "Pola: plan limit / feature gating" ditulis ulang (invarian paket-per-event, dua lapis `PlanGate`, `orgAllows` monoton, tiga bentuk penolakan 403 / 422-field / 422-feature, boolean-sebelum-angka); "Pola: langganan & dokumen tagihan" → **"Pola: pembelian paket per event & dokumen tagihan"** (kredit ≠ entitlement, klaim atomik, prefix `SUB-`/`PLN-`, `org.admin` di `POST events`, backfill). Referensi `destinationFor($org, …)` di pola transfer manual ikut dikoreksi jadi event-keyed.
- [x] Catatan rilis: `docs/per-event-billing-release-notes.md` — 8 perubahan perilaku (**operator tidak bisa lagi membuat event** di urutan pertama), fitur baru, urutan deploy

Checklist verifikasi manual (19 poin, §10 rencana). Yang sudah terbukti di sesi sebelumnya
ditandai sumbernya; sisanya dijalankan 2026-08-02 lewat API + `php artisan` + SSR:
- [x] 1. `/pricing` 3 kartu, `/event`, tanpa toggle, "Paling Populer" di Pro, footnote fee 3/2/1% *(browser, tabel di bawah)*
- [x] 2. `<body>` tanpa `data-billing`; tidak ada node `.bill-switch` — diulang 2026-08-02: `grep -c` di HTML `/` = **0**
- [x] 3. User baru → onboarding cuma nama organisasi. Org lahir tanpa satu pun field paket (payload cuma punya `unconsumed_plan_orders_count` & `plan_payment_awaiting_verification`); halamannya satu langkah, lalu `router.replace("/organizer/events/new")`
- [x] 4. `/organizer/events/new` tanpa kredit → pemilih paket, bukan form *(browser)*
- [x] 5. Beli Starter gateway **nyala** → `paid`, muncul di "Paket siap dipakai", banner tampil *(browser + webhook)*
- [x] 6. Beli Starter gateway **mati** → `payment_method: manual`, `snap_token: null`, **`mock: false`** (tidak tersedot ke cabang mock), rekening platform penuh terkirim → unggah bukti (`plan_payment_awaiting_verification: true`) → acc di `/admin/plan-orders` → `paid` + `KW/2026/08/0007`, `event_id` **tetap null**, kredit 0→1. PDF invoice **dan** kwitansi render benar (status **Lunas**, "Berlaku untuk 1 event", kolom Event "Belum dipakai")
- [x] 7. Buat event dari kredit → hilang dari "siap dipakai", riwayat menyebut nama event
- [x] 8. Event kedua ditolak, **tanpa draft tertinggal** *(jumlah event tetap 1)*
- [x] 9. Kategori ke-2 ditolak (proaktif + 403 kalau dipaksa API); `max_teams` > 32 ditolak inline
- [x] 10. Tim ke-33 ditolak — 32 lolos, ke-33 **403 `max_teams_per_category`**, `teams_count` berhenti di 32. Pencacah event kedua **independen**: tim pertama di event Professional tetap 201 walau event pertama sudah mentok
- [x] 11. Satu org, dua event, request identik: **Starter 403** (`export_data`, `sponsor_logos`, `certificate_generator`) vs **Professional lolos** (xlsx `Microsoft Excel 2007+`, sponsor cuma kena validasi field, `1 sertifikat diterbitkan`)
- [x] 12. Galeri: cap = total event, bukan per request *(sesi sebelumnya: 1+20 ditolak, 1+14 lolos, +1 ditolak)*
- [x] 13. Tiket harga sama → `platform_fee` 3% vs 1% *(sesi sebelumnya + test #2)*
- [x] 14. Export xlsx & pdf dari Pro terunduh dan terbuka; dari Starter → 403 *(Tahap 6 + diulang di poin 11)*
- [x] 15. `/{orgSlug}` tetap **200** dengan `has_profile: false` sebelum ada event Pro/Professional, lalu `has_profile: true` setelah event Professional dibuat — org yang sama, dua request
- [x] 16. `/organizer/upgrade` → **308** `/organizer/plans`; `/organizer/subscription` → **308** `/organizer/billing`
- [x] 17. Operator: **403** di `GET plan-orders`, `POST plan-orders/checkout`, **dan `POST events`**; `GET events` tetap 200 (yang hilang cuma pintu yang membelanjakan uang)
- [x] 18. Event hasil backfill (`KABOAX CUP 2026`) punya 13 key Professional, export xlsx 200, sponsor lolos gate; invoice **dan** kwitansi historis pra-migrasi (`INV/2026/07/0002`) tetap render
- [x] 19. `plan-orders:expire-manual`: order tanpa bukti → `cancelled`, order **dengan** bukti tetap `past_due`. Setelah run: **0** order cancelled yang punya bukti, **10** order berbukti lewat deadline dibiarkan, **0** order tanpa bukti tersisa

**Dua sisa teks era langganan ditemukan lewat verifikasi ini** (tidak akan tertangkap test —
keduanya string yang tidak di-assert):

1. `receipt.blade.php` mencetak "pembayaran untuk **langganan** tersebut di atas" di tiap
   kwitansi → "paket event".
2. Subjek email `PlanOrderPaid` masih "**Langganan** {paket} aktif" → "Paket {paket} siap
   dipakai". Ikut dibersihkan: tiga label di `/admin` ("Kelola paket langganan", "Paket &
   fitur langganan", "Bukti transfer langganan").

**Data uji tertinggal di DB dev**: org `eo-verifikasi-tahap-10` (2 event, 4 order — 1 dipakai,
1 kredit Professional menganggur, 1 cancelled, 1 past_due berbukti), 33 tim, 1 template
sertifikat, 1 sertifikat, plus user operator `op-t10-*@example.com`. Hapus kalau mengganggu.

---

## Verifikasi alur beli → buat event (2026-08-01, lewat API + SSR)

Dijalankan dengan user baru di DB dev. **Semua lulus.**

| Yang diuji | Hasil |
|---|---|
| Registrasi → buat org **tanpa** `plan_id` | ✅ payload org tidak lagi punya `plan_id`/`plan_expires_at`/`plan` |
| Buat event **tanpa** kredit | ✅ 403 `plan_order_required` |
| Checkout Starter | ✅ `past_due`, `INV/2026/08/0001`, `event_id: null` |
| Settle (jalur webhook) | ✅ `paid` + `KW/2026/08/0001`, **`event_id` tetap null** — kredit, bukan entitlement |
| `unconsumed_plan_orders_count` | ✅ 0 → 1 setelah lunas → 0 setelah dipakai |
| Buat event dengan kredit | ✅ `plan=Starter` menempel di event |
| Buat event **kedua** | ✅ 403, dan jumlah event tetap **1** (bukan cuma 403 — kreditnya benar-benar habis) |
| `max_categories` (Starter=1) | ✅ tolak 2 kategori, `max_categories` |
| `max_teams_per_category` (32) | ✅ 422 di `categories.0.max_teams`, bukan 403 |
| `qr_tickets` (Starter punya) | ✅ kategori tiket dibuat |
| **Dua event, satu org, entitlement berbeda** | ✅ galeri & sponsor: **tolak** di Starter, **izinkan** di Professional |
| Cap galeri = total event, bukan per-request | ✅ 1+20 ditolak, 1+14 lolos (=15), +1 ditolak |
| **Fee dari paket event** | ✅ tiket Rp50.000 identik → **3%** (Starter) vs **1%** (Professional) |
| Halaman frontend | ✅ `/`, `/pricing`, `/organizer/plans`, `/organizer/billing`, `/organizer/events/new`, `/onboarding` semua 200 |
| Redirect rute lama | ✅ 308 ke `/organizer/plans`, `/organizer/billing`, `/admin/plan-orders` |
| Jejak siklus di HTML | ✅ tidak ada `data-billing`/`bill-switch`; suffix hanya `/event` |

### Verifikasi lewat browser (setelah izin site diberikan)

| Permukaan | Hasil |
|---|---|
| `/pricing` | ✅ 3 kartu `/event`, tanpa toggle, "Paling Populer" di Pro, matriks fitur persis tabel target, footnote fee 3/2/1% |
| Sidebar | ✅ "Pembelian Paket" (bukan "Langganan") |
| `/organizer/events/new` **tanpa** kredit | ✅ pemilih paket inline, form tidak muncul |
| `/organizer/events/new` **dengan** kredit | ✅ banner "Kamu punya 1 paket yang belum dipakai" + form |
| Cap proaktif di form | ✅ "Maks 32", hint paket, tombol "Tambah kategori" **mati**, baris "Paket Starter: maks 1 kategori, 32 peserta per kategori." |
| `/organizer/billing` | ✅ "Paket siap dipakai" + riwayat yang menyebut event tiap order |
| PDF invoice | ✅ kolom **Event** berisi nama event, "Berlaku untuk 1 event", status **Lunas** |

**Dua bug ditemukan dan diperbaiki lewat verifikasi ini** — keduanya tidak akan tertangkap oleh typecheck atau test API:

1. **`PlanOrderController::index` hanya eager-load `plan`, bukan `plan.features`.** Akibatnya `features` kosong, semua cap terbaca *unlimited*, dan form tidak mematikan kontrol apa pun. Gate server tetap menolak — jadi gejalanya adalah user mengisi seluruh form lalu ditolak saat submit, persis yang gate proaktif ada untuk mencegahnya.
2. **`invoice.blade.php` masih memetakan status lama.** `@default` jatuh ke "Kedaluwarsa", jadi setiap invoice **lunas** tercetak "Kedaluwarsa". Nilai `active` sudah tidak ada; sekarang `paid` → "Lunas" dan default → "Menunggu pembayaran" (order paket tidak punya jam yang bisa habis).

**Data uji tertinggal di DB dev**: org `eo-uji-perevent` dengan 2 event, 2 order, 2 pesanan tiket, 15 foto. Hapus kalau mengganggu.

## Verifikasi Tahap 6 (2026-08-01)

| Yang diuji | Hasil |
|---|---|
| Export di event **Starter** | ✅ 403 `export_data` |
| Export di event **Professional** | ✅ xlsx asli (`Microsoft Excel 2007+`) & PDF asli |
| Isi xlsx | ✅ header + data nyata (diperiksa lewat `sharedStrings.xml`) |
| `standings` tanpa `category_id` | ✅ 422 dengan pesan yang menyebut field-nya |
| Jenis export tak dikenal | ✅ 404 |
| Reassign paket oleh **org admin** | ✅ 403 "Hanya untuk Super Admin." |
| Reassign paket oleh **super_admin** | ✅ event pindah Starter → Professional, dan galeri yang tadinya **ditolak** di event yang sama jadi **diizinkan** |
| Buku order setelah reassign | ✅ order lama dilepas (bukan dihapus), **tidak ada event dengan 2 order** |

## Jalur webhook Midtrans — terverifikasi, dan itu yang membuka blokir e2e

Signature webhook cuma `sha512(order_id + status_code + gross_amount + server_key)`, dan key-nya ada di `api/.env`. Artinya notifikasi Midtrans bisa **dikirim sendiri** tanpa Midtrans dan tanpa tunnel.

Diuji langsung (2026-08-01):

| Uji | Hasil |
|---|---|
| Webhook `PLN-` + signature benar | `past_due` → **`paid`** + kwitansi terbit |
| `event_id` setelah settle | **tetap null** — kredit, bukan entitlement |
| Webhook `SUB-` (id lama sebelum rename) | **ikut settle** lewat arm `default` |
| Signature salah | **403 "Signature tidak valid."** |

`fixtures/api.ts::grantCredit` memakai jalur ini, dan `createEvent` memanggilnya. **E2E butuh `MIDTRANS_SERVER_KEY` di environment-nya.**

Sengaja **bukan** opsi "jalankan API tanpa server key": itu membuat `openSnap()` melunasi di tempat dan **melewati webhook sepenuhnya**, jadi baik pemeriksaan signature maupun routing order id tidak akan pernah teruji. Jalur ini justru menutup keduanya.

> **MCP Midtrans tidak relevan untuk ini.** Kendalanya bukan cara bicara ke Midtrans, melainkan notifikasi sandbox harus **sampai** ke API — `localhost:8000` tidak terjangkau dari server mereka, jadi itu butuh tunnel + webhook URL di dashboard. Berguna untuk eksplorasi manual, bukan untuk test otomatis.

## Sapuan akhir (2026-08-02) — selesai

Yang **terlewat**, bukan yang sengaja ditunda. Semuanya sudah diperbaiki.

### 1. Konten landing masih menjual langganan bulanan  ✅

`components/landing/pricing.tsx` sudah benar sejak Tahap 8, tapi FAQ dan testimoni di
halaman yang **sama** tidak pernah ikut. Diperbaiki lewat
`2026_08_02_100000_refresh_landing_copy_for_per_event_billing` **dan** seeder-nya —
migrasi karena prod mungkin tak pernah dijalankan seeder, dan `FaqSeeder` keyed `question`
sehingga pertanyaan yang berubah kata akan lahir sebagai baris kedua. Tiap statement
mencocokkan teks lama, jadi editan super_admin di `/admin/faqs` tetap menang.

- [x] FAQ *"Paket paling murah…"* — "Basic **Rp 49.000/bulan**, 1 event aktif, maks 8 tim, **hemat 20% bayar tahunan**" → Starter Rp150.000 sekali bayar per event, 1 kategori 32 peserta, tanpa masa berlaku
- [x] FAQ *"Apakah saya bisa upgrade atau downgrade paket?"* → **pertanyaannya diganti**: "Kalau event berikutnya butuh paket yang lebih besar?"
- [x] FAQ *"Metode pembayaran…"* — "Berlaku untuk **langganan**" → "pembelian paket"
- [x] FAQ *sertifikat* — "paket **Pro** ke atas" → Professional saja (Pro tidak punya `certificate_generator`)
- [x] FAQ *cabang olahraga* — "basket dan tenis **menyusul di roadmap**" padahal `SportSeeder` sudah membawa sembilan cabang termasuk keduanya
- [x] Testimoni **Hendra Wijaya** — "Naik dari **Basic** ke Pro… **Upgrade**-nya mulus"
- [x] Diverifikasi: 8 FAQ tetap 8 (tanpa duplikat), dan isi seeder **identik** dengan isi DB untuk kedelapan pertanyaan

### 2. Spec e2e menulis alur yang sudah tidak ada  ✅

- [x] `plan-order-manual-flow.spec.ts` — onboarding 3 langkah → 1; pembelian dipindah ke pemilih paket di `/organizer/events/new`, panel transfer di `/organizer/billing`
- [x] `plan-order-manual.spec.ts` — "Verifikasi Langganan" → "Verifikasi Pembelian Paket"; URL `/admin/subscriptions` → `/admin/plan-orders`; grup sidebar harus dibuka dulu (tertutup kecuali memegang rute aktif)
- [x] `auth-onboarding.spec.ts` (**tidak ketahuan lewat grep statis** — baru muncul saat suite-nya benar-benar jalan): tombol `/lanjut|simpan|buat/i` tidak pernah cocok dengan "Selesai", dan "buat event" perlu `grantCredit()` karena fixture `organizer` sengaja tidak memberi kredit

### 3. Akar masalah lingkungan e2e: **CORS, bukan worker**  ✅

Web dev tergeser ke `:3001` (Next.js pindah sendiri saat `:3000` terpakai), sementara
`api/config/cors.php` cuma mengizinkan origin `http://localhost:3000`. Menyetel `WEB_URL`
**tidak cukup** — halamannya memuat, tapi tiap panggilan API dari browser diblokir. Karena
axios tidak menerima respons sama sekali, `parseApiError` jatuh ke pesan default dan
layarnya cuma bertuliskan "Registrasi gagal", persis seperti bug aplikasi; hampir semua
spec mendaftar akun di langkah pertama, jadi seluruh suite runtuh dari situ. Sudah
ditulis di `e2e/README.md` sebagai catatan lingkungan pertama.

**Hasil setelah dibereskan: `39 passed` (suite default) + `2 passed` (`@gateway-off`).**

### 5. Flaky terakhir: `public-header > keluar dari halaman publik`  ✅

Tidak ada hubungannya dengan billing, tapi sekalian dikejar. Test-nya meng-assert link
"Daftar" **terlihat** tepat setelah logout, di viewport 390px. Padahal di bawah 940px
`.nav-actions .btn { display: none }` — salinan aksi milik bar disembunyikan CSS — dan
logout **menutup sheet-nya sendiri** lewat `onNavigate`. Jadi saat diam, "Daftar" tidak
terlihat di mana pun: assertion itu cuma lolos selagi sheet masih dalam animasi keluar.
Bukan fakta, melainkan lomba — dan setiap beberapa run, assertion-nya kalah.

Diperbaiki dengan **membuka menunya lagi** setelah logout, dan menyempitkan locator ke
`getByRole("dialog", { name: "Menu" })` alih-alih `.first()` — `.first()` bisa mendarat di
node yang ada di DOM tapi tidak akan pernah terlihat, yang persis jebakan yang sama sekali
lagi. Sekalian assert "Masuk" juga kembali, dan "Dashboard" hilang **di dalam sheet**.

**Diverifikasi bisa merah**: `clearAuth()` di `public-auth-actions.tsx` dimatikan sementara
→ test gagal di assertion "Daftar"; dikembalikan → hijau. Versi lamanya juga lulus 5×
berturut-turut saat filenya dijalankan sendirian, jadi "lulus berkali-kali" **bukan** bukti
— yang membedakan cuma kontrol negatif ini. Setelah itu 3× suite penuh: `39 passed`.

### 4. Bug aplikasi yang ditemukan e2e: onboarding mendarat di tempat yang salah  ✅

`/onboarding` `onSuccess` cuma `invalidateQueries(["organizations"])` lalu
`router.replace("/organizer/events/new")`. Tapi `hasNoOrg` = `isSuccess && length === 0`,
dan query yang di-invalidate **tetap menyajikan array kosong yang lama** selama refetch —
cukup lama untuk `OrganizerLayout` menyimpulkan user ini belum punya organisasi dan
memantulkannya kembali ke `/onboarding`, yang saat itu sudah melihat org-nya dan
meneruskan ke `/organizer`. Organizer baru mendarat dua halaman dari tujuannya, di
dashboard, tanpa satu pun petunjuk soal paket yang seharusnya dia pilih. Diperbaiki dengan
`setQueryData(["organizations"], [created])` (menyemai, bukan sekadar invalidate) plus
latch `justCreated` untuk guard "sudah punya org". **Tidak terlihat oleh test backend mana
pun, dan tidak terlihat oleh sapuan statis** — butuh browser yang benar-benar menavigasi.

## Utang yang sengaja ditinggalkan (jangan hilang)

- [x] **Daftar admin untuk kredit lunas menganggur + email pengingat** (§11.1) — `plan-orders:remind-idle` (harian 09:00, `--dry-run`), notifikasi `PlanOrderIdle`, ambang `billing.idle_credit_days` (14) & `idle_credit_repeat_days` (30), kolom `idle_reminded_at` supaya sapuan harian tidak mengirim tiap hari, dan section "Kredit menganggur" di `/admin/plan-orders`. 8 test.
- [x] **UI untuk `reassign-plan`** — tombol "Pakai untuk event lain" pada tiap kredit menganggur, membuka pemilih event organisasi itu (`GET admin/organizations/{org}/events`, tipis: id/nama/paket).
- [x] **Label `max_teams_per_category` → "Entri per kategori"** (§11.2) — lewat migrasi + seeder, plus pesan error `EventController`/`RegistrationController` dan hint di `EventForm`. Deskripsinya dulu ada semata untuk membantah kata di atasnya; sekarang ia bisa menjelaskan apa itu satu entri.
- [-] **Paket ke-4 untuk enterprise: diputuskan TIDAK dibuat** (§11.4, user 2026-08-02). Bukan ditunda — diputuskan. Professional sudah unlimited di kategori, entri, dan galeri, jadi tidak ada yang bisa ditawarkan tier keempat selain harga. Mekanismenya tetap ada seandainya berubah pikiran (`is_public` sudah memisahkan katalog publik dari admin, dan `planCovers()` menerima paket kustom apa pun asal supersetnya benar) — tapi jangan dibuat tanpa permintaan nyata.
  - **Konsekuensi yang ikut dibereskan**: `sales_email` jadi yatim. Ia dulu memberi makan CTA "Hubungi Sales" di kartu Professional; CTA-nya dilepas saat katalog jadi self-serve, dan tanpa tier enterprise tidak ada yang menggantikannya — jadi `PublicSiteSettingResource` mengirim alamat internal ke tiap pengunjung untuk tombol yang sudah tidak ada. Sekarang tidak dikirim lagi. Kolom, form admin, dan `SiteSettingResource` dibiarkan utuh: menghapusnya membuang data dan tidak menghasilkan apa-apa.

## Jebakan yang sudah diketahui (baca sebelum menyentuh area terkait)

00. ~~**`KnockoutPlanTest > plan is saved in slots...` flaky karena undian acak.**~~ **Ketiga test baseline sudah diperbaiki di Tahap 10; suite hijau penuh.** Catatan ini disimpan karena diagnosisnya salah selama berminggu-minggu dan kesalahannya instruktif: yang acak **bukan** hasil undian babak grup melainkan **tabel klasemen nol-hasil**. Saat semua tim seri, tiap aturan tiebreaker tidak punya apa-apa untuk dijawab dan urutannya jatuh ke `StandingService::lot()` = `crc32(category_id . team_id)` — uuid baru tiap run, jadi permutasi baru tiap run. Test yang membandingkan "sesudah" dengan "sebelum" karena itu memilih titik acuan yang **bergerak**. Pelajarannya: sebelum melabeli sesuatu flaky, cari **sisi mana** yang acak; melabelinya lebih murah daripada mencarinya, dan label itu menular ke test lain yang sebenarnya rusak deterministik (dua `CatalogTest` itu ikut tertutup selama berbulan-bulan karena disebut sebaris dengan ini).

0. **`$model->update()` menelan kolom yang tidak ada di `$fillable`, tanpa error.** Backfill pertama melaporkan "100 event diberi paket" padahal `events.plan_id` masih null semuanya — `plan_id` belum ditambahkan ke `Event::$fillable`. Sekarang `BackfillEventPlans` menghitung ulang di akhir dan **gagal** kalau masih ada sisa. Setiap kali menambah kolom baru: cek `$fillable` **sebelum** menulis kode yang mengisinya. Terkait: jangan mencentang checkbox tracker secara borongan — item ini tercentang tanpa pernah dikerjakan.

1. **`withinLimit()` memperlakukan nilai absen sebagai unlimited.** Karena itu galeri butuh **dua** key: `event_gallery` (boolean, yang menolak) + `max_gallery_photos` (angka, yang membatasi). Boolean **wajib** dicek duluan.
2. **`platformDestination()` harus tetap planless.** Membeli paket pertama terjadi sebelum ada event sama sekali.
3. **`activate()` tidak boleh mengikat event** — event-nya belum ada saat pembayaran settle.
4. **Klaim kredit wajib `whereNull('event_id')` di dalam UPDATE**, bukan `lockForUpdate` saja — kalau tidak, dua create bersamaan menimpa satu sama lain.
5. **`platform_fee` tetap di-snapshot per order**, tidak pernah diturunkan ulang saat dibaca. Sumbernya yang pindah, bukan aturannya.
6. **Transfer manual tetap tidak menyentuh dompet** dan `platform_fee` = 0. Uji dengan **membandingkan** gateway vs manual di event yang sama.
7. **`CertificateController::download`/`destroy` sengaja tetap terbuka.** Sertifikat yang sah terbit di bawah event berbayar wajib bisa diunduh selamanya.
8. **`PlanGate::orgAllows()` sengaja monoton.** Mencabut profil publik saat turnamen selesai akan mem-404-kan URL yang sudah terindeks.
