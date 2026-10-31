"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Building2 } from "lucide-react";

import { createEvent, type EventInput } from "@/lib/api/events";
import { getPlanOrders } from "@/lib/api/organizations";
import { parseApiError, isPlanLimitError, type FieldErrors } from "@/lib/api/errors";
import { unconsumedOrders } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { EventForm } from "@/components/event/event-form";
import { PlanPurchaseNotice } from "@/components/event/plan-purchase-notice";
import { PlanOrderPicker } from "@/components/event/plan-order-picker";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Creating an event spends a plan the organizer has already paid for.
 *
 * Three states, decided by how many unspent credits they hold: none → buy one
 * here; exactly one → straight to the form; several → let them say which, since
 * spending a Starter on a national championship has no undo.
 */
export default function NewEventPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const params = useSearchParams();
  const { orgId } = useActiveOrg();
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const ordersQuery = useQuery({
    queryKey: ["plan-orders", orgId],
    queryFn: () => getPlanOrders(orgId!),
    enabled: !!orgId,
  });

  const credits = unconsumedOrders(ordersQuery.data);

  // ?plan_order_id= comes from the "Buat event" button on a credit card in
  // /organizer/billing. Falls back to the oldest, which is what the backend
  // would have picked anyway.
  const requested = params.get("plan_order_id");
  const selected =
    credits.find((c) => c.id === requested) ?? credits[credits.length - 1] ?? null;
  const [chosenId, setChosenId] = useState<string | null>(null);
  const active = credits.find((c) => c.id === chosenId) ?? selected;

  const mutation = useMutation({
    mutationFn: (values: EventInput) =>
      createEvent(orgId!, { ...values, plan_order_id: active?.id }),
    onSuccess: (ev) => {
      qc.invalidateQueries({ queryKey: ["plan-orders"] });
      qc.invalidateQueries({ queryKey: ["organizations"] });
      toast.success("Event berhasil dibuat", {
        description: "Lanjutkan mengatur detail, lalu publikasikan.",
      });
      router.push(`/organizer/events/${ev.id}/edit`);
    },
    onError: (err) => {
      // Reactive safety net: the credit was spent elsewhere between our fetch
      // and this request, or a plan cap refused the categories.
      if (isPlanLimitError(err)) {
        qc.invalidateQueries({ queryKey: ["plan-orders"] });
        toast.error(parseApiError(err, "Paketmu tidak mengizinkan itu.").message);
        return;
      }
      const parsed = parseApiError(err, "Gagal membuat event.");
      setFieldErrors(parsed.fieldErrors);
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
        backHref="/organizer/events"
        backLabel="Daftar event"
      />

      {/* No org, no credits to spend and nobody to bill — and `orgId` goes
          straight into the checkout URL. /organizer/layout exempts super admins
          from onboarding, so they are who lands here with none. */}
      {!orgId ? (
        <EmptyState
          icon={Building2}
          title="Belum punya organisasi"
          description="Event dibuat atas nama organisasi. Buat organisasi dulu untuk mulai."
          action={
            <Button asChild>
              <Link href="/onboarding">Buat organisasi</Link>
            </Button>
          }
        />
      ) : ordersQuery.isLoading ? (
        <Skeleton className="h-48 w-full max-w-2xl rounded-xl" />
      ) : credits.length === 0 ? (
        <PlanPurchaseNotice orgId={orgId} />
      ) : (
        <>
          {credits.length > 1 && (
            <PlanOrderPicker credits={credits} activeId={active?.id} onChange={setChosenId} />
          )}
          <EventForm
            submitLabel="Buat Event"
            plan={active?.plan}
            pending={mutation.isPending || !orgId}
            fieldErrors={fieldErrors}
            cancelHref="/organizer/events"
            onSubmit={(values) => {
              setFieldErrors({});
              mutation.mutate(values);
            }}
          />
        </>
      )}
    </div>
  );
}
