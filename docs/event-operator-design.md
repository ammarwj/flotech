# Desain Teknis: Event Operator per Event dengan Permission Dinamis

Status: desain disetujui, siap implementasi. Lihat `docs/event-operator-progress.md` untuk tracking.

## 0. Konteks & keputusan final (dikonfirmasi user)

- Yang mengundang: organizer (org admin/owner) sendiri, bukan super_admin.
- Scope: **per event**, bukan per organisasi.
- Invite: **full flow token + email**, satu code path untuk email sudah terdaftar maupun belum — selalu lewat halaman invite + klik "Terima" eksplisit (tidak ada auto-attach).
- 4 kategori permission independen (boolean, bisa dikombinasi bebas): `ticketing`, `scheduling`, `registration`, `documents`.
- "All akses" di UI = shortcut yang mencentang ke-4 kategori itu sekaligus. **Bukan** kolom ke-5, dan **tidak pernah** termasuk uang (wallet/bank-account/withdrawal/plan-orders), exports, org profile update, event store, `matches/{match}/confirm`, ticket-orders index, race-entries index/update/cancel, race-refunds, payments verification — semua itu **tetap eksklusif org.admin selamanya**, tidak ada jalan grant ke event operator apa pun permission-nya.
- Event Operator adalah konsep BARU, terpisah total dari `organization_members` (role org) dan `event_personnel` (referee/staff). Satu user boleh jadi event operator dan event_personnel sekaligus di event yang sama — independen.

## 1. Skema DB

### 1.1 `event_operators`

```php
Schema::create('event_operators', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
    $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
    $table->boolean('can_ticketing')->default(false);
    $table->boolean('can_scheduling')->default(false);
    $table->boolean('can_registration')->default(false);
    $table->boolean('can_documents')->default(false);
    $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->unique(['event_id', 'user_id']);
});
```

4 kolom boolean dipilih (bukan JSON array, bukan pivot permission table) karena himpunan kategori FIX dan dikunci di kode — middleware harus tahu nama kategorinya untuk gate route, sama persis `EventPersonnel::KINDS` yang array hardcoded, bukan katalog dinamis macam `feature_definitions`. Tidak ada kolom `role`: kombinasi 4 boolean itu sendiri sudah menjawab "siapa dia", kolom role akan jadi fakta kedua yang bisa berselisih (pelajaran `effective_payment_method`).

### 1.2 `event_operator_invites`

```php
Schema::create('event_operator_invites', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
    $table->string('email');
    $table->boolean('can_ticketing')->default(false);
    $table->boolean('can_scheduling')->default(false);
    $table->boolean('can_registration')->default(false);
    $table->boolean('can_documents')->default(false);
    $table->string('status', 20)->default('pending'); // pending|accepted|revoked|expired
    $table->string('token', 64)->unique();
    $table->timestamp('expires_at');
    $table->timestamp('accepted_at')->nullable();
    $table->foreignUuid('invited_by')->constrained('users');
    $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->index(['event_id', 'email']);
});
```

Partial unique index via raw SQL (migration terpisah) untuk "satu email+event cuma satu invite pending":

```php
DB::statement("CREATE UNIQUE INDEX event_operator_invites_pending_unique ON event_operator_invites (event_id, email) WHERE status = 'pending'");
```

Invite tabel terpisah dari `event_operators` (bukan kolom token nullable di baris operator) — nullable-pair anti-pattern yang CLAUDE.md berulang kali tolak. Baris `event_operators` hanya ada **setelah** accept.

### 1.3 Migration files
```
2026_11_26_100000_create_event_operators_table.php
2026_11_26_100001_create_event_operator_invites_table.php
2026_11_26_100002_add_pending_unique_index_to_event_operator_invites.php
```

## 2. Model

`EventOperator` — `PERMISSIONS = ['ticketing','scheduling','registration','documents']`, method `has(string $permission): bool`.
`EventOperatorInvite` — `STATUSES = ['pending','accepted','revoked','expired']`, `isExpired()`, `isUsable()`.

## 3. Middleware

### `EventOrOperatorScope` (alias `event.access`)
Jalur paralel ke `tenant`, dipakai HANYA di sub-route yang punya `{event}` param. Resolve dua jenis caller:
- Org member yang lolos `tenant` → set `organization` attribute seperti biasa, lanjut tanpa dibatasi.
- Event Operator (tidak punya baris `organization_members`) → set `event` + `operator` attribute, **TIDAK PERNAH** set `organization`.

