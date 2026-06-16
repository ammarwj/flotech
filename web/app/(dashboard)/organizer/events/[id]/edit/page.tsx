"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Rocket, Trash2, Eye, CalendarClock } from "lucide-react";
import { toast } from "sonner";

import {
  getEvent,
  updateEvent,
  publishEvent,
  deleteEvent,
  type EventInput,
} from "@/lib/api/events";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { EventForm } from "@/components/event/event-form";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EventStatusBadge } from "@/components/shared/status-badge";

export default function EditEventPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { org, orgId } = useActiveOrg();
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

  const publish = useMutation({
    mutationFn: () => publishEvent(orgId!, eventId),
    onSuccess: () => {
      toast.success("Event berhasil dipublikasikan", {
        description: "Pendaftaran tim kini terbuka.",
        action: {
          label: "Lihat daftar",
          onClick: () => router.push("/organizer/events"),
        },
      });
      refresh();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mempublikasikan event.").message),
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
            {ev.status === "draft" && (
              <Button onClick={() => publish.mutate()} disabled={publish.isPending}>
                <Rocket className="h-4 w-4" />
                {publish.isPending ? "Mempublikasikan…" : "Publikasikan"}
              </Button>
            )}
            <Button asChild variant="outline">
              <Link href={`/organizer/events/${eventId}/schedule`}>
                <CalendarClock className="h-4 w-4" />
                Jadwal & Klasemen
              </Link>
            </Button>
            <Button variant="destructive" onClick={() => remove.mutate()} disabled={remove.isPending}>
              <Trash2 className="h-4 w-4" />
              Hapus
            </Button>
          </>
        }
      />

      {ev.status === "draft" && (
        <div className="mb-5 flex items-start gap-2 rounded-md border border-[color-mix(in_srgb,var(--warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] px-4 py-3 text-sm text-[var(--warning)]">
          <Eye className="mt-0.5 h-4 w-4 shrink-0" />
          <span>
            Event ini masih <b>draf</b> dan belum bisa dilihat publik. Klik{" "}
            <b>Publikasikan</b> agar halaman event &amp; pendaftaran tim aktif.
          </span>
        </div>
      )}

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
