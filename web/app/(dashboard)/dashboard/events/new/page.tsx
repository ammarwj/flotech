"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation } from "@tanstack/react-query";
import { toast } from "sonner";

import { createEvent, type EventInput } from "@/lib/api/events";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { EventForm } from "@/components/event/event-form";
import { PageHeader } from "@/components/shared/page-header";

export default function NewEventPage() {
  const router = useRouter();
  const { orgId } = useActiveOrg();
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const mutation = useMutation({
    mutationFn: (values: EventInput) => createEvent(orgId!, values),
    onSuccess: (ev) => {
      toast.success("Event berhasil dibuat", {
        description: "Lanjutkan mengatur detail, lalu publikasikan.",
      });
      router.push(`/dashboard/events/${ev.id}/edit`);
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal membuat event.");
      setFieldErrors(parsed.fieldErrors);
      // Per-field errors render inline; only surface a toast for other failures.
      if (Object.keys(parsed.fieldErrors).length === 0) {
        toast.error(parsed.message);
      }
    },
  });

  return (
    <div>
      <PageHeader
        title="Buat Event"
        description="Atur detail turnamen, lalu publikasikan untuk membuka pendaftaran."
        backHref="/dashboard/events"
        backLabel="Daftar event"
      />

      <EventForm
        submitLabel="Buat Event"
        pending={mutation.isPending || !orgId}
        fieldErrors={fieldErrors}
        onSubmit={(values) => {
          setFieldErrors({});
          mutation.mutate(values);
        }}
      />
    </div>
  );
}