Invariant kunci (tiru alasan `EventPersonnelScope`): begitu `organization` attribute ada di request, SEMUA controller tenant-scoped jalan untuk pemiliknya tanpa sadar — termasuk wallet/billing. `organization` wajib tetap null untuk event operator selamanya.

### `EnsureOperatorPermission` (4 alias: `event.perm.ticketing`, `event.perm.scheduling`, `event.perm.registration`, `event.perm.documents`)
No-op kalau `operator` attribute null (berarti org member asli, akses penuh). Kalau `operator` ada dan `!$operator->has($category)` → 403.

`org.admin` TIDAK disentuh sama sekali — endpoint uang/plan/event-store/confirm tetap di situ, operator tidak pernah lewat sana karena `organization` attribute sengaja tidak pernah diset untuknya.

## 4. Route mapping (api/routes/api.php)

Ganti middleware route-group untuk 4 kategori di bawah ini dari `tenant` implisit jadi `event.access` + `event.perm.<kategori>`:

- **ticketing**: ticket-categories CRUD, scan, checkins, ticket-report, ticket-poster, ticket-sales, ticket-orders/{id}/check-in
- **scheduling**: schedule, draw, swiss*, knockout*, bracket/shuffle, matches index/store/update/schedule/teams/slot/sides/status/clock/destroy/stats, standings, adjustments, leaderboard, discipline, lineup-sheet, match report, rubbers. **KECUALI** `matches/{match}/confirm` — tetap `org.admin` only, tidak pernah digerbang kategori operator apa pun.
- **registration**: registrations index/store/update/import/invoice/receipt/album, race-periods, race-categories CRUD, discount-codes (event_discount_codes) CRUD, race-scan, race-report, race-checkins
- **documents**: certificates (generate/recipients/zip/download/send/destroy — BUKAN certificate-templates CRUD, itu org-scoped tetap di luar), id-card generate/recipients/batches/issued/send/download (BUKAN id-card-templates CRUD), bib generate/recipients/batches/send/download (BUKAN bib-templates CRUD), event personnel index/sync, photos CRUD, sponsors CRUD

Template CRUD (certificate/id-card/bib templates) **sengaja tidak** dimasukkan ke kategori manapun — org-scoped rows dipakai lintas event, bukan milik satu event, jadi tetap `tenant`/`org.admin` biasa tanpa jalur operator.

Route tanpa `{event}` di path langsung (contoh: `ticket-categories/{ticketCategory}`, `rubbers/{rubber}`, `photos/{photo}`) butuh util resolve event dari relasi model: `EventOperatorAttribution::resolveEventFrom($request, $boundParam, $modelClass)`, dipakai middleware kategori lewat parameter binding (`event.perm.ticketing:ticketCategory`).

## 5. Service

`EventOperatorService` — satu-satunya penulis `event_operators` & `event_operator_invites`:
- `invite(Event, string $email, array $permissions, User $invitedBy)` — expire/revoke pending lama untuk (event,email) dalam transaction, insert baru, kirim mail `afterCommit()`.
- `resend(EventOperatorInvite)` — token baru (invalidasi lama), extend expiry.
- `revoke(EventOperatorInvite)` — pending → revoked.
- `accept(EventOperatorInvite, User)` — guard `isUsable()` + guard email cocok (case-insensitive), `updateOrCreate(['event_id','user_id'], permissions)`, mark invite accepted.
- `updatePermissions(EventOperator, array $permissions)`.
- `remove(EventOperator)`.

## 6. Endpoint API baru

Di grup `tenant` + `org.admin` (pengundang = org admin/owner):
```
GET    organizations/{organization}/events/{event}/operators
GET    organizations/{organization}/events/{event}/operator-invites
POST   organizations/{organization}/events/{event}/operator-invites
POST   operator-invites/{invite}/resend
DELETE operator-invites/{invite}
PATCH  operators/{operator}
DELETE operators/{operator}
```

Publik/auth terpisah (luar prefix organizations):
```
GET  invites/{token}                 (tanpa auth — preview)
POST invites/{token}/accept          (auth:api — wajib login/register dulu, guard email cocok)
GET  my-operator-invites             (auth:api — daftar invite pending untuk email user login)
```

## 7. Flow invite (satu code path)

