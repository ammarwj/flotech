"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";

import {
  createSport,
  deleteSport,
  getAdminSports,
  syncSportOfficialRoles,
  syncSportPositions,
  syncSportStats,
  updateSport,
  type AdminSport,
  type AdminSportOfficialRole,
  type AdminSportPosition,
  type AdminSportStat,
} from "@/lib/api/catalog";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { participantLabel, participantModes, tracksDiscipline } from "@/lib/scoring";
import type { DisciplineRuleValues, ParticipantType } from "@/types/api";

type SportForm = {
  slug: string;
  name: string;
  color: string;
  icon: string;
  scoring: "goal" | "set";
  participant_modes: ParticipantType[];
  default_match_minutes: number;
  /** Card thresholds this sport's events inherit; {} = platform defaults. */
  discipline_config: DisciplineRuleValues;
  is_active: boolean;
  sort_order: number;
};

/** Entrant shapes, in the order they read as an escalation. */
const MODES: ParticipantType[] = ["single", "double", "team"];

const EMPTY: SportForm = {
  slug: "",
  name: "",
  color: "#1E6FFF",
  icon: "",
  scoring: "goal",
  // Every sport fields a squad; racket sports add the other two.
  participant_modes: ["team"],
  default_match_minutes: 60,
  discipline_config: {},
  is_active: true,
  sort_order: 0,
};

const EMPTY_STAT: AdminSportStat = {
  stat_key: "",
  label: "",
  short: "",
  role: null,
  fair_play_weight: 0,
};

const EMPTY_POSITION: AdminSportPosition = { position_key: "", label: "" };

const EMPTY_ROLE: AdminSportOfficialRole = { role_key: "", label: "" };

/**
 * Sports, their stat columns and their positions. Adding one here is all it
 * takes for organizers to run events in it — the sport list, scoring style,
 * match length, colour, the statistics tracked, the positions a roster may pick
 * from and the roles its bench may hold all come from these rows.
 */
