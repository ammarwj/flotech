"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Trash2, Eye, CalendarClock, Images } from "lucide-react";
import { toast } from "sonner";

import {
  getEvent,
  updateEvent,
  updateEventStatus,
  deleteEvent,
  type EventInput,
} from "@/lib/api/events";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { EVENT_STATUS_LABELS } from "@/lib/labels";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { EventForm } from "@/components/event/event-form";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EventStatusBadge } from "@/components/shared/status-badge";
import { EventStatusPanel } from "@/components/event/event-status-panel";
import { EventPlanPanel } from "@/components/event/event-plan-panel";
import { useConfirm } from "@/components/shared/confirm-provider";
import type { EventStatus } from "@/types/api";

/** Per-status confirmation copy; the API sends its own message, this is the UI's. */
const STATUS_TOASTS: Partial<Record<EventStatus, string>> = {
  open: "Event dipublikasikan",
  registration_closed: "Pendaftaran ditutup",
  ongoing: "Event ditandai sedang berlangsung",
  finished: "Event diselesaikan",
  cancelled: "Event dibatalkan",
};

export default function EditEventPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { org, orgId } = useActiveOrg();
  const confirm = useConfirm();
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const refresh = () => qc.invalidateQueries({ queryKey: ["event", orgId, eventId] });

  const update = useMutation({
    mutationFn: (values: EventInput) => updateEvent(orgId!, eventId, values),
    onSuccess: () => {
      toast.success("Perubahan tersimpan");
      refresh();
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan perubahan.");
      setFieldErrors(parsed.fieldErrors);
      // Per-field errors render inline; only surface a toast for other failures.
      if (Object.keys(parsed.fieldErrors).length === 0) {
        toast.error(parsed.message);
      }
    },
  });

  const changeStatus = useMutation({
    mutationFn: (status: EventStatus) => updateEventStatus(orgId!, eventId, status),
    // Reactivating lands on an ordinary status, so it is only recognisable from
    // where the event stood before the move — which is gone by onSuccess.
    onMutate: () => ({ reactivating: eventQuery.data?.status === "cancelled" }),
    onSuccess: (updated, _status, ctx) => {
      const reactivated = ctx?.reactivating ?? false;
      toast.success(
        reactivated ? "Event diaktifkan kembali" : (STATUS_TOASTS[updated.status] ?? "Status event diperbarui"),
        {
          description: reactivated
            ? `Status kembali ke ${EVENT_STATUS_LABELS[updated.status]}.`
            : updated.status === "open"
              ? "Halaman event tayang dan pendaftaran tim terbuka."
              : updated.status === "finished"
                ? "Dana tertahan dicairkan ke saldo organizer."
                : undefined,
        }
      );
      refresh();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengubah status event.").message),
  });

  const remove = useMutation({
    mutationFn: () => deleteEvent(orgId!, eventId),
    onSuccess: () => {
      toast.success("Event dihapus");
      router.push("/organizer/events");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus event.").message),
  });

  if (eventQuery.isLoading) {
    return (
      <div>
        <PageHeader title="Memuat…" backHref="/organizer/events" backLabel="Daftar event" />
        <div className="grid max-w-2xl gap-5">
          <Skeleton className="h-48 w-full rounded-xl" />
          <Skeleton className="h-40 w-full rounded-xl" />
        </div>
      </div>
    );
  }

  if (eventQuery.isError || !eventQuery.data)
    return (
      <div>
        <PageHeader title="Event tidak ditemukan" backHref="/organizer/events" backLabel="Daftar event" />
        <p className="text-sm text-[var(--danger)]">Event yang kamu cari tidak tersedia.</p>
      </div>
    );

  const ev = eventQuery.data;

  /**
   * Only a draft that nobody has touched may be deleted; the server refuses the
   * rest with a 422. Mirrored here so the button is absent rather than present
   * and rejected — the reactive 422 is still the net, but a destructive control
   * that cannot work should not be offered.
   *
   * `teams_count` is the one signal the payload carries. It is deliberately not
   * the whole test the server runs (that also weighs ticket orders and issued
   * certificates); a draft holding either is unusual enough that the 422 can
   * carry it.
   */
  const deletable = ev.status === "draft" && (ev.teams_count ?? 0) === 0;

  // The dialog names what actually goes with it rather than asking "yakin?" —
  // and, for a draft, that the plan comes back, which is the whole reason
  // deleting one is allowed at all.
  const confirmDelete = async () => {
    const ok = await confirm({
      title: "Hapus event ini?",
      description: `"${ev.name}" masih draf dan belum pernah dipublikasikan, jadi bisa dihapus permanen.`,
      tone: "danger",
      icon: Trash2,
      confirmLabel: "Hapus event",
      consequences: (
        <ul className="grid gap-1">
          <li>{ev.categories.length} kategori kompetisi</li>
          <li>Foto galeri &amp; sponsor</li>
          <li className="text-[var(--success)]">
            Paketnya kembali jadi kredit dan bisa dipakai untuk event lain
          </li>
        </ul>
      ),
    });
    if (ok) remove.mutate();
  };

  return (
    <div>
      <PageHeader
        title={ev.name}
        description={<span className="inline-flex items-center gap-2">Status <EventStatusBadge status={ev.status} /></span>}
        backHref="/organizer/events"
        backLabel="Daftar event"
        actions={
          <>
            {ev.status !== "draft" && org?.slug && (
              <Button asChild variant="outline">
                <a href={`/${org.slug}/${ev.slug}`} target="_blank" rel="noopener noreferrer">
                  <Eye className="h-4 w-4" />
                  Lihat halaman event
                </a>
              </Button>
            )}
            <Button asChild variant="outline">
              <Link href={`/organizer/events/${eventId}/schedule`}>
                <CalendarClock className="h-4 w-4" />
                Jadwal & Klasemen
              </Link>
            </Button>
            <Button asChild variant="outline">
              <Link href={`/organizer/events/${eventId}/media`}>
                <Images className="h-4 w-4" />
                Galeri & Sponsor
              </Link>
            </Button>
            {deletable && (
            <Button
              variant="destructive"
              onClick={() => void confirmDelete()}
              disabled={remove.isPending}
            >
              <Trash2 className="h-4 w-4" />
              Hapus
            </Button>
            )}
          </>
        }
      />

      <EventStatusPanel
        event={ev}
        pending={changeStatus.isPending}
        onChange={(status) => changeStatus.mutate(status)}
      />

      {orgId && <EventPlanPanel orgId={orgId} event={ev} />}

      <EventForm
        initial={ev}
        submitLabel="Simpan perubahan"
        pending={update.isPending}
        fieldErrors={fieldErrors}
        cancelHref="/organizer/events"
        onSubmit={(values) => {
          setFieldErrors({});
          update.mutate(values);
        }}
      />
    </div>
  );
}
