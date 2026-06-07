"use client";

import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, X, Users, Phone, FileText, Inbox, MapPin } from "lucide-react";

import { toast } from "sonner";

import { getRegistrations, updateRegistrationStatus } from "@/lib/api/events";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { TeamStatusBadge } from "@/components/shared/status-badge";
import type { TeamStatus } from "@/types/api";

function initials(name: string) {
  return name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
}

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
    onSuccess: (_, { status }) => {
      toast.success(status === "approved" ? "Tim berhasil disetujui." : "Tim berhasil ditolak.");
      qc.invalidateQueries({ queryKey: ["registrations", orgId, eventId] });
    },
    onError: (_, { status }) => {
      toast.error(status === "approved" ? "Gagal menyetujui tim." : "Gagal menolak tim.");
    },
  });

  const teams = query.data;
  const pendingCount = teams?.filter((t) => t.status === "pending").length ?? 0;

  return (
    <div>
      <PageHeader
        title="Kelola Pendaftaran"
        description={
          teams && teams.length > 0
            ? `${teams.length} tim terdaftar · ${pendingCount} menunggu persetujuan`
            : "Setujui atau tolak tim yang mendaftar ke event ini."
        }
        backHref="/dashboard/events"
        backLabel="Daftar event"
      />

      {query.isLoading && (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[96px] w-full rounded-xl" />
          ))}
        </div>
      )}

      {teams?.length === 0 && (
        <EmptyState
          icon={Inbox}
          title="Belum ada pendaftaran"
          description="Bagikan tautan event agar tim bisa mulai mendaftar."
        />
      )}

      <div className="grid gap-3">
        {teams?.map((team) => (
          <Card key={team.id} className="p-4">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div className="flex min-w-0 items-start gap-3">
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
                  <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                    {team.city && (
                      <span className="inline-flex items-center gap-1.5">
                        <MapPin className="h-3.5 w-3.5" />
                        {team.city}
                      </span>
                    )}
                    <span className="inline-flex items-center gap-1.5">
                      <Phone className="h-3.5 w-3.5" />
                      {team.contact_name} · {team.contact_phone}
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                      <Users className="h-3.5 w-3.5" />
                      {team.players?.length ?? 0} pemain
                    </span>
                    {team.documents?.length ? (
                      <span className="inline-flex items-center gap-1.5">
                        <FileText className="h-3.5 w-3.5" />
                        {team.documents.length} dokumen
                      </span>
                    ) : null}
                  </div>
                </div>
              </div>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  onClick={() => mutate.mutate({ teamId: team.id, status: "approved" })}
                  disabled={mutate.isPending || team.status === "approved"}
                >
                  <Check className="h-4 w-4" />
                  Setujui
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => mutate.mutate({ teamId: team.id, status: "rejected" })}
                  disabled={mutate.isPending || team.status === "rejected"}
                >
                  <X className="h-4 w-4" />
                  Tolak
                </Button>
              </div>
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