export default function AdminSportsPage() {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ["admin-sports"], queryFn: getAdminSports });

  const [form, setForm] = useState<SportForm>(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [statsFor, setStatsFor] = useState<string | null>(null);
  const [stats, setStats] = useState<AdminSportStat[]>([]);
  const [positionsFor, setPositionsFor] = useState<string | null>(null);
  const [positions, setPositions] = useState<AdminSportPosition[]>([]);
  const [rolesFor, setRolesFor] = useState<string | null>(null);
  const [roles, setRoles] = useState<AdminSportOfficialRole[]>([]);

  const reset = () => {
    setForm(EMPTY);
    setEditingId(null);
  };

  const save = useMutation({
    mutationFn: () => (editingId ? updateSport(editingId, form) : createSport(form)),
    onSuccess: () => {
      toast.success(editingId ? "Cabang olahraga diperbarui" : "Cabang olahraga dibuat");
      reset();
      qc.invalidateQueries({ queryKey: ["admin-sports"] });
      qc.invalidateQueries({ queryKey: ["catalog"] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan cabang olahraga.").message),
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteSport(id),
    onSuccess: () => {
      toast.success("Cabang olahraga dihapus");
      qc.invalidateQueries({ queryKey: ["admin-sports"] });
      qc.invalidateQueries({ queryKey: ["catalog"] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus cabang olahraga.").message),
  });

  const saveStats = useMutation({
    mutationFn: () => syncSportStats(statsFor!, stats.filter((s) => s.stat_key.trim() !== "")),
    onSuccess: () => {
      toast.success("Kolom statistik disimpan");
      setStatsFor(null);
      qc.invalidateQueries({ queryKey: ["admin-sports"] });
      qc.invalidateQueries({ queryKey: ["catalog"] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan statistik.").message),
  });

  const savePositions = useMutation({
    mutationFn: () =>
      syncSportPositions(
        positionsFor!,
        positions.filter((p) => p.position_key.trim() !== "")
      ),
    onSuccess: () => {
      toast.success("Posisi disimpan");
      setPositionsFor(null);
      qc.invalidateQueries({ queryKey: ["admin-sports"] });
      qc.invalidateQueries({ queryKey: ["catalog"] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan posisi.").message),
  });

  const saveRoles = useMutation({
    mutationFn: () =>
      syncSportOfficialRoles(
        rolesFor!,
        roles.filter((r) => r.role_key.trim() !== "")
      ),
    onSuccess: () => {
      toast.success("Peran ofisial disimpan");
      setRolesFor(null);
      qc.invalidateQueries({ queryKey: ["admin-sports"] });
      qc.invalidateQueries({ queryKey: ["catalog"] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan peran ofisial.").message),
  });

  const edit = (sport: AdminSport) => {
    setEditingId(sport.id);
    setForm({
      slug: sport.slug,
      name: sport.name,
      color: sport.color,
      icon: sport.icon ?? "",
      scoring: sport.scoring,
      participant_modes: participantModes(sport),
      default_match_minutes: sport.default_match_minutes,
      discipline_config: sport.discipline_config ?? {},
      is_active: sport.is_active,
      sort_order: sport.sort_order,
    });
  };

  // Only one editor at a time — two open lists under one sport read as one list.
  const openStats = (sport: AdminSport) => {
    setPositionsFor(null);
    setRolesFor(null);
    setStatsFor(sport.id);
    setStats(sport.stats.length > 0 ? sport.stats : [{ ...EMPTY_STAT }]);
  };

  const openPositions = (sport: AdminSport) => {
    setStatsFor(null);
    setRolesFor(null);
    setPositionsFor(sport.id);
    setPositions(sport.positions.length > 0 ? sport.positions : [{ ...EMPTY_POSITION }]);
  };

  const openRoles = (sport: AdminSport) => {
    setStatsFor(null);
    setPositionsFor(null);
    setRolesFor(sport.id);
    setRoles(sport.official_roles.length > 0 ? sport.official_roles : [{ ...EMPTY_ROLE }]);
  };

  const setStat = (i: number, patch: Partial<AdminSportStat>) =>
    setStats((rows) => rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

  const setPosition = (i: number, patch: Partial<AdminSportPosition>) =>
    setPositions((rows) => rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

  const setRole = (i: number, patch: Partial<AdminSportOfficialRole>) =>
    setRoles((rows) => rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

  const sports = query.data ?? [];
  // The rulebook only means something once the sport has a card column, and
  // those are edited in the stats list below — so it appears when editing a
  // sport that already books players, never on the blank create form.
  const editingSport = sports.find((s) => s.id === editingId) ?? null;

  /** Blank = fall back to the platform default, so it must leave as no key. */
  const setRule = (key: keyof DisciplineRuleValues, raw: string) =>
    setForm((f) => {
      const next = { ...f.discipline_config };
      const n = Number(raw.trim());

      if (raw.trim() === "" || Number.isNaN(n)) delete next[key];
      else next[key] = n as never;

      return { ...f, discipline_config: next };
    });

  return (
    <div>
      <PageHeader
        title="Cabang Olahraga"
        description="Cabang yang bisa dipakai organizer, lengkap dengan gaya skor dan kolom statistiknya."
        backHref="/admin"
        backLabel="Panel admin"
      />

      {/* ---- Form ---- */}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
        className="mb-6 grid gap-4 rounded-xl border border-border bg-card p-5"
      >
        <h2 className="text-sm font-bold">
          {editingId ? "Edit cabang olahraga" : "Tambah cabang olahraga"}
        </h2>

        <div className="grid gap-4 md:grid-cols-3">
          <div className="grid gap-1.5">
            <Label htmlFor="name">Nama</Label>
            <Input
              id="name"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="Basket"
              required
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="slug">Slug</Label>
            <Input
              id="slug"
              value={form.slug}
              onChange={(e) => setForm({ ...form, slug: e.target.value })}
              placeholder="basketball"
              required
            />
            <p className="text-xs text-muted-foreground">
              Nilai yang disimpan di event. Tidak bisa diubah setelah dipakai.
            </p>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="scoring">Gaya skor</Label>
            <Select
              id="scoring"
              value={form.scoring}
              onChange={(e) => setForm({ ...form, scoring: e.target.value as "goal" | "set" })}
            >
              <option value="goal">Gol / poin tunggal</option>
              <option value="set">Per set (voli, badminton, padel)</option>
            </Select>
          </div>
        </div>

        <div className="grid gap-1.5">
          <Label>Jenis peserta</Label>
          <div className="flex flex-wrap gap-4">
            {MODES.map((mode) => {
              const checked = form.participant_modes.includes(mode);
              return (
                <label key={mode} className="flex cursor-pointer items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="h-4 w-4 accent-[var(--brand-600)]"
                    checked={checked}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        participant_modes: e.target.checked
                          ? MODES.filter((m) => m === mode || form.participant_modes.includes(m))
                          : form.participant_modes.filter((m) => m !== mode),
                      })
                    }
                  />
                  {participantLabel(mode)}
                </label>
              );
            })}
          </div>
          <p className="text-xs text-muted-foreground">
            Cabang raket bisa ketiganya. Mode yang sudah dipakai kategori event tidak bisa dilepas.
          </p>
        </div>

        <div className="grid gap-4 md:grid-cols-4">
          <div className="grid gap-1.5">
            <Label htmlFor="color">Warna</Label>
            <div className="flex items-center gap-2">
              <input
                id="color"
                type="color"
                value={form.color}
                onChange={(e) => setForm({ ...form, color: e.target.value })}
                className="h-10 w-12 cursor-pointer rounded-md border border-border bg-background"
              />
              <Input
                value={form.color}
                onChange={(e) => setForm({ ...form, color: e.target.value })}
                className="font-mono"
              />
            </div>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="icon">Ikon (emoji)</Label>
            <Input
              id="icon"
              value={form.icon}
              onChange={(e) => setForm({ ...form, icon: e.target.value })}
              placeholder="🏀"
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="minutes">Durasi match (menit)</Label>
            <Input
              id="minutes"
              type="number"
              min={5}
              value={form.default_match_minutes}
              onChange={(e) =>
                setForm({ ...form, default_match_minutes: Number(e.target.value) || 60 })
              }
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="sort">Urutan</Label>
            <Input
              id="sort"
              type="number"
              min={0}
              value={form.sort_order}
              onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) || 0 })}
            />
          </div>
        </div>

        {tracksDiscipline(editingSport) && (
          <div className="grid gap-3 rounded-xl border border-border bg-[var(--surface-2)] p-3">
            <div>
              <p className="text-sm font-semibold">Akumulasi kartu</p>
              <p className="text-xs text-muted-foreground">
                Bawaan untuk event cabang ini. Organizer bisa menimpanya per event, dan
                kolom yang dikosongkan di sini memakai bawaan platform (3 / 1 / 1 / 2 / 1).
              </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
              <div className="grid gap-1.5">
                <Label htmlFor="disc-threshold">Kartu kuning per larangan</Label>
                <Input
                  id="disc-threshold"
                  type="number"
                  min={1}
                  max={20}
                  value={form.discipline_config.yellow_threshold ?? ""}
                  onChange={(e) => setRule("yellow_threshold", e.target.value)}
                  placeholder="3"
                />
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="disc-yellow-ban">Lama larangan (akumulasi)</Label>
                <Input
                  id="disc-yellow-ban"
                  type="number"
                  min={0}
                  max={10}
                  value={form.discipline_config.yellow_ban_matches ?? ""}
                  onChange={(e) => setRule("yellow_ban_matches", e.target.value)}
                  placeholder="1"
                />
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="disc-red-ban">Lama larangan (kartu merah)</Label>
                <Input
                  id="disc-red-ban"
                  type="number"
                  min={0}
                  max={10}
                  value={form.discipline_config.red_ban_matches ?? ""}
                  onChange={(e) => setRule("red_ban_matches", e.target.value)}
                  placeholder="1"
                />
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="disc-expulsion">Kartu kuning 1 laga = dikeluarkan</Label>
                <Input
                  id="disc-expulsion"
                  type="number"
                  min={0}
                  max={10}
                  value={form.discipline_config.yellows_per_expulsion ?? ""}
                  onChange={(e) => setRule("yellows_per_expulsion", e.target.value)}
                  placeholder="2"
                />
                {/* 0 is a real setting here, not an empty field — a sport whose
                    rules never turn cautions into a dismissal sets it. */}
                <p className="text-xs text-muted-foreground">0 = aturan ini dimatikan.</p>
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="disc-expulsion-ban">Lama larangan (dikeluarkan)</Label>
                <Input
                  id="disc-expulsion-ban"
                  type="number"
                  min={0}
                  max={10}
                  value={form.discipline_config.expulsion_ban_matches ?? ""}
                  onChange={(e) => setRule("expulsion_ban_matches", e.target.value)}
                  placeholder="1"
                />
              </div>
            </div>
          </div>
        )}

        <label className="flex cursor-pointer items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="h-4 w-4 accent-[var(--brand-600)]"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
          />
          Aktif — tampil di pilihan organizer
        </label>

        <div className="flex items-center gap-2">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? "Menyimpan…" : editingId ? "Simpan perubahan" : "Tambah"}
          </Button>
          {editingId && (
            <Button type="button" variant="ghost" onClick={reset}>
              Batal
            </Button>
          )}
        </div>
      </form>

      {/* ---- List ---- */}
      {query.isLoading ? (
        <Skeleton className="h-40 w-full rounded-xl" />
      ) : (
        <div className="grid gap-3">
          {sports.map((sport) => (
            <div key={sport.id} className="rounded-xl border border-border bg-card p-4">
              <div className="flex flex-wrap items-center gap-3">
                <span
                  className="grid h-10 w-10 shrink-0 place-items-center rounded-lg text-lg"
                  style={{ background: `${sport.color}1f`, color: sport.color }}
                >
                  {sport.icon || "🏆"}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="font-semibold">
                    {sport.name}
                    {!sport.is_active && (
                      <span className="ml-2 text-xs font-medium text-muted-foreground">
                        (nonaktif)
                      </span>
                    )}
                  </p>
                  <p className="truncate text-xs text-muted-foreground">
                    {sport.slug} · {sport.scoring === "set" ? "per set" : "gol"} ·{" "}
                    {sport.default_match_minutes} menit ·{" "}
                    {sport.stats.length > 0
                      ? sport.stats.map((s) => s.short).join(" / ")
                      : "belum ada statistik"}{" "}
                    ·{" "}
                    {sport.positions.length > 0
                      ? `${sport.positions.length} posisi`
                      : "belum ada posisi"}{" "}
                    ·{" "}
                    {sport.official_roles.length > 0
                      ? `${sport.official_roles.length} peran ofisial`
                      : "belum ada peran ofisial"}
                  </p>
                </div>
                <Button size="sm" variant="outline" onClick={() => openStats(sport)}>
                  Kolom statistik
                </Button>
                <Button size="sm" variant="outline" onClick={() => openPositions(sport)}>
                  Posisi
                </Button>
                <Button size="sm" variant="outline" onClick={() => openRoles(sport)}>
                  Peran ofisial
                </Button>
                <Button size="sm" variant="outline" onClick={() => edit(sport)}>
                  Edit
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => remove.mutate(sport.id)}
                  aria-label={`Hapus ${sport.name}`}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>

              {/* ---- Stat columns editor ---- */}
              {statsFor === sport.id && (
                <div className="mt-4 grid gap-3 border-t border-border pt-4">
                  <p className="text-xs text-muted-foreground">
                    Baris pertama adalah statistik utama (dipakai leaderboard). Role
                    &quot;gol&quot; dicocokkan dengan skor pertandingan, &quot;assist&quot; tidak
                    boleh melebihi gol. Bobot fair play &gt; 0 menjadikan statistik itu
                    pelanggaran (kartu kuning 1, merah 3).
                  </p>

                  {stats.map((stat, i) => (
                    <div key={i} className="grid gap-2 md:grid-cols-[1fr_1fr_5rem_8rem_7rem_auto]">
                      <Input
                        value={stat.stat_key}
                        onChange={(e) => setStat(i, { stat_key: e.target.value })}
                        placeholder="points"
                        aria-label="Kunci statistik"
                      />
                      <Input
                        value={stat.label}
                        onChange={(e) => setStat(i, { label: e.target.value })}
                        placeholder="Poin"
                        aria-label="Label"
                      />
                      <Input
                        value={stat.short}
                        onChange={(e) => setStat(i, { short: e.target.value })}
                        placeholder="PTS"
                        aria-label="Singkatan"
                      />
                      <Select
                        value={stat.role ?? ""}
                        onChange={(e) =>
                          setStat(i, { role: (e.target.value || null) as AdminSportStat["role"] })
                        }
                        aria-label="Role"
                      >
                        <option value="">— tanpa role —</option>
                        <option value="goal">Gol (= skor)</option>
                        <option value="assist">Assist</option>
                        {/* What the suspension engine looks for. It reads the
                          role, never the key, so a sport may name its cards
                          anything. Bobot fair play beside this is a separate
                          question — it only weighs the standings tiebreaker. */}
                        <option value="yellow">Kartu kuning (akumulasi)</option>
                        <option value="red">Kartu merah (larangan)</option>
                      </Select>
                      <Input
                        type="number"
                        min={0}
                        max={10}
                        value={stat.fair_play_weight}
                        onChange={(e) =>
                          setStat(i, { fair_play_weight: Number(e.target.value) || 0 })
                        }
                        aria-label="Bobot fair play"
                      />
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setStats((rows) => rows.filter((_, idx) => idx !== i))}
                        aria-label="Hapus kolom"
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  ))}

                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => setStats((rows) => [...rows, { ...EMPTY_STAT }])}
                    >
                      <Plus className="h-4 w-4" />
                      Tambah kolom
                    </Button>
                    <Button size="sm" onClick={() => saveStats.mutate()} disabled={saveStats.isPending}>
                      {saveStats.isPending ? "Menyimpan…" : "Simpan statistik"}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setStatsFor(null)}>
                      Tutup
                    </Button>
                  </div>
                </div>
              )}

              {/* ---- Positions editor ---- */}
              {positionsFor === sport.id && (
                <div className="mt-4 grid gap-3 border-t border-border pt-4">
                  <p className="text-xs text-muted-foreground">
                    Posisi yang bisa dipilih saat mendaftarkan pemain. Urutan baris = urutan di
                    dropdown. Kunci adalah yang tersimpan di roster — mengganti <em>label</em> aman
                    dan langsung berlaku di semua tim, tapi kunci yang masih dipakai pemain tidak
                    bisa dihapus.
                  </p>

                  {positions.map((position, i) => (
                    <div key={i} className="grid gap-2 md:grid-cols-[1fr_1fr_auto]">
                      <Input
                        value={position.position_key}
                        onChange={(e) => setPosition(i, { position_key: e.target.value })}
                        placeholder="goalkeeper"
                        aria-label="Kunci posisi"
                      />
                      <Input
                        value={position.label}
                        onChange={(e) => setPosition(i, { label: e.target.value })}
                        placeholder="Kiper"
                        aria-label="Label posisi"
                      />
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setPositions((rows) => rows.filter((_, idx) => idx !== i))}
                        aria-label="Hapus posisi"
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  ))}

                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => setPositions((rows) => [...rows, { ...EMPTY_POSITION }])}
                    >
                      <Plus className="h-4 w-4" />
                      Tambah posisi
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => savePositions.mutate()}
                      disabled={savePositions.isPending}
                    >
                      {savePositions.isPending ? "Menyimpan…" : "Simpan posisi"}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setPositionsFor(null)}>
                      Tutup
                    </Button>
                  </div>
                </div>
              )}

              {/* ---- Official roles editor ---- */}
              {rolesFor === sport.id && (
                <div className="mt-4 grid gap-3 border-t border-border pt-4">
                  <p className="text-xs text-muted-foreground">
                    Peran yang bisa dipilih saat mendaftarkan pelatih dan ofisial tim. Urutan baris
                    = urutan di dropdown. Sama seperti posisi: mengganti <em>label</em> aman dan
                    langsung berlaku di semua tim, tapi kunci yang masih dipakai ofisial tidak bisa
                    dihapus.
                  </p>

                  {roles.map((role, i) => (
                    <div key={i} className="grid gap-2 md:grid-cols-[1fr_1fr_auto]">
                      <Input
                        value={role.role_key}
                        onChange={(e) => setRole(i, { role_key: e.target.value })}
                        placeholder="head_coach"
                        aria-label="Kunci peran ofisial"
                      />
                      <Input
                        value={role.label}
                        onChange={(e) => setRole(i, { label: e.target.value })}
                        placeholder="Pelatih Kepala"
                        aria-label="Label peran ofisial"
                      />
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setRoles((rows) => rows.filter((_, idx) => idx !== i))}
                        aria-label="Hapus peran ofisial"
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  ))}

                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => setRoles((rows) => [...rows, { ...EMPTY_ROLE }])}
                    >
                      <Plus className="h-4 w-4" />
                      Tambah peran
                    </Button>
                    <Button size="sm" onClick={() => saveRoles.mutate()} disabled={saveRoles.isPending}>
                      {saveRoles.isPending ? "Menyimpan…" : "Simpan peran"}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setRolesFor(null)}>
                      Tutup
                    </Button>
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
