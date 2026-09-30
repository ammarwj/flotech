"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { CalendarDays, Globe, Link2Off, ShieldCheck, TriangleAlert } from "lucide-react";

import { useConfirm } from "@/components/shared/confirm-provider";
import {
  getAdminEvents,
  setAdminEventDomain,
  activateAdminEventDomain,
  releaseAdminEventDomain,
} from "@/lib/api/admin-events";
import { parseApiError } from "@/lib/api/errors";
import { EVENT_STATUS_LABELS } from "@/lib/labels";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import type { AdminEvent, DomainStatus, EventStatus } from "@/types/api";

/**
 * Daftar event lintas-organisasi + pemasangan custom domain.
 *
 * Ini satu-satunya pintu custom domain, dan memang hanya untuk super admin:
 * menerbitkan sertifikat membakar kuota Let's Encrypt milik seluruh platform,
 * dan baru berhasil setelah seseorang di luar kendali kita mengarahkan DNS ke
 * VPS ini. Tidak ada gating paket — lihat `docs/custom-domain-progress.md`.
 */

const DOMAIN_BADGE: Record<DomainStatus, { label: string; variant: "neutral" | "warning" | "success" | "danger" }> = {
  none: { label: "Tanpa domain", variant: "neutral" },
  pending: { label: "Menunggu aktivasi", variant: "warning" },
  active: { label: "Aktif", variant: "success" },
  failed: { label: "Gagal", variant: "danger" },
};

