"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Users, Trophy } from "lucide-react";

import { getMyTeams } from "@/lib/api/events";
import { SPORT_LABELS } from "@/lib/labels";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { TeamStatusBadge } from "@/components/shared/status-badge";

function initials(name: string) {
  return name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
}

export default function ParticipantPage() {
  const query = useQuery({ queryKey: ["my-teams"], queryFn: getMyTeams });
  const teams = query.data;
  const approvedCount = teams?.filter((t) => t.status === "approved").length ?? 0;

  return (
    <div>
      <PageHeader
        title="Tim Saya"
        description="Pusat peserta — pantau tim yang kamu daftarkan dan statusnya di tiap event."
        actions={
          <Button asChild variant="outline">
            <Link href="/event">
              <Trophy className="h-4 w-4" />
              Jelajahi event
            </Link>
          </Button>
        }
      />

      {query.isLoading && (
        <div className="grid gap-3">
          {[0, 1].map((i) => (
            <Skeleton key={i} className="h-[80px] w-full rounded-xl" />
          ))}
        </div>
      )}

      {teams && teams.length > 0 && (
        <div className="mb-4 flex flex-wrap gap-3 text-sm text-muted-foreground">
          <span>
            <span className="font-semibold text-foreground">{teams.length}</span> tim terdaftar
          </span>
          <span>·</span>
          <span>
            <span className="font-semibold text-foreground">{approvedCount}</span> disetujui
          </span>
        </div>
      )}

      {teams?.length === 0 && (
        <EmptyState
          icon={Users}
          title="Belum ada tim"
          description="Kamu belum mendaftarkan tim ke event mana pun. Temukan event dan daftarkan timmu."
          action={
            <Button asChild variant="outline">
              <Link href="/event">Jelajahi event</Link>
            </Button>
          }
        />
      )}

      <div className="grid gap-3">
        {teams?.map((team) => (
          <Card key={team.id} className="flex items-center gap-4 p-4">
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--tint)] text-sm font-bold text-[var(--brand-600)]" style={{ fontFamily: "var(--font-display)" }}>
              {initials(team.name)}
            </span>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                  {team.name}
                </span>
                <TeamStatusBadge status={team.status} />
              </div>
              <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                  <Trophy className="h-3.5 w-3.5" />
                  {team.event?.name ?? "Event"}
                </span>
                {team.event?.sport_type && <span>{SPORT_LABELS[team.event.sport_type]}</span>}
                <span className="inline-flex items-center gap-1.5">
                  <Users className="h-3.5 w-3.5" />
                  {team.players?.length ?? 0} pemain
                </span>
              </div>
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
