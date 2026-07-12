"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";

import {
  createSport,
  deleteSport,
  getAdminSports,
  syncSportStats,
  updateSport,
  type AdminSport,
  type AdminSportStat,
} from "@/lib/api/catalog";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";

type SportForm = {
  slug: string;
  name: string;
  color: string;
  icon: string;
  scoring: "goal" | "set";
  default_match_minutes: number;
  is_active: boolean;
  sort_order: number;
};

const EMPTY: SportForm = {
  slug: "",
  name: "",
  color: "#1E6FFF",
  icon: "",
  scoring: "goal",
  default_match_minutes: 60,
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

/**
 * Sports and their stat columns. Adding one here is all it takes for organizers
 * to run events in it — the sport list, scoring style, match length, colour and
 * the statistics tracked all come from these rows.
 */
export default function AdminSportsPage() {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ["admin-sports"], queryFn: getAdminSports });

  const [form, setForm] = useState<SportForm>(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [statsFor, setStatsFor] = useState<string | null>(null);
  const [stats, setStats] = useState<AdminSportStat[]>([]);

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

  const edit = (sport: AdminSport) => {
    setEditingId(sport.id);
    setForm({
      slug: sport.slug,
      name: sport.name,
      color: sport.color,
      icon: sport.icon ?? "",
      scoring: sport.scoring,
      default_match_minutes: sport.default_match_minutes,
      is_active: sport.is_active,
      sort_order: sport.sort_order,
    });
  };

  const openStats = (sport: AdminSport) => {
    setStatsFor(sport.id);
    setStats(sport.stats.length > 0 ? sport.stats : [{ ...EMPTY_STAT }]);
  };

  const setStat = (i: number, patch: Partial<AdminSportStat>) =>
    setStats((rows) => rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

  const sports = query.data ?? [];

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
                      : "belum ada statistik"}
                  </p>
                </div>
                <Button size="sm" variant="outline" onClick={() => openStats(sport)}>
                  Kolom statistik
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
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
