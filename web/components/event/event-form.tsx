"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import {
  AlertCircle,
  CalendarDays,
  FileText,
  ImagePlus,
  Layers,
  Loader2,
  MapPin,
  Plus,
  Trash2,
  Trophy,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { format, parseISO } from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { DatePicker } from "@/components/ui/date-picker";
import { Card, CardContent } from "@/components/ui/card";
import { SectionHeader } from "@/components/event/section-header";
import { HybridConfigCard } from "@/components/event/hybrid-config-card";
import { rupiah } from "@/lib/labels";
import { TIMEZONES } from "@/lib/match-dates";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { compressToWebp } from "@/lib/image";
import { uploadImage, type EventCategoryInput, type EventInput } from "@/lib/api/events";
import type { FieldErrors } from "@/lib/api/errors";
import type { SportEvent } from "@/types/api";

/** A category being edited; `_key` is a stable local id for React lists only. */
type CategoryDraft = EventCategoryInput & { _key: string };

const newCategory = (tournament_format = ""): CategoryDraft => ({
  _key: crypto.randomUUID(),
  name: "",
  tournament_format,
  registration_fee: 0,
  max_teams: undefined,
  bracket_config: undefined,
});

/** Inline validation message shown under a field. */
function FieldError({ message }: { message?: string }) {
  if (!message) return null;
  return (
    <p className="flex items-center gap-1.5 text-xs text-destructive">
      <AlertCircle className="h-3.5 w-3.5 shrink-0" />
      {message}
    </p>
  );
}

/** Hint text under a field; rendered only when there's no error to show. */
function FieldHint({ children }: { children: React.ReactNode }) {
  return <p className="text-xs text-muted-foreground">{children}</p>;
}

/** Whole days between two YYYY-MM-DD dates, inclusive (e.g. same day = 1). */
function durationDays(start?: string | null, end?: string | null): number | null {
  if (!start || !end || end < start) return null;
  const ms = new Date(end).getTime() - new Date(start).getTime();
  if (Number.isNaN(ms)) return null;
  return Math.round(ms / 86_400_000) + 1;
}

const fmtDate = (d: string) => format(parseISO(d), "d MMM yyyy", { locale: idLocale });

/** Human date range for the preview, e.g. "12 – 14 Jun 2026". */
function formatRange(start?: string | null, end?: string | null): string {
  if (!start && !end) return "Tanggal belum diatur";
  if (start && end) return start === end ? fmtDate(start) : `${fmtDate(start)} – ${fmtDate(end)}`;
  return fmtDate((start || end)!);
}

/** A labelled row in the live preview card. */
function SummaryRow({ icon: Icon, children }: { icon: typeof Trophy; children: React.ReactNode }) {
  return (
    <div className="flex items-start gap-2.5 text-sm">
      <Icon className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
      <span className="min-w-0 text-foreground">{children}</span>
    </div>
  );
}

/**
 * Live preview of the event as it's being filled in — fills the otherwise empty
 * space beside the form and mirrors how the public event header will look.
 */
