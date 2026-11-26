"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { AxiosError } from "axios";
import { CheckCircle2, LogIn, Mail, UserPlus, UserX } from "lucide-react";
import { toast } from "sonner";

import { acceptInvite, getInvitePreview } from "@/lib/api/event-operators";
import { parseApiError } from "@/lib/api/errors";
import {
  OPERATOR_CATEGORY_LABELS,
  grantedCategories,
} from "@/lib/event-operator-permissions";
import { useOptionalSession } from "@/components/auth/use-optional-session";
import { useLogout } from "@/lib/hooks/use-logout";
import { Logo } from "@/components/shared/logo";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuthStore } from "@/stores/auth-store";

/**
 * The landing page for an event-operator invite link.
 *
 * Unauthenticated preview (via `getInvitePreview`, no auth header) so it can
 * render before anyone logs in and choose between a login/register CTA and
 * the explicit "Terima" button — there is never an auto-attach. See
 * `docs/event-operator-design.md` section 7.
 */
export default function InvitePage() {
  const { token } = useParams<{ token: string }>();
  const router = useRouter();
  const { ready, isAuthenticated } = useOptionalSession();
  const user = useAuthStore((s) => s.user);
  const { logout, pending: loggingOut } = useLogout();

  const previewQuery = useQuery({
    queryKey: ["invite-preview", token],
    queryFn: () => getInvitePreview(token),
    retry: false,
  });

  const accept = useMutation({
    mutationFn: () => acceptInvite(token),
    onSuccess: ({ event_id }) => {
      toast.success("Undangan diterima");
      // `/organizer/events/{id}` has no page of its own — every route under
      // it is a sub-page (`edit`, `schedule`, …). `edit` is the event's own
      // detail/overview page, same destination the "Kelola" link on the
      // events list points at.
      router.push(`/organizer/events/${event_id}/edit`);
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menerima undangan.").message),
  });

  if (previewQuery.isLoading || !ready) {
    return (
      <Shell>
        <Skeleton className="h-40 w-full" />
      </Shell>
    );
  }

  if (previewQuery.isError) {
    const status = previewQuery.error instanceof AxiosError ? previewQuery.error.response?.status : null;
    return (
      <Shell>
        <div className="grid place-items-center gap-3 py-6 text-center">
          <UserX className="h-10 w-10 text-muted-foreground" />
          <h1 className="text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Undangan tidak ditemukan
          </h1>
          <p className="text-sm text-muted-foreground">
            {status === 404
              ? "Tautan ini tidak valid, atau undangannya sudah dihapus."
              : "Terjadi kesalahan saat memuat undangan ini."}
          </p>
        </div>
      </Shell>
    );
  }

  const preview = previewQuery.data!;
  const { invite, event, organization } = preview;

  if (!preview.usable) {
    const reason =
      invite.status === "revoked"
        ? "Undangan ini sudah dibatalkan oleh organizer."
        : invite.status === "accepted"
          ? "Undangan ini sudah diterima sebelumnya."
          : "Undangan ini sudah kedaluwarsa.";

    return (
      <Shell>
        <div className="grid place-items-center gap-3 py-6 text-center">
          <UserX className="h-10 w-10 text-muted-foreground" />
          <h1 className="text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Undangan tidak bisa dipakai
          </h1>
          <p className="text-sm text-muted-foreground">{reason}</p>
        </div>
      </Shell>
    );
  }

  const categories = grantedCategories(invite);
  const next = `/invites/${token}`;

  return (
    <Shell>
      <div className="grid gap-4 text-center">
        <div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[var(--tint)] text-[var(--brand-600)]">
          <Mail className="h-7 w-7" />
        </div>
        <div>
          <h1 className="text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Undangan Operator Event
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {organization.name} mengundangmu membantu mengelola
          </p>
          <p className="text-base font-semibold">{event.name}</p>
        </div>

        <div className="flex flex-wrap justify-center gap-1.5">
          {categories.map((c) => (
            <Badge key={c} variant="outline">
              {OPERATOR_CATEGORY_LABELS[c]}
            </Badge>
          ))}
        </div>

        <p className="text-xs text-muted-foreground">Dikirim ke {invite.email}</p>
      </div>

      <div className="mt-6 grid gap-3">
        {!isAuthenticated && (
          <>
            <Button asChild size="lg">
              <Link href={`/login?next=${encodeURIComponent(next)}`}>
                <LogIn className="h-4 w-4" />
                Masuk
              </Link>
            </Button>
            <Button asChild size="lg" variant="outline">
              <Link href={`/register?next=${encodeURIComponent(next)}&email=${encodeURIComponent(invite.email)}`}>
                <UserPlus className="h-4 w-4" />
                Daftar
              </Link>
            </Button>
          </>
        )}

        {isAuthenticated && user && user.email.toLowerCase() !== invite.email.toLowerCase() && (
          <div className="grid gap-3 rounded-lg border border-border bg-[var(--bg-soft)] p-4 text-sm">
            <p>
              Undangan ini untuk <strong>{invite.email}</strong>, kamu login sebagai{" "}
              <strong>{user.email}</strong>. Logout dan masuk dengan akun yang benar.
            </p>
            <Button variant="outline" onClick={() => void logout()} disabled={loggingOut}>
              {loggingOut ? "Keluar…" : "Logout"}
            </Button>
          </div>
        )}

        {isAuthenticated && user && user.email.toLowerCase() === invite.email.toLowerCase() && (
          <Button size="lg" onClick={() => accept.mutate()} disabled={accept.isPending}>
            <CheckCircle2 className="h-4 w-4" />
            {accept.isPending ? "Memproses…" : "Terima Undangan"}
          </Button>
        )}
      </div>
    </Shell>
  );
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen grid place-items-center bg-[var(--bg-alt)] px-4">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <Logo className="logo justify-center" />
        </div>
        <div className="rounded-xl border border-border bg-card p-8 shadow-sm">{children}</div>
      </div>
    </div>
  );
}
