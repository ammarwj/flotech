"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

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
import { EVENT_STATUS_LABELS } from "@/lib/labels";

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
      setMsg("Tersimpan ✓");
      refresh();
    },
  });

  const publish = useMutation({
    mutationFn: () => publishEvent(orgId!, eventId),
    onSuccess: () => {
      setMsg("Event dipublikasikan ✓");
      refresh();
    },
  });

  const remove = useMutation({
    mutationFn: () => deleteEvent(orgId!, eventId),
    onSuccess: () => router.push("/dashboard/events"),
  });

  if (eventQuery.isLoading) return <p className="text-muted-foreground">Memuat…</p>;
  if (eventQuery.isError || !eventQuery.data)
    return <p className="text-destructive">Event tidak ditemukan.</p>;

  const ev = eventQuery.data;

  return (
    <div>
      <Link href="/dashboard/events" className="text-sm text-muted-foreground hover:text-foreground">
        ← Kembali ke daftar event
      </Link>

      <div className="mt-2 mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {ev.name}
          </h1>
          <span className="text-sm text-muted-foreground">
            Status: {EVENT_STATUS_LABELS[ev.status]}
          </span>
        </div>
        <div className="flex gap-2">
          {ev.status === "draft" && (
            <Button onClick={() => publish.mutate()} disabled={publish.isPending}>
              Publikasikan
            </Button>
          )}
          <Button variant="destructive" onClick={() => remove.mutate()} disabled={remove.isPending}>
            Hapus
          </Button>
        </div>
      </div>

      {msg && (
        <p className="mb-4 rounded-md bg-accent p-3 text-sm text-accent-foreground">
          {msg}
          {ev.status !== "draft" && (
            <>
              {" · "}
              <Link href="/dashboard/events" className="underline">
                lihat daftar
              </Link>
            </>
          )}
        </p>
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