function EventSummary({
  v,
  categories,
  banner,
  days,
}: {
  v: EventInput;
  categories: CategoryDraft[];
  banner: string | null;
  days: number | null;
}) {
  const { sportLabel, sportColor: colorOf, formatLabel } = useCatalog();
  const sport = v.sport_type ?? "";
  const sportColor = colorOf(sport);

  return (
    <Card className="overflow-hidden">
      <div className="flex justify-center bg-[var(--bg-soft)] p-4">
        <div
          className="relative flex aspect-[4/5] w-36 items-center justify-center overflow-hidden rounded-md"
          style={
            banner
              ? undefined
              : { background: `linear-gradient(135deg, ${sportColor}26, ${sportColor}0d)` }
          }
        >
          {banner ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={banner} alt="Banner turnamen" className="h-full w-full object-cover" />
          ) : (
            <ImagePlus className="h-7 w-7" style={{ color: sportColor }} />
          )}
        </div>
      </div>
      <CardContent className="space-y-3 p-4">
        <span
          className="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
          style={{ color: sportColor, background: `${sportColor}1f` }}
        >
          {sportLabel(sport)}
        </span>
        <h3 className="text-lg font-bold leading-snug" style={{ fontFamily: "var(--font-display)" }}>
          {v.name?.trim() || "Nama event"}
        </h3>
        <div className="space-y-2 border-t border-border pt-3">
          <SummaryRow icon={CalendarDays}>
            {formatRange(v.start_date, v.end_date)}
            {days ? <span className="text-muted-foreground"> · {days} hari</span> : null}
          </SummaryRow>
          <SummaryRow icon={MapPin}>
            {v.location_name?.trim() || <span className="text-muted-foreground">Lokasi belum diatur</span>}
          </SummaryRow>
        </div>
        <div className="space-y-2 border-t border-border pt-3">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            {categories.length} kategori
          </p>
          {categories.map((c) => {
            const free = !c.registration_fee || c.registration_fee <= 0;
            return (
              <div key={c._key} className="rounded-md bg-[var(--bg-soft)] px-2.5 py-2 text-sm">
                <p className="font-semibold">{c.name?.trim() || "Kategori tanpa nama"}</p>
                <p className="text-xs text-muted-foreground">
                  {formatLabel(c.tournament_format)} · {free ? "Gratis" : rupiah(c.registration_fee ?? 0)}
                  {c.max_teams ? ` · maks. ${c.max_teams} tim` : ""}
                </p>
              </div>
            );
          })}
        </div>
      </CardContent>
    </Card>
  );
}

