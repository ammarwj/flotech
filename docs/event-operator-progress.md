# Progress: Event Operator per Event dengan Permission Dinamis

Desain lengkap: `docs/event-operator-design.md`

## Backend
- [x] Migration `event_operators`
- [x] Migration `event_operator_invites`
- [x] Migration partial unique index pending
- [x] Model `EventOperator`
- [x] Model `EventOperatorInvite`
- [x] Middleware `EventOrOperatorScope` (`event.access`)
- [x] Middleware `EnsureOperatorPermission` x4 (`event.perm.ticketing/scheduling/registration/documents`)
- [x] Util `EventOperatorAttribution::resolveEventFrom()` untuk route tanpa `{event}` di path
- [x] Service `EventOperatorService` (invite/resend/revoke/accept/updatePermissions/remove)
- [x] Controller `EventOperatorController` (index/invites/invite/resend/revoke/updatePermissions/remove)
- [x] Controller `EventOperatorInviteController` (show/accept/pending/myEvents)
- [x] Route mapping api.php — ticketing, scheduling (exclude confirm), registration, documents
- [x] Mail `EventOperatorInvitedMail` + blade template
- [x] `MailLinks::operatorInvite()`
- [x] Endpoint `GET my-operator-events`
- [x] FormRequest validasi invite (email + minimal 1 kategori true)

