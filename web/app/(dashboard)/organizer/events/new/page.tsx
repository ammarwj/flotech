"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { toast } from "sonner";

import { createEvent, getEvents, type EventInput } from "@/lib/api/events";
import { parseApiError, isPlanLimitError, type FieldErrors } from "@/lib/api/errors";
import { getActiveEventLimit, countActiveEvents } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { EventForm } from "@/components/event/event-form";
import { EventLimitNotice } from "@/components/event/event-limit-notice";
import { PageHeader } from "@/components/shared/page-header";
import { Skeleton } from "@/components/ui/skeleton";

export default function NewEventPage() {
  const router = useRouter();
  const { org, orgId } = useActiveOrg();
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [limitHit, setLimitHit] = useState(false);

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });

  const limit = getActiveEventLimit(org);
  // Proactive: we know the cap and have the current count → block before the form.
  const limitReached =
    limitHit || (limit !== null && countActiveEvents(eventsQuery.data) >= limit);

  const mutation = useMutation({
    mutationFn: (values: EventInput) => createEvent(orgId!, values),
    onSuccess: (ev) => {
      toast.success("Event berhasil dibuat", {
        description: "Lanjutkan mengatur detail, lalu publikasikan.",
      });
      router.push(`/organizer/events/${ev.id}/edit`);
    },
    onError: (err) => {
      // Reactive safety net: server enforced the plan cap (race / stale count).
      if (isPlanLimitError(err)) {
        setLimitHit(true);
        return;
      }
      const parsed = parseApiError(err, "Gagal membuat event.");
      setFieldErrors(parsed.fieldErrors);
      if (Object.keys(parsed.fieldErrors).length === 0) {
        toast.error(parsed.message);
      }
    },
  });

  // Wait for the count before deciding whether to show the form or the notice.
  const deciding = limit !== null && eventsQuery.isLoading;

  return (
    <div>
      <PageHeader
        title="Buat Event"
        description="Atur detail turnamen, lalu publikasikan untuk membuka pendaftaran."
        backHref="/organizer/events"
        backLabel="Daftar event"
      />

      {deciding ? (
        <Skeleton className="h-48 w-full max-w-2xl rounded-xl" />
      ) : limitReached ? (
        <EventLimitNotice planName={org?.plan?.name} limit={limit} />
      ) : (
        <EventForm
          submitLabel="Buat Event"
          pending={mutation.isPending || !orgId}
          fieldErrors={fieldErrors}
          cancelHref="/organizer/events"
          onSubmit={(values) => {
            setFieldErrors({});
            mutation.mutate(values);
          }}
        />
      )}
    </div>
  );
}
