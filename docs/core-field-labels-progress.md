# Rename label field inti pendaftaran (`registration_form.labels`)

Empat field yang platform selalu tanyakan di form pendaftaran tim — logo, nama
tim, nama kontak, no. HP kontak — labelnya bisa diganti organizer per event.
**Kolom DB tidak disentuh sama sekali**: `teams.logo_url`, `teams.name`,
`teams.contact_name`, `teams.contact_phone` tetap namanya. Yang disimpan cuma
peta label.

Pola yang diikuti: `landing_stat_settings` (kode = katalog + default, DB cuma
menyimpan override) digabung dengan `registration_form` (satu kolom json milik
event, dinormalkan satu value object, dicerminkan satu file di `web/lib`).

## Keputusan

- Cakupan **4 field tim saja**. Nama pemain / no. punggung / posisi / ofisial
  **tidak** ikut — itu kolom roster yang lima pembaca lain bergantung padanya.
- Label baru ikut ke: ketiga form tulis, template import/export Excel, sheet
  export peserta, halaman organizer & publik, PDF album, email tim baru.
- Key inti: `team_logo`, `team_name`, `contact_name`, `contact_phone`.

## Checklist

### Backend
- [x] `api/app/Support/CoreFieldLabels.php` — katalog key + default + `clean()`
- [x] `RegistrationForm`: properti `labels`, `label()`, `toArray`/`toStorage()`
- [x] `RegistrationForm::validationRules()` menerima `labels`
- [x] `SyncRegistrationFormRequest` — tidak perlu aturan tambahan (shape saja)
- [x] `EventController::syncRegistrationForm()` menyimpan `toStorage()`
- [x] `RegistrationTemplateColumns::forCategory()` membaca label event
- [x] `TeamRosterImport::headerMatches()` dilonggarkan case/spacing-insensitive
- [x] `RegistrationsExport::headings()`
- [x] `TeamAlbumService` + `pdf/team-album.blade.php`
- [x] `NewTeamRegistered` + `mail/new-team-registered.blade.php`
- [x] `EventResource` / `PublicEventResource` — otomatis, keduanya `toArray()`

### Frontend
- [x] `web/lib/registration-form.ts` — `CORE_LABEL_DEFAULTS`, `coreLabel()`, tipe
- [x] Builder `/organizer/events/{id}/registration-form` — `CoreLabelRows`
- [x] Form publik `(public)/[orgSlug]/[eventSlug]/register`
- [x] Halaman peserta `(dashboard)/participant/teams/[id]`
- [x] Dialog manual organizer `components/event/manual-team-dialog.tsx`
- [x] Daftar pendaftaran organizer (`Info label="Kontak"`)
- [x] `tsc --noEmit` bersih

### Uji
- [x] `tests/Feature/CoreFieldLabelsTest.php` — 7 test, semua membandingkan
      event ber-override vs event default dalam satu test
- [x] Suite terkait hijau (RegistrationForm / Import / Registration / Album)

## Catatan untuk yang menyentuh ini berikutnya

- **`toStorage()` vs `toArray()` adalah intinya.** Yang disimpan cuma override;
  yang dikirim ke klien setiap key sudah teresolusi. Menyimpan hasil resolusi
  akan membekukan default hari ini ke tiap event yang pernah membuka halaman —
  invarian yang sama dengan `landing_stat_settings`.
- **Album PDF punya default cetak sendiri** ("NAMA KLUB" / "KONTAK"), dibaca
  dari `$form->labels[...]` langsung, **bukan** `label()`. Lewat `label()` tiap
  album lama akan berubah jadi "NAMA TIM".
- **Key tidak ikut rename.** `RegistrationTemplateColumns` tetap mengirim key
  `team_name`/`contact_name`/`contact_phone`; import membaca list itu per index.
- Label pemain / no. punggung / posisi / peran ofisial **sengaja di luar
  cakupan** — lima pembaca `players` bergantung pada konsepnya, bukan katanya.
