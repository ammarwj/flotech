"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Maximize2, Minimize2, RefreshCw } from "lucide-react";

import { getPublicScoreboard } from "@/lib/api/matches";
import { crestGradient, matchWinnerId, wentToPenalties } from "@/lib/bracket";
import { rubberLineup, scoreUnitLabel } from "@/lib/scoring";
import { timeOf, tzLabel } from "@/lib/match-dates";
import { PublicStatusBadge } from "@/components/event/public-status-badge";
import { cn } from "@/lib/utils";
import type { MatchRubber } from "@/types/api";
import "../scoreboard.css";

/**
 * Papan skor satu pertandingan — dibuka di tab baru dari detail jadwal, lalu
 * ditinggal di monitor atau proyektor di pinggir lapangan.
 *
 * Sengaja berdiri sendiri, di luar halaman event: tidak ada nav, tidak ada tab,
 * tidak ada footer — satu layar penuh berisi satu pertandingan. Tidak ada
 * interaksi selain layar penuh dan satu tombol muat ulang, karena tidak ada
 * yang duduk di depannya.
 *
 * Halaman ini tidak berada di bawah `EventTimezoneProvider` milik halaman event
 * (ia route tersendiri, bukan tab di sana), jadi zona waktunya datang dari
 * payload dan `timeOf` dipanggil dengan zona itu langsung. Jam kickoff adalah
 * instant UTC; membiarkannya jatuh ke zona penonton akan mencetak jam yang
 * salah justru di layar yang dipasang di venue.
 */
export default function ScoreboardPage() {
  const params = useParams<{ orgSlug: string; eventSlug: string; matchId: string }>();

  const query = useQuery({
    queryKey: ["public-scoreboard", params.orgSlug, params.eventSlug, params.matchId],
    queryFn: () => getPublicScoreboard(params.orgSlug, params.eventSlug, params.matchId),
    retry: false,
    /**
     * Polling berhenti sendiri begitu pertandingan selesai atau dibatalkan.
     *
     * Papan ini ditinggal menyala berjam-jam — kadang semalaman setelah laga
     * terakhir — dan tanpa syarat ini satu layar yang terlupakan terus memukul
     * API tiap sepuluh detik sampai ada yang menutupnya. Pola yang sama dengan
     * halaman pesanan tiket, yang berhenti begitu pembayarannya settle.
     *
     * Laga `scheduled` ikut di-poll: papan dipasang sebelum kickoff, dan
     * statusnya sendiri yang harus berubah jadi LIVE di layar tanpa ada yang
     * menyentuhnya.
     */
    refetchInterval: (q) => {
      const status = q.state.data?.match.status;
      return status === "finished" || status === "cancelled" ? false : 10_000;
    },
  });

  const data = query.data;
  const match = data?.match;

  if (query.isLoading) {
    return <div className="sb-state">Memuat papan skor…</div>;
  }

  if (query.isError || !data || !match) {
    return (
      <div className="sb-state">
        <p>Pertandingan tidak ditemukan.</p>
        <p style={{ fontSize: "0.85rem" }}>
          Tautan papan skor mungkin sudah tidak berlaku, atau jadwalnya telah diubah.
        </p>
      </div>
    );
  }

  const tz = data.timezone || "Asia/Jakarta";
  const live = match.status === "ongoing";
  const hasScore = match.home_score !== null && match.away_score !== null;
  const done = match.status === "finished" && hasScore;
  // Aturan yang sama dengan PublicMatchCard: skor berjalan ditampilkan seperti
  // skor akhir, tapi yang meredupkan pihak kalah hanya hasil final — di menit
  // ke-20 belum ada yang kalah.
  const showScore = done || (live && hasScore);
  const winner = done ? matchWinnerId(match) : null;
  const time = timeOf(match.scheduled_at, tz);
  const sets = showScore && match.sets?.length ? match.sets : null;
  const rubbers = match.rubbers ?? [];
  const unit = showScore ? scoreUnitLabel(data.sport, match) : null;

  return (
    <div className="sb">
      <div className="sb-top">
        <div className="sb-where">
          <span className="sb-event">{data.event_name}</span>
          <span className="sb-sub">
            {data.category_name && <span className="pill">{data.category_name}</span>}
            <PublicStatusBadge status={match.status} />
            {time && (
              <span>
                {time} {tzLabel(tz)}
              </span>
            )}
            {match.venue && <span>{match.venue}</span>}
          </span>
        </div>
        <div className="sb-tools">
          <button
            type="button"
            className="sb-btn"
            onClick={() => query.refetch()}
            aria-label="Muat ulang skor"
            title="Muat ulang skor"
          >
            <RefreshCw
              className={cn("h-[1.1em] w-[1.1em]", query.isFetching && "animate-spin")}
              aria-hidden="true"
            />
          </button>
          <FullscreenButton />
        </div>
      </div>

      <div className="sb-body">
        <div className="sb-match">
          <Side
            name={match.home_team?.name ?? "TBD"}
            logoUrl={match.home_team?.logo_url}
            dimmed={!!winner && winner !== match.home_team_id}
          />

          <div>
            <div className="sb-score">
              {showScore ? (
                <>
                  <span>{match.home_score}</span>
                  <span className="sb-dash">–</span>
                  <span>{match.away_score}</span>
                </>
              ) : (
                <span className="sb-vs">VS</span>
              )}
            </div>
            {unit && <div className="sb-pen">{unit}</div>}
            {wentToPenalties(match) && (
              <div className="sb-pen">
                Penalti {match.home_penalty}–{match.away_penalty}
              </div>
            )}
          </div>

          <Side
            name={match.away_team?.name ?? "TBD"}
            logoUrl={match.away_team?.logo_url}
            dimmed={!!winner && winner !== match.away_team_id}
          />
        </div>

        {sets && (
          <div className="sb-sets">
            {sets.map((s, i) => (
              <span key={i} className="sb-set">
                <small>Set {i + 1}</small>
                <span>
                  <b>{s.home}</b>
                  <span className="sb-dash"> – </span>
                  <b>{s.away}</b>
                </span>
              </span>
            ))}
          </div>
        )}

        {/* Tie beregu: "3 – 0" di atas ADALAH partai-partai ini dihitung, jadi
            rinciannya bagian dari skornya, bukan pelengkap di bawahnya. */}
        {rubbers.length > 0 && (
          <div className="sb-rubbers">
            {rubbers.map((r) => (
              <RubberRow key={r.id} rubber={r} />
            ))}
          </div>
        )}
      </div>

      <div className="sb-foot">
        <span>{data.event_name}</span>
        {live && <span>· Skor diperbarui otomatis</span>}
      </div>
    </div>
  );
}

