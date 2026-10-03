# Counter landing configurable — progress

Rencana: `/Users/ammar/.claude/plans/synchronous-tickling-brooks.md`

Tujuan: counter "Tiket terjual" di strip Proof landing diganti metrik pengunjung, dan
katalog counter bisa diatur super_admin di `/admin/landing-stats`. Kode = default,
DB = override (pola `PlatformSettings`, **bukan** Testimoni/FAQ).

- [x] 1. Migrasi `landing_stat_settings` + model `LandingStatSetting`
- [x] 2. `App\Support\LandingMetrics` (katalog 6 metrik + resolver closure)
- [x] 3. `App\Services\LandingStatService` (merge, memo traffic, put overrides-only)
- [x] 4. `PublicStatController` → list; update `PublicStatTest` → hijau
- [x] 5. `UpdateLandingStatsRequest` + `LandingStatController` + 2 rute; `LandingStatSettingTest` → hijau
- [x] 6. `web/types/api.ts` + `web/lib/api/landing.ts`
- [x] 7. `Proof()` di `hero.tsx` + `.stat-row` di `globals.css`
- [x] 8. Halaman `/admin/landing-stats` + entri sidebar
- [x] 9. Subsection CLAUDE.md

Catatan: langkah 6–7 wajib satu deploy dengan 1–5 (bentuk API berubah flat → list).
