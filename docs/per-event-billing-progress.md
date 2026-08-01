# Progress: billing per-event

Tracker pengerjaan migrasi dari langganan bulanan org-level ke **pembelian paket sekali bayar per event**.

- **Rencana lengkap**: `~/.claude/plans/no-item-glistening-leaf.md` (referensi §-nya disebut di tiap item)
- **Branch**: `feat/per-event-billing`
- **Baseline test sebelum perubahan** (2026-08-01): `412 passed`, 2–3 gagal yang **sudah gagal sejak awal & flaky** — `CatalogTest > public catalog lists sports and options`, `CatalogTest > a sport added to the catalog can immediately host an event`, `KnockoutPlanTest > plan is saved in slots and read back with live occupants`. Ketiganya **tidak** disebabkan perubahan ini; jangan dikejar.

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
| 2 | Katalog paket + backfill | `[ ]` |
| 3 | `PlanGate` + call site backend | `[ ]` |
| 4 | Siklus order + rute + resource | `[ ]` |
| 5 | Drop kolom paket di `organizations` | `[ ]` |
| 6 | Exporter Excel/PDF + katup super_admin | `[ ]` |
| 7 | Tipe frontend + `lib/plan.ts` + `lib/api` | `[ ]` |
| 8 | Halaman frontend | `[ ]` |
| 9 | Test backend + e2e | `[ ]` |
| 10 | Docs (`CLAUDE.md`) + verifikasi manual | `[ ]` |

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

## Tahap 2 — Katalog + backfill

- [ ] `2026_08_01_100003_seed_per_event_plan_catalogue.php` — **4 langkah urut**: upsert 3 paket (match `slug`, id lama dipertahankan) → pensiunkan `basic` (`is_active`/`is_public` false + hapus `plan_features`-nya, **jangan delete barisnya**) → **prune** 7 key pensiun dari `plan_features` **dan** `feature_definitions` → tulis 13 definisi + nilai per paket
- [x] `database/seeders/PlanSeeder.php` — **dikerjakan di Tahap 1**: 3 paket, harga tunggal, fitur "tidak dapat" tidak ditulis
- [x] `database/seeders/FeatureDefinitionSeeder.php` — **dikerjakan di Tahap 1**: 13 definisi
- [ ] `app/Console/Commands/BackfillEventPlans.php` — `events:backfill-plan {--dry-run}`, idempoten (`whereNull('plan_id')` + `whereNotExists` order), `invoice_number`/`receipt_number` **null**
- [ ] `2026_08_01_100004_backfill_event_plans.php` memanggil command itu
- [ ] **Verifikasi silang**: `migrate` terhadap `flo_event` (salinan prod) vs `migrate:fresh --seed` di DB **sekali-pakai** `flo_event_scratch` → diff isi `plan_features` **harus identik**. ⚠️ jangan `migrate:fresh` di `flo_event`
- [ ] `events:backfill-plan --dry-run` terhadap `flo_event` melaporkan angka masuk akal
- [ ] Test yang dipecahkan tahap ini: yang meng-assert key fitur spesifik (`max_active_events`, `max_teams_per_event`, `*_fee_percent`) → ✅ `php artisan test` hijau

> Prune di **migrasi, bukan seeder**: seeder yang menghapus akan ikut menyapu key custom yang ditambahkan super_admin di `/admin/plans`.

## Tahap 3 — `PlanGate` + penegakan backend

- [ ] `app/Exceptions/PlanFeatureException.php` (pola `WalletException`) + render di `bootstrap/app.php`
- [ ] `app/Services/PlanGate.php` ditulis ulang (§2): `planValue`/`planAllows`/`planLimit`/`planWithinLimit` + pembungkus Event + `orgAllows` + `flush()` + memo per plan id
- [ ] `tests/TestCase.php` `setUp()` tambah `PlanGate::flush()` (di sebelah `Catalog::flush()`)
- [ ] **Hapus** `app/Http/Middleware/CheckPlanFeature.php`, `CheckPlanLimit.php`, dan kedua alias di `bootstrap/app.php:53-54`

