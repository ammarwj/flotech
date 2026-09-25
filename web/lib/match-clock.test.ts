import { expect, test } from "bun:test";

import { clockSeconds, clockText, displaySeconds, periodText } from "./match-clock";
import type { MatchClock } from "@/types/api";

/**
 * `bun test lib/match-clock.test.ts` — runner bawaan bun, tanpa dependensi baru
 * (pola `registration-form.test.ts`).
 *
 * Yang diuji adalah penurunan menit, dan tiap uji **membandingkan**: satu angka
 * yang benar juga keluar dari fungsi yang mengabaikan skew, mengabaikan babak,
 * atau mengabaikan `running`. Yang membuktikannya adalah dua panggilan yang
 * harus keluar sama, atau dua yang harus keluar berbeda.
 */

const ANCHOR = "2026-09-25T08:00:00+00:00";

function clock(over: Partial<MatchClock> = {}): MatchClock {
  return {
    period: 1,
    periods: 2,
    label: "Babak",
    period_minutes: 45,
    elapsed_seconds: 600,
    running: true,
    server_time: ANCHOR,
    ...over,
  };
}

/** Jam dinding klien, `offsetMs` melenceng dari jam server. */
function wall(secondsAfterAnchor: number, offsetMs = 0): number {
  return Date.parse(ANCHOR) + secondsAfterAnchor * 1000 + offsetMs;
}

test("dua laptop dengan skew berbeda menampilkan menit yang sama", () => {
  const payload = clock();

  // Laptop A tepat; laptop B melenceng empat menit ke depan. Keduanya membaca
  // payload yang sama 30 detik setelah server membuatnya.
  const a = clockSeconds(payload, 0, wall(30));
  const b = clockSeconds(payload, 4 * 60 * 1000, wall(30, 4 * 60 * 1000));

  expect(a).toBe(630);
  expect(b).toBe(a);

  // Pembandingnya: tanpa koreksi skew, laptop B akan melompat empat menit.
  expect(clockSeconds(payload, 0, wall(30, 4 * 60 * 1000))).toBe(630 + 240);
});

test("jam yang terjeda tidak bergerak sedangkan yang berjalan ikut dinding", () => {
  const running = clock();
  const paused = clock({ running: false });

  // Satu jam kemudian, payload yang sama persis kecuali `running`.
  expect(clockSeconds(running, 0, wall(3600))).toBe(600 + 3600);
  expect(clockSeconds(paused, 0, wall(3600))).toBe(600);
});

test("jam klien yang mundur berhenti, tidak pernah negatif", () => {
  // Skew salah tanda / NTP melompat ke belakang: "-3:12" di layar lapangan
  // lebih buruk daripada angka yang berhenti sejenak.
  expect(clockSeconds(clock({ elapsed_seconds: 0 }), 0, wall(-200))).toBe(0);
});

test("babak kedua berlanjut dari durasi babak pertama, bukan mulai dari nol", () => {
  const first = clock({ period: 1, elapsed_seconds: 600, running: false });
  const second = clock({ period: 2, elapsed_seconds: 600, running: false });

  // Detik tersimpannya identik; yang membedakan cuma nomor babaknya.
  expect(displaySeconds(first)).toBe(600);
  expect(displaySeconds(second)).toBe(45 * 60 + 600);

  // Dan offsetnya ikut aturan event, bukan angka yang dipatok di kode.
  expect(displaySeconds(clock({ period: 2, period_minutes: 25, running: false }))).toBe(25 * 60 + 600);
});

test("kuarter keempat basket memakai durasinya sendiri", () => {
  const kuarter = clock({ period: 4, periods: 4, label: "Kuarter", period_minutes: 10, running: false });

  expect(displaySeconds(kuarter)).toBe(3 * 10 * 60 + 600);
  expect(periodText(kuarter)).toBe("Kuarter 4");
  expect(periodText(clock())).toBe("Babak 1");
});

test("laga yang belum dimulai tidak menyebut babak apa pun", () => {
  expect(periodText(clock({ period: null }))).toBeNull();
  expect(periodText(null)).toBeNull();

  // Tapi jamnya tetap bisa dibaca 0, bukan NaN.
  expect(displaySeconds(clock({ period: null, elapsed_seconds: 0, running: false }))).toBe(0);
  expect(displaySeconds(null)).toBe(0);
});

test("menit tidak dipotong di 99", () => {
  expect(clockText(0)).toBe("0:00");
  expect(clockText(614)).toBe("10:14");
  expect(clockText(4034)).toBe("67:14");
  expect(clockText(6000)).toBe("100:00");
});
