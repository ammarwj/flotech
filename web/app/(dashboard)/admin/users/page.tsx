"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { formatDistanceToNow } from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import {
  Users,
  ShieldCheck,
  BadgeCheck,
  Trash2,
  Building2,
  UserCog,
  UserCheck,
  KeyRound,
  Megaphone,
  Shirt,
  ClipboardList,
  Search as SearchIcon,
  X,
} from "lucide-react";

import { useConfirm } from "@/components/shared/confirm-provider";

import {
  getAdminUsers,
  updateAdminUser,
  deleteAdminUser,
  impersonateAdminUser,
  type AdminUserUpdate,
} from "@/lib/api/admin";
import { parseApiError } from "@/lib/api/errors";
import { useAuthStore } from "@/stores/auth-store";
import { dashboardModeFor, MODE_HOME, MODE_LABEL } from "@/lib/hooks/use-dashboard-mode";
import { EVENT_PERSONNEL_KIND_LABELS } from "@/lib/labels";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { ResetPasswordDialog } from "@/components/admin/reset-password-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import type { AccountType, AdminUser, EventPersonnelKind } from "@/types/api";

const relative = (iso: string | null) =>
  iso ? formatDistanceToNow(new Date(iso), { addSuffix: true, locale: idLocale }) : "belum pernah";

const roleLabel = (role: string) => (role === "super_admin" ? "Super Admin" : "Pengguna");
const orgRoleLabel = (role: string) => (role === "admin" ? "Admin" : role === "operator" ? "Operator" : role);

// Jenis akun turunan dari server (lihat AdminUser["account_types"]). Ikon + label
// dikunci di sini supaya badge dan opsi filter di bawah tidak bisa menyimpang.
const ACCOUNT_TYPES: Record<AccountType, { label: string; icon: typeof Megaphone }> = {
  organizer: { label: "Organizer", icon: Megaphone },
  participant: { label: "Tim Peserta", icon: Shirt },
};

/**
 * Petugas **bukan** salah satu `account_types` — itu diturunkan dari organisasi
 * & tim saja, jadi seorang wasit murni terbaca kosong di sana dan dulu memakai
 * badge "Belum ada aktivitas" padahal dia bertugas di dua event. Urutannya
 * dikunci di sini (bukan urutan kemunculan di `officiating`) supaya dua kartu
 * dengan penugasan sama tidak menampilkan badge dengan urutan berbeda.
 */
const CREW_KINDS: EventPersonnelKind[] = ["referee", "staff"];

/** Berapa chip tim/penugasan yang ditampilkan sebelum sisanya diringkas jadi "+N". */
const CHIP_LIMIT = 3;

