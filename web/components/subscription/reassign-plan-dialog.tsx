"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Repeat } from "lucide-react";

import {
  getAdminOrganizationEvents,
  reassignEventPlan,
  type AdminOrgEvent,
} from "@/lib/api/admin-wallet";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import type { EventPlanOrder } from "@/types/api";

/**
 * Apply an unspent credit to an event that already has a different plan.
 *
 * Not an upgrade, and the difference is the whole reason both exist: here both
 * credits were paid in full, so the one being displaced returns to the
 * organizer's pool. An upgrade only charges the difference, which is why the
 * order it replaces retires instead.
 *
 * Super admin only, and it stays that way: it hands out an entitlement on
 * nothing but a judgement call, and the party who benefits is the one asking.
 */
export function ReassignPlanDialog({
  credit,
  open,
  onOpenChange,
}: {
  credit: EventPlanOrder;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const qc = useQueryClient();
  const [pendingId, setPendingId] = useState<string | null>(null);
  const orgId = credit.organization?.id ?? credit.organization_id;

  const eventsQuery = useQuery({
    queryKey: ["admin-org-events", orgId],
    queryFn: () => getAdminOrganizationEvents(orgId),
    enabled: open && !!orgId,
  });

  const reassign = useMutation({
    mutationFn: (eventId: string) => reassignEventPlan(eventId, credit.id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-idle-plan-credits"] });
      qc.invalidateQueries({ queryKey: ["admin-org-events", orgId] });
      toast.success("Paket event dipindahkan", {
        description: "Paket lama dilepas dan kembali jadi kredit organizer.",
      });
      onOpenChange(false);
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memindahkan paket.").message),
    onSettled: () => setPendingId(null),
  });

  const events = eventsQuery.data ?? [];

  return (
    <Dialog open={open} onOpenChange={(next) => !reassign.isPending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader
          icon={Repeat}
          title="Pakai kredit ini untuk event lain"
          description={`${credit.plan?.name ?? "Paket"} milik ${credit.organization?.name ?? "organisasi ini"}. Paket event yang dipilih akan dilepas dan kembali jadi kredit.`}
        />

        <DialogBody>
          {eventsQuery.isPending && <Skeleton className="h-28 w-full rounded-lg" />}

          {eventsQuery.data && events.length === 0 && (
            <p className="text-sm text-muted-foreground">
              Organisasi ini belum punya event. Kreditnya akan terpakai sendiri saat mereka
              membuat satu.
            </p>
          )}

          <div className="grid gap-2">
            {events.map((event: AdminOrgEvent) => {
              // Applying it to the event it already funds would be a no-op that
              // still moves two rows; refusing is clearer than doing nothing.
              const samePlan = event.plan?.id === credit.plan_id;

              return (
                <div
                  key={event.id}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
                >
                  <div className="min-w-0">
                    <p className="truncate font-semibold">{event.name}</p>
                    <p className="text-sm text-muted-foreground">
                      Sekarang: {event.plan?.name ?? "tanpa paket"}
                    </p>
                  </div>
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={reassign.isPending || samePlan}
                    onClick={() => {
                      setPendingId(event.id);
                      reassign.mutate(event.id);
                    }}
                  >
                    {pendingId === event.id
                      ? "Memindahkan…"
                      : samePlan
                        ? "Sudah paket ini"
                        : "Pindahkan ke sini"}
                  </Button>
                </div>
              );
            })}
          </div>
        </DialogBody>

        <DialogFooter>
          <Button
            variant="secondary"
            onClick={() => onOpenChange(false)}
            disabled={reassign.isPending}
          >
            Tutup
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
