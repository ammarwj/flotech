import type { Browser, Page } from "@playwright/test";

import { expect as baseExpect, signIn, test, toast } from "../fixtures/test";
import { Api, PASSWORD, unique } from "../fixtures/api";

/**
 * Tiap rute yang dibuka di sini dibuka **pertama kali** — `/officiating`,
 * `/officiating/events/[id]`, `/officiating/matches/[id]`, dan dua halaman
 * participant — dan Next dev mengompilasinya saat itu juga, kadang lebih lama
 * dari 10 detik default. Yang gagal karenanya selalu assertion pertama di
 * halaman baru, dan gagalnya berbunyi persis seperti fitur yang rusak: badge
 * yang benar sudah ada di screenshot kegagalannya. Angka yang lebih besar di
 * sini tidak melonggarkan apa pun — assertion yang salah tetap gagal, cuma
 * lebih lambat.
 */
const expect = baseExpect.configure({ timeout: 30_000 });

/**
 * Petugas pertandingan sebagai akun kerja: undangan → rotasi password → staff
 * mengisi skor & statistik → manajer menyusun pemain → wasit meng-acc → lembar
 * susunan pemain tercetak → dan wasit tetap tidak punya pintu organizer.
 *
 * Ini otomasi langkah 1–7 dari bagian "Verifikasi" rencana officiating, yang
 * sampai sekarang dikerjakan tangan. Satu test, bukan tujuh: langkah 6 baru
 * punya arti setelah langkah 5 meng-acc kedua tim, dan langkah 5 baru ada
 * setelah langkah 4 mengirim susunannya — memecahnya jadi test terpisah berarti
 * membangun ulang seluruh rantai di tiap berkas, lewat UI, untuk satu assertion.
 *
 * ── Lima identitas, empat browser ───────────────────────────────────────────
 *
 * Organizer, staff, wasit, dan dua manajer tim. Tiap aktor yang muncul di layar
 * dapat context-nya sendiri, alasan yang sama dengan `adminPage` di fixtures:
 * shell aplikasi memegang user yang membootnya dan tidak pernah membaca ulang
 * identitas di tengah sesi, jadi menukar sesi di dalam satu context membuat
 * halaman dirender sebagai orang sebelumnya. Manajer kedua tidak pernah muncul
 * di browser — susunan timnya dikirim lewat API, karena yang diuji di layar
 * adalah kunci setelah submit dan alur tolak/kirim ulang, dan keduanya cukup
 * dibuktikan sekali.
 *
 * ── Kenapa emailnya tidak dibuka ────────────────────────────────────────────
 *
 * `MAIL_MAILER=smtp` ke Mailtrap dan `PersonnelInvited implements ShouldQueue`,
 * jadi badan undangannya memang tidak bisa dibaca dari sini. Yang berdiri di
 * tempatnya adalah dua hal yang bisa: badge "Akun aktif" yang tidak ada sebelum
 * simpan dan ada sesudahnya (itu bukti barisnya berubah jadi login), dan login
 * yang berhasil dengan `welcomefloevent1` persis — password yang dijanjikan
 * emailnya. Undangan yang tidak pernah terkirim akan tetap lolos keduanya, tapi
 * undangan yang menyebut password lain tidak.
 */
test.describe.configure({ mode: "serial" });

