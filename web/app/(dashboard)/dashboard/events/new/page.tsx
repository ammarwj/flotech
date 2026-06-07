"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation } from "@tanstack/react-query";
import { AxiosError } from "axios";
import { AlertCircle } from "lucide-react";

import { createEvent, type EventInput } from "@/lib/api/events";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { EventForm } from "@/components/event/event-form";
import { PageHeader } from "@/components/shared/page-header";

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
      <PageHeader
        title="Buat Event"
        description="Atur detail turnamen, lalu publikasikan untuk membuka pendaftaran."
        backHref="/dashboard/events"
        backLabel="Daftar event"
      />

      {error && (
        <div className="mb-5 flex items-center gap-2 rounded-md border border-[color-mix(in_srgb,var(--danger)_40%,transparent)] bg-[color-mix(in_srgb,var(--danger)_10%,transparent)] px-4 py-3 text-sm text-[var(--danger)]">
          <AlertCircle className="h-4 w-4 shrink-0" />
          {error}
        </div>
      )}

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
