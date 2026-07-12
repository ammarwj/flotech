"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import {
  AlertCircle,
  CalendarDays,
  FileText,
  ImagePlus,
  Loader2,
  MapPin,
  Network,
  Trophy,
  Users,
  Wallet,
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
import { useCatalog } from "@/lib/hooks/use-catalog";
import { hybridConfig, qualifierCount } from "@/lib/hybrid";
import { compressToWebp } from "@/lib/image";
import { uploadImage, type EventInput } from "@/lib/api/events";
import type { FieldErrors } from "@/lib/api/errors";
import type { SportEvent } from "@/types/api";

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
  banner,
  days,
  isHybrid,
}: {
  v: EventInput;
  banner: string | null;
  days: number | null;
  /** The chosen format runs on the hybrid engine. */
  isHybrid: boolean;
}) {
  const { sportLabel, sportColor: colorOf, formatLabel } = useCatalog();
  const sport = v.sport_type ?? "";
  const sportColor = colorOf(sport);
  const isFree = !v.registration_fee || v.registration_fee <= 0;
  const hybrid = isHybrid ? hybridConfig(v.bracket_config) : null;

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
        <div className="flex flex-wrap items-center gap-2 text-xs font-semibold">
          <span
            className="rounded-full px-2 py-0.5"
            style={{ color: sportColor, background: `${sportColor}1f` }}
          >
            {sportLabel(sport)}
          </span>
          <span className="text-muted-foreground">{formatLabel(v.tournament_format)}</span>
        </div>
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
          <SummaryRow icon={Wallet}>
            {isFree ? "Gratis untuk peserta" : rupiah(v.registration_fee ?? 0)}
          </SummaryRow>
          <SummaryRow icon={Users}>
            {v.max_teams ? `Maks. ${v.max_teams} tim` : "Tim tak terbatas"}
          </SummaryRow>
          {hybrid && (
            <SummaryRow icon={Network}>
              {hybrid.groups} grup × {hybrid.teams_per_group} tim ·{" "}
              {qualifierCount(hybrid)} lolos ke knockout
            </SummaryRow>
          )}
        </div>
      </CardContent>
    </Card>
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
    // Empty until the catalog arrives; the first sport/format then becomes the
    // default, so the form works no matter what the admin has configured.
    sport_type: initial?.sport_type ?? "",
    tournament_format: initial?.tournament_format ?? "",
    start_date: initial?.start_date ?? "",
    end_date: initial?.end_date ?? "",
    location_name: initial?.location_name ?? "",
    location_address: initial?.location_address ?? "",
    description: initial?.description ?? "",
    banner_url: initial?.banner_url ?? "",
    max_teams: initial?.max_teams ?? undefined,
    registration_fee: initial?.registration_fee ?? 0,
    bracket_config: initial?.bracket_config ?? undefined,
  });

  // Local object URL for instant preview; in dev R2 returns a non-renderable
  // `mock://` URL, so we keep the local blob to show the image either way.
  const [bannerPreview, setBannerPreview] = useState<string | null>(null);
  const [bannerUploading, setBannerUploading] = useState(false);
  const bannerInputRef = useRef<HTMLInputElement>(null);

  // Client-side overrides: a key present here wins over the server error for
  // that field (`undefined`/"" = no error). Lets us validate instantly and
  // clear a stale server error the moment the user edits the field.
  const [clientErrors, setClientErrors] = useState<Record<string, string | undefined>>({});

  const errorFor = (k: keyof EventInput): string | undefined =>
    (k in clientErrors ? clientErrors[k as string] : fieldErrors?.[k as string]) || undefined;

  const set = <K extends keyof EventInput>(k: K, val: EventInput[K]) => {
    setV((s) => ({ ...s, [k]: val }));
    setClientErrors((e) => ({ ...e, [k as string]: undefined }));
  };

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

    if (next.name || next.start_date || next.end_date) {
      // Surface the first error to the user.
      document.querySelector<HTMLElement>("[aria-invalid='true']")?.focus();
      return;
    }

    onSubmit({ ...v, sport_type: sportValue, tournament_format: formatValue });
  };

  // Fall back to the first catalog entry until the user picks one.
  const sportValue = v.sport_type || sports[0]?.slug || "";
  const formatValue = v.tournament_format || tournament_formats[0]?.key || "";

  // A format is a preset over an engine — the hybrid card belongs to any format
  // that runs on the hybrid engine, whatever the admin named it.
  const isHybrid =
    tournament_formats.find((f) => f.key === formatValue)?.meta?.engine === "hybrid";

  const days = durationDays(v.start_date, v.end_date);
  const isFree = !v.registration_fee || v.registration_fee <= 0;

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

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
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
            </div>
            <div className="grid gap-2">
              <Label htmlFor="format" className="font-semibold">
                Format
              </Label>
              <Select
                id="format"
                value={formatValue}
                onChange={(e) => set("tournament_format", e.target.value as EventInput["tournament_format"])}
              >
                {tournament_formats.map((f) => (
                  <option key={f.key} value={f.key}>
                    {f.label}
                  </option>
                ))}
              </Select>
            </div>
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
        </CardContent>
      </Card>

      {isHybrid && (
        <HybridConfigCard
          value={v.bracket_config}
          onChange={(config) => set("bracket_config", config)}
        />
      )}

      <Card>
        <SectionHeader
          icon={MapPin}
          title="Lokasi & Pendaftaran"
          description="Tempat berlangsung dan ketentuan pendaftaran tim."
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

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="max" className="font-semibold">
                Maks. tim
              </Label>
              <Input
                id="max"
                type="number"
                min={2}
                value={v.max_teams ?? ""}
                onChange={(e) => set("max_teams", e.target.value ? Number(e.target.value) : undefined)}
                placeholder="Tidak dibatasi"
                aria-invalid={!!errorFor("max_teams")}
                className={invalidCls("max_teams")}
              />
              {errorFor("max_teams") ? (
                <FieldError message={errorFor("max_teams")} />
              ) : (
                <FieldHint>Kosongkan untuk peserta tak terbatas.</FieldHint>
              )}
            </div>
            <div className="grid gap-2">
              <div className="flex items-center justify-between gap-2">
                <Label htmlFor="fee" className="font-semibold">
                  Biaya registrasi
                </Label>
                <label className="flex cursor-pointer items-center gap-1.5 text-xs font-medium text-muted-foreground">
                  <input
                    type="checkbox"
                    className="h-3.5 w-3.5 accent-[var(--brand-600)]"
                    checked={isFree}
                    onChange={(e) => set("registration_fee", e.target.checked ? 0 : 1)}
                  />
                  Gratis
                </label>
              </div>
              <div className="relative">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
                  Rp
                </span>
                <Input
                  id="fee"
                  type="number"
                  min={0}
                  value={isFree ? "" : v.registration_fee}
                  disabled={isFree}
                  placeholder="0"
                  onChange={(e) => set("registration_fee", e.target.value ? Number(e.target.value) : 0)}
                  aria-invalid={!!errorFor("registration_fee")}
                  className={`pl-9 ${invalidCls("registration_fee")}`}
                />
              </div>
              {errorFor("registration_fee") ? (
                <FieldError message={errorFor("registration_fee")} />
              ) : (
                <FieldHint>
                  {isFree ? "Gratis untuk peserta." : rupiah(v.registration_fee ?? 0)}
                </FieldHint>
              )}
            </div>
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
          <EventSummary v={v} banner={bannerShown} days={days} isHybrid={isHybrid} />
        </aside>
      </div>

      <div className="sticky bottom-0 -mx-1 flex items-center justify-end gap-3 rounded-xl border border-border bg-background/80 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/60">
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
