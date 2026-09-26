import { expect, test } from "bun:test";

import {
  dashboardModeFor,
  isCrewOnly,
  MODE_HOME,
  modeFromPath,
  type DashboardMode,
} from "./use-dashboard-mode";

/**
 * `bun test lib/hooks/dashboard-mode.test.ts` — runner bawaan bun, tanpa
 * dependensi baru (pola `match-clock.test.ts`).
 *
 * Yang diuji adalah pemetaan path → mode, bukan hook-nya: `useDashboardMode()`
 * butuh router Next, sementara seluruh aturan yang bisa salah ada di fungsi murni
 * ini. Tiap uji **membandingkan** — satu jawaban yang benar juga keluar dari versi
 * lama yang menjatuhkan semuanya ke `"organizer"`, jadi yang membuktikannya adalah
 * dua path yang harus keluar berbeda.
 */

test("rute di dalam sebuah mode menjawab mode-nya sendiri, bukan default", () => {
  // Dibandingkan satu sama lain: versi lama mengembalikan "organizer" untuk
  // ketiganya, jadi assert baris pertama saja akan tetap hijau.
  expect(modeFromPath("/organizer")).toBe("organizer");
  expect(modeFromPath("/participant")).toBe("participant");
  expect(modeFromPath("/officiating")).toBe("officiating");
});

test("sub-rute ikut mode induknya", () => {
  expect(modeFromPath("/participant/teams/abc/matches/1/lineup")).toBe("participant");
  expect(modeFromPath("/organizer/events/abc/schedule")).toBe("organizer");
  expect(modeFromPath("/officiating/matches/abc")).toBe("officiating");
});

test("rute tanpa mode mengembalikan null, dan /account bukan /organizer", () => {
  // Inti bug tombol "Akun": /account bukan bagian dari mode mana pun, dan yang
  // membedakannya dari path yang memang organizer adalah null vs "organizer".
  expect(modeFromPath("/account")).toBeNull();
  expect(modeFromPath("/organizer")).toBe("organizer");

  // /admin juga tanpa mode — itu yang membuat `useNav()` harus membacanya dari
  // pathname, bukan dari mode yang menempel.
  expect(modeFromPath("/admin")).toBeNull();
  expect(modeFromPath("/admin/users")).toBeNull();
});

test("prefix yang cuma mirip tidak dihitung satu mode", () => {
  // `startsWith(home)` polos akan mencocoki keduanya. Dibandingkan dengan path
  // yang benar-benar di dalam mode itu supaya pemisah "/" yang jadi buktinya.
  expect(modeFromPath("/organizer-guide")).toBeNull();
  expect(modeFromPath("/participants")).toBeNull();
  expect(modeFromPath("/organizer/events")).toBe("organizer");
});

test("setiap mode di MODE_HOME dikenali kembali dari home-nya", () => {
  // Ini yang membuat mode baru tidak bisa punya home tanpa ikut dikenali di sini:
  // menambah entri ke MODE_HOME tanpa menyentuh MATCH_ORDER menjatuhkan uji ini,
  // bukan diam-diam membuat home-nya terbaca "organizer".
  // Cast because Object.entries widens the key to string; MODE_HOME is a
  // Record over the mode union, so the runtime values really are modes.
  for (const [mode, home] of Object.entries(MODE_HOME) as [DashboardMode, string][]) {
    expect(modeFromPath(home)).toBe(mode);
  }
});

/**
 * Ke mana sebuah akun dibukakan — dibaca "Login sebagai", AdminLayout saat
 * memulangkan sesi impersonasi, dan cabang petugas di halaman login.
 *
 * Tiap uji di bawah **membandingkan dua akun**, karena versi lama
 * (`default_mode ?? "organizer"`) menjawab benar untuk sebagian di antaranya:
 * assert satu akun saja akan tetap hijau walau penugasannya tidak pernah
 * dibaca.
 */

test("petugas murni dibukakan ke area petugas walau default_mode-nya organizer", () => {
  // Inti bugnya. EventPersonnelService cuma menulis 'officiating' pada akun yang
  // ia *buat*, jadi baris petugas yang menempel ke email lama datang persis
  // seperti ini — dan MODE_HOME["organizer"] mengirimnya ke dashboard organizer,
  // yang karena ia tidak punya organisasi diteruskan lagi ke /onboarding.
  const stale = { default_mode: "organizer" as const, officiating: [{}], account_types: [] };
  const fresh = { default_mode: "officiating" as const, officiating: [{}], account_types: [] };

  // Dibandingkan: keduanya petugas yang sama, cuma beda isi kolom preferensinya.
  // Versi lama memisahkan keduanya; yang benar menyatukannya.
  expect(dashboardModeFor(stale)).toBe("officiating");
  expect(dashboardModeFor(fresh)).toBe("officiating");
});

test("wasit yang juga memanajeri tim tetap memegang topinya sendiri", () => {
  // Pasangan pembanding untuk uji di atas: `officiating` sama-sama terisi, yang
  // memisahkan keduanya cuma `account_types`. Tanpa uji ini, "ada penugasan =
  // petugas" lolos — dan manajer tim yang kebetulan jadi wasit kehilangan akses
  // ke timnya sendiri setiap kali admin login sebagai dia.
  const both = {
    default_mode: "participant" as const,
    officiating: [{}],
    account_types: ["participant"],
  };
  const crewOnly = { default_mode: "participant" as const, officiating: [{}], account_types: [] };

  expect(dashboardModeFor(both)).toBe("participant");
  expect(dashboardModeFor(crewOnly)).toBe("officiating");
});

test("akun tanpa penugasan memakai default_mode-nya, dan tanpa itu jatuh ke organizer", () => {
  // Arah sebaliknya: derivasi tidak boleh menimpa preferensi orang yang memang
  // memilihnya. Dibandingkan dengan akun yang kolomnya belum ada sama sekali.
  expect(dashboardModeFor({ default_mode: "participant", officiating: [], account_types: [] })).toBe(
    "participant"
  );
  expect(dashboardModeFor({ officiating: [], account_types: [] })).toBe("organizer");
});

test("isCrewOnly membaca absen sebagai kosong, bukan sebagai petugas", () => {
  // `officiating` & `account_types` opsional di AuthUser — "tidak di-load" dan
  // "tidak punya" adalah absen yang sama. Dibandingkan dengan payload petugas
  // sungguhan, karena keduanya sama-sama punya `account_types` kosong dan cuma
  // penugasannya yang memisahkan.
  expect(isCrewOnly({})).toBe(false);
  expect(isCrewOnly({ account_types: [] })).toBe(false);
  expect(isCrewOnly({ officiating: [{}], account_types: [] })).toBe(true);
});

test("tujuan petugas punya home-nya sendiri di MODE_HOME", () => {
  // Yang menyambungkan kedua bagian: dashboardModeFor() memilih mode, MODE_HOME
  // menerjemahkannya jadi URL. Dibandingkan dengan tujuan organizer, karena
  // itulah alamat yang dulu dituju — dan /onboarding bukan salah satunya.
  const crew = { default_mode: "organizer" as const, officiating: [{}], account_types: [] };
  const organizer = { default_mode: "organizer" as const, officiating: [], account_types: ["organizer"] };

  expect(MODE_HOME[dashboardModeFor(crew)]).toBe("/officiating");
  expect(MODE_HOME[dashboardModeFor(organizer)]).toBe("/organizer");
});