export default function AdminUsersPage() {
  const confirm = useConfirm();
  const qc = useQueryClient();
  const router = useRouter();
  const currentUserId = useAuthStore((s) => s.user?.id);
  const impersonating = useAuthStore((s) => s.impersonating);
  const startImpersonation = useAuthStore((s) => s.startImpersonation);

  const [search, setSearch] = useState("");
  const [q, setQ] = useState("");
  const [role, setRole] = useState("");
  const [type, setType] = useState("");
  const [page, setPage] = useState(1);
  // The user whose password dialog is open. One dialog for the whole list, not
  // one per card — mounting 20 hidden dialogs to show at most one is waste.
  const [resetting, setResetting] = useState<AdminUser | null>(null);

  // Debounce the search box so we don't fire a request per keystroke.
  useEffect(() => {
    const t = setTimeout(() => {
      setQ(search);
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const query = useQuery({
    queryKey: ["admin-users", { q, role, type, page }],
    queryFn: () =>
      getAdminUsers({ q: q || undefined, role: role || undefined, type: type || undefined, page }),
    // The moment impersonation starts the token is no longer a super admin's, so
    // refetching this admin-only list would just 403 while we navigate away.
    enabled: !impersonating,
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

  const impersonate = useMutation({
    mutationFn: (id: string) => impersonateAdminUser(id),
    onSuccess: (res) => {
      // Swapping the token + user in the store re-points the whole app: the
      // request interceptor reads the token live and every gate reads `user`.
      startImpersonation(res.access_token, res.user);
      // Don't let the admin's cached data (org list, etc.) follow into the
      // impersonated session — it's cached under keys with no user id.
      qc.clear();
      toast.success(`Sekarang login sebagai ${res.user.full_name || res.user.email}.`);
      // Tujuannya dari `dashboardModeFor`, bukan `default_mode` mentah — kolom
      // itu cuma benar untuk akun yang undangan petugas ikut *buatkan*; lihat
      // docblock helper-nya. Dan tujuan yang sama sekarang dipakai AdminLayout,
      // jadi `push` di sini dan `replace` dari layout (yang menyala pada tick
      // yang sama, begitu store berganti user) mendarat di alamat yang sama
      // siapa pun yang menang.
      router.push(MODE_HOME[dashboardModeFor(res.user)]);
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal login sebagai user ini.").message),
  });

  const users = query.data?.items ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <PageHeader
        title="Manajemen User"
        description="Semua pengguna platform. Ubah role, tandai verifikasi, reset password, atau hapus akun."
      />

      {/* Filter. Ambangnya `lg`, bukan `sm`: sidebar dashboard muncul di `md`
          dan memakan 260px, jadi tiga kontrol satu baris sejak `sm` menyisakan
          ~48px untuk kotak pencarian tepat di lebar tablet. Di bawah `sm`
          semuanya satu kolom, di `sm` dua select berbagi satu baris dan
          pencarian mengambil keduanya. */}
      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:flex">
        <div className="relative min-w-0 sm:col-span-2 lg:max-w-sm lg:flex-1">
          <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          {/* Event ikut dicari di server (UserController::orWhereInEvent) lewat
              keempat jalan user→event, jadi placeholder-nya wajib menyebutnya:
              fitur pencarian yang tidak diumumkan tidak akan pernah dicoba. */}
          <Input
            placeholder="Cari nama, email, atau nama event…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="pl-9 pr-9"
            aria-label="Cari user"
          />
          {search && (
            <button
              type="button"
              onClick={() => setSearch("")}
              aria-label="Hapus pencarian"
              className="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>
        <Select
          value={role}
          onChange={(e) => {
            setRole(e.target.value);
            setPage(1);
          }}
          className="lg:max-w-[180px]"
          aria-label="Filter role platform"
        >
          <option value="">Semua role</option>
          <option value="user">Pengguna</option>
          <option value="super_admin">Super Admin</option>
        </Select>
        <Select
          value={type}
          onChange={(e) => {
            setType(e.target.value);
            setPage(1);
          }}
          className="lg:max-w-[200px]"
          aria-label="Filter jenis akun"
        >
          <option value="">Semua jenis akun</option>
          <option value="organizer">{ACCOUNT_TYPES.organizer.label}</option>
          <option value="participant">{ACCOUNT_TYPES.participant.label}</option>
          <option value="crew">Petugas Event</option>
          <option value="none">Belum ada aktivitas</option>
        </Select>
      </div>

      {query.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[150px] rounded-xl" />
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
          description="Coba ubah kata kunci pencarian, filter role, atau filter jenis akun."
        />
      ) : (
        <>
          {/* Hitungan hasil, bukan cuma nomor halaman: pencarian nama event bisa
              mengembalikan seluruh panitia satu turnamen, dan angkanya di atas
              daftar itulah yang memberi tahu pencarinya bahwa filternya kena. */}
          {meta && (
            <p className="mb-3 text-xs text-muted-foreground">
              {meta.total} user{q ? ` cocok dengan “${q}”` : ""}
            </p>
          )}

          {/* `grid-cols-1`, bukan `grid` telanjang. Kolom implisit `auto`
              mengambil lebar **minimum-content** item terbesarnya, dan
              min-content sebuah kartu di sini adalah string nowrap
              terpanjangnya — satu nama organisasi ("Asosiasi Futsal Kabupaten
              Kepulauan Anambas · Pemilik") membuat seluruh kartu ~800px di
              viewport 375px, lalu semua isinya ikut melebar bersamanya. Itu
              yang membuat kolom kanan baris aksi terpotong di tepi layar, bukan
              tombolnya sendiri. `grid-cols-1` = `minmax(0, 1fr)`, jadi
              track-nya boleh menyusut; `min-w-0` di Card melengkapinya karena
              item grid tetap punya `min-width: auto` sendiri. */}
          <div className="grid grid-cols-1 gap-3">
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
                onDelete={() =>
                  void confirm({
                    title: "Hapus akun ini?",
                    description: `${u.full_name || u.email} kehilangan akses ke seluruh platform.`,
                    consequences: "Penghapusan permanen dan tidak bisa dibatalkan.",
                    confirmLabel: "Hapus akun",
                    tone: "danger",
                    icon: Trash2,
                  }).then((ok) => ok && remove.mutate(u.id))
                }
                onResetPassword={() => setResetting(u)}
                onImpersonate={() =>
                  void confirm({
                    title: "Login sebagai pengguna ini?",
                    // Tujuan pendaratannya disebut, karena untuk akun petugas ia
                    // bukan dashboard organizer. Dibaca dari `dashboardModeFor`
                    // yang sama dengan navigasinya di `onSuccess`, bukan dari
                    // `default_mode` mentah: kalimat yang menjanjikan satu
                    // halaman lalu mendarat di halaman lain lebih buruk daripada
                    // tidak menyebutkannya sama sekali.
                    description: `Kamu akan melihat platform sebagai ${u.full_name || u.email} di ${MODE_LABEL[dashboardModeFor(u)]}.`,
                    consequences: (
                      <>
                        Tampilan admin tidak tersedia sampai kamu menekan &quot;Kembali ke
                        admin&quot;.
                        {u.must_change_password && (
                          <>
                            {" "}
                            Akun ini masih memakai password sementara dari undangan petugas —
                            kamu tetap masuk tanpa menggantinya, jadi kredensial aslinya tidak
                            tersentuh.
                          </>
                        )}
                      </>
                    ),
                    confirmLabel: "Login sebagai pengguna",
                    icon: UserCheck,
                  }).then((ok) => ok && impersonate.mutate(u.id))
                }
                busy={update.isPending || remove.isPending || impersonate.isPending}
              />
            ))}
          </div>

          {resetting && (
            <ResetPasswordDialog
              // Keyed by user so the dialog's local field state can never carry
              // a half-typed password over to the next user opened.
              key={resetting.id}
              user={resetting}
              open
              onOpenChange={(next) => !next && setResetting(null)}
            />
          )}

          {/* `flex-wrap` + `ml-auto`, bukan `justify-between` sendirian:
              stringnya panjang dan kedua tombolnya `whitespace-nowrap`, jadi di
              lebar ponsel barisnya meluber keluar kartu. Setelah membungkus,
              tombolnya tetap rapat ke kanan. */}
          {meta && meta.last_page > 1 && (
            <div className="mt-4 flex flex-wrap items-center gap-3 text-sm">
              <span className="text-muted-foreground">
                Halaman {meta.page} dari {meta.last_page} · {meta.total} user
              </span>
              <div className="ml-auto flex gap-2">
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

/** Chip abu-abu seragam untuk organisasi / tim / penugasan. */
function Chip({
  icon: Icon,
  children,
  title,
  tone = "muted",
}: {
  icon: typeof Building2;
  children: React.ReactNode;
  title?: string;
  tone?: "muted" | "brand";
}) {
  return (
    <span
      title={title}
      className={
        tone === "brand"
          ? "inline-flex min-w-0 max-w-full items-center gap-1 rounded-full bg-[var(--tint)] px-2 py-0.5 text-xs text-[var(--brand-600)]"
          : "inline-flex min-w-0 max-w-full items-center gap-1 rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-[var(--text-2)]"
      }
    >
      <Icon className="h-3 w-3 shrink-0" />
      {/* `min-w-0` di samping `truncate`: min-width flex item default `auto` =
          min-content, dan untuk teks itu kata terpanjangnya — jadi satu nama
          event tanpa spasi tetap mendorong chip melewati `max-w-full` induknya.
          `truncate` sendirian tidak pernah jalan di dalam flex.

          Yang di root chip (`min-w-0 max-w-full`) menjawab setengah lainnya:
          chip ini sendiri item flex dari baris yang membungkusnya, dan
          **min-width menang atas max-width**, jadi `max-w-full` tanpa `min-w-0`
          tidak pernah menahan apa pun. Itu yang membuat baris ini terpotong di
          tepi layar alih-alih ter-ellipsis di tepi kartu. */}
      <span className="min-w-0 truncate">{children}</span>
    </span>
  );
}

function UserCard({
  user,
  isSelf,
  onRoleChange,
  onToggleVerified,
  onDelete,
  onResetPassword,
  onImpersonate,
  busy,
}: {
  user: AdminUser;
  isSelf: boolean;
  onRoleChange: (role: "super_admin" | "user") => void;
  onToggleVerified: () => void;
  onDelete: () => void;
  onResetPassword: () => void;
  onImpersonate: () => void;
  busy: boolean;
}) {
  const initial = (user.full_name || user.email || "?").charAt(0).toUpperCase();
  const ownsOrgs = user.owned_organizations.length > 0;
  // Server yang menurunkannya; `?? []` cuma menjaga klien yang lebih baru dari API.
  const accountTypes = user.account_types ?? [];
  const teams = user.managed_teams ?? [];
  const crew = user.officiating ?? [];
  // "Belum ada aktivitas" harus melihat petugas juga, persis seperti filter
  // `none` di server — kalau tidak, satu kartu bisa berbadge "Wasit" DAN
  // "Belum ada aktivitas" sekaligus.
  const idle = accountTypes.length === 0 && crew.length === 0;

  const deleteReason = isSelf
    ? "Tidak bisa menghapus akun sendiri"
    : ownsOrgs
      ? "User masih memiliki organisasi"
      : undefined;

  // Mirrors the server guard in UserController@impersonate.
  const isSuperAdmin = user.role === "super_admin";
  const impersonateReason = isSelf
    ? "Kamu sudah login sebagai akun ini"
    : isSuperAdmin
      ? "Tidak bisa login sebagai sesama super admin"
      : undefined;

  // Mirrors UserController@resetPassword, which refuses the same two targets.
  const resetReason = isSelf
    ? "Ubah password sendiri lewat halaman Akun Saya"
    : isSuperAdmin
      ? "Tidak bisa mereset password sesama super admin"
      : undefined;

  return (
    <Card className="min-w-0 p-4 sm:p-5">
      <div className="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex min-w-0 items-start gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[var(--tint)] text-sm font-bold text-[var(--brand-600)]">
            {initial}
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate font-semibold">{user.full_name || "Tanpa nama"}</p>
            <p className="truncate text-sm text-muted-foreground">{user.email}</p>

            {/* Badge di barisnya sendiri, tidak lagi menyambung nama. Satu kartu
                bisa membawa tujuh badge sekaligus (role, verifikasi, dua jenis
                akun, dua jenis petugas, password sementara) dan semuanya
                `whitespace-nowrap` dari cva-nya — di lebar ponsel nama panjang
                tergencet jadi satu kata per baris di antara mereka. */}
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
              {isSelf && (
                <Badge variant="neutral" className="font-medium">
                  Anda
                </Badge>
              )}
              {isSuperAdmin && (
                <Badge variant="danger">
                  <ShieldCheck className="h-3 w-3" />
                  {roleLabel("super_admin")}
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
              {/* Jenis akun — apa yang user ini benar-benar lakukan di platform,
                  bukan mode dashboard yang terakhir dia pilih. */}
              {accountTypes.map((t) => {
                const { label, icon: Icon } = ACCOUNT_TYPES[t];
                return (
                  <Badge key={t} variant="info">
                    <Icon className="h-3 w-3" />
                    {label}
                  </Badge>
                );
              })}
              {/* Wasit/Staf. Labelnya dari EVENT_PERSONNEL_KIND_LABELS (cermin
                  EventPersonnel::KIND_LABELS) — jangan tulis "Wasit" di sini. */}
              {CREW_KINDS.map((kind) => {
                const n = crew.filter((c) => c.kind === kind).length;
                if (n === 0) return null;
                return (
                  <Badge key={kind} variant="default">
                    <ClipboardList className="h-3 w-3" />
                    {EVENT_PERSONNEL_KIND_LABELS[kind]}
                    {n > 1 && ` · ${n} event`}
                  </Badge>
                );
              })}
              {/* Password undangan petugas yang belum diganti. Ini yang membuat
                  "Login sebagai" dulu terasa rusak, jadi keadaannya kelihatan di
                  kartu sebelum tombolnya ditekan. */}
              {user.must_change_password && (
                <Badge
                  variant="warning"
                  title="Masih memakai password sementara dari undangan petugas"
                >
                  <KeyRound className="h-3 w-3" />
                  Password sementara
                </Badge>
              )}
              {idle && (
                <Badge
                  variant="outline"
                  title="Belum punya organisasi, tim terdaftar, maupun penugasan petugas"
                >
                  Belum ada aktivitas
                </Badge>
              )}
            </div>
            <p className="mt-1.5 text-xs text-muted-foreground">
              Aktif terakhir {relative(user.last_seen_at)}
            </p>

            {(ownsOrgs || user.memberships.length > 0 || teams.length > 0 || crew.length > 0) && (
              <div className="mt-2 flex min-w-0 flex-wrap gap-1.5">
                {user.owned_organizations.map((o) => (
                  <Chip key={o.id} icon={Building2} tone="brand">
                    {o.name} · Pemilik
                  </Chip>
                ))}
                {user.memberships.map((m) => (
                  <Chip key={m.organization_id} icon={Building2}>
                    {m.organization_name ?? "—"} · {orgRoleLabel(m.role)}
                  </Chip>
                ))}
                {teams.slice(0, CHIP_LIMIT).map((t) => (
                  <Chip key={t.id} icon={Shirt} title={t.event_name ?? undefined}>
                    {t.name}
                    {t.event_name && <span className="opacity-70"> · {t.event_name}</span>}
                  </Chip>
                ))}
                {teams.length > CHIP_LIMIT && (
                  <span className="inline-flex items-center rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-[var(--text-2)]">
                    +{teams.length - CHIP_LIMIT} tim lain
                  </span>
                )}
                {/* Event tempat dia bertugas. Ini juga jawaban yang dicari
                    pencarian nama event: baris yang ketemu harus bisa
                    menunjukkan event mana yang membuatnya ketemu. */}
                {crew.slice(0, CHIP_LIMIT).map((c) => (
                  <Chip
                    key={`${c.event_id}-${c.kind}`}
                    icon={ClipboardList}
                    title={`${EVENT_PERSONNEL_KIND_LABELS[c.kind]} di ${c.event_name}`}
                  >
                    {c.event_name}
                    <span className="opacity-70"> · {EVENT_PERSONNEL_KIND_LABELS[c.kind]}</span>
                  </Chip>
                ))}
                {crew.length > CHIP_LIMIT && (
                  <span className="inline-flex items-center rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-[var(--text-2)]">
                    +{crew.length - CHIP_LIMIT} penugasan lain
                  </span>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Role platform dipisah dari tombol aksi: ia satu-satunya kontrol yang
            mengubah *apa* akun ini, bukan sesuatu yang dilakukan padanya.

            Dan tanpa `shrink-0` — perbaikan yang sama yang sudah ditulis panjang
            di PageHeader: pada flex item, `flex-basis: auto` jadi lebar
            max-content, dan untuk flex container bersarang itu berarti seluruh
            anaknya dalam SATU baris; `shrink-0` lalu melarangnya turun dari
            sana. Di lebar ponsel select 150px-nya jadi pulau terpencil di kiri
            kartu, jadi di bawah `lg` ia lebar penuh dan labelnya ikut terbaca
            (bukan `sr-only` lagi: di barisnya sendiri memang ada tempatnya). */}
        <label className="flex w-full items-center gap-2 text-xs text-muted-foreground lg:w-auto">
          <ShieldCheck className="h-4 w-4 shrink-0" />
          <span>Role</span>
          <div className="min-w-0 flex-1 lg:flex-none">
            <Select
              value={user.role}
              disabled={isSelf || busy}
              title={isSelf ? "Tidak bisa mengubah role akun sendiri" : undefined}
              onChange={(e) => onRoleChange(e.target.value as "super_admin" | "user")}
              className="h-9 w-full text-sm lg:w-[150px]"
            >
              <option value="user">{roleLabel("user")}</option>
              <option value="super_admin">{roleLabel("super_admin")}</option>
            </Select>
          </div>
        </label>
      </div>

      {/* Aksi pindah ke barisnya sendiri di bawah pemisah. Sebelumnya lima
          kontrol berebut separuh kanan kartu dengan identitas, jadi di lebar
          laptop mana pun ia membungkus jadi tangga tiga baris; di sini mereka
          dapat lebar penuh kartu dan urutannya tetap.

          Grid 2 kolom di ponsel, flex-wrap baru sejak `sm`: keempat tombol
          `whitespace-nowrap` (itu di cva Button, bukan di sini), jadi flex-wrap
          sendirian meluber keluar kartu yang interiornya cuma ~303px di 375px
          alih-alih membungkus di dalamnya. Grid memberi tiap tombol lebar kolom
          yang sudah pasti muat. `ml-auto` pada Hapus dipindah ke `sm:` karena
          di grid ia tidak berarti apa-apa. */}
      <div className="mt-4 grid grid-cols-2 gap-2 border-t border-border pt-3 sm:flex sm:flex-wrap sm:items-center">
        <Button
          size="sm"
          variant="outline"
          onClick={onToggleVerified}
          disabled={busy}
          className="w-full min-w-0 sm:w-auto"
        >
          <BadgeCheck className="h-4 w-4" />
          {/* Label pendek di ponsel lewat pola `sm:hidden`/`hidden sm:inline`
              yang sudah dipakai tabel-tabel lain. "Batalkan verifikasi" adalah
              label terpanjang di baris ini, dan satu-satunya yang tidak muat. */}
          <span className="min-w-0 truncate sm:hidden">{user.is_verified ? "Batalkan" : "Verifikasi"}</span>
          <span className="hidden min-w-0 truncate sm:inline">
            {user.is_verified ? "Batalkan verifikasi" : "Verifikasi"}
          </span>
        </Button>

        <Button
          size="sm"
          variant="outline"
          onClick={onResetPassword}
          disabled={busy || isSelf || isSuperAdmin}
          title={resetReason}
          className="w-full min-w-0 sm:w-auto"
        >
          <KeyRound className="h-4 w-4" />
          {/* Pola label pendek yang sama dengan tombol verifikasi di atas. Di
              375px tiap kolom grid ~147px dan chrome tombol (padding + ikon +
              gap) memakan ~48px, jadi "Reset password" adalah label kedua yang
              tidak muat — dan tombol yang berbunyi "Reset passwor…" lebih buruk
              daripada yang berbunyi "Reset". */}
          <span className="min-w-0 truncate sm:hidden">Reset</span>
          <span className="hidden min-w-0 truncate sm:inline">Reset password</span>
        </Button>

        <Button
          size="sm"
          variant="outline"
          onClick={onImpersonate}
          disabled={busy || isSelf || isSuperAdmin}
          title={impersonateReason}
          className="w-full min-w-0 sm:w-auto"
        >
          <UserCog className="h-4 w-4" />
          <span className="min-w-0 truncate">Login sebagai</span>
        </Button>

        <Button
          size="sm"
          variant="destructive"
          onClick={onDelete}
          disabled={busy || isSelf || ownsOrgs}
          title={deleteReason}
          className="w-full min-w-0 sm:ml-auto sm:w-auto"
        >
          <Trash2 className="h-4 w-4" />
          <span className="min-w-0 truncate">Hapus</span>
        </Button>
      </div>
    </Card>
  );
}