/** A single category's inline editor: name, format, optional hybrid config, cap & fee. */
function CategoryEditor({
  cat,
  index,
  canRemove,
  isHybrid,
  nameError,
  onChange,
  onRemove,
}: {
  cat: CategoryDraft;
  index: number;
  canRemove: boolean;
  isHybrid: boolean;
  nameError?: string;
  onChange: (patch: Partial<CategoryDraft>) => void;
  onRemove: () => void;
}) {
  const { tournament_formats } = useCatalog();
  const free = !cat.registration_fee || cat.registration_fee <= 0;

  return (
    <div className="grid gap-4 rounded-lg border border-border p-3 sm:p-4">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm font-semibold text-muted-foreground">Kategori {index + 1}</p>
        {canRemove && (
          <Button
            type="button"
            size="sm"
            variant="ghost"
            className="h-7 gap-1.5 px-2 text-muted-foreground hover:text-destructive"
            onClick={onRemove}
          >
            <Trash2 className="h-3.5 w-3.5" />
            Hapus
          </Button>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="grid gap-2">
          <Label className="font-semibold">Nama kategori</Label>
          <Input
            value={cat.name ?? ""}
            onChange={(e) => onChange({ name: e.target.value })}
            placeholder="U-17 / Woman / Senior"
            aria-invalid={!!nameError}
            className={nameError ? "border-destructive focus-visible:ring-destructive" : ""}
          />
          {nameError ? (
            <FieldError message={nameError} />
          ) : (
            <FieldHint>Mis. kelompok umur atau divisi.</FieldHint>
          )}
        </div>
        <div className="grid gap-2">
          <Label className="font-semibold">Format</Label>
          <Select
            value={cat.tournament_format}
            onChange={(e) => onChange({ tournament_format: e.target.value })}
          >
            {tournament_formats.map((f) => (
              <option key={f.key} value={f.key}>
                {f.label}
              </option>
            ))}
          </Select>
        </div>
      </div>

      {isHybrid && (
        <HybridConfigCard
          value={cat.bracket_config}
          onChange={(config) => onChange({ bracket_config: config })}
        />
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="grid gap-2">
          <Label className="font-semibold">Maks. tim</Label>
          <Input
            type="number"
            min={2}
            value={cat.max_teams ?? ""}
            onChange={(e) => onChange({ max_teams: e.target.value ? Number(e.target.value) : undefined })}
            placeholder="Tidak dibatasi"
          />
          <FieldHint>Kosongkan untuk peserta tak terbatas.</FieldHint>
        </div>
        <div className="grid gap-2">
          <div className="flex items-center justify-between gap-2">
            <Label className="font-semibold">Biaya registrasi</Label>
            <label className="flex cursor-pointer items-center gap-1.5 text-xs font-medium text-muted-foreground">
              <input
                type="checkbox"
                className="h-3.5 w-3.5 accent-[var(--brand-600)]"
                checked={free}
                onChange={(e) => onChange({ registration_fee: e.target.checked ? 0 : 1 })}
              />
              Gratis
            </label>
          </div>
          <div className="relative">
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
              Rp
            </span>
            <Input
              type="number"
              min={0}
              value={free ? "" : cat.registration_fee}
              disabled={free}
              placeholder="0"
              onChange={(e) => onChange({ registration_fee: e.target.value ? Number(e.target.value) : 0 })}
              className="pl-9"
            />
          </div>
          <FieldHint>{free ? "Gratis untuk peserta." : rupiah(cat.registration_fee ?? 0)}</FieldHint>
        </div>
      </div>
    </div>
  );
}

export function EventForm({
  initial,
  submitLabel,
  onSubmit,
  pending,
  fieldErrors,
  cancelHref,
}: {
  initial?: Partial<SportEvent>;
  submitLabel: string;
  onSubmit: (values: EventInput) => void;
  pending?: boolean;
  /** Server-side validation errors (Laravel 422), keyed by field name. */
  fieldErrors?: FieldErrors;
  /** When set, renders a "Batal" link in the sticky footer. */
  cancelHref?: string;
}) {
  const { sports, tournament_formats } = useCatalog();

  const [v, setV] = useState<EventInput>({
    name: initial?.name ?? "",
    // Empty until the catalog arrives; the first sport then becomes the default,
    // so the form works no matter what the admin has configured.
    sport_type: initial?.sport_type ?? "",
    start_date: initial?.start_date ?? "",
    end_date: initial?.end_date ?? "",
    timezone: initial?.timezone ?? "Asia/Jakarta",
    location_name: initial?.location_name ?? "",
    location_address: initial?.location_address ?? "",
    description: initial?.description ?? "",
    banner_url: initial?.banner_url ?? "",
  });

  // Each event runs one-or-more categories; a new event starts with one blank.
  const [categories, setCategories] = useState<CategoryDraft[]>(() =>
    initial?.categories?.length
      ? initial.categories.map((c) => ({
          _key: c.id,
          id: c.id,
          name: c.name,
          tournament_format: c.tournament_format,
          registration_fee: c.registration_fee,
          max_teams: c.max_teams ?? undefined,
          bracket_config: c.bracket_config ?? undefined,
        }))
      : [newCategory()]
  );

  // Local object URL for instant preview; in dev R2 returns a non-renderable
  // `mock://` URL, so we keep the local blob to show the image either way.
  const [bannerPreview, setBannerPreview] = useState<string | null>(null);
  const [bannerUploading, setBannerUploading] = useState(false);
  const bannerInputRef = useRef<HTMLInputElement>(null);

  // Client-side overrides: a key present here wins over the server error for
  // that field (`undefined`/"" = no error). Lets us validate instantly and
  // clear a stale server error the moment the user edits the field.
  const [clientErrors, setClientErrors] = useState<Record<string, string | undefined>>({});
  // Per-category name errors, keyed by the category's local `_key`.
  const [catErrors, setCatErrors] = useState<Record<string, string | undefined>>({});

  // No-gambling attestation. An existing event was already allowed, so editing
  // starts pre-attested; a brand-new event must tick it explicitly.
  const [attested, setAttested] = useState(!!initial);
  const [attestError, setAttestError] = useState<string>();

  const errorFor = (k: keyof EventInput): string | undefined =>
    (k in clientErrors ? clientErrors[k as string] : fieldErrors?.[k as string]) || undefined;

  const set = <K extends keyof EventInput>(k: K, val: EventInput[K]) => {
    setV((s) => ({ ...s, [k]: val }));
    setClientErrors((e) => ({ ...e, [k as string]: undefined }));
  };

  // The engine a format runs on — the hybrid card belongs to any format on the
  // hybrid engine, whatever the admin named it.
  const engineOf = (key?: string) =>
    tournament_formats.find((f) => f.key === key)?.meta?.engine as string | undefined;

  const updateCat = (key: string, patch: Partial<CategoryDraft>) => {
    setCategories((cs) => cs.map((c) => (c._key === key ? { ...c, ...patch } : c)));
    if ("name" in patch) setCatErrors((e) => ({ ...e, [key]: undefined }));
  };
  const addCat = () =>
    setCategories((cs) => [...cs, newCategory(tournament_formats[0]?.key ?? "")]);
  const removeCat = (key: string) =>
    setCategories((cs) => (cs.length > 1 ? cs.filter((c) => c._key !== key) : cs));

  // Red border + ring for an invalid field.
  const invalidCls = (k: keyof EventInput) =>
    errorFor(k) ? "border-destructive focus-visible:ring-destructive" : "";

  // Image to render: local blob first, else a stored http(s) URL (mock:// won't render).
  const bannerShown =
    bannerPreview ?? (v.banner_url && /^https?:\/\//.test(v.banner_url) ? v.banner_url : null);

  const uploadBanner = async (file?: File | null) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      toast.error("File harus berupa gambar.");
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast.error("Ukuran gambar maksimal 5 MB.");
      return;
    }
    setBannerUploading(true);
    try {
      // Compress + convert to WebP client-side, then store and keep the real URL.
      const webp = await compressToWebp(file, { maxDim: 1280, quality: 0.8 });
      setBannerPreview(URL.createObjectURL(webp));
      set("banner_url", await uploadImage(webp, "events"));
    } catch {
      toast.error("Gagal mengunggah gambar. Coba lagi.");
      setBannerPreview(null);
    } finally {
      setBannerUploading(false);
    }
  };

  const clearBanner = () => {
    setBannerPreview(null);
    set("banner_url", "");
    if (bannerInputRef.current) bannerInputRef.current.value = "";
  };

  // Fall back to the first catalog entry until the user picks one.
  const sportValue = v.sport_type || sports[0]?.slug || "";
  const fallbackFormat = tournament_formats[0]?.key ?? "";

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const next: Record<string, string | undefined> = {
      name: v.name?.trim() ? undefined : "Nama event wajib diisi.",
      start_date: v.start_date ? undefined : "Tanggal mulai wajib diisi.",
      end_date: !v.end_date
        ? "Tanggal selesai wajib diisi."
        : v.start_date && v.end_date < v.start_date
          ? "Tanggal selesai harus sama dengan atau setelah tanggal mulai."
          : undefined,
    };
    setClientErrors(next);

    const catNext: Record<string, string | undefined> = {};
    for (const c of categories) {
      if (!c.name?.trim()) catNext[c._key] = "Nama kategori wajib diisi.";
    }
    setCatErrors(catNext);

    const attestBad = !attested;
    setAttestError(attestBad ? "Kamu harus menyetujui pernyataan ini untuk melanjutkan." : undefined);

    if (next.name || next.start_date || next.end_date || Object.keys(catNext).length > 0 || attestBad) {
      if (Object.keys(catNext).length > 0 && !next.name && !next.start_date && !next.end_date) {
        toast.error("Lengkapi nama setiap kategori.");
      }
      document.querySelector<HTMLElement>("[aria-invalid='true']")?.focus();
      return;
    }

    const cleanedCategories: EventCategoryInput[] = categories.map((c) => ({
      id: c.id,
      name: c.name!.trim(),
      tournament_format: c.tournament_format || fallbackFormat,
      registration_fee: c.registration_fee ?? 0,
      max_teams: c.max_teams ?? null,
      bracket_config: c.bracket_config ?? null,
    }));

    onSubmit({ ...v, sport_type: sportValue, categories: cleanedCategories });
  };

  const days = durationDays(v.start_date, v.end_date);

  return (
    <form onSubmit={handleSubmit} className="grid gap-6">
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
        <div className="grid min-w-0 gap-6">
      <Card>
        <SectionHeader
          icon={Trophy}
          title="Detail Event"
          description="Informasi dasar turnamen."
        />
        <CardContent className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="name" className="font-semibold">
              Nama event
            </Label>
            <Input
              id="name"
              value={v.name ?? ""}
              onChange={(e) => set("name", e.target.value)}
              placeholder="Liga Komunitas Jakarta 2026"
              aria-invalid={!!errorFor("name")}
              className={invalidCls("name")}
            />
            {errorFor("name") ? (
              <FieldError message={errorFor("name")} />
            ) : (
              <FieldHint>Tampil sebagai judul di halaman publik event.</FieldHint>
            )}
          </div>

          <div className="grid gap-2 sm:max-w-[50%] sm:pr-2">
            <Label htmlFor="sport" className="font-semibold">
              Cabang olahraga
            </Label>
            <Select
              id="sport"
              value={sportValue}
              onChange={(e) => set("sport_type", e.target.value as EventInput["sport_type"])}
            >
              {sports.map((s) => (
                <option key={s.slug} value={s.slug}>
                  {s.name}
                </option>
              ))}
            </Select>
            <FieldHint>Semua kategori memakai cabang olahraga yang sama.</FieldHint>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="start" className="font-semibold">
                Tanggal mulai
              </Label>
              <DatePicker
                id="start"
                value={v.start_date ?? ""}
                onChange={(val) => set("start_date", val)}
                placeholder="Pilih tanggal mulai"
                aria-invalid={!!errorFor("start_date")}
              />
              <FieldError message={errorFor("start_date")} />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="end" className="font-semibold">
                Tanggal selesai
              </Label>
              <DatePicker
                id="end"
                value={v.end_date ?? ""}
                min={v.start_date || undefined}
                onChange={(val) => set("end_date", val)}
                placeholder="Pilih tanggal selesai"
                aria-invalid={!!errorFor("end_date")}
              />
              {errorFor("end_date") ? (
                <FieldError message={errorFor("end_date")} />
              ) : (
                days && <FieldHint>Berlangsung {days} hari.</FieldHint>
              )}
            </div>
          </div>

          <div className="grid gap-2 sm:max-w-[50%] sm:pr-2">
            <Label htmlFor="timezone" className="font-semibold">
              Zona waktu
            </Label>
            <Select
              id="timezone"
              value={v.timezone ?? "Asia/Jakarta"}
              onChange={(e) => set("timezone", e.target.value)}
            >
              {TIMEZONES.map((z) => (
                <option key={z.value} value={z.value}>
                  {z.label}
                </option>
              ))}
            </Select>
            <FieldHint>
              Zona waktu lokasi pertandingan. Jam yang kamu isi di jadwal berarti jam setempat, dan
              penonton di zona mana pun akan melihat jam yang sama.
            </FieldHint>
          </div>
        </CardContent>
      </Card>

      <Card>
        <SectionHeader
          icon={Layers}
          title="Kategori Kompetisi"
          description="Tiap kategori (mis. U-17, Woman) punya format & biaya registrasi sendiri."
        />
        <CardContent className="grid gap-4">
          {categories.map((c, i) => (
            <CategoryEditor
              key={c._key}
              cat={c}
              index={i}
              canRemove={categories.length > 1}
              isHybrid={engineOf(c.tournament_format) === "hybrid"}
              nameError={catErrors[c._key]}
              onChange={(patch) => updateCat(c._key, patch)}
              onRemove={() => removeCat(c._key)}
            />
          ))}
          <Button type="button" variant="outline" className="gap-2" onClick={addCat}>
            <Plus className="h-4 w-4" />
            Tambah kategori
          </Button>
        </CardContent>
      </Card>

      <Card>
        <SectionHeader
          icon={MapPin}
          title="Lokasi"
          description="Tempat turnamen berlangsung."
        />
        <CardContent className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="loc" className="font-semibold">
              Lokasi
            </Label>
            <Input
              id="loc"
              value={v.location_name ?? ""}
              onChange={(e) => set("location_name", e.target.value)}
              placeholder="GBK Soccer Field"
              aria-invalid={!!errorFor("location_name")}
              className={invalidCls("location_name")}
            />
            <FieldError message={errorFor("location_name")} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <SectionHeader
          icon={ImagePlus}
          title="Gambar Turnamen"
          description="Banner yang tampil di halaman publik event."
        />
        <CardContent>
          <input
            ref={bannerInputRef}
            type="file"
            accept="image/*"
            className="hidden"
            onChange={(e) => uploadBanner(e.target.files?.[0])}
          />
          <div className="max-w-[260px]">
          {bannerShown ? (
            <div className="group relative overflow-hidden rounded-lg border border-border">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={bannerShown} alt="Banner turnamen" className="aspect-[4/5] w-full object-cover" />
              {bannerUploading && (
                <div className="absolute inset-0 grid place-items-center bg-black/40">
                  <Loader2 className="h-6 w-6 animate-spin text-white" />
                </div>
              )}
              <div className="absolute right-2 top-2 flex gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className="bg-background/90"
                  onClick={() => bannerInputRef.current?.click()}
                >
                  Ganti
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className="bg-background/90"
                  onClick={clearBanner}
                  aria-label="Hapus gambar"
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>
            </div>
          ) : (
            <button
              type="button"
              onClick={() => bannerInputRef.current?.click()}
              disabled={bannerUploading}
              className="flex aspect-[4/5] w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border bg-[var(--bg-soft)] text-muted-foreground transition-colors hover:border-[var(--brand-500)] hover:text-foreground disabled:opacity-60"
            >
              {bannerUploading ? (
                <Loader2 className="h-6 w-6 animate-spin" />
              ) : (
                <ImagePlus className="h-6 w-6" />
              )}
              <span className="text-sm font-medium">
                {bannerUploading ? "Mengunggah…" : "Unggah gambar turnamen"}
              </span>
              <span className="text-xs">PNG / JPG, maks 5 MB · rasio 4:5 disarankan</span>
            </button>
          )}
          </div>
        </CardContent>
      </Card>

      <Card>
        <SectionHeader
          icon={FileText}
          title="Deskripsi"
          description="Ceritakan turnamenmu ke calon peserta."
        />
        <CardContent>
          <div className="grid gap-2">
            <Label htmlFor="desc" className="font-semibold">
              Tentang event
            </Label>
            <Textarea
              id="desc"
              value={v.description ?? ""}
              onChange={(e) => set("description", e.target.value)}
              placeholder="Jelaskan turnamen, syarat peserta, hadiah, dan informasi penting lainnya."
              aria-invalid={!!errorFor("description")}
              className={invalidCls("description")}
            />
            <FieldError message={errorFor("description")} />
          </div>
        </CardContent>
      </Card>
        </div>

        <aside className="lg:sticky lg:top-20">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Pratinjau
          </p>
          <EventSummary v={v} categories={categories} banner={bannerShown} days={days} />
        </aside>
      </div>

      <div className="rounded-xl border border-border p-4">
        <label className="flex cursor-pointer items-start gap-2.5 text-sm">
          <input
            type="checkbox"
            aria-label="Pernyataan tanpa perjudian"
            className="mt-0.5 h-4 w-4 shrink-0 accent-[var(--brand-600)]"
            checked={attested}
            onChange={(e) => {
              setAttested(e.target.checked);
              if (e.target.checked) setAttestError(undefined);
            }}
          />
          <span className="leading-relaxed text-muted-foreground">
            Saya menyatakan event ini <strong className="text-foreground">tidak mengandung unsur perjudian</strong>{" "}
            dalam bentuk apa pun, termasuk hadiah yang bersumber dari akumulasi biaya pendaftaran peserta, dan
            saya menyetujui{" "}
            <Link
              href="/ketentuan"
              target="_blank"
              className="font-medium text-[var(--brand-600)] underline"
            >
              Ketentuan Layanan
            </Link>
            .
          </span>
        </label>
        <FieldError message={attestError} />
      </div>

      <div className="sticky bottom-0 flex items-center justify-end gap-3 rounded-xl border border-border bg-background/80 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/60">
        {cancelHref && (
          <Button asChild variant="ghost" size="lg">
            <Link href={cancelHref}>Batal</Link>
          </Button>
        )}
        <Button type="submit" size="lg" disabled={pending}>
          {pending ? "Menyimpan…" : submitLabel}
        </Button>
      </div>
    </form>
  );
}