## Frontend
- [x] `web/lib/api/event-operators.ts`
- [x] `web/lib/event-operator-permissions.ts`
- [x] Halaman `organizer/events/[id]/operators/page.tsx`
- [x] `InviteOperatorDialog` (+ `EditOperatorPermissionsDialog` untuk edit akses)
- [x] Halaman publik `app/invites/[token]/page.tsx`
- [x] Register page: terima `?email=` prefill + lock
- [x] `use-dashboard-mode.ts` extend untuk event-operator-only user — `hasOperatorAccess()`, dipakai di `OrganizerLayout` (skip redirect ke `/onboarding`) dan `DashboardPage`/`login/page.tsx` (redirect ke `/organizer/operating`). Backend: `AuthController::withAuth()` eager-load `operatorAssignments`, `UserResource.operating`.
- [x] Landing page `/organizer/operating` (`web/app/(dashboard)/organizer/operating/page.tsx`) — baca `GET my-operator-events`; 1 event → langsung redirect, >1 → daftar pilihan.
- [x] **Gap 1 (temuan QA lanjutan)**: `GET events/{event}` (show) dipindah ke bawah `event.access`, di luar `event.perm.*` apa pun — operator dengan kategori manapun (bahkan satu saja) bisa membaca detail event (nama/status/tanggal) untuk header/breadcrumb, read-only. `PATCH`/`DELETE events/{event}` TETAP di route `tenant` polos (bukan `event.access`) — operator ditolak di sana sebelum controller jalan sama sekali, tidak ada perubahan perilaku untuk org member. Lihat komentar di `api/routes/api.php` baris ~500-517 dan `EventResource::viewerPermissions()`. Diverifikasi via test (sudah ada sebelumnya, lihat bagian Review).
- [x] **Gap 2 (temuan QA lanjutan)**: `EventResource` mengirim `viewer_permissions` (`null` = org member/unrestricted, object 4 boolean = event operator, dibaca dari `operator` request attribute — lihat `EventResource::viewerPermissions()`). Frontend: helper baru `canAccessCategory(perm, category)` dan `isOrgMember(perm)` di `web/lib/event-operator-permissions.ts`. **Tidak ada satu komponen nav/sidebar terpusat untuk sub-page event** (dikonfirmasi — setiap sub-page punya cross-link sendiri di `PageHeader`-nya, lihat komentar di `sidebar-nav.tsx` kenapa sidebar global tidak punya menu per-event). Filter diterapkan di titik cross-link yang ADA, bukan komponen baru: `edit/page.tsx` (link ke schedule/registration-form/discount-codes/media/operators/domain + tombol Hapus), `registrations/page.tsx` (link ke registration-form & payments), `tickets/page.tsx` (link ke buyers). Tiap link dicocokkan ke kategori backend-nya yang sebenarnya menggerbang rute tujuan (`event.perm.scheduling/registration/documents`, atau `isOrgMember` untuk rute yang tetap `tenant`/`org.admin` murni tanpa jalur operator sama sekali — registration-form, operators, domain, ticket-orders/buyers, payments queue). Halaman lain (`media`, `operators`, `personnel`, `discount-codes`, `registration-form`, `schedule`, `race`) tidak punya cross-link internal ke sub-page lain selain `backHref` ke `/edit`, jadi tidak perlu filter tambahan — mereka sendiri sudah tergate oleh kategori/`org.admin` di level route.
- [x] **Gap 3 (`orgId` tidak bisa diresolve untuk operator murni) — SELESAI**: `useActiveOrg()` (`web/lib/hooks/use-active-org.ts`) dulu cuma membaca `GET /organizations`, kosong untuk operator murni (nol baris `organization_members`), sehingga `orgId` jatuh ke `null` dan setiap halaman sub-event (`getEvent(orgId!, eventId)` di-gate `enabled: !!orgId`) tersangkut skeleton selamanya. Diperbaiki **di dalam hook itu sendiri**, tanpa menyentuh satu pun dari 18 pemanggilnya: `useActiveOrg()` sekarang membaca `{id}` dari route (`useParams<{id}>()` — pola yang sama dengan `modeFromPath()` di `use-dashboard-mode.ts`, setiap halaman event memang berada di `/organizer/events/{id}/...`), dan HANYA kalau `GET /organizations` sudah confirmed kosong (`hasNoOrg`) DAN ada `{id}` di URL, menjalankan `GET my-operator-events` lalu mencari entry yang `event.id`-nya cocok untuk mengambil `organization.id`-nya. Fallback query di-gate `enabled: hasNoOrg && !!eventId` — org member (selalu punya minimal satu org) tidak pernah memicunya, jadi `orgId` mereka dihitung persis seperti sebelumnya, zero behavior change. Dengan `orgId` yang resolve, seluruh alur kerja operator murni otomatis jalan tanpa perubahan lain: halaman event `getEvent()` sukses, `viewer_permissions` dari `EventResource` tetap jadi sumber filter cross-link (Gap 2), dan rute yang permission-nya tidak dimiliki tetap menjawab 403 bersih dari API (bukan crash React) karena middleware `event.perm.*`/`EventOrOperatorScope` sudah menegakkannya di server sejak awal — tidak ada perubahan backend sama sekali.
  - File diubah: `web/lib/hooks/use-active-org.ts` saja.
  - Verifikasi: `bun run build` + `bunx tsc --noEmit` sukses tanpa error. `php artisan test --filter=EventOperatorTest` (30 test) dan full suite (`php artisan test`) tetap **1334 passed** — baseline utuh, backend tidak disentuh. End-to-end nyata via curl dengan fixture operator murni (user tanpa baris `organization_members`, satu `EventOperator` row `can_ticketing=true` saja): `GET /organizations` → `[]`, `GET /my-operator-events` → satu entry dengan `organization.id` yang benar, `GET /organizations/{resolved_org_id}/events/{event}` → 200 dengan `viewer_permissions` yang benar, `GET .../ticket-categories` (kategori yang dimiliki) → 200, `GET .../registrations` (kategori yang TIDAK dimiliki) → 403 bersih (`{"success":false,"message":"Kamu tidak punya izin untuk aksi ini."}`), bukan crash. Fixture dibersihkan sesudahnya.

## Review
- [x] Security review
- [x] QA / test coverage — `api/tests/Feature/EventOperatorTest.php` (27 test): invite/accept/resend/revoke lifecycle, gating per kategori, invariant uang & admin (termasuk `matches/{match}/confirm` eksplisit), isolasi cross-event & cross-org, 404 utk management cross-org, regresi partial PATCH (Temuan 1), cascade penghapusan event draft.
