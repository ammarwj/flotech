"use client";

import { useQuery } from "@tanstack/react-query";

import { getMyTeams } from "@/lib/api/events";
import { TEAM_STATUS_LABELS, SPORT_LABELS } from "@/lib/labels";
import type { TeamStatus } from "@/types/api";

const STATUS_COLORS: Record<TeamStatus, string> = {
  pending: "var(--warning)",
  approved: "var(--success)",
  rejected: "var(--danger)",
  disqualified: "var(--danger)",
  withdrawn: "var(--text-muted)",
};

export default function MyTeamsPage() {
  const query = useQuery({ queryKey: ["my-teams"], queryFn: getMyTeams });

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        Tim Saya
      </h1>
      <p className="mt-2 text-muted-foreground">Tim yang kamu daftarkan ke berbagai event.</p>

      {query.isLoading && <p className="mt-6 text-muted-foreground">Memuat…</p>}
      {query.data?.length === 0 && (
        <p className="mt-6 text-muted-foreground">Kamu belum mendaftarkan tim ke event mana pun.</p>
      )}

      <div className="mt-6 grid gap-3">
        {query.data?.map((team) => (
          <div key={team.id} className="rounded-lg border border-border bg-card p-4">
            <div className="flex items-center gap-2">
              <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                {team.name}
              </span>
              <span
                className="rounded-full px-2 py-0.5 text-xs font-semibold"
                style={{ color: STATUS_COLORS[team.status], background: "var(--bg-soft)" }}
              >
                {TEAM_STATUS_LABELS[team.status]}
              </span>
            </div>
            <div className="mt-1 text-sm text-muted-foreground">
              {team.event?.name ?? "Event"}
              {team.event?.sport_type ? ` · ${SPORT_LABELS[team.event.sport_type]}` : ""}
              {" · "}
              {team.players?.length ?? 0} pemain
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
