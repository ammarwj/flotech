"use client";

import { useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Check,
  X,
  Plus,
  Pencil,
  Users,
  Phone,
  FileText,
  Inbox,
  MapPin,
  ChevronDown,
  ExternalLink,
  CalendarClock,
} from "lucide-react";
import { format, parseISO } from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import { toast } from "sonner";

import {
  createRegistration,
  getEvent,
  getRegistrations,
  updateRegistration,
  updateRegistrationStatus,
  type RegisterTeamPayload,
} from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { TeamStatusBadge } from "@/components/shared/status-badge";
import { ManualTeamDialog } from "@/components/event/manual-team-dialog";
import type { Team, TeamStatus } from "@/types/api";

function initials(name: string) {
  return name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
}

const fmtDateTime = (iso: string | null) =>
  iso ? format(parseISO(iso), "d MMM yyyy, HH:mm", { locale: idLocale }) : "—";

type Filter = "all" | "pending" | "approved" | "rejected";

const FILTERS: [Filter, string][] = [
  ["all", "Semua"],
  ["pending", "Menunggu"],
  ["approved", "Disetujui"],
  ["rejected", "Ditolak"],
];

export default function RegistrationsPage() {
  const qc = useQueryClient();
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();
  const [filter, setFilter] = useState<Filter>("all");

  // null = closed, "new" = adding, a Team = editing that team.
  const [manual, setManual] = useState<Team | "new" | null>(null);
  const [manualErrors, setManualErrors] = useState<Record<string, string>>({});

  const query = useQuery({
    queryKey: ["registrations", orgId, eventId],
    queryFn: () => getRegistrations(orgId!, eventId),
    enabled: !!orgId,
  });

  // Only for the position suggestions in the roster editor.
  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const saveManual = useMutation({
    mutationFn: (payload: RegisterTeamPayload) =>
      manual && manual !== "new"
        ? updateRegistration(orgId!, eventId, manual.id, payload)
        : createRegistration(orgId!, eventId, payload),
    onSuccess: () => {
      toast.success(manual === "new" ? "Tim berhasil ditambahkan." : "Data tim diperbarui.");
      qc.invalidateQueries({ queryKey: ["registrations", orgId, eventId] });
      setManual(null);
      setManualErrors({});
    },
    onError: (err) => {
      const { message, fieldErrors } = parseApiError(err, "Gagal menyimpan tim.");
      setManualErrors(fieldErrors);
      if (Object.keys(fieldErrors).length === 0) toast.error(message);
    },
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

  const counts: Record<Filter, number> = {
    all: teams?.length ?? 0,
    pending: pendingCount,
    approved: teams?.filter((t) => t.status === "approved").length ?? 0,
    rejected: teams?.filter((t) => t.status === "rejected").length ?? 0,
  };
  const shown = (teams ?? []).filter((t) => filter === "all" || t.status === filter);

  return (
    <div>
      <PageHeader
        title="Kelola Pendaftaran"
        description={
          teams && teams.length > 0
            ? `${teams.length} tim terdaftar · ${pendingCount} menunggu persetujuan`
            : "Setujui atau tolak tim yang mendaftar ke event ini."
        }
        backHref="/organizer/events"
        backLabel="Daftar event"
        actions={
          <Button onClick={() => setManual("new")} disabled={!orgId}>
            <Plus className="h-4 w-4" />
            Tambah Tim
          </Button>
        }
      />

      {query.isLoading && (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[88px] w-full rounded-xl" />
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

      {teams && teams.length > 0 && (
        <>
          <div className="mb-4 inline-flex items-center gap-1 rounded-lg border border-border bg-[var(--surface)] p-0.5 text-xs font-semibold">
            {FILTERS.map(([key, label]) => (
              <button
                key={key}
                onClick={() => setFilter(key)}
                className={cn(
                  "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 transition-colors",
                  filter === key ? "bg-[var(--brand-600)] text-white" : "text-muted-foreground hover:text-foreground"
                )}
              >
                {label}
                <span
                  className={cn(
                    "rounded-full px-1.5 text-[11px]",
                    filter === key ? "bg-white/20" : "bg-[var(--bg-soft)]"
                  )}
                >
                  {counts[key]}
                </span>
              </button>
            ))}
          </div>

          {shown.length === 0 ? (
            <EmptyState icon={Inbox} title="Tidak ada tim" description="Tidak ada tim pada filter ini." />
          ) : (
            <div className="grid gap-3">
              {shown.map((team) => (
                <RegistrationCard
                  key={team.id}
                  team={team}
                  sport={eventQuery.data?.sport_type}
                  pending={mutate.isPending}
                  onUpdate={(status) => mutate.mutate({ teamId: team.id, status })}
                  onEdit={() => setManual(team)}
                />
              ))}
            </div>
          )}
        </>
      )}

      <ManualTeamDialog
        // Remount per team (and per open) so the form is seeded fresh.
        key={manual === "new" ? "new" : (manual?.id ?? "closed")}
        open={!!manual}
        team={manual !== "new" ? manual : null}
        sport={eventQuery.data?.sport_type}
        pending={saveManual.isPending}
        fieldErrors={manualErrors}
        onClose={() => {
          setManual(null);
          setManualErrors({});
        }}
        onSubmit={(payload) => saveManual.mutate(payload)}
      />
    </div>
  );
}

function RegistrationCard({
  team,
  sport,
  pending,
  onUpdate,
  onEdit,
}: {
  team: Team;
  /** Sport slug — a roster stores position keys, not the words to show. */
  sport?: string | null;
  pending: boolean;
  onUpdate: (status: TeamStatus) => void;
  onEdit: () => void;
}) {
  const { positionLabel } = useCatalog();
  const [open, setOpen] = useState(false);
  const logo = team.logo_url && /^https?:\/\//.test(team.logo_url) ? team.logo_url : null;
  const paid = team.payment_amount > 0;
  const players = team.players ?? [];
  const docs = team.documents ?? [];

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-wrap items-start justify-between gap-4 p-4">
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="flex min-w-0 flex-1 items-start gap-3 text-left"
        >
          {logo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logo} alt={team.name} className="h-11 w-11 shrink-0 rounded-xl border border-border object-cover" />
          ) : (
            <span
              className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--tint)] text-sm font-bold text-[var(--brand-600)]"
              style={{ fontFamily: "var(--font-display)" }}
            >
              {initials(team.name)}
            </span>
          )}
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                {team.name}
              </span>
              <TeamStatusBadge status={team.status} />
              {paid && (
                <Badge variant={team.payment_status === "paid" ? "success" : "warning"}>
                  {team.payment_status === "paid" ? "Lunas" : "Belum bayar"}
                </Badge>
              )}
            </div>
            <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
              {team.city && (
                <span className="inline-flex items-center gap-1.5">
                  <MapPin className="h-3.5 w-3.5" />
                  {team.city}
                </span>
              )}
              <span className="inline-flex items-center gap-1.5">
                <Users className="h-3.5 w-3.5" />
                {players.length} pemain
              </span>
              {docs.length > 0 && (
                <span className="inline-flex items-center gap-1.5">
                  <FileText className="h-3.5 w-3.5" />
                  {docs.length} dokumen
                </span>
              )}
              <span className="inline-flex items-center gap-1.5 font-medium text-[var(--brand-600)]">
                {open ? "Sembunyikan" : "Lihat detail"}
                <ChevronDown className={cn("h-3.5 w-3.5 transition-transform", open && "rotate-180")} />
              </span>
            </div>
          </div>
        </button>
        <div className="flex gap-2">
          <Button size="sm" variant="outline" onClick={onEdit} aria-label={`Ubah tim ${team.name}`}>
            <Pencil className="h-4 w-4" />
            Ubah
          </Button>
          <Button size="sm" onClick={() => onUpdate("approved")} disabled={pending || team.status === "approved"}>
            <Check className="h-4 w-4" />
            Setujui
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={() => onUpdate("rejected")}
            disabled={pending || team.status === "rejected"}
          >
            <X className="h-4 w-4" />
            Tolak
          </Button>
        </div>
      </div>

      {open && (
        <div className="border-t border-border bg-[var(--bg-alt)] p-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Info label="Kontak">
              <span className="inline-flex items-center gap-1.5 text-sm font-medium">
                <Phone className="h-3.5 w-3.5 text-muted-foreground" />
                {team.contact_name ?? "—"} · {team.contact_phone ?? "—"}
              </span>
            </Info>
            <Info label="Kota" value={team.city || "—"} />
            <Info label="Warna jersey" value={team.jersey_color || "—"} />
            <Info label="Tanggal daftar">
              <span className="inline-flex items-center gap-1.5 text-sm font-medium">
                <CalendarClock className="h-3.5 w-3.5 text-muted-foreground" />
                {fmtDateTime(team.registered_at)}
              </span>
            </Info>
            <Info label="Pembayaran">
              {paid ? (
                <span className="text-sm font-medium">
                  {rupiah(team.payment_amount)} ·{" "}
                  <span className={team.payment_status === "paid" ? "text-[var(--success)]" : "text-[var(--warning)]"}>
                    {team.payment_status === "paid" ? "Lunas" : "Belum bayar"}
                  </span>
                </span>
              ) : (
                <span className="text-sm font-medium">Gratis</span>
              )}
            </Info>
            {team.status === "approved" && <Info label="Disetujui" value={fmtDateTime(team.approved_at)} />}
          </div>

          <div className="mt-5">
            <h4 className="mb-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">
              Pemain ({players.length})
            </h4>
            {players.length === 0 ? (
              <p className="text-sm text-muted-foreground">Belum ada pemain.</p>
            ) : (
              <div className="grid gap-1.5 sm:grid-cols-2">
                {players.map((p, i) => (
                  <div
                    key={p.id ?? i}
                    className="flex items-center gap-2.5 rounded-md border border-border bg-[var(--surface)] px-3 py-2 text-sm"
                  >
                    <span className="w-6 shrink-0 text-center font-mono text-xs text-muted-foreground">
                      {p.jersey_number || "–"}
                    </span>
                    <span className="min-w-0 flex-1 truncate font-medium">{p.full_name}</span>
                    {p.position && (
                      <span className="shrink-0 text-xs text-muted-foreground">
                        {positionLabel(sport, p.position)}
                      </span>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>

          {docs.length > 0 && (
            <div className="mt-5">
              <h4 className="mb-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                Dokumen ({docs.length})
              </h4>
              <div className="grid gap-1.5">
                {docs.map((d, i) => {
                  const url = /^https?:\/\//.test(d.file_url) ? d.file_url : null;
                  const label = d.file_name ?? d.document_type ?? "Dokumen";
                  return url ? (
                    <a
                      key={d.id ?? i}
                      href={url}
                      target="_blank"
                      rel="noreferrer"
                      className="flex items-center gap-2 rounded-md border border-border bg-[var(--surface)] px-3 py-2 text-sm transition-colors hover:border-[var(--brand-500)]"
                    >
                      <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                      <span className="min-w-0 flex-1 truncate">{label}</span>
                      <ExternalLink className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    </a>
                  ) : (
                    <div
                      key={d.id ?? i}
                      className="flex items-center gap-2 rounded-md border border-border bg-[var(--surface)] px-3 py-2 text-sm text-muted-foreground"
                    >
                      <FileText className="h-4 w-4 shrink-0" />
                      <span className="min-w-0 flex-1 truncate">{label}</span>
                      <span className="shrink-0 text-xs">tidak tersedia</span>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

function Info({ label, value, children }: { label: string; value?: string; children?: React.ReactNode }) {
  return (
    <div className="grid gap-1">
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      {children ?? <span className="text-sm font-medium">{value}</span>}
    </div>
  );
}