export default function AdminEventsPage() {
  const confirm = useConfirm();
  const qc = useQueryClient();

  const [search, setSearch] = useState("");
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("");
  const [domain, setDomain] = useState("");
  const [page, setPage] = useState(1);

  // Debounce so we don't fire a request per keystroke.
  useEffect(() => {
    const t = setTimeout(() => {
      setQ(search);
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const query = useQuery({
    queryKey: ["admin-events", { q, status, domain, page }],
    queryFn: () =>
      getAdminEvents({
        q: q || undefined,
        status: status || undefined,
        domain: domain || undefined,
        page,
      }),
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ["admin-events"] });

  const save = useMutation({
    mutationFn: ({ id, value }: { id: string; value: string | null }) =>
      setAdminEventDomain(id, value),
    onSuccess: (event) => {
      toast.success(
        event.custom_domain
          ? "Domain disimpan. Tekan Aktifkan setelah A record-nya diarahkan."
          : "Domain dilepas."
      );
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan domain.").message),
  });

  const activate = useMutation({
    mutationFn: (id: string) => activateAdminEventDomain(id),
    onSuccess: () => {
      toast.success("Domain aktif. SSL sudah terbit.");
      invalidate();
    },
    // Aktivasi gagal itu kasus biasa (DNS belum propagasi), dan alasannya
    // tersimpan di baris event — jadi daftar tetap di-invalidate supaya
    // `domain_error` yang baru langsung tampil di kartunya.
    onError: (err) => {
      toast.error(parseApiError(err, "Aktivasi domain gagal.").message);
      invalidate();
    },
  });

  const release = useMutation({
    mutationFn: (id: string) => releaseAdminEventDomain(id),
    onSuccess: () => {
      toast.success("Domain dicabut dan sertifikatnya dihapus.");
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mencabut domain.").message),
  });

  const events = query.data?.items ?? [];
  const meta = query.data?.meta;
  const busy = save.isPending || activate.isPending || release.isPending;

  return (
    <>
      <PageHeader
        title="Event & Custom Domain"
        description="Semua event di seluruh organisasi. Pasang domain sendiri untuk event yang sudah publish, lalu aktifkan SSL-nya."
      />

      <div className="mb-4 flex flex-col gap-3 sm:flex-row">
        <Input
          placeholder="Cari nama event, slug, atau domain…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="sm:max-w-xs"
        />
        <Select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          className="sm:max-w-[200px]"
        >
          <option value="">Semua status</option>
          {Object.entries(EVENT_STATUS_LABELS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </Select>
        <Select
          value={domain}
          onChange={(e) => {
            setDomain(e.target.value);
            setPage(1);
          }}
          className="sm:max-w-[220px]"
        >
          <option value="">Semua domain</option>
          <option value="active">{DOMAIN_BADGE.active.label}</option>
          <option value="pending">{DOMAIN_BADGE.pending.label}</option>
          <option value="failed">{DOMAIN_BADGE.failed.label}</option>
          <option value="none">{DOMAIN_BADGE.none.label}</option>
        </Select>
      </div>

      {query.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[150px] rounded-xl" />
          ))}
        </div>
      ) : query.isError ? (
        <p className="text-sm text-destructive">
          Tidak bisa memuat event (butuh akses Super Admin &amp; API berjalan).
        </p>
      ) : events.length === 0 ? (
        <EmptyState
          icon={CalendarDays}
          title="Tidak ada event"
          description="Coba ubah kata kunci pencarian atau filternya."
        />
      ) : (
        <>
          <div className="grid gap-3">
            {events.map((event) => (
              <EventDomainCard
                key={event.id}
                event={event}
                busy={busy}
                onSave={(value) => save.mutate({ id: event.id, value })}
                onActivate={() => activate.mutate(event.id)}
                onRelease={() =>
                  void confirm({
                    title: "Cabut domain ini?",
                    description: `${event.custom_domain} berhenti dilayani dan sertifikatnya dihapus.`,
                    consequences:
                      "Pengunjung yang membuka domain itu tidak akan sampai ke halaman event lagi. Mengaktifkannya lagi berarti menerbitkan sertifikat baru.",
                    confirmLabel: "Cabut domain",
                    tone: "danger",
                    icon: Link2Off,
                  }).then((ok) => ok && release.mutate(event.id))
                }
              />
            ))}
          </div>

          {meta && meta.last_page > 1 && (
            <div className="mt-4 flex items-center justify-between text-sm">
              <span className="text-muted-foreground">
                Halaman {meta.page} dari {meta.last_page} · {meta.total} event
              </span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={meta.page <= 1}
                >
                  Sebelumnya
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage((p) => p + 1)}
                  disabled={meta.page >= meta.last_page}
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </>
  );
}

function EventDomainCard({
  event,
  busy,
  onSave,
  onActivate,
  onRelease,
}: {
  event: AdminEvent;
  busy: boolean;
  onSave: (value: string | null) => void;
  onActivate: () => void;
  onRelease: () => void;
}) {
  const [value, setValue] = useState(event.custom_domain ?? "");
  const badge = DOMAIN_BADGE[event.domain_status];

  // Draf tidak bisa dipasangi domain: halamannya belum publik, jadi domainnya
  // akan resolve ke 404 — dan sertifikatnya membakar kuota untuk halaman yang
  // tidak bisa dibuka siapa pun. Cerminan guard di DomainService::assign().
  const isDraft = event.status === "draft";
  const dirty = value.trim() !== (event.custom_domain ?? "");

  return (
    <Card className="flex flex-col gap-4 p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-semibold">{event.name}</span>
            <Badge variant="neutral">
              {EVENT_STATUS_LABELS[event.status as EventStatus] ?? event.status}
            </Badge>
            <Badge variant={badge.variant}>
              {event.domain_status === "active" ? <ShieldCheck className="h-3 w-3" /> : null}
              {badge.label}
            </Badge>
          </div>
          <p className="mt-1 text-sm text-muted-foreground">
            {event.organization?.name ?? "—"} · /{event.organization?.slug}/{event.slug}
          </p>
        </div>

        {event.domain_status === "active" && event.custom_domain && (
          <a
            href={`https://${event.custom_domain}`}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1.5 text-sm font-medium text-[var(--brand-600)] hover:underline"
          >
            <Globe className="h-4 w-4" />
            {event.custom_domain}
          </a>
        )}
      </div>

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <Input
          placeholder="eventa.id"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          disabled={isDraft || busy}
          className="sm:max-w-xs"
        />
        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            variant="outline"
            disabled={isDraft || busy || !dirty}
            onClick={() => onSave(value.trim() || null)}
          >
            Simpan domain
          </Button>
          {event.custom_domain && event.domain_status !== "active" && (
            <Button size="sm" disabled={busy || dirty} onClick={onActivate}>
              Aktifkan &amp; terbitkan SSL
            </Button>
          )}
          {event.custom_domain && (
            <Button size="sm" variant="ghost" disabled={busy} onClick={onRelease}>
              <Link2Off className="h-4 w-4" />
              Cabut
            </Button>
          )}
        </div>
      </div>

      {isDraft ? (
        <p className="text-sm text-muted-foreground">
          Event masih draf. Publikasikan dulu sebelum memasang domain.
        </p>
      ) : (
        <p className="text-sm text-muted-foreground">
          Arahkan A record domain ke IP VPS flotech, lalu tekan Aktifkan. Aktivasi
          yang gagal karena DNS belum propagasi akan dicoba ulang otomatis.
        </p>
      )}

      {/* Pesan certbot/DNS apa adanya — satu-satunya petunjuk kenapa gagal. */}
      {event.domain_error && (
        <p className="flex items-start gap-2 rounded-lg bg-[color-mix(in_srgb,var(--danger)_10%,transparent)] p-3 text-sm text-[var(--danger)]">
          <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
          <span className="break-words">{event.domain_error}</span>
        </p>
      )}
    </Card>
  );
}
