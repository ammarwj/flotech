"use client";

import { useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Mail, RotateCw, ShieldCheck, Trash2, UserPlus, Users, X } from "lucide-react";

import { getEvent } from "@/lib/api/events";
import {
  getEventOperators,
  getOperatorInvites,
  inviteEventOperator,
  removeOperator,
  resendOperatorInvite,
  revokeOperatorInvite,
  updateOperatorPermissions,
} from "@/lib/api/event-operators";
import { parseApiError } from "@/lib/api/errors";
import {
  OPERATOR_CATEGORY_LABELS,
  grantedCategories,
} from "@/lib/event-operator-permissions";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Badge, type BadgeProps } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  AlertDialog,
  AlertDialogBody,
  AlertDialogContent,
  AlertDialogFooter,
  AlertDialogHeader,
} from "@/components/ui/alert-dialog";
import { dialogConsequences } from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { SectionHeader } from "@/components/event/section-header";
import { EmptyState } from "@/components/shared/empty-state";
import { InviteOperatorDialog } from "@/components/event/invite-operator-dialog";
import { EditOperatorPermissionsDialog } from "@/components/event/edit-operator-permissions-dialog";
import type { EventOperator, EventOperatorInvite, EventOperatorInviteStatus } from "@/types/api";

const INVITE_STATUS_VARIANT: Record<EventOperatorInviteStatus, NonNullable<BadgeProps["variant"]>> = {
  pending: "warning",
  accepted: "success",
  revoked: "neutral",
  expired: "danger",
};

const INVITE_STATUS_LABEL: Record<EventOperatorInviteStatus, string> = {
  pending: "Menunggu",
  accepted: "Diterima",
  revoked: "Dibatalkan",
  expired: "Kedaluwarsa",
};

function dateTime(iso: string): string {
  return new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });
}

function PermissionBadges({ permissions }: { permissions: EventOperator | EventOperatorInvite }) {
  const categories = grantedCategories(permissions);
  return (
    <div className="flex flex-wrap gap-1.5">
      {categories.map((c) => (
        <Badge key={c} variant="outline">
          {OPERATOR_CATEGORY_LABELS[c]}
        </Badge>
      ))}
    </div>
  );
}

/**
 * Who an owner/admin has handed a slice of this event's organizer surface to.
 *
 * Event-scoped, not org-scoped: an operator is given access to one event, and
 * an organizer running several events invites separately for each. Money
 * (wallet, bank accounts, plan-orders), exports, and a handful of other
 * surfaces are never grantable here — see the design doc's section 0 — so
 * this page only ever edits the four `can_*` categories.
 */
