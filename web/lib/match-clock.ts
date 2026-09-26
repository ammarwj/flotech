import { useEffect, useMemo, useState } from "react";

import type { MatchClock } from "@/types/api";

/**
 * Babak & jam pertandingan, diturunkan di klien.
 *
 * Server mengirim *anchor* — `elapsed_seconds` yang benar **pada**
 * `server_time` — bukan menit yang sudah jadi. Papan skor dipasang lalu
 * ditinggal, jadi angka yang dikirim jadi salah satu detik setelah dikirim;
 * yang menjadikannya benar adalah penjumlahan di sini, dan polling 10 detik yang
 * sudah ada cuma menyelaraskan ulang.
 *
 * Semua penurunan hidup di file ini. Papan skor, kontrol organizer, dan halaman
 * petugas membaca jam yang sama; tiga salinan `Math.floor(ms / 1000)` akan
 * menyimpang tepat di tempat yang paling kelihatan — dua layar di lapangan yang
 * sama menampilkan menit berbeda.
 */

/**
 * Selisih jam klien terhadap jam server, dalam milidetik.
 *
 * Bukan hiasan: laptop venue yang jamnya meleset empat menit akan menampilkan
 * menit yang salah kalau ia mengurangi `server_time` dengan `Date.now()` mentah.
 * Dihitung sekali dari payload, lalu dipakai tiap detak.
 */
export function clockSkewMs(clock: Pick<MatchClock, "server_time">, now = Date.now()): number {
  const server = Date.parse(clock.server_time);

  return Number.isNaN(server) ? 0 : now - server;
}

/**
 * Detik yang sudah berjalan di babak ini, pada saat ini.
 *
 * Jam yang terjeda tidak bergerak sama sekali — `elapsed_seconds` sudah final,
 * dan menambahkan waktu dinding padanya akan membuat laga yang sudah selesai
 * terus berdetak di papan yang ditinggal semalaman.
 *
 * Tidak pernah negatif: jam klien yang mundur (atau skew yang salah tanda) akan
 * menghasilkan selisih negatif, dan "-3:12" di layar lapangan lebih buruk
 * daripada angka yang berhenti sejenak. Penjagaan yang sama ada di
 * `MatchClockService::elapsedSeconds()`.
 */
export function clockSeconds(
  clock: MatchClock | null | undefined,
  skewMs = 0,
  now = Date.now()
): number {
  if (!clock) return 0;
  if (!clock.running) return Math.max(0, clock.elapsed_seconds);

  const since = Math.floor((now - skewMs - Date.parse(clock.server_time)) / 1000);

  return Math.max(0, clock.elapsed_seconds + Math.max(0, since));
}

/**
 * Detik yang ditampilkan: jam babak ini ditambah babak-babak sebelumnya.
 *
 * Papan skor sepak bola membaca "67:14" di babak dua, bukan "22:14" — menitnya
 * berlanjut, walau yang tersimpan tetap detik babak itu sendiri. Offsetnya
 * dihitung dari `period_minutes` justru supaya yang tersimpan tidak perlu tahu:
 * organizer yang mengubah durasi babak menggeser presentasi, bukan data.
 */
export function displaySeconds(
  clock: MatchClock | null | undefined,
  skewMs = 0,
  now = Date.now()
): number {
  if (!clock) return 0;

  const period = Math.max(1, clock.period ?? 1);

  return (period - 1) * clock.period_minutes * 60 + clockSeconds(clock, skewMs, now);
}

/**
 * `{ minutes: "67", seconds: "14" }` — dua bagian, karena papan skor memisahkan
 * keduanya untuk mengedipkan titik duanya.
 *
 * Menit tidak pernah dipotong di 99: laga yang jamnya lupa dijeda lebih baik
 * membaca "134" daripada "34", yang terbaca sebagai menit yang wajar dan
 * menyembunyikan kesalahannya.
 */
export function clockParts(seconds: number): { minutes: string; seconds: string } {
  const safe = Math.max(0, Math.floor(seconds));

  return {
    minutes: String(Math.floor(safe / 60)),
    seconds: String(safe % 60).padStart(2, "0"),
  };
}

/** `"67:14"`, dirakit dari {@link clockParts} supaya formatnya satu sumber. */
export function clockText(seconds: number): string {
  const { minutes, seconds: secs } = clockParts(seconds);

  return `${minutes}:${secs}`;
}

/** `"Babak 2"` / `"Kuarter 4"`, atau null selama babaknya belum dimulai. */
export function periodText(clock: MatchClock | null | undefined): string | null {
  if (!clock?.period) return null;

  return `${clock.label} ${clock.period}`;
}

/**
 * Jam yang berdetak, sampai ia berhenti.
 *
 * `setInterval` dipasang **hanya saat `running`**. Papan skor ditinggal
 * semalaman di layar lapangan; interval yang tidak pernah berhenti adalah
 * alasan yang sama kenapa `refetchInterval` di halaman itu sudah dimatikan saat
 * laganya selesai.
 *
 * Skew dihitung ulang tiap payload baru datang (`server_time` yang berubah),
 * bukan sekali seumur komponen: papan yang dibuka sebelum jam server dikoreksi
 * akan memegang selisih yang salah sampai tabnya ditutup.
 */
export function useMatchClock(clock: MatchClock | null | undefined): {
  seconds: number;
  text: string;
  period: string | null;
} {
  const skewMs = useMemo(
    () => (clock ? clockSkewMs(clock) : 0),
    // Payload baru = pengukuran skew baru; `server_time` yang menandainya.
    [clock?.server_time], // eslint-disable-line react-hooks/exhaustive-deps
  );

  /**
   * State-nya cuma pemicu, bukan jamnya.
   *
   * Menitnya diturunkan saat render — sama seperti di server, dan dengan alasan
   * yang sama: angka yang disimpan di state basi satu detik setelah ditulis, dan
   * ia harus dijaga tetap sejalan dengan `clock` yang datang dari polling lewat
   * effect kedua yang menulis balik ke React. Detik yang berdetak adalah
   * *sumber eksternal* (waktu dinding); yang dilanggan effect ini adalah
   * perubahannya, bukan nilainya.
   */
  const [, tick] = useState(0);

  useEffect(() => {
    if (!clock?.running) return;

    const id = setInterval(() => tick((n) => n + 1), 1000);

    return () => clearInterval(id);
  }, [clock?.running]);

  const seconds = displaySeconds(clock, skewMs);

  return { seconds, text: clockText(seconds), period: periodText(clock) };
}
