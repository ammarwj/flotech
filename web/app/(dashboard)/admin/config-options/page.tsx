"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Trash2 } from "lucide-react";
import { toast } from "sonner";

import {
  createConfigOption,
  deleteConfigOption,
  getConfigOptions,
  getEngines,
  updateConfigOption,
  type AdminConfigOption,
} from "@/lib/api/catalog";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { cn } from "@/lib/utils";

const GROUPS = [
  { key: "tournament_format", label: "Format Turnamen" },
  { key: "tiebreaker", label: "Tie Breaker" },
  { key: "draw_method", label: "Metode Undian" },
  { key: "knockout_round", label: "Babak Knockout" },
  { key: "sponsor_tier", label: "Tier Sponsor" },
] as const;

type Group = (typeof GROUPS)[number]["key"];

/** Which piece of `meta` a group must carry, and where its options come from. */
const META_FIELD: Record<Group, { name: string; label: string; from?: keyof Engines } | null> = {
  tournament_format: { name: "engine", label: "Engine", from: "formats" },
  tiebreaker: { name: "comparator", label: "Comparator", from: "tiebreakers" },
  draw_method: { name: "strategy", label: "Strategy", from: "draw_methods" },
  knockout_round: { name: "size", label: "Jumlah tim" },
  sponsor_tier: null,
};

interface Engines {
  formats: string[];
  tiebreakers: string[];
  draw_methods: string[];
}

const EMPTY = { key: "", label: "", meta: "", is_active: true, sort_order: 0 };

/**
 * The reference options behind the app's dropdowns.
 *
 * A format, tiebreaker or draw method is a *preset over an engine that exists in
 * code* — so a new row must name one. That's why "Liga 2 Putaran" is possible
 * (engine `league`, defaults `legs: 2`) but "Swiss System" is not: nothing can
 * run it.
 */
