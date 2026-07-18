"use client";

import { useQuery } from "@tanstack/react-query";
import { formatDistanceToNow } from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import { Users, Monitor } from "lucide-react";

import { getActiveSessions } from "@/lib/api/admin";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import type { ActiveSession } from "@/types/api";

const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "—";

const relative = (iso: string | null) =>
  iso ? formatDistanceToNow(new Date(iso), { addSuffix: true, locale: idLocale }) : "belum pernah";

const roleLabel = (role: string) =>
  role === "super_admin" ? "Super Admin" : "Pengguna";

/** Short, readable device label from a raw user-agent. */
function deviceLabel(ua: string | null): string {
  if (!ua) return "Perangkat tak dikenal";
  const os = /Windows/.test(ua)
    ? "Windows"
    : /iPhone|iPad|iOS/.test(ua)
      ? "iOS"
      : /Android/.test(ua)
        ? "Android"
        : /Mac OS X|Macintosh/.test(ua)
          ? "macOS"
          : /Linux/.test(ua)
            ? "Linux"
            : "";
  const browser = /Edg\//.test(ua)
    ? "Edge"
    : /Chrome\//.test(ua)
      ? "Chrome"
      : /Firefox\//.test(ua)
        ? "Firefox"
        : /Safari\//.test(ua)
          ? "Safari"
          : "";
  const label = [browser, os].filter(Boolean).join(" · ");
  return label || ua;
}

export default function AdminActiveSessionsPage() {
  const query = useQuery({
    queryKey: ["admin-active-sessions"],
    queryFn: getActiveSessions,
    // Keep the Online status fresh without a manual refresh.
    refetchInterval: 20000,
  });

  const users = query.data ?? [];
  const onlineCount = users.filter((u) => u.online).length;

  return (
    <>
      <PageHeader
        title="Sesi Aktif"
        description="Pengguna yang mengakses aplikasi dalam 30 menit terakhir. Badge Online berarti aktif dalam 5 menit terakhir."
      />

      {query.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[120px] rounded-xl" />
          ))}
        </div>
      ) : users.length === 0 ? (
        <EmptyState
          icon={Users}
          title="Belum ada sesi aktif"
          description="Pengguna yang sedang mengakses aplikasi akan muncul di sini."
        />
      ) : (
        <>
          <p className="mb-4 text-sm text-muted-foreground">
            <span className="font-semibold text-foreground">{onlineCount}</span> online ·{" "}
            <span className="font-semibold text-foreground">{users.length}</span> aktif 30 menit
            terakhir
          </p>

          <div className="grid gap-3">
            {users.map((u) => (
              <SessionCard key={u.id} user={u} />
            ))}
          </div>
        </>
      )}
    </>
  );
}

const MAX_DEVICES = 6;

function SessionCard({ user }: { user: ActiveSession }) {
  const initial = (user.full_name || user.email || "?").charAt(0).toUpperCase();
  const shown = user.sessions.slice(0, MAX_DEVICES);
  const extra = user.sessions.length - shown.length;

  return (
    <Card className="flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between">
      <div className="flex min-w-0 items-start gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[var(--tint)] text-sm font-bold text-[var(--brand-600)]">
          {initial}
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-semibold">{user.full_name || "Tanpa nama"}</span>
            <OnlineBadge online={user.online} lastSeen={user.last_seen_at} />
            {user.role === "super_admin" && (
              <span className="rounded-full bg-[var(--surface-2)] px-2 py-0.5 text-xs font-medium text-muted-foreground">
                {roleLabel(user.role)}
              </span>
            )}
          </div>
          <p className="truncate text-sm text-muted-foreground">{user.email}</p>
        </div>
      </div>

      <div className="min-w-0 md:max-w-md md:text-right">
        <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          {user.session_count} perangkat login
        </p>
        <ul className="grid gap-1.5">
          {shown.map((s) => (
            <li
              key={s.id}
              className="flex items-center gap-2 text-sm text-muted-foreground md:justify-end"
            >
              <Monitor className="h-3.5 w-3.5 shrink-0" />
              <span className="font-medium text-foreground">{deviceLabel(s.device_info)}</span>
              {s.ip_address && <span className="font-mono text-xs">{s.ip_address}</span>}
              <span className="text-xs">· masuk {dateTime(s.login_at)}</span>
            </li>
          ))}
          {extra > 0 && (
            <li className="text-xs text-muted-foreground md:text-right">
              +{extra} perangkat lain
            </li>
          )}
        </ul>
      </div>
    </Card>
  );
}

function OnlineBadge({ online, lastSeen }: { online: boolean; lastSeen: string | null }) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium",
        online
          ? "bg-[color-mix(in_srgb,var(--success)_14%,transparent)] text-[var(--success)]"
          : "bg-[var(--surface-2)] text-muted-foreground"
      )}
    >
      <span
        className={cn(
          "h-1.5 w-1.5 rounded-full",
          online ? "bg-[var(--success)]" : "bg-muted-foreground/60"
        )}
      />
      {online ? "Online" : `Aktif ${relative(lastSeen)}`}
    </span>
  );
}