Call site (§5), satu checkbox per titik:
- [ ] `EventController::syncCategories()` — gate `max_categories` (sebelum loop, `current: 0, adding: count($categories)`) + cap `max_teams` per kategori (**422 field path**, bukan 403)
- [ ] `EventController::store()` — **hapus** blok `max_active_events`
- [ ] `RegistrationController::store()` — `max_teams_per_event` → `max_teams_per_category` (403 + feature)
- [ ] `Public/PublicEventController::register()` — `max_teams_per_category` (**422** + feature key) **dan** gate `online_registration` (422) di atas `isRegistrationOpen()`
- [ ] `EventMediaController` — tambah `__construct(protected PlanGate $gate)` (belum punya constructor); gate `sponsor_logos` di `storeSponsor`; gate `event_gallery` **lalu** `max_gallery_photos` (total event, bukan per-request) di `storePhotos`; `updateSponsor`/`destroySponsor` **tetap** tanpa gate
- [ ] `PaymentRails::destinationFor()` → `(Event $event, float $amount)`; `platformDestination()` **tidak disentuh & tetap planless**
- [ ] `RegistrationService::startPayment(Team $team)` — buang argumen `$org`; `PublicEventController::register()` tambah `$team->setRelation('event', $event)`; perbaiki pemanggil `MyTeamController`/`RegistrationController`
- [ ] `TicketService::platformFee(Event, float)` + `RegistrationService::platformFee(Event, float)` → key `platform_fee_percent`
- [ ] `TicketCategoryController::ensureTicketsEnabled(Event)`; **hapus** `ensureWithinTicketLimit()` + 2 call site-nya
- [ ] `Public/PublicTicketController::purchase()` — `destinationFor($event,…)`, `platformFee($event,…)`, `qr_tickets` (422 + feature key)
- [ ] `CertificateController` — `ensureEnabled` pindah **setelah** `findEvent`; `generate`/`send` gate di event; `download`/`destroy` **tetap terbuka + tulis alasannya di kode**
- [ ] `CertificateTemplateController` — `orgAllows($org, 'certificate_generator')` (template org-scoped, tanpa `event_id`)
- [ ] `Public/PublicOrganizationController::show()` + `PublicOrganizationResource($org, bool $rich)` — degradasi jadi daftar event, **bukan 404**
- [ ] `Admin\PlanController::destroy()` — tolak 422 kalau `$plan->events()->exists()`

Fixture + migrasi test **dikerjakan di tahap ini**, karena di sinilah gate berhenti org-keyed:
- [ ] `tests/Concerns/CreatesPlannedEvents.php` — `planWith`/`orgFor`/`creditFor`/`eventOn`
- [ ] Migrasi **45 file test** dari `orgWithPlan()` ke trait (mekanis)
- [ ] `EventTest::test_plan_limit_blocks_extra_events` **dihapus**; `EventMediaTest` butuh paket yang mengizinkan foto & sponsor
- [ ] ✅ `php artisan test` hijau

## Tahap 4 — Siklus order + rute + resource

- [ ] `EventPlanOrderService::checkout(Organization, Plan)` — buang aritmetika siklus; `platformDestination()` tetap ditanya **sebelum** baris dibuat (checkout ditolak tidak boleh membakar nomor invoice)
- [ ] `EventPlanOrderService::activate()` — **tidak menulis apa pun ke `organizations`**; idempotensi receipt + email dipertahankan
- [ ] `pay()` tambah guard menolak order yang sudah dipakai
- [ ] `EventController::store()` — `DB::transaction` + `claimOrder()` + **klaim atomik** `whereNull('event_id')` di dalam UPDATE
- [ ] `StoreEventRequest` tambah `plan_order_id` nullable uuid (kepemilikan dicek di `claimOrder`, bukan FormRequest)
- [ ] `routes/api.php` — rute `plan-orders` (organizer + admin); **`POST events` pindah ke belakang `org.admin`**
- [ ] `PlanOrder/CheckoutRequest` — buang `billing_cycle`; `exists` dibatasi `is_active = true`
- [ ] `EventPlanOrderResource` — `event_id`, `consumed_at`, `event`
- [ ] `PlanSummaryResource` baru; `EventResource` memakainya; `EventController::index/show` `->with('plan.features')`
- [ ] `OrganizationResource` — buang `plan_id`/`plan_expires_at`/`plan`, tambah `unconsumed_plan_orders_count`, rename `subscription_awaiting_verification` → `plan_payment_awaiting_verification`
- [ ] `resources/views/pdf/_document.blade.php` — "Siklus" → "Event"; blade mail ikut
- [ ] `BillingDocumentService` — tipe + `loadMissing('plan','organization','event')`
- [ ] `MidtransWebhookController` — tipe baru; **arm `SUB-` tidak disentuh**

