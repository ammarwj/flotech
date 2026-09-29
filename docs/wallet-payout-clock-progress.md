# Progress: pencairan dompet pakai batas jam 01:00 WIB

Mengganti aturan rilis dana organizer dari **"setelah event selesai"** menjadi
**"setelah lewat jam 01:00 WIB berikutnya"**, plus pembebasan minimal penarikan
untuk event yang sudah berakhir.

- **Branch**: `master`
- **Tanggal mulai**: 2026-09-29

**Konvensi commit.** Pesan commit berhenti di badan teksnya — **jangan** tambahkan trailer `Co-Authored-By:` atau `Claude-Session:`.

> ⚠️ DB dev `flo_event` adalah **salinan produksi**. Jangan pernah `migrate:fresh`, `migrate:refresh`, atau `db:wipe`. `php artisan migrate` (maju) aman; `php artisan test` aman (sqlite in-memory).

---

## Keputusan produk (dikonfirmasi user 2026-09-29)

| | Pilihan |
|---|---|
| Batas rilis | **Jam 01:00 WIB berikutnya, literal** — kredit 00:30 cair 01:00 hari yang sama; kredit 10:00 cair 01:00 besok. Tidak ada aturan "minimal satu malam". |
| Cakupan pembebasan minimum | **Per event** — hanya porsi `balance_available` yang kreditnya berasal dari event berakhir |
| "Event berakhir" | `finished` **ATAU** `cancelled` |
| Jalur rilis lewat event | **Dihapus** (`ReleaseEventFundsJob`, `wallet:release --event`) |
| `wallet_hold_days` | Dipertahankan, dimaknai ulang = tambahan hari **di atas** batas 01:00 |
| Biaya admin | **Tidak dibebaskan**. Yang bisa ditarik = `saldo − biaya admin`; saldo di bawah fee tidak bisa ditarik. |
| "1x24 jam" | SLA transfer admin, **bukan** batas rilis — copy saja |

`cancelled` ikut dibebaskan karena `Event::nextStatuses()` cuma memberi **satu** langkah
balik dari `cancelled`; mencapai `finished` butuh 2–3 hop, jadi mengecualikannya berarti
uangnya terjebak.

---

## Invarian

> **1. Jam adalah satu-satunya penentu rilis.** Dulu ada dua pembaca aturan — sapuan
> `available_at` dan jalur "event selesai" (`ReleaseEventFundsJob`) — dan dua pembaca
> aturan yang sama pasti berselisih (bentuk bug yang sama dengan dua pembaca `stage`).
> Jalur event dihapus seluruhnya, bukan dinonaktifkan. `availableAtFor()` tidak lagi
> menerima `Event`: batasnya diturunkan dari **saat kredit ditulis**.

> **2. Batas 01:00 dihitung di zona WIB, bukan UTC.** App timezone UTC, jadi aritmetika
> naif menggeser batas tujuh jam. Alasan yang sama sudah tertulis di docblock lama; cuma
> subjeknya berubah dari `end_date` ke jam batas.

> **3. Pembebasan minimum = stok diturunkan DIKURANGI konsumsi yang DICATAT.**
> Versi stateless (stok dikurangi arus penarikan kumulatif) **terbukti gagal diam-diam**:
> penarikan/`withdrawal_reversal`/`adjustment` semuanya ber-`event_id` null, jadi menarik
> uang tidak menyusutkan stok. Organizer yang menarik Rp 295.000 secara normal dari event
> yang masih hidup akan menghapus **seluruh** pembebasan di masa depan
> (`40.000 − 300.000 < 0`) — dan karena perubahan #1 membuat uang event hidup bisa
> ditarik, hampir setiap organizer aktif berakhir dengan arus ≫ stok. Hijau di semua test
> satu langkah. Karena itu konsumsinya dicatat di `withdrawals.exempt_consumed`, pola
> snapshot yang sudah hidup di tabel yang sama bersama `minimum_at_request`/`admin_fee`.