export default function AdminConfigOptionsPage() {
  const qc = useQueryClient();
  const [group, setGroup] = useState<Group>("tournament_format");
  const [form, setForm] = useState({ ...EMPTY });
  const [editingId, setEditingId] = useState<string | null>(null);
  const [defaults, setDefaults] = useState("");

  const query = useQuery({
    queryKey: ["admin-config-options", group],
    queryFn: () => getConfigOptions(group),
  });
  const enginesQuery = useQuery({ queryKey: ["admin-engines"], queryFn: getEngines });

  const meta = META_FIELD[group];
  const engineChoices = meta?.from ? (enginesQuery.data?.[meta.from] ?? []) : [];

  const reset = () => {
    setForm({ ...EMPTY });
    setDefaults("");
    setEditingId(null);
  };

  const payload = () => {
    const metaObject: Record<string, unknown> = {};

    if (meta) {
      metaObject[meta.name] = meta.name === "size" ? Number(form.meta) : form.meta;
    }

    // A format preset can ship a starting bracket_config, e.g. {"legs": 2}.
    if (group === "tournament_format" && defaults.trim() !== "") {
      try {
        metaObject.defaults = JSON.parse(defaults);
      } catch {
        throw new Error("Default config bukan JSON yang valid.");
      }
    }

    return {
      group,
      key: form.key,
      label: form.label,
      meta: meta || Object.keys(metaObject).length > 0 ? metaObject : undefined,
      is_active: form.is_active,
      sort_order: form.sort_order,
    };
  };

  const save = useMutation({
    mutationFn: async () => {
      const body = payload();
      return editingId ? updateConfigOption(editingId, body) : createConfigOption(body);
    },
    onSuccess: () => {
      toast.success(editingId ? "Opsi diperbarui" : "Opsi dibuat");
      reset();
      qc.invalidateQueries({ queryKey: ["admin-config-options", group] });
      qc.invalidateQueries({ queryKey: ["catalog"] });
    },
    // payload() throws on malformed JSON; its message is the useful one there.
    onError: (err) =>
      toast.error(
        parseApiError(err, err instanceof Error ? err.message : "Gagal menyimpan opsi.").message
      ),
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteConfigOption(id),
    onSuccess: () => {
      toast.success("Opsi dihapus");
      qc.invalidateQueries({ queryKey: ["admin-config-options", group] });
      qc.invalidateQueries({ queryKey: ["catalog"] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus opsi.").message),
  });

  const edit = (option: AdminConfigOption) => {
    setEditingId(option.id);
    setForm({
      key: option.key,
      label: option.label,
      meta: meta ? String(option.meta?.[meta.name] ?? "") : "",
      is_active: option.is_active,
      sort_order: option.sort_order,
    });
    setDefaults(option.meta?.defaults ? JSON.stringify(option.meta.defaults) : "");
  };

  const options = query.data ?? [];

  return (
    <div>
      <PageHeader
        title="Opsi Konfigurasi"
        description="Format turnamen, tie breaker, metode undian, babak knockout, dan tier sponsor."
        backHref="/admin"
        backLabel="Panel admin"
      />

      <div className="mb-5 inline-flex flex-wrap items-center gap-1 rounded-full border border-border bg-[var(--surface)] p-1 text-sm font-semibold">
        {GROUPS.map((g) => (
          <button
            key={g.key}
            onClick={() => {
              setGroup(g.key);
              reset();
            }}
            className={cn(
              "rounded-full px-4 py-1.5 transition-colors",
              group === g.key
                ? "bg-[var(--brand-600)] text-white"
                : "text-muted-foreground hover:text-foreground"
            )}
          >
            {g.label}
          </button>
        ))}
      </div>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
        className="mb-6 grid gap-4 rounded-xl border border-border bg-card p-5"
      >
        <h2 className="text-sm font-bold">{editingId ? "Edit opsi" : "Tambah opsi"}</h2>

        <div className="grid gap-4 md:grid-cols-4">
          <div className="grid gap-1.5">
            <Label htmlFor="label">Label</Label>
            <Input
              id="label"
              value={form.label}
              onChange={(e) => setForm({ ...form, label: e.target.value })}
              placeholder="Liga 2 Putaran"
              required
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="key">Key</Label>
            <Input
              id="key"
              value={form.key}
              onChange={(e) => setForm({ ...form, key: e.target.value })}
              placeholder="league_double"
              required
            />
          </div>

          {meta && (
            <div className="grid gap-1.5">
              <Label htmlFor="meta">{meta.label}</Label>
              {meta.from ? (
                <Select
                  id="meta"
                  value={form.meta}
                  onChange={(e) => setForm({ ...form, meta: e.target.value })}
                  required
                >
                  <option value="">— pilih —</option>
                  {engineChoices.map((choice) => (
                    <option key={choice} value={choice}>
                      {choice}
                    </option>
                  ))}
                </Select>
              ) : (
                <Input
                  id="meta"
                  type="number"
                  min={2}
                  value={form.meta}
                  onChange={(e) => setForm({ ...form, meta: e.target.value })}
                  placeholder="8"
                  required
                />
              )}
              <p className="text-xs text-muted-foreground">
                {meta.from ? "Algoritma yang menjalankannya." : "Jumlah tim di babak ini."}
              </p>
            </div>
          )}

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

        {group === "tournament_format" && (
          <div className="grid gap-1.5">
            <Label htmlFor="defaults">Default konfigurasi (JSON, opsional)</Label>
            <Input
              id="defaults"
              value={defaults}
              onChange={(e) => setDefaults(e.target.value)}
              placeholder='{"home_away": true, "legs": 2}'
              className="font-mono text-xs"
            />
            <p className="text-xs text-muted-foreground">
              Diterapkan ke event baru dengan format ini — begitulah sebuah preset dibuat.
            </p>
          </div>
        )}

        <label className="flex cursor-pointer items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="h-4 w-4 accent-[var(--brand-600)]"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
          />
          Aktif
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

      {query.isLoading ? (
        <Skeleton className="h-32 w-full rounded-xl" />
      ) : (
        <div className="grid gap-2">
          {options.map((option) => (
            <div
              key={option.id}
              className="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4 py-3"
            >
              <div className="min-w-0 flex-1">
                <p className="font-semibold">
                  {option.label}
                  {!option.is_active && (
                    <span className="ml-2 text-xs font-medium text-muted-foreground">
                      (nonaktif)
                    </span>
                  )}
                </p>
                <p className="truncate font-mono text-xs text-muted-foreground">
                  {option.key}
                  {option.meta && Object.keys(option.meta).length > 0
                    ? ` · ${JSON.stringify(option.meta)}`
                    : ""}
                </p>
              </div>
              <Button size="sm" variant="outline" onClick={() => edit(option)}>
                Edit
              </Button>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => remove.mutate(option.id)}
                aria-label={`Hapus ${option.label}`}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          ))}
          {options.length === 0 && (
            <p className="text-sm text-muted-foreground">Belum ada opsi di grup ini.</p>
          )}
        </div>
      )}
    </div>
  );
}