## Tahap 5 — Lepas kolom paket org

- [ ] `2026_08_01_100005_drop_plan_from_organizations_table.php` (**dijalankan terakhir** — backfill & semua tahap sebelumnya harus bisa jalan di DB yang masih punya kolomnya)
- [ ] Hapus `Organization::plan()`, fillable, casts
- [ ] Hapus `OrganizationController::assignPlan()` + rute `PATCH organizations/{org}/plan`
- [ ] Hapus `plan_id` dari `StoreOrganizationRequest`
- [ ] Hapus 4× `->with('plan.features')` di `OrganizationController`

## Tahap 6 — Exporter + katup super_admin

> `maatwebsite/excel ^3.1` **sudah ada di `composer.json` tapi belum dipakai satu baris pun**; `barryvdh/laravel-dompdf` sudah dipakai sertifikat & invoice. **Tidak ada dependency baru.**

- [ ] `app/Http/Controllers/Api/ExportController.php` — gate `export_data` di **baris pertama** tiap method
- [ ] `app/Exports/{Registrations,TicketBuyers,Standings,Leaderboard}Export.php` — **pakai ulang** `StandingService` & `PlayerStatService::leaderboard()`, jangan tulis ulang agregasi (itu cara dua angka di layar yang sama jadi berbeda)
- [ ] `resources/views/pdf/export.blade.php` — helper format **dioper sebagai view data**, bukan didefinisikan di layout (Blade menjalankan section anak sebelum layout)
- [ ] Rute `GET organizations/{org}/events/{event}/exports/{kind}?format=xlsx|pdf` di bawah `tenant` + `org.admin`
- [ ] `POST admin/events/{event}/reassign-plan` (§7) — unique index **tetap**; null-kan `event_id` lama lalu klaim baru dalam satu transaksi

## Tahap 7 — Tipe frontend + lib

- [ ] `web/types/api.ts` — `Plan.price`, `PlanSummary` baru, `SportEvent.plan`, `EventPlanOrder`, `PlanOrderStatus`, `CheckoutResult.plan_order`, `Organization` (§8.2)
- [ ] `web/lib/plan.ts` ditulis ulang event-keyed (§8.1) — hapus 9 fungsi siklus/org, tambah `planAllows`/`planLimit`/6 helper boolean/3 helper limit/`anyEventAllows`/`unconsumedOrders`
- [ ] `web/lib/api/organizations.ts` — rename 5 fungsi ke `/plan-orders`
- [ ] `web/lib/api/exports.ts` baru — `apiClient` + `responseType: "blob"` (**bukan `<a href>`** — token in-memory, akan 401)
- [ ] `web/lib/checkout.ts` — `res.plan_order.status === "paid"`
- [ ] `web/lib/labels.ts` — hapus `BILLING_CYCLE_LABELS`, tambah `PLAN_ORDER_STATUS_LABELS`
- [ ] `cd web && bun run build` → kerjakan error TypeScript ke luar (itulah daftar konsumen yang tersisa)

## Tahap 8 — Halaman frontend

