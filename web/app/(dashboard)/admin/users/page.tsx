"use client";

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { formatDistanceToNow } from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import { Users, ShieldCheck, BadgeCheck, Trash2, Building2 } from "lucide-react";

import {
  getAdminUsers,
  updateAdminUser,
  deleteAdminUser,
  type AdminUserUpdate,
} from "@/lib/api/admin";
import { parseApiError } from "@/lib/api/errors";
import { useAuthStore } from "@/stores/auth-store";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import type { AdminUser } from "@/types/api";

const relative = (iso: string | null) =>
  iso ? formatDistanceToNow(new Date(iso), { addSuffix: true, locale: idLocale }) : "belum pernah";

const roleLabel = (role: string) => (role === "super_admin" ? "Super Admin" : "Pengguna");
const orgRoleLabel = (role: string) => (role === "admin" ? "Admin" : role === "operator" ? "Operator" : role);

export default function AdminUsersPage() {
  const qc = useQueryClient();
  const currentUserId = useAuthStore((s) => s.user?.id);

  const [search, setSearch] = useState("");
  const [q, setQ] = useState("");
  const [role, setRole] = useState("");
  const [page, setPage] = useState(1);

  // Debounce the search box so we don't fire a request per keystroke.
  useEffect(() => {
    const t = setTimeout(() => {
      setQ(search);
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const query = useQuery({
    queryKey: ["admin-users", { q, role, page }],
    queryFn: () => getAdminUsers({ q: q || undefined, role: role || undefined, page }),
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ["admin-users"] });

  const update = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: AdminUserUpdate }) =>
      updateAdminUser(id, payload),
    onSuccess: () => {
      toast.success("User diperbarui.");
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memperbarui user.").message),
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteAdminUser(id),
    onSuccess: () => {
      toast.success("User dihapus.");
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus user.").message),
  });

  const users = query.data?.items ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <PageHeader
        title="Manajemen User"
        description="Semua pengguna platform. Ubah role, tandai verifikasi, atau hapus akun."
      />

      {/* Filters */}
      <div className="mb-4 flex flex-col gap-3 sm:flex-row">
        <Input
          placeholder="Cari nama atau email…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="sm:max-w-xs"
        />
        <Select
          value={role}
          onChange={(e) => {
            setRole(e.target.value);
            setPage(1);
          }}
          className="sm:max-w-[200px]"
        >
          <option value="">Semua role</option>
          <option value="user">Pengguna</option>
          <option value="super_admin">Super Admin</option>
        </Select>
      </div>

      {query.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[110px] rounded-xl" />
          ))}
        </div>
      ) : query.isError ? (
        <p className="text-sm text-destructive">
          Tidak bisa memuat user (butuh akses Super Admin &amp; API berjalan).
        </p>
      ) : users.length === 0 ? (
        <EmptyState
          icon={Users}
          title="Tidak ada user"
          description="Coba ubah kata kunci pencarian atau filter role."
        />
      ) : (
        <>
          <div className="grid gap-3">
            {users.map((u) => (
              <UserCard
                key={u.id}
                user={u}
                isSelf={u.id === currentUserId}
                onRoleChange={(newRole) =>
                  update.mutate({ id: u.id, payload: { role: newRole } })
                }
                onToggleVerified={() =>
                  update.mutate({ id: u.id, payload: { is_verified: !u.is_verified } })
                }
                onDelete={() => {
                  if (confirm(`Hapus akun ${u.full_name || u.email}? Tindakan ini permanen.`)) {
                    remove.mutate(u.id);
                  }
                }}
                busy={update.isPending || remove.isPending}
              />
            ))}
          </div>

          {meta && meta.last_page > 1 && (
            <div className="mt-4 flex items-center justify-between text-sm">
              <span className="text-muted-foreground">
                Halaman {meta.page} dari {meta.last_page} · {meta.total} user
              </span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={meta.page <= 1}
                >
                  Sebelumnya
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage((p) => p + 1)}
                  disabled={meta.page >= meta.last_page}
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </>
  );
}

function UserCard({
  user,
  isSelf,
  onRoleChange,
  onToggleVerified,
  onDelete,
  busy,
}: {
  user: AdminUser;
  isSelf: boolean;
  onRoleChange: (role: "super_admin" | "user") => void;
  onToggleVerified: () => void;
  onDelete: () => void;
  busy: boolean;
}) {
  const initial = (user.full_name || user.email || "?").charAt(0).toUpperCase();
  const ownsOrgs = user.owned_organizations.length > 0;
  const deleteReason = isSelf
    ? "Tidak bisa menghapus akun sendiri"
    : ownsOrgs
      ? "User masih memiliki organisasi"
      : undefined;

  return (
    <Card className="flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between">
      <div className="flex min-w-0 items-start gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[var(--tint)] text-sm font-bold text-[var(--brand-600)]">
          {initial}
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-semibold">{user.full_name || "Tanpa nama"}</span>
            {isSelf && (
              <Badge variant="neutral" className="font-medium">
                Anda
              </Badge>
            )}
            {user.is_verified ? (
              <Badge variant="success">
                <BadgeCheck className="h-3 w-3" />
                Terverifikasi
              </Badge>
            ) : (
              <Badge variant="warning">Belum verifikasi</Badge>
            )}
          </div>
          <p className="truncate text-sm text-muted-foreground">{user.email}</p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Aktif terakhir {relative(user.last_seen_at)}
          </p>

          {(user.owned_organizations.length > 0 || user.memberships.length > 0) && (
            <div className="mt-2 flex flex-wrap gap-1.5">
              {user.owned_organizations.map((o) => (
                <span
                  key={o.id}
                  className="inline-flex items-center gap-1 rounded-full bg-[var(--tint)] px-2 py-0.5 text-xs text-[var(--brand-600)]"
                >
                  <Building2 className="h-3 w-3" />
                  {o.name} · Pemilik
                </span>
              ))}
              {user.memberships.map((m) => (
                <span
                  key={m.organization_id}
                  className="inline-flex items-center gap-1 rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-[var(--text-2)]"
                >
                  <Building2 className="h-3 w-3" />
                  {m.organization_name ?? "—"} · {orgRoleLabel(m.role)}
                </span>
              ))}
            </div>
          )}
        </div>
      </div>

      <div className="flex shrink-0 flex-wrap items-center gap-2 lg:justify-end">
        <div className="flex items-center gap-1.5">
          <ShieldCheck className="h-4 w-4 text-muted-foreground" />
          <Select
            value={user.role}
            disabled={isSelf || busy}
            title={isSelf ? "Tidak bisa mengubah role akun sendiri" : undefined}
            onChange={(e) => onRoleChange(e.target.value as "super_admin" | "user")}
            className="h-9 w-[150px] text-sm"
          >
            <option value="user">{roleLabel("user")}</option>
            <option value="super_admin">{roleLabel("super_admin")}</option>
          </Select>
        </div>

        <Button size="sm" variant="outline" onClick={onToggleVerified} disabled={busy}>
          {user.is_verified ? "Batalkan verifikasi" : "Verifikasi"}
        </Button>

        <Button
          size="sm"
          variant="destructive"
          onClick={onDelete}
          disabled={busy || isSelf || ownsOrgs}
          title={deleteReason}
        >
          <Trash2 className="h-4 w-4" />
          Hapus
        </Button>
      </div>
    </Card>
  );
}
