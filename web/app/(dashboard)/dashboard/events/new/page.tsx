"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMutation } from "@tanstack/react-query";
import { AxiosError } from "axios";

import { createEvent, type EventInput } from "@/lib/api/events";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { EventForm } from "@/components/event/event-form";

export default function NewEventPage() {
  const router = useRouter();
  const { orgId } = useActiveOrg();
  const [error, setError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: (values: EventInput) => createEvent(orgId!, values),
    onSuccess: (ev) => router.push(`/dashboard/events/${ev.id}/edit`),
    onError: (err) =>
      setError(err instanceof AxiosError ? (err.response?.data?.message ?? "Gagal") : "Gagal"),
  });

  return (
    <div>
      <Link href="/dashboard/events" className="text-sm text-muted-foreground hover:text-foreground">
        ← Kembali ke daftar event
      </Link>
      <h1 className="mt-2 mb-6 text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        Buat Event
      </h1>

      {error && <p className="mb-4 text-sm text-destructive">{error}</p>}

      <EventForm
        submitLabel="Buat Event"
        pending={mutation.isPending || !orgId}
        onSubmit={(values) => {
          setError(null);
          mutation.mutate(values);
        }}
      />
    </div>
  );
}
