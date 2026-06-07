"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, Rocket, Trash2 } from "lucide-react";

import {
  getEvent,
  updateEvent,
  publishEvent,
  deleteEvent,
  type EventInput,
} from "@/lib/api/events";
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
  const { orgId } = useActiveOrg();
  const [msg, setMsg] = useState<string | null>(null);

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const refresh = () => qc.invalidateQueries({ queryKey: ["event", orgId, eventId] });

  const update = useMutation({
    mutationFn: (values: EventInput) => updateEvent(orgId!, eventId, values),
    onSuccess: () => {
      setMsg("Perubahan tersimpan");
      refresh();
    },
  });

  const publish = useMutation({
    mutationFn: () => publishEvent(orgId!, eventId),
    onSuccess: () => {
      setMsg("Event berhasil dipublikasikan");
      refresh();
    },
  });

  const remove = useMutation({
    mutationFn: () => deleteEvent(orgId!, eventId),
    onSuccess: () => router.push("/dashboard/events"),
  });

  if (eventQuery.isLoading) {
    return (
      <div>
        <PageHeader title="Memuat…" backHref="/dashboard/events" backLabel="Daftar event" />
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
        <PageHeader title="Event tidak ditemukan" backHref="/dashboard/events" backLabel="Daftar event" />
        <p className="text-sm text-[var(--danger)]">Event yang kamu cari tidak tersedia.</p>
      </div>
    );

  const ev = eventQuery.data;

  return (
    <div>
      <PageHeader
        title={ev.name}
        description={<span className="inline-flex items-center gap-2">Status <EventStatusBadge status={ev.status} /></span>}
        backHref="/dashboard/events"
        backLabel="Daftar event"
        actions={
          <>
            {ev.status === "draft" && (
              <Button onClick={() => publish.mutate()} disabled={publish.isPending}>
                <Rocket className="h-4 w-4" />
                {publish.isPending ? "Mempublikasikan…" : "Publikasikan"}
              </Button>
            )}
            <Button variant="destructive" onClick={() => remove.mutate()} disabled={remove.isPending}>
              <Trash2 className="h-4 w-4" />
              Hapus
            </Button>
          </>
        }
      />

      {msg && (
        <div className="mb-5 flex items-center gap-2 rounded-md border border-[color-mix(in_srgb,var(--success)_40%,transparent)] bg-[color-mix(in_srgb,var(--success)_10%,transparent)] px-4 py-3 text-sm text-[var(--success)]">
          <CheckCircle2 className="h-4 w-4 shrink-0" />
          {msg}
          {ev.status !== "draft" && (
            <Link href="/dashboard/events" className="ml-1 font-semibold underline">
              lihat daftar
            </Link>
          )}
        </div>
      )}

      <EventForm
        initial={ev}
        submitLabel="Simpan perubahan"
        pending={update.isPending}
        onSubmit={(values) => {
          setMsg(null);
          update.mutate(values);
        }}
      />
    </div>
  );
}