function Side({
  name,
  logoUrl,
  dimmed,
}: {
  name: string;
  logoUrl?: string | null;
  dimmed: boolean;
}) {
  return (
    <div className="sb-side" style={dimmed ? { opacity: 0.45 } : undefined}>
      {logoUrl ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img className="sb-crest" src={logoUrl} alt="" />
      ) : (
        <span className="sb-crest" style={{ background: crestGradient(name) }} />
      )}
      <span className="sb-name">{name}</span>
    </div>
  );
}

/**
 * Satu partai: namanya, siapa yang turun, dan skornya.
 *
 * Lineup-nya ikut karena di tie beregu itulah yang dilihat penonton — "Ganda
 * Putra" tidak memberi tahu siapa pun siapa yang sedang di lapangan. `null`
 * saat roster tidak ikut dimuat, dan `rubberLineup` sudah menjawabnya dengan
 * "—" sehingga barisnya tidak perlu bercabang.
 */
function RubberRow({ rubber }: { rubber: MatchRubber }) {
  const played = rubber.home_score !== null && rubber.away_score !== null;

  return (
    <div className="sb-rubber">
      <span className="sb-rubber-label">
        {rubber.label}
        <small>
          {rubberLineup(rubber.home_players)} vs {rubberLineup(rubber.away_players)}
        </small>
      </span>
      {played ? (
        <span className="sb-rubber-score">
          {rubber.home_score} – {rubber.away_score}
        </span>
      ) : (
        <span className="sb-rubber-pending">belum dimainkan</span>
      )}
    </div>
  );
}

/**
 * Layar penuh, untuk papan yang dipasang di monitor atau proyektor.
 *
 * Statusnya dibaca dari `document.fullscreenElement` lewat event
 * `fullscreenchange`, bukan disimpan sendiri saat tombolnya diklik: Escape dan
 * F11 keluar dari layar penuh tanpa melewati tombol ini, dan state yang ditulis
 * sendiri akan tertinggal menampilkan ikon "keluar" pada halaman yang sudah
 * kembali berjendela.
 */
function FullscreenButton() {
  const [full, setFull] = useState(false);

  useEffect(() => {
    const sync = () => setFull(Boolean(document.fullscreenElement));
    sync();
    document.addEventListener("fullscreenchange", sync);
    return () => document.removeEventListener("fullscreenchange", sync);
  }, []);

  const toggle = useCallback(() => {
    // Ditelan: Safari di iOS menolak layar penuh untuk elemen non-video dan
    // me-reject promise-nya. Papan skornya sendiri tetap benar, jadi tidak ada
    // yang perlu dilaporkan ke layar yang tidak ada penunggunya.
    if (document.fullscreenElement) {
      void document.exitFullscreen().catch(() => {});
    } else {
      void document.documentElement.requestFullscreen().catch(() => {});
    }
  }, []);

  return (
    <button
      type="button"
      className="sb-btn"
      onClick={toggle}
      aria-label={full ? "Keluar dari layar penuh" : "Layar penuh"}
      title={full ? "Keluar dari layar penuh" : "Layar penuh"}
    >
      {full ? (
        <Minimize2 className="h-[1.1em] w-[1.1em]" aria-hidden="true" />
      ) : (
        <Maximize2 className="h-[1.1em] w-[1.1em]" aria-hidden="true" />
      )}
    </button>
  );
}
