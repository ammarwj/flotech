"use client";

import { useEffect } from "react";
import { Users, X } from "lucide-react";

import { crestGradient } from "@/lib/bracket";
import { useCatalog } from "@/lib/hooks/use-catalog";
import type { PublicTeam } from "@/types/api";

export function TeamRosterDialog({
  team,
  sport,
  onClose,
}: {
  team: PublicTeam;
  /** Sport slug — a roster stores position keys, not the words to show. */
  sport?: string | null;
  onClose: () => void;
}) {
  const { positionLabel } = useCatalog();

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
    };
  }, [onClose]);

  const players = team.players ?? [];

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={`Skuad ${team.name}`}
      onClick={onClose}
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 backdrop-blur-sm sm:items-center sm:p-6"
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-t-2xl border border-border bg-[var(--surface)] shadow-xl sm:rounded-2xl"
      >
        <div className="flex items-center gap-3 border-b border-border p-4">
          {team.logo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={team.logo_url}
              alt={team.name}
              className="object-cover"
              style={{ width: 44, height: 44, borderRadius: 12, border: "1px solid var(--border)" }}
            />
          ) : (
            <span className="crest" style={{ width: 44, height: 44, borderRadius: 12, background: crestGradient(team.name) }} />
          )}
          <div className="min-w-0 flex-1">
            <h3 className="truncate text-base font-bold">{team.name}</h3>
            <div className="flex flex-wrap items-center gap-3 text-xs" style={{ color: "var(--text-muted)" }}>
              <span className="inline-flex items-center gap-1">
                <Users className="h-3.5 w-3.5" />
                {players.length} pemain
              </span>
            </div>
          </div>
          <button
            onClick={onClose}
            aria-label="Tutup"
            className="rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-[var(--surface-2)] hover:text-foreground"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="overflow-y-auto p-4">
          {players.length === 0 ? (
            <p className="section-sub" style={{ margin: 0 }}>
              Tim ini belum mendaftarkan pemain.
            </p>
          ) : (
            <ul className="flex flex-col gap-2">
              {players.map((p) => {
                const photo = p.photo_url && /^https?:\/\//.test(p.photo_url) ? p.photo_url : null;
                return (
                  <li
                    key={p.id ?? p.full_name}
                    className="flex items-center gap-3 rounded-xl border border-border p-2.5"
                  >
                    {photo ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={photo}
                        alt={p.full_name}
                        className="shrink-0 object-cover"
                        style={{ width: 34, height: 34, borderRadius: 9, border: "1px solid var(--border)" }}
                      />
                    ) : (
                      <span
                        className="grid shrink-0 place-items-center text-sm font-bold"
                        style={{
                          width: 34,
                          height: 34,
                          borderRadius: 9,
                          background: "var(--surface-2)",
                          color: "var(--text-muted)",
                        }}
                      >
                        {p.jersey_number ?? "–"}
                      </span>
                    )}
                    <span className="min-w-0 flex-1 truncate text-sm font-semibold">
                      {p.full_name}
                      {photo && p.jersey_number && (
                        <span className="ml-1.5 font-normal" style={{ color: "var(--text-muted)" }}>
                          #{p.jersey_number}
                        </span>
                      )}
                    </span>
                    {p.position && (
                      <span className="pill shrink-0">{positionLabel(sport, p.position)}</span>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}
