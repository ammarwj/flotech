"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getRegistrations, updateRegistrationStatus } from "@/lib/api/events";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { TEAM_STATUS_LABELS } from "@/lib/labels";
import type { TeamStatus } from "@/types/api";

const STATUS_COLORS: Record<TeamStatus, string> = {
  pending: "var(--warning)",
  approved: "var(--success)",
  rejected: "var(--danger)",
  disqualified: "var(--danger)",
  withdrawn: "var(--text-muted)",
};

export default function RegistrationsPage() {
  const qc = useQueryClient();
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();

  const query = useQuery({
    queryKey: ["registrations", orgId, eventId],
    queryFn: () => getRegistrations(orgId!, eventId),
    enabled: !!orgId,
  });

  const mutate = useMutation({
    mutationFn: ({ teamId, status }: { teamId: string; status: TeamStatus }) =>
      updateRegistrationStatus(orgId!, eventId, teamId, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["registrations", orgId, eventId] }),
  });

  return (
    <div>
      <Link href="/dashboard/events" className="text-sm text-muted-foreground hover:text-foreground">
        ← Kembali ke daftar event
      </Link>
      <h1 className="mt-2 mb-6 text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        Kelola Pendaftaran
      </h1>

      {query.isLoading && <p className="text-muted-foreground">Memuat…</p>}
      {query.data?.length === 0 && <p className="text-muted-foreground">Belum ada pendaftaran tim.</p>}

      <div className="grid gap-3">
        {query.data?.map((team) => (
          <div key={team.id} className="rounded-lg border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
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
                  {team.city ? `${team.city} · ` : ""}
                  {team.contact_name} ({team.contact_phone}) · {team.players?.length ?? 0} pemain
                  {team.documents?.length ? ` · ${team.documents.length} dokumen` : ""}
                </div>
              </div>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  onClick={() => mutate.mutate({ teamId: team.id, status: "approved" })}
                  disabled={mutate.isPending || team.status === "approved"}
                >
                  Setujui
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => mutate.mutate({ teamId: team.id, status: "rejected" })}
                  disabled={mutate.isPending || team.status === "rejected"}
                >
                  Tolak
                </Button>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
