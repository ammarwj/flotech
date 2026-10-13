# Kode diskon checkout paket — progress

Fitur: potongan harga (persentase / nominal) saat organizer membeli paket event.
Dikelola super_admin di `/admin/discount-codes`, dipakai organizer di `/organizer/plans`.

Keputusan: **checkout saja** (upgrade tidak menerima kode), batasan lengkap
(aktif, jendela, kuota, 1× per org, batasan paket), total Rp 0 langsung lunas,
kuota dihitung saat lunas. Pola lengkap didokumentasikan di `CLAUDE.md`
(section "Kode diskon").

## Backend

- [x] Migrasi `create_discount_codes_table` + pivot `discount_code_plan`
- [x] Migrasi `add_discount_to_event_plan_orders_table` (FK + 4 kolom snapshot)
- [x] `App\Models\DiscountCode` (`TYPES`, `normalize()`, `discountFor()`, `plans()`)
- [x] `App\Services\DiscountCodeService` (`resolve()`, `usage()`) — satu-satunya gate
- [x] `EventPlanOrder`: fillable + casts + relasi `discountCode()`
- [x] `EventPlanOrderService::checkout()` menerima kode, diskon sebelum `resolvePayment()`
- [x] Cabang total nol di `start()` (lindungi `pay()` juga) → `activate($order, 'discount')`
- [x] `CheckoutRequest` + `PlanOrderController::discountPreview()` + rute
- [x] `EventPlanOrderResource` menerbitkan 4 field diskon
- [x] Admin CRUD: `Api\Admin\DiscountCodeController`, `DiscountCodeRequest`, `DiscountCodeResource`, rute `apiResource`
- [x] PDF: baris potongan di `_document.blade.php`, label `discount` di `receipt.blade.php`
- [x] Mail: baris potongan di `plan-order-invoice-issued` & `plan-order-paid`

## Frontend

- [x] Tipe: `DiscountCode`, `DiscountPreview`, `DiscountType`, 4 field di `EventPlanOrder`
- [x] `checkoutPlan(..., discountCode)` + `previewDiscount()` di `lib/api/organizations.ts`
- [x] `lib/api/discount-codes.ts` (kuartet admin CRUD)
- [x] `/organizer/plans`: dialog di kedua rail, input kode + preview, `ChannelPicker` pakai `preview.amount`
- [x] `/admin/discount-codes` (create/edit/list + multi-select paket + neraca pemakaian)
- [x] Sidebar admin: entri "Kode Diskon"
- [x] `/organizer/billing`: badge diskon di kartu kredit & riwayat

## Verifikasi

- [x] `PlanDiscountCodeTest` — 16 test, 104 assertion, semua **membandingkan** dua kasus
- [x] Suite penuh backend hijau (901+ test)
- [x] `tsc --noEmit`, `eslint`, `bun run build` bersih
- [x] Render Blade invoice/receipt diperiksa langsung (baris potongan, total, label)
- [ ] Uji manual di browser (buat kode → checkout → PDF → kode kedua ditolak)
- [ ] Deploy: `php artisan migrate` (dua migrasi baru, tanpa seeder — nol baris itu benar)
