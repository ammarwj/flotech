"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ChevronsRight, Pause, Play, RotateCcw } from "lucide-react";
import { toast } from "sonner";

import { parseApiError } from "@/lib/api/errors";
import { useMatchClock } from "@/lib/match-clock";
import { Button } from "@/components/ui/button";
import type { MatchClockGateway } from "@/lib/match-doors";
import type { Match, MatchClock, MatchClockAction } from "@/types/api";

/**
 * Stopwatch pertandingan: Mulai / Jeda / Lanjut / Babak berikutnya / Reset.
 *
 * Dipasang di `MatchCardHeader`, bukan `MatchCard` — kartu itu punya enam
 * cabang render, dan docblock header itu sendiri sudah menyatakan ia ada supaya
 * hal semacam ini ditulis sekali.
 *
 * Jamnya ikut ditampilkan di sini, dari hook yang sama yang dipakai papan skor
 * publik. Tanpa itu yang memegang tombol tidak melihat apa yang dilihat
 * penonton, dan satu-satunya cara memastikan jamnya benar adalah membuka tab
 * papan skor — persis saat ia sedang berdiri di pinggir lapangan.
 *
 * Pintunya dioper, bukan dipilih di sini: organizer dan petugas pertandingan
 * memanggil service yang sama di server, dan komponen yang memilih sendiri
 * antara dua endpoint adalah salinan ketiga aturan scoping (lihat
 * `lib/match-doors.ts`).
 */
export function MatchClockControls({
  match,
  clock,
  gateway,
}: {
  match: Match;
  /**
   * Null saat laga ini tidak berjam — cabang berskor set dan tie beregu.
   *
   * **Required, bukan optional-berdefault**, alasan yang sama yang sudah
   * tertulis untuk `bans` di `MatchCardHeader`: pemanggil yang lupa mengopernya
   * harus jadi error tipe, bukan kartu yang diam-diam kehilangan jamnya.
   */
  clock: MatchClock | null;
  gateway: MatchClockGateway;
}) {
  const qc = useQueryClient();
  const { text, period } = useMatchClock(clock);

  const mutation = useMutation({
    mutationFn: (action: MatchClockAction) => gateway.save(action),
    onSuccess: (_updated, action) => {
      toast.success(
        action === "pause"
          ? "Jam pertandingan dijeda"
          : action === "reset"
            ? "Jam pertandingan direset"
            : action === "advance"
              ? `${clock?.label ?? "Babak"} berikutnya dimulai`
              : "Jam pertandingan berjalan",
      );
      gateway.invalidate.forEach((queryKey) => qc.invalidateQueries({ queryKey }));
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengubah jam pertandingan.").message),
  });

  // Laga yang sudah selesai atau dibatalkan ditolak server, jadi tombolnya tidak
  // ditawarkan sama sekali — menawarkan tombol yang pasti 422 sama saja dengan
  // menawarkan penolakan.
  if (!clock || match.status === "finished" || match.status === "cancelled") {
    return null;
  }

  const started = clock.period !== null;
  const lastPeriod = clock.period !== null && clock.period >= clock.periods;

  return (
    <div className="flex flex-wrap items-center gap-1">
      {started && (
        <span className="mr-1 flex items-baseline gap-1.5 text-sm">
          {period && <span className="text-muted-foreground">{period}</span>}
          <span
            className="font-semibold tabular-nums"
            // Jam yang dijeda diredupkan, bukan disembunyikan: baris yang kosong
            // di menit turun minum terbaca sebagai kartu yang rusak.
            style={clock.running ? undefined : { opacity: 0.55 }}
          >
            {text}
          </span>
        </span>
      )}

      {clock.running ? (
        <Button
          size="sm"
          variant="ghost"
          disabled={mutation.isPending}
          onClick={() => mutation.mutate("pause")}
          className="text-muted-foreground"
        >
          <Pause className="h-4 w-4" />
          Jeda
        </Button>
      ) : (
        <Button
          size="sm"
          variant="ghost"
          disabled={mutation.isPending}
          onClick={() => mutation.mutate(started ? "resume" : "start")}
          className="text-muted-foreground"
        >
          <Play className="h-4 w-4" />
          {started ? "Lanjut" : "Mulai jam"}
        </Button>
      )}

      {/* Babak terakhir tidak punya babak berikutnya; server menolaknya 422. */}
      {started && !lastPeriod && (
        <Button
          size="sm"
          variant="ghost"
          disabled={mutation.isPending}
          onClick={() => mutation.mutate("advance")}
          className="text-muted-foreground"
        >
          <ChevronsRight className="h-4 w-4" />
          {clock.label} {(clock.period ?? 0) + 1}
        </Button>
      )}

      {/* Salah tekan, bukan babak baru — karena itu babaknya tidak ikut turun. */}
      {started && (
        <Button
          size="sm"
          variant="ghost"
          disabled={mutation.isPending}
          onClick={() => mutation.mutate("reset")}
          className="text-muted-foreground"
          aria-label="Reset jam babak ini"
          title="Reset jam babak ini"
        >
          <RotateCcw className="h-4 w-4" />
        </Button>
      )}
    </div>
  );
}