- [ ] `components/event/event-limit-notice.tsx` → `plan-purchase-notice.tsx` (grid paket inline; cabang "batas event aktif" dihapus)
- [ ] `organizer/events/new/page.tsx` — cabang 0 / 1 / >1 kredit; kirim `plan_order_id`; tangani `plan_order_required`
- [ ] `EventForm` — cap **proaktif** (tombol tambah kategori mati, `max` di input `max_teams`, baris ringkasan paket)
- [ ] `organizer/subscription/page.tsx` → `organizer/billing/page.tsx` — 3 blok (§8.3); hapus kartu "Paket saat ini", `daysUntil`, `currentCycle`
- [ ] `organizer/upgrade/page.tsx` → `organizer/plans/page.tsx` — tanpa toggle
- [ ] `next.config` redirect `/organizer/upgrade` + `/organizer/subscription`
- [ ] 7× CTA "Upgrade paket" → "Beli paket"; `components/auth/public-auth-actions.tsx:143`
- [ ] `onboarding/page.tsx` — **3 langkah → 1**; hapus `Steps`/`STEP_COPY`/`subsQuery`/`pendingManual`/`BillingCycleToggle`
- [ ] `subscription-pending-banner.tsx` → `plan-payment-pending-banner.tsx`; **baru** `unconsumed-plan-banner.tsx`
- [ ] `organizer/tickets/page.tsx` — empty state satu-halaman → **per-baris event**
- [ ] `organizer/certificates/page.tsx` + `generate/page.tsx` + template `new`/`[id]` (**yang terakhir belum punya gate sama sekali**)
- [ ] `organizer/events/[id]/media/page.tsx` — gate sponsor & galeri, tampilkan `n/15`
- [ ] Tombol export → dropdown Excel/PDF di `events/[id]/tickets/buyers/page.tsx` + `components/event/leaderboard-table.tsx`; **hapus `web/lib/csv.ts`** dan kedua pemanggilnya
- [ ] `components/subscription/plan-card.tsx` — hapus `BillingCycle`/`BillingCycleToggle`/prop `cycle`/baris coret/`Ditagih …/tahun`/badge hemat
- [ ] `components/landing/pricing.tsx` — tanpa toggle, `/event`, `platform_fee_percent`, **3 CTA self-serve** (mailto sales dilepas)
- [ ] `app/layout.tsx:52` — buang `data-billing`
- [ ] `app/globals.css` blok PRICING (~965-1050) — hapus 11 rule siklus; **pertahankan** `.price-grid`/`--plan-count`/`.plan`/`.plan.featured`/`.plan-tag`/`.plan-feats`/`.price-foot`
- [ ] `components/shared/status-badge.tsx` + `components/dashboard/sidebar-nav.tsx`
- [ ] `admin/plans/page.tsx` — satu input harga ("Harga per event")
- [ ] `admin/subscriptions/page.tsx` → `admin/plan-orders/page.tsx` — tambah kolom event
- [ ] `grep -rn "data-billing\|bill-switch\|billing_cycle\|price_monthly\|BillingCycle" web` → nol hasil

## Tahap 9 — Test baru

> Fixture (`CreatesPlannedEvents`) dan migrasi 45 file test sudah selesai di **Tahap 3**; perbaikan test lain sudah dikerjakan di tahap penyebabnya. Tahap ini tinggal **menambah** test yang membuktikan perilaku baru.

19 test baru §9.2 — **semuanya komparatif** (assert satu nilai akan lolos walau fiturnya tidak pernah jalan):
- [ ] 1. dua event satu org → entitlement berbeda *(kunci utama)*
- [ ] 2. `platform_fee` dari paket **event**, bukan org (3% vs 1%, harga identik, satu test)
- [ ] 3. fee pendaftaran key sama + manual = 0 **dengan ledger kosong** di kedua paket
- [ ] 4. kredit lunas dipakai **tepat sekali** (assert jumlah event 0→1→**1**)
- [ ] 5. organizer bisa memilih kredit mana yang dipakai (assert Starter **masih** utuh)
- [ ] 6. event mempertahankan paketnya walau paket lebih besar dibeli belakangan
- [ ] 7. `max_categories` menolak **seluruh** create (assert event **dan** `event_id` tak tersentuh)
- [ ] 8. `max_teams` kategori tidak boleh lewat cap paket (422 + kasus yang lolos di test yang sama)
- [ ] 9. cap per **kategori**, bukan per event (2+2 lolos, ke-3 di A gagal)
- [ ] 10. cap galeri menghitung **total event**, bukan satu request (10 lolos, 10 berikutnya ditolak)
- [ ] 11. galeri ditolak tanpa boolean-nya *(jebakan "null lolos bebas")*
- [ ] 12. logo sponsor ditolak di Starter, diterima di Pro (body identik byte-per-byte)
- [ ] 13. profil publik baru kaya setelah ada event yang membawanya
- [ ] 14. key pensiun **tidak memberi apa-apa**
- [ ] 15. `events:backfill-plan` idempoten (**jalankan dua kali**)
- [ ] 16. `activate()` idempoten **dan tidak menulis ke `organizations`**
- [ ] 17. `online_registration` & `qr_tickets` bisa dimatikan per event
- [ ] 18. export butuh paket (403 vs file non-kosong, satu test)
- [ ] 19. operator tidak bisa membuat event
- [ ] `php artisan test` hijau (kecuali 2–3 flaky baseline)
- [ ] E2E: `subscription-manual*.spec.ts`, `auth-onboarding.spec.ts`, `fixtures/api.ts` + spec baru **beli → buat → terpakai**

## Tahap 10 — Docs + verifikasi manual