test.describe("§7 Petugas pertandingan: wasit & staff", () => {
  test("undangan, rotasi password, skor, acc susunan pemain, lalu cetak", async ({
    browser,
    page,
    api,
    organizer,
  }) => {
    // Tujuh langkah, lima identitas, dan tiap rute officiating/participant
    // dikompilasi Next dev saat pertama kali dibuka — anggaran 60 detik habis
    // di kompilasi, bukan di aplikasinya.
    test.slow();

    const token = organizer.account.token;
    const refereeEmail = `${unique("wasit")}@e2e.test`;
    const staffEmail = `${unique("staf")}@e2e.test`;

    // ── Persiapan lewat API ────────────────────────────────────────────────
    // Dua tim yang benar-benar punya manajer: `approvedTeams()` masuk lewat
    // entri offline organizer, dan tim hasil entri itu tidak punya
    // `manager_user_id` sama sekali — tidak ada yang bisa menyusun pemainnya.
    //
    // Nama pemain dibedakan per tim karena editor statistik memberi label tiap
    // input dengan nama pemainnya ("Gol Rangga"); dua tim dengan roster default
    // yang sama membuat tiap locator cocok dua kali.
    const event = await api.liveEvent(token, organizer.org.id);

    const homeManager = await api.registerUser("manajer-tuan", "participant");
    const awayManager = await api.registerUser("manajer-tamu", "participant");

    const home = await api.registerTeam(
      organizer.org.slug,
      event,
      homeManager.token,
      unique("Tim Tuan"),
      [
        { full_name: "Rangga Putra", jersey_number: "7" },
        { full_name: "Bimo Saputra", jersey_number: "9" },
      ],
    );
    const away = await api.registerTeam(
      organizer.org.slug,
      event,
      awayManager.token,
      unique("Tim Tamu"),
      [
        { full_name: "Yoga Pratama", jersey_number: "4" },
        { full_name: "Dimas Anggara", jersey_number: "11" },
      ],
    );

    await api.setTeamStatus(token, organizer.org.id, event.id, home.id, "approved");
    await api.setTeamStatus(token, organizer.org.id, event.id, away.id, "approved");

    // Bangku cadangan, supaya lembar cetaknya punya baris ofisial untuk diisi
    // dan langkah 4 punya sesuatu yang bisa dicentang.
    await api.addTeamOfficials(homeManager.token, home.id, [
      { full_name: "Coach Tuan", role: "head_coach" },
    ]);
    await api.addTeamOfficials(awayManager.token, away.id, [
      { full_name: "Coach Tamu", role: "head_coach" },
    ]);

    // Dua tim di satu kategori liga = tepat satu laga.
    expect(await api.generateSchedule(token, organizer.org.id, event)).toBe(1);
    const [fixture] = await api.teamMatches(homeManager.token, home.id);

    // ─────────────────────────────────────────────────────────────────────
    // Langkah 1 — organizer mendaftarkan wasit + staff beserta emailnya
    // ─────────────────────────────────────────────────────────────────────
    await signIn(page, organizer.account.email);
    await page.goto(`/organizer/events/${event.id}/personnel`);

    await page.getByRole("button", { name: "Wasit", exact: true }).click();
    await page.getByLabel("Nama petugas 1").fill("Wasit E2E");
    await page.getByLabel("Email petugas 1").fill(refereeEmail);

    await page.getByRole("button", { name: "Staf", exact: true }).click();
    await page.getByLabel("Nama petugas 2").fill("Staf E2E");
    await page.getByLabel("Email petugas 2").fill(staffEmail);

    // Sebelum disimpan keduanya cuma nama untuk dicetak di ID card. Badge ini
    // yang memisahkan "punya email di kolom" dari "punya akun", dan
    // membandingkan tidak-ada/ada adalah satu-satunya cara membuktikannya:
    // assert badge-nya muncul saja akan lolos walau ia selalu muncul.
    await expect(page.getByText("Akun aktif")).toHaveCount(0);

    await page.getByRole("button", { name: "Simpan petugas" }).click();
    await expect(toast(page, "Petugas disimpan")).toBeVisible();
    await expect(page.getByText("Akun aktif")).toHaveCount(2);

    // ─────────────────────────────────────────────────────────────────────
    // Langkah 2 — staff login dengan password default, dipaksa menggantinya
    // ─────────────────────────────────────────────────────────────────────
    const staffPage = await newActor(browser, staffEmail, Api.CREW_PASSWORD);

    // Dashboard mana pun: gerbangnya membungkus seluruh layout, jadi tidak ada
    // satu halaman pun di baliknya yang bisa dibuka. `/officiating` dipilih
    // karena itu juga tujuan akhirnya — kalau gerbangnya tidak jalan, halaman
    // penugasan yang muncul di sini dan assertion ini gagal dengan benar.
    await staffPage.goto("/officiating");
    await expect(staffPage.getByRole("heading", { name: "Ganti password dulu" })).toBeVisible();

    await staffPage.getByLabel("Password sementara").fill(Api.CREW_PASSWORD);
    await staffPage.getByLabel("Password baru", { exact: true }).fill(PASSWORD);
    await staffPage.getByLabel("Konfirmasi password baru").fill(PASSWORD);
    await staffPage.getByRole("button", { name: "Simpan & lanjutkan" }).click();

    await expect(toast(staffPage, "Password berhasil diganti")).toBeVisible();
    await expect(staffPage.getByRole("heading", { name: "Ganti password dulu" })).toBeHidden();

    // Gerbangnya cuma melepas takeover-nya, tidak menavigasi ke mana-mana.
    // Penugasannya cuma satu, jadi daftar event dilewati: `/officiating`
    // me-`replace` dirinya sendiri ke event itu.
    await staffPage.goto("/officiating");
    await expect(staffPage).toHaveURL(new RegExp(`/officiating/events/${event.id}$`));
    await expect(staffPage.getByText(`bertugas sebagai Staf`)).toBeVisible();

    // ─────────────────────────────────────────────────────────────────────
    // Langkah 3 — staff mencatat skor + kartu + pencetak gol, tanpa meratifikasi
    // ─────────────────────────────────────────────────────────────────────
    // Skornya dulu: editor statistik baru mencocokkan pencetak gol dengan skor
    // setelah lagany selesai, dan sebelum itu ia justru menyuruh menyimpan skor.
    await staffPage.getByLabel(`Skor ${home.name}`).fill("2");
    await staffPage.getByLabel(`Skor ${away.name}`).fill("1");
    await staffPage.getByRole("button", { name: "Simpan", exact: true }).click();
    await expect(toast(staffPage, "Hasil disimpan")).toBeVisible();

    await staffPage.getByRole("button", { name: "Statistik pemain" }).click();
    await staffPage.getByLabel("Gol Rangga Putra").fill("2");
    await staffPage.getByLabel("Assist Bimo Saputra").fill("1");
    await staffPage.getByLabel("Kartu Kuning Bimo Saputra").fill("1");
    await staffPage.getByLabel("Gol Yoga Pratama").fill("1");
    await staffPage.getByLabel("Kartu Merah Dimas Anggara").fill("1");
    await staffPage.getByRole("button", { name: "Simpan statistik" }).click();
    await expect(toast(staffPage, "Statistik disimpan")).toBeVisible();

    // Staff mencatat, tidak meratifikasi — `$autoConfirm = false` selalu. Di
    // sisi organizer laganya berhenti di "Menunggu konfirmasi", bukan "Hasil
    // final", dan itu perbedaan yang cuma bisa dilihat dengan membandingkan
    // kedua badge: assert badge-nya ada akan lolos apa pun bunyinya.
    await page.goto(`/organizer/events/${event.id}/schedule`);
    await expect(page.getByText("Menunggu konfirmasi").first()).toBeVisible();
    await expect(page.getByText("Hasil final")).toHaveCount(0);

    // ─────────────────────────────────────────────────────────────────────
    // Langkah 4 — manajer menyusun pemain lalu mengirimnya; formnya terkunci
    // ─────────────────────────────────────────────────────────────────────
    const managerPage = await newActor(browser, homeManager.email);
    await managerPage.goto(`/participant/teams/${home.id}/matches`);

    // Barisnya adalah `<Link>` yang memuat kedua nama tim; mencocokkan nama tim
    // sebagai teks akan menangkap deskripsi PageHeader di atasnya dan tidak
    // menavigasi ke mana-mana. URL yang dipakai untuk menandai sampai, bukan
    // judul kartunya: halaman daftar ini sendiri berjudul "Jadwal & Susunan
    // Pemain", jadi `getByText("Susunan Pemain")` lolos tanpa pindah halaman.
    await managerPage.getByRole("link", { name: new RegExp(home.name) }).click();
    await expect(managerPage).toHaveURL(new RegExp(`/matches/${fixture.id}/lineup$`));
    await pick(managerPage, "Rangga Putra", "Inti");
    await pick(managerPage, "Bimo Saputra", "Cadangan");
    await managerPage.getByRole("button", { name: /Coach Tuan/ }).click();

    await managerPage.getByRole("button", { name: "Simpan draf" }).click();
    await expect(toast(managerPage, "Susunan pemain disimpan")).toBeVisible();

    await managerPage.getByRole("button", { name: "Kirim ke wasit" }).click();
    await expect(toast(managerPage, "dikirim ke wasit")).toBeVisible();

    // Terkunci berarti tombolnya tidak ada sama sekali, bukan disabled —
    // itu yang membuat acc wasit berarti sesuatu.
    // Dua elemen mengatakannya — badge status dan paragraf penjelasan — jadi
    // yang dipilih paragrafnya: badge ada juga di daftar laga, paragraf ini
    // cuma muncul di form yang sedang terkunci.
    await expect(managerPage.getByText("Susunan pemain sedang menunggu acc wasit")).toBeVisible();
    await expect(managerPage.getByRole("button", { name: "Simpan draf" })).toHaveCount(0);
    await expect(managerPage.getByRole("button", { name: "Kirim ke wasit" })).toHaveCount(0);

    // Tim tamu lewat API: yang diuji di layar adalah kunci di atas dan alur
    // tolak di bawah, dan mengulangnya untuk sisi kedua tidak membuktikan
    // apa pun yang baru. Sisi ini tetap harus di-acc, kalau tidak langkah 6
    // tidak punya kasus "kedua tim disetujui".
    await api.submitLineup(awayManager.token, away.id, fixture.id, {
      starters: ["Yoga Pratama"],
      substitutes: ["Dimas Anggara"],
      officials: ["Coach Tamu"],
    });

    // ─────────────────────────────────────────────────────────────────────
    // Langkah 5 — wasit menolak dengan alasan, manajer memperbaiki, lalu di-acc
    // ─────────────────────────────────────────────────────────────────────
    const refereePage = await newActor(browser, refereeEmail, Api.CREW_PASSWORD);
    await rotatePassword(refereePage);

    await refereePage.goto(`/officiating/events/${event.id}`);
    await refereePage.getByRole("link", { name: "Susunan pemain" }).first().click();
    await expect(refereePage.getByText("Setiap tim di-acc sendiri-sendiri")).toBeVisible();

    const homeCard = teamCard(refereePage, home.name);
    await homeCard.getByRole("button", { name: "Tolak" }).click();
    await homeCard.getByLabel("Alasan dikembalikan").fill("Nomor punggung ganda.");
    await homeCard.getByRole("button", { name: "Kembalikan ke manajer" }).click();
    await expect(toast(refereePage, "dikembalikan ke manajer")).toBeVisible();

    // Alasannya harus sampai ke manajer — dan harus dibaca **sebelum** dikirim
    // ulang: `LineupService::submit()` mengosongkan `note`, jadi memeriksanya
    // setelah itu akan selalu gagal.
    await managerPage.reload();
    await expect(managerPage.getByRole("heading", { name: "Ditolak wasit" })).toBeVisible();
    await expect(managerPage.getByText("Nomor punggung ganda.")).toBeVisible();

    await managerPage.getByRole("button", { name: "Kirim ke wasit" }).click();
    await expect(toast(managerPage, "dikirim ke wasit")).toBeVisible();

    await refereePage.reload();
    await teamCard(refereePage, home.name).getByRole("button", { name: "Setujui" }).click();
    await expect(toast(refereePage, `Susunan pemain ${home.name} disetujui`)).toBeVisible();
    await teamCard(refereePage, away.name).getByRole("button", { name: "Setujui" }).click();
    await expect(toast(refereePage, `Susunan pemain ${away.name} disetujui`)).toBeVisible();

    // ─────────────────────────────────────────────────────────────────────
    // Langkah 6 — lembar susunan pemain: ditolak sebelum acc, terunduh sesudah
    // ─────────────────────────────────────────────────────────────────────
    // Penolakan sebelum acc sudah dibuktikan di atas — tombolnya memang selalu
    // ditawarkan, gerbangnya milik server sendirian. Yang tersisa di sini
    // adalah sisi sesudahnya.
    await staffPage.reload();
    const download = staffPage.waitForEvent("download");
    await staffPage.getByRole("button", { name: "Cetak susunan pemain" }).click();
    expect((await download).suggestedFilename()).toMatch(/^susunan-pemain-.+\.pdf$/);

    // ─────────────────────────────────────────────────────────────────────
    // Langkah 7 — wasit tidak punya pintu organizer, di browser maupun di API
    // ─────────────────────────────────────────────────────────────────────
    // Dua bukti, karena keduanya bisa gagal sendiri-sendiri: layoutnya
    // melempar ke onboarding karena akun ini tidak punya organisasi, dan API
    // organizer menolak 403 karena ia bukan `organization_members` — yang kedua
    // itulah keputusan #2, dan ia tetap berlaku walau redirect frontend hilang.
    await refereePage.goto("/organizer");
    await expect(refereePage).toHaveURL(/\/onboarding/);

    const refereeToken = await api.login(refereeEmail, PASSWORD);
    const forbidden = await refereePage.request.get(
      `${process.env.API_URL ?? "http://localhost:8000/api/v1"}/organizations/${organizer.org.id}/events`,
      { headers: { Authorization: `Bearer ${refereeToken}`, Accept: "application/json" } },
    );
    expect(forbidden.status()).toBe(403);
    expect(await forbidden.text()).toContain("Kamu bukan anggota organisasi ini.");

    await staffPage.context().close();
    await managerPage.context().close();
    await refereePage.context().close();
  });
});