1. Org admin invite via form (email + checkbox kategori).
2. Mail dikirim berisi link `{frontend_url}/invites/{token}`.
3. User klik link → halaman publik preview event/org/permission.
4. Belum punya akun → daftar dulu (`/register?next=/invites/{token}&email=...`, email di-lock read-only). Sudah punya akun tapi belum login → login dulu (`/login?next=...`). Keduanya redirect balik ke halaman invite.
5. User (harus login dengan email yang cocok) klik tombol **"Terima"** eksplisit → `POST invites/{token}/accept`.
6. Sukses → baris `event_operators` dibuat/update → redirect ke `/organizer/events/{id}`.

Tidak ada auto-accept di background — klik eksplisit wajib di semua kasus.

## 8. Email

`App\Mail\EventOperatorInvitedMail` (Mailable, `ShouldQueue`, dikirim `afterCommit()`), pola sama `CertificateIssuedMail`/`personnel-invited.blade.php`. Isi: nama event, org, pengundang, label permission (Indonesia), link `MailLinks::operatorInvite($invite)`, catatan expiry 7 hari.

## 9. Interaksi dengan `event_personnel`

Independen total — dua tabel, dua middleware, boleh dobel di user & event yang sama, tidak ada validasi silang.

**Perlu ditangani di frontend**: `isCrewOnly()`/`dashboardModeFor()` (`web/lib/hooks/use-dashboard-mode.ts`) saat ini hanya membaca `account_types` dan `officiating`. User yang HANYA jadi event operator (tanpa org membership) akan kena redirect ke `/onboarding` yang salah kalau tidak ditambah field baru (misal `operating`) ke payload user dan dimasukkan ke logic dashboard mode. Endpoint baru `GET my-operator-events` untuk listing event yang dioperatori user, dipakai sebagai landing page kalau bukan org member.

## 10. Lifecycle cascade

- `event_operators.event_id` → CASCADE (hidup bergantung event).
- `event_operator_invites.event_id` → CASCADE termasuk yang masih pending (event hilang = tidak ada apa pun untuk diterima; link di email yang sudah terkirim akan 404, itu benar bukan bug).

## 11. Invariant wajib (gaya dokumentasi CLAUDE.md, tulis sebagai docblock di kode)

- **`matches/{match}/confirm` tidak pernah digerbang kategori operator apa pun** — tetap `org.admin` eksklusif.
- **`organization` attribute tidak pernah diset untuk Event Operator** di `EventOrOperatorScope`.
- **"All akses" = 4 boolean true, bukan role/kolom kelima.**
- **Daftar endpoint uang/admin-only tidak bisa di-grant ke event operator selamanya** — tidak ada 5th boolean, tidak ada override.
- **Invite ditujukan ke alamat, diterima oleh akun yang membuktikannya** — `accept()` wajib validasi email invite == email user login.
- **Resend menginvalidasi token lama.**
- **Satu `(event_id, email)` cuma satu invite pending aktif** — ditegakkan partial unique index di DB, bukan hanya validasi aplikasi.
- **Event operator dan event_personnel independen**, boleh dobel, tidak ada validasi silang.

## 12. Frontend

- `web/app/(dashboard)/organizer/events/[id]/operators/page.tsx` — list operator + invite pending, tombol invite/resend/revoke/edit/remove.
- `InviteOperatorDialog` — email + 4 checkbox + "All akses" (derived state, bukan kolom tersimpan sendiri — pola `derivedSwissRounds()`).
- `web/app/invites/[token]/page.tsx` — halaman publik accept invite, 3 state (belum login → login/daftar CTA; login salah akun → pesan; login benar → tombol Terima).
- `web/lib/api/event-operators.ts`, `web/lib/event-operator-permissions.ts` (kategori + label, satu sumber format).
- Sidebar organizer event: filter menu sesuai permission kalau login sebagai event operator (bukan org member).

## 13. Critical files

- `api/app/Http/Middleware/EventPersonnelScope.php` — pola ditiru untuk `EventOrOperatorScope`.
- `api/app/Http/Middleware/TenantScope.php` — tidak diubah.
- `api/bootstrap/app.php` — daftar alias middleware baru.
- `api/routes/api.php` — baris ~411+, seluruh mapping route section 4.
- `api/app/Services/EventPersonnelService.php` — pola service yang dicontoh.
- `web/lib/hooks/use-dashboard-mode.ts` — perlu diperluas untuk event operator (section 9).