- [ ] `CLAUDE.md` — tulis ulang "Pola: plan limit / feature gating" dan "Pola: langganan & dokumen tagihan": invarian paket-per-event, model kredit, klaim atomik, `orgAllows` monoton, divergensi 403-vs-422 di dua pintu pendaftaran
- [ ] Catatan rilis: **operator tidak bisa lagi membuat event**

Checklist verifikasi manual (19 poin, §10 rencana):
- [ ] 1. `/pricing` 3 kartu, `/event`, tanpa toggle, "Paling Populer" di Pro, footnote fee 3/2/1%
- [ ] 2. `<body>` tanpa `data-billing`; tidak ada node `.bill-switch`
- [ ] 3. User baru → onboarding cuma nama organisasi → `/organizer`
- [ ] 4. `/organizer/events/new` tanpa kredit → pemilih paket, bukan form
- [ ] 5. Beli Starter gateway **nyala** → `paid`, muncul di "Paket siap dipakai", banner tampil
- [ ] 6. Beli Starter gateway **mati** → panel manual → acc di `/admin/plan-orders` → kredit muncul; PDF invoice **dan** kwitansi render benar
- [ ] 7. Buat event dari kredit → hilang dari "siap dipakai", riwayat menyebut nama event
- [ ] 8. Event kedua ditolak, **tanpa draft tertinggal**
- [ ] 9. Kategori ke-2 ditolak (proaktif + 403 kalau dipaksa API); `max_teams` > 32 ditolak inline
- [ ] 10. Tim ke-33 ditolak; pencacah kategori event kedua **independen**
- [ ] 11. Di Starter: sponsor/galeri/sertifikat/export absen. Di Professional: keempatnya ada **hanya di event itu**
- [ ] 12. Galeri: 10 lolos, 10 berikutnya ditolak di 15
- [ ] 13. Tiket harga sama → `platform_fee` 3% vs 1%
- [ ] 14. Export xlsx & pdf dari Pro terunduh dan terbuka; dari Starter → 403
- [ ] 15. `/{orgSlug}` kaya hanya setelah ada event Pro/Professional; sebelumnya tetap 200 + grid event
- [ ] 16. `/organizer/upgrade` & `/organizer/subscription` redirect
- [ ] 17. Operator: 403 di `/organizer/billing`, tidak bisa buat event
- [ ] 18. Event hasil backfill masih punya sertifikat/galeri/sponsor/export + invoice historis render
- [ ] 19. `plan-orders:expire-manual` membatalkan yang tanpa bukti, **membiarkan** yang ada buktinya

---

## Utang yang sengaja ditinggalkan (jangan hilang)

- [ ] Daftar admin untuk kredit lunas menganggur > N hari + email pengingat (§11.1)
- [ ] Pertimbangkan mengganti label `max_teams_per_category` jadi "Entri per kategori" — "peserta" terbaca sebagai *orang*, padahal yang dihitung entri (§11.2)
- [ ] Putuskan apakah butuh paket ke-4 `is_public: false` untuk deal enterprise, karena CTA "Hubungi Sales" dilepas (§11.4)

## Jebakan yang sudah diketahui (baca sebelum menyentuh area terkait)

1. **`withinLimit()` memperlakukan nilai absen sebagai unlimited.** Karena itu galeri butuh **dua** key: `event_gallery` (boolean, yang menolak) + `max_gallery_photos` (angka, yang membatasi). Boolean **wajib** dicek duluan.
2. **`platformDestination()` harus tetap planless.** Membeli paket pertama terjadi sebelum ada event sama sekali.
3. **`activate()` tidak boleh mengikat event** — event-nya belum ada saat pembayaran settle.
4. **Klaim kredit wajib `whereNull('event_id')` di dalam UPDATE**, bukan `lockForUpdate` saja — kalau tidak, dua create bersamaan menimpa satu sama lain.
5. **`platform_fee` tetap di-snapshot per order**, tidak pernah diturunkan ulang saat dibaca. Sumbernya yang pindah, bukan aturannya.
6. **Transfer manual tetap tidak menyentuh dompet** dan `platform_fee` = 0. Uji dengan **membandingkan** gateway vs manual di event yang sama.
7. **`CertificateController::download`/`destroy` sengaja tetap terbuka.** Sertifikat yang sah terbit di bawah event berbayar wajib bisa diunduh selamanya.
8. **`PlanGate::orgAllows()` sengaja monoton.** Mencabut profil publik saat turnamen selesai akan mem-404-kan URL yang sudah terindeks.