/** Satu aktor, satu browser — alasannya ada di docblock `adminPage`. */
async function newActor(browser: Browser, email: string, password: string = PASSWORD): Promise<Page> {
  const context = await browser.newContext();
  const actor = await context.newPage();
  await signIn(actor, email, password);
  return actor;
}

/** Lewati takeover ganti-password milik akun tugas yang baru diundang. */
async function rotatePassword(actor: Page): Promise<void> {
  await actor.goto("/officiating");
  await actor.getByLabel("Password sementara").fill(Api.CREW_PASSWORD);
  await actor.getByLabel("Password baru", { exact: true }).fill(PASSWORD);
  await actor.getByLabel("Konfirmasi password baru").fill(PASSWORD);
  await actor.getByRole("button", { name: "Simpan & lanjutkan" }).click();
  await expect(toast(actor, "Password berhasil diganti")).toBeVisible();
}

/**
 * Tombol status satu pemain. `RoleToggle` membungkus ketiganya dalam
 * `role="group"` bernama pemainnya — tanpa itu "Inti" cocok di tiap baris
 * roster sekaligus.
 */
async function pick(actor: Page, player: string, role: "Inti" | "Cadangan"): Promise<void> {
  await actor.getByRole("group", { name: `Status ${player}` }).getByRole("button", { name: role }).click();
}

/**
 * Kartu satu tim di halaman acc wasit.
 *
 * Judul kartu dirender `<div>`, bukan heading, jadi kartunya dicari lewat
 * teksnya — tapi nama tim saja tidak cukup dari dua arah sekaligus: ke atas ia
 * menangkap tiap div leluhur sampai `<body>`, dan `.last()` yang membereskannya
 * justru mendarat di `CardTitle`-nya sendiri, div terdalam yang memuat nama itu
 * dan tidak memuat satu tombol pun.
 *
 * Karena itu dua jangkar: nama tim (di header) dan judul "Ofisial di bangku"
 * (di body). Div terdalam yang memuat **keduanya** adalah kartunya, dan
 * jangkar kedua dipilih karena ia satu-satunya yang ada di keempat status —
 * tombol Tolak lenyap begitu textarea alasannya terbuka.
 */
function teamCard(actor: Page, teamName: string) {
  return actor
    .locator("div")
    .filter({ has: actor.getByText(teamName, { exact: true }) })
    .filter({ has: actor.getByRole("heading", { name: "Ofisial di bangku" }) })
    .last();
}