> **4. Tidak ada flag kedua untuk "penarikan ini dibebaskan".** Yang dihitung cuma
> penarikan yang masih memegang uangnya, difilter dari `status` penarikan itu sendiri
> (`pending|processing|completed`). Penarikan ditolak/dibatalkan otomatis berhenti
> mengonsumsi karena `reverseWithdrawal()` sudah mengembalikan uangnya ke ledger.

> **5. `minimum_at_request` tetap nilai setelan, bukan 0.** `wallet_minimum_withdrawal`
> punya `min: 0`, jadi 0 adalah nilai sah dan "dibebaskan" jadi tidak bisa dibedakan dari
> "super_admin men-set 0" — tepat di tempat sejarahnya paling dibutuhkan.
> `exempt_consumed > 0` yang menandai penarikan yang lolos lewat pembebasan.

> **6. Adjustment super_admin tidak pernah membebaskan.** `adjust()` ber-`event_id` null,
> jadi kredit koreksi tidak masuk `endedNet`. **Jangan** menyederhanakannya jadi
> `balance_available − liveNet` — bentuk itu terlihat setara tapi membocorkan koreksi
> admin Rp 60.000 sebagai bebas minimum.

---

## Checklist

### Backend
- [x] Migrasi index `wallet_transactions (wallet_id, status, event_id)`
- [x] Migrasi `withdrawals.exempt_consumed`
- [x] `WalletService::availableAtFor(?Carbon)` — batas jam, tanpa `Event`
- [x] `WalletService::releaseDue()` murni jam (buang arm finished + guard cancelled)
- [x] Hapus `WalletService::releaseEvent()` + `ReleaseEventFundsJob`
- [x] `ReleaseWalletFunds`: buang `--event`
- [x] `EventController::transition()`: buang dispatch + sesuaikan `STATUS_MESSAGES`
- [x] `Wallet::exemptSourceBalance()` (LEFT JOIN `events`, bukan subquery)
- [x] `Wallet::minimumWaivedBalance()`
- [x] `Withdrawal`: `exempt_consumed` di `$fillable` + `casts()`
- [x] `WithdrawalService::request()`: gate pembebasan + snapshot `exempt_consumed`
- [x] `WalletResource`: `balance_minimum_waived`, `max_withdrawable`

### Copy & label
- [x] `PlatformSettings` label `wallet_hold_days` + docblock timezone
- [x] `config/wallet.php` komentar `hold_days` & `timezone`
- [x] Docblock kelas `WalletService`, `releaseTransactions()`
- [x] `Event::TRANSITIONS` docblock, `routes/console.php`, `BackfillWallets.php`
- [x] `web/types/api.ts` interface `Wallet`
- [x] `organizer/wallet/page.tsx` (`belowMinimum`, `heldCoversMinimum`, StatCard hint, kebijakan fee)
- [x] `withdraw-dialog.tsx` (`belowMinimum`, helper, SLA 1x24 jam)
- [x] `mail/withdrawal-completed.blade.php`
- [x] `event-status-panel.tsx` + `events/[id]/edit/page.tsx`

### Test
- [x] `WalletReleaseTest` ditulis ulang (batas jam, WIB, `hold_days`, `cancelled` ikut rilis, idempoten)
- [x] `WithdrawalTest` pembebasan (finished vs open, cancelled vs open, sebagian, konsumsi, normal-tidak-mengurangi, penolakan memulihkan)
- [x] Pin jam: `WalletTest` (+`tearDown`), `WalletLedgerTest`, `RefundTest::release()`, `ManualPaymentTest`
- [x] `WalletAuditTest` tetap hijau tanpa disentuh
- [x] e2e `platform-settings.spec.ts:52`, `wallet-payout.spec.ts:49`

### Dokumentasi
- [x] `CLAUDE.md` bagian dompet
- [x] `WALLET.md`
- [x] `PRD.md`
- [x] `LiveMatchSeeder.php` komentar

### Verifikasi
- [x] `php artisan test --filter 'Wallet|Withdrawal|Refund|ManualPayment|PlatformSetting'`
- [x] `php artisan test` penuh
- [x] `docker compose exec api php artisan migrate`
- [x] `php artisan wallet:audit`