export default function EventOperatorsPage() {
  const { orgId } = useActiveOrg();
  const { id: eventId } = useParams<{ id: string }>();
  const qc = useQueryClient();

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const operatorsQuery = useQuery({
    queryKey: ["event-operators", orgId, eventId],
    queryFn: () => getEventOperators(orgId!, eventId),
    enabled: !!orgId,
  });

  const invitesQuery = useQuery({
    queryKey: ["event-operator-invites", orgId, eventId],
    queryFn: () => getOperatorInvites(orgId!, eventId),
    enabled: !!orgId,
  });

  const [inviteOpen, setInviteOpen] = useState(false);
  const [editing, setEditing] = useState<EventOperator | null>(null);
  const [removing, setRemoving] = useState<EventOperator | null>(null);

  const invalidateOperators = () =>
    qc.invalidateQueries({ queryKey: ["event-operators", orgId, eventId] });
  const invalidateInvites = () =>
    qc.invalidateQueries({ queryKey: ["event-operator-invites", orgId, eventId] });

  const invite = useMutation({
    mutationFn: (payload: Parameters<typeof inviteEventOperator>[2]) =>
      inviteEventOperator(orgId!, eventId, payload),
    onSuccess: () => {
      toast.success("Undangan dikirim");
      setInviteOpen(false);
      invalidateInvites();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengundang operator.").message),
  });

  const resend = useMutation({
    mutationFn: (inviteId: string) => resendOperatorInvite(orgId!, inviteId),
    onSuccess: () => {
      toast.success("Undangan dikirim ulang");
      invalidateInvites();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengirim ulang undangan.").message),
  });

  const revoke = useMutation({
    mutationFn: (inviteId: string) => revokeOperatorInvite(orgId!, inviteId),
    onSuccess: () => {
      toast.success("Undangan dibatalkan");
      invalidateInvites();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membatalkan undangan.").message),
  });

  const updatePermissions = useMutation({
    mutationFn: (payload: Parameters<typeof updateOperatorPermissions>[2]) =>
      updateOperatorPermissions(orgId!, editing!.id, payload),
    onSuccess: () => {
      toast.success("Akses operator diperbarui");
      setEditing(null);
      invalidateOperators();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memperbarui akses operator.").message),
  });

  const remove = useMutation({
    mutationFn: (operatorId: string) => removeOperator(orgId!, operatorId),
    onSuccess: () => {
      toast.success("Operator dihapus");
      setRemoving(null);
      invalidateOperators();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus operator.").message),
  });

  const pendingInvites = invitesQuery.data?.filter((i) => i.status === "pending") ?? [];
  const historyInvites = invitesQuery.data?.filter((i) => i.status !== "pending") ?? [];

  return (
    <div>
      <PageHeader
        title="Operator Event"
        description={
          eventQuery.data?.name ??
          "Beri orang lain akses ke sebagian dashboard organizer untuk event ini."
        }
        backHref={`/organizer/events/${eventId}/edit`}
        backLabel="Kelola event"
        actions={
          <Button onClick={() => setInviteOpen(true)}>
            <UserPlus className="h-4 w-4" />
            Undang Operator
          </Button>
        }
      />

      <p className="mb-6 max-w-2xl text-sm text-muted-foreground">
        Operator cuma dapat akses yang kamu centang — ticketing, jadwal, pendaftaran peserta,
        dan/atau dokumen. Dompet, rekening bank, pembelian paket, dan export tetap eksklusif
        milikmu sebagai admin organisasi.
      </p>

      <Card>
        <SectionHeader
          icon={Users}
          title="Operator Aktif"
          description="Akun yang sudah menerima undangan dan punya akses ke event ini."
        />
        <CardContent className="grid gap-3">
          {operatorsQuery.isLoading && <Skeleton className="h-20 w-full rounded-lg" />}
          {operatorsQuery.data?.length === 0 && (
            <EmptyState
              icon={Users}
              title="Belum ada operator"
              description="Undang seseorang untuk membantu mengelola event ini."
            />
          )}
          {operatorsQuery.data?.map((op) => (
            <div
              key={op.id}
              className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border bg-card p-4"
            >
              <div className="min-w-[13rem] flex-1">
                <p className="font-semibold">{op.user.full_name}</p>
                <p className="text-sm text-muted-foreground">{op.user.email}</p>
                <div className="mt-2">
                  <PermissionBadges permissions={op} />
                </div>
                {op.invited_by && (
                  <p className="mt-2 text-xs text-muted-foreground">Diundang oleh {op.invited_by}</p>
                )}
              </div>
              <div className="flex shrink-0 gap-2">
                <Button size="sm" variant="outline" onClick={() => setEditing(op)}>
                  <ShieldCheck className="h-4 w-4" />
                  Ubah Akses
                </Button>
                <Button size="sm" variant="destructive" onClick={() => setRemoving(op)}>
                  <Trash2 className="h-4 w-4" />
                  Hapus
                </Button>
              </div>
            </div>
          ))}
        </CardContent>
      </Card>

      <Card className="mt-6">
        <SectionHeader
          icon={Mail}
          title="Undangan"
          description="Status pengiriman — yang menunggu bisa dikirim ulang atau dibatalkan."
        />
        <CardContent className="grid gap-3">
          {invitesQuery.isLoading && <Skeleton className="h-20 w-full rounded-lg" />}
          {invitesQuery.data?.length === 0 && (
            <p className="text-sm text-muted-foreground">Belum ada undangan untuk event ini.</p>
          )}
          {[...pendingInvites, ...historyInvites].map((inv) => (
            <div
              key={inv.id}
              className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border bg-card p-4"
            >
              <div className="min-w-[13rem] flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{inv.email}</span>
                  <Badge variant={INVITE_STATUS_VARIANT[inv.status]}>
                    {inv.status === "pending" && inv.is_expired
                      ? "Kedaluwarsa"
                      : INVITE_STATUS_LABEL[inv.status]}
                  </Badge>
                </div>
                <div className="mt-2">
                  <PermissionBadges permissions={inv} />
                </div>
                <p className="mt-2 text-xs text-muted-foreground">
                  Dikirim {dateTime(inv.created_at)}
                  {inv.accepted_at && ` · Diterima ${dateTime(inv.accepted_at)}`}
                </p>
              </div>
              {inv.status === "pending" && (
                <div className="flex shrink-0 gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => resend.mutate(inv.id)}
                    disabled={resend.isPending}
                  >
                    <RotateCw className="h-4 w-4" />
                    Kirim Ulang
                  </Button>
                  <Button
                    size="sm"
                    variant="destructive"
                    onClick={() => revoke.mutate(inv.id)}
                    disabled={revoke.isPending}
                  >
                    <X className="h-4 w-4" />
                    Batalkan
                  </Button>
                </div>
              )}
            </div>
          ))}
        </CardContent>
      </Card>

      <InviteOperatorDialog
        open={inviteOpen}
        pending={invite.isPending}
        fieldErrors={parseApiError(invite.error).fieldErrors}
        onClose={() => setInviteOpen(false)}
        onSubmit={(payload) => invite.mutate(payload)}
      />

      <EditOperatorPermissionsDialog
        operator={editing}
        pending={updatePermissions.isPending}
        fieldErrors={parseApiError(updatePermissions.error).fieldErrors}
        onClose={() => setEditing(null)}
        onSubmit={(payload) => updatePermissions.mutate(payload)}
      />

      <AlertDialog open={!!removing} onOpenChange={(o) => !o && setRemoving(null)}>
        <AlertDialogContent>
          <AlertDialogHeader
            icon={Trash2}
            tone="danger"
            title="Hapus operator?"
            description={removing?.user.full_name}
          />
          <AlertDialogBody>
            <ul className={dialogConsequences.danger}>
              <li>Semua akses operator ini ke event akan dicabut.</li>
              <li>Ia tetap bisa diundang ulang kapan saja.</li>
            </ul>
          </AlertDialogBody>
          <AlertDialogFooter>
            <Button variant="outline" onClick={() => setRemoving(null)}>
              Batal
            </Button>
            <Button
              variant="destructive"
              disabled={remove.isPending}
              onClick={() => removing && remove.mutate(removing.id)}
            >
              {remove.isPending ? "Menghapus…" : "Hapus operator"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
