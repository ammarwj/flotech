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
  RectangleVertical,
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
import { Switch } from "@/components/ui/switch";
import { DatePicker } from "@/components/ui/date-picker";
import { Card, CardContent } from "@/components/ui/card";
import { InfoHint } from "@/components/ui/info-hint";
import { SectionHeader } from "@/components/event/section-header";
import { HybridConfigCard } from "@/components/event/hybrid-config-card";
import { rupiah } from "@/lib/labels";
import { TIMEZONES } from "@/lib/match-dates";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { disciplineStatDefs, tracksDiscipline } from "@/lib/scoring";
import { compressToWebp } from "@/lib/image";
import { uploadImage, type EventCategoryInput, type EventInput } from "@/lib/api/events";
import type { FieldErrors } from "@/lib/api/errors";
import { participantLabel, participantModes, standingsContextOf } from "@/lib/scoring";
import { RubberFormatEditor } from "@/components/event/rubber-format-editor";
import type { ParticipantType, PlanSummary, SportDef, SportEvent } from "@/types/api";

/** A category being edited; `_key` is a stable local id for React lists only. */
type CategoryDraft = EventCategoryInput & {
  /** Stable local id for React lists only. */
  _key: string;
  /** Entrants already registered, when this category exists server-side. */
  _teamsCount?: number;
};

const newCategory = (tournament_format = ""): CategoryDraft => ({
  _key: crypto.randomUUID(),
  name: "",
  // A squad is what a category has always been; racket sports opt into the rest.
  participant_type: "team",
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
  isSingleElim,
  sport,
  teamsCap,
  locked,
  nameError,
  onChange,
  onRemove,
}: {
  cat: CategoryDraft;
  index: number;
  canRemove: boolean;
  isHybrid: boolean;
  /** The event's sport — decides the entrant shapes and the standings shape. */
  sport: SportDef | undefined;
  /** The plan's per-category entrant cap; null when it sets none. */
  teamsCap: number | null;
  /** Entrants already registered — the shape can no longer change. */
  locked: boolean;
  /** Single elimination has no config card, but it can still play for third. */
  isSingleElim: boolean;
  nameError?: string;
  onChange: (patch: Partial<CategoryDraft>) => void;
  onRemove: () => void;
}) {
  const { tournament_formats } = useCatalog();
  const free = !cat.registration_fee || cat.registration_fee <= 0;
  const modes = participantModes(sport);
  // The category isn't saved yet, so the server hasn't derived this for us.
  const context = standingsContextOf(cat, sport);

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

      {/* Only worth asking when there's a choice: most sports field a squad and
          nothing else, so the row would be a dropdown with one option. */}
      {modes.length > 1 && (
        <div className="grid gap-2 sm:max-w-[calc(50%-0.5rem)]">
          <Label className="font-semibold">Jenis peserta</Label>
          <Select
            value={cat.participant_type ?? "team"}
            disabled={locked}
            onChange={(e) => {
              const participant_type = e.target.value as ParticipantType;
              // A template only means something for a squad tie; drop it when
              // the category stops being one, matching what the backend stores.
              onChange({
                participant_type,
                rubber_format: participant_type === "team" ? cat.rubber_format : null,
              });
            }}
          >
            {modes.map((m) => (
              <option key={m} value={m}>
                {participantLabel(m)}
              </option>
            ))}
          </Select>
          <FieldHint>
            {locked
              ? "Tidak bisa diubah — sudah ada peserta terdaftar."
              : "Tunggal = 1 pemain, Ganda = 2 pemain, Tim = beregu."}
          </FieldHint>
        </div>
      )}

      {modes.includes("single") && (cat.participant_type ?? "team") === "team" && (
        <RubberFormatEditor
          value={cat.rubber_format}
          onChange={(rubber_format) => onChange({ rubber_format })}
        />
      )}

      {isHybrid && (
        <HybridConfigCard
          value={cat.bracket_config}
          context={context}
          onChange={(config) => onChange({ bracket_config: config })}
        />
      )}

      {/* The only bracket setting single elimination has, so it gets a lone
          checkbox rather than a card of its own. */}
      {isSingleElim && (
        <label className="flex cursor-pointer items-start gap-2 text-sm">
          <input
            type="checkbox"
            className="mt-0.5 h-4 w-4 accent-[var(--brand-600)]"
            checked={cat.bracket_config?.third_place ?? false}
            onChange={(e) =>
              onChange({ bracket_config: { ...cat.bracket_config, third_place: e.target.checked } })
            }
          />
          <span>
            <span className="font-medium">Perebutan juara 3</span>
            <span className="block text-xs text-muted-foreground">
              Dua tim yang kalah di semifinal bermain sekali lagi.
            </span>
          </span>
        </label>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="grid gap-2">
          <Label className="font-semibold">Maks. tim</Label>
          <Input
            type="number"
            min={2}
            max={teamsCap ?? undefined}
            value={cat.max_teams ?? ""}
            onChange={(e) => onChange({ max_teams: e.target.value ? Number(e.target.value) : undefined })}
            placeholder={teamsCap !== null ? `Maks ${teamsCap}` : "Tidak dibatasi"}
          />
          <FieldHint>
            {teamsCap !== null
              ? `Paket event ini membatasi ${teamsCap} peserta per kategori.`
              : "Kosongkan untuk peserta tak terbatas."}
          </FieldHint>
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
  plan,
}: {
  initial?: Partial<SportEvent>;
  submitLabel: string;
  onSubmit: (values: EventInput) => void;
  pending?: boolean;
  /** Server-side validation errors (Laravel 422), keyed by field name. */
  fieldErrors?: FieldErrors;
  /** When set, renders a "Batal" link in the sticky footer. */
  cancelHref?: string;
  /**
   * The plan this event runs on — from the credit being spent when creating, or
   * from the event itself when editing. Its caps are applied proactively: the
   * backend refuses the same things, but meeting a limit only after filling in
   * the whole form is a bad way to learn about it.
   */
  plan?: PlanSummary;
}) {
  const { sports, tournament_formats } = useCatalog();

  // -1 (and an absent key) means unlimited, matching PlanGate.
  const capOf = (key: string): number | null => {
    const raw = plan?.features?.[key];
    if (raw === undefined) return null;
    const n = Number(raw);
    return Number.isNaN(n) || n < 0 ? null : n;
  };
  const categoryCap = capOf("max_categories");
  const teamsCap = capOf("max_teams_per_category");

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
    courts: initial?.courts ?? [],
    description: initial?.description ?? "",
    banner_url: initial?.banner_url ?? "",
  });

  // Card thresholds, held as strings so a cleared field stays cleared. Empty
  // means "follow the sport default" all the way to the server — see
  // DisciplineRules, which strips nulls before merging its three layers.
  const [discipline, setDiscipline] = useState(() => {
    const d = initial?.rules_config?.discipline;
    return {
      yellow_threshold: d?.yellow_threshold?.toString() ?? "",
      yellow_ban_matches: d?.yellow_ban_matches?.toString() ?? "",
      red_ban_matches: d?.red_ban_matches?.toString() ?? "",
      yellows_per_expulsion: d?.yellows_per_expulsion?.toString() ?? "",
      expulsion_ban_matches: d?.expulsion_ban_matches?.toString() ?? "",
      reset_yellow_on_knockout: d?.reset_yellow_on_knockout ?? false,
    };
  });

  // Each event runs one-or-more categories; a new event starts with one blank.
  const [categories, setCategories] = useState<CategoryDraft[]>(() =>
    initial?.categories?.length
      ? initial.categories.map((c) => ({
          _key: c.id,
          id: c.id,
          name: c.name,
          participant_type: c.participant_type ?? "team",
          rubber_format: c.rubber_format,
          tournament_format: c.tournament_format,
          registration_fee: c.registration_fee,
          max_teams: c.max_teams ?? undefined,
          bracket_config: c.bracket_config ?? undefined,
          // Only known for a saved category, and only used to lock the shape.
          _teamsCount: c.teams_count,
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
  const selectedSport = sports.find((s) => s.slug === sportValue) ?? null;
  // What this sport's cards are actually called, and what it defaults to —
  // both shown as placeholders so a blank field reads as "inherited", not "0".
  const sportCards = disciplineStatDefs(selectedSport);
  const sportDiscipline = selectedSport?.discipline_config ?? {};
  // Lowercased card names for the explanation panels, which read as prose. The
  // sport owns its labels, so a cabang that calls them something else says so
  // everywhere instead of only in the column headings.
  const yellowWord = (sportCards.yellow?.label ?? "kartu kuning").toLowerCase();
  const redWord = (sportCards.red?.label ?? "kartu merah").toLowerCase();

  /** Blank = inherit, so it must leave as an absent key rather than a null. */
  const num = (raw: string): number | undefined => {
    const n = Number(raw.trim());
    return raw.trim() === "" || Number.isNaN(n) ? undefined : n;
  };

  const cleanedDiscipline = {
    yellow_threshold: num(discipline.yellow_threshold),
    yellow_ban_matches: num(discipline.yellow_ban_matches),
    red_ban_matches: num(discipline.red_ban_matches),
    yellows_per_expulsion: num(discipline.yellows_per_expulsion),
    expulsion_ban_matches: num(discipline.expulsion_ban_matches),
    reset_yellow_on_knockout: discipline.reset_yellow_on_knockout,
  };
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
      participant_type: c.participant_type ?? "team",
      // Drop blank template rows rather than posting partai with no name.
      rubber_format: c.rubber_format?.filter((r) => r.label.trim()) ?? null,
      tournament_format: c.tournament_format || fallbackFormat,
      registration_fee: c.registration_fee ?? 0,
      max_teams: c.max_teams ?? null,
      bracket_config: c.bracket_config ?? null,
    }));

    // Drop blank court rows so an empty input never becomes a nameless court.
    const cleanedCourts = (v.courts ?? []).map((c) => c.trim()).filter(Boolean);

    onSubmit({
      ...v,
      sport_type: sportValue,
      courts: cleanedCourts,
      categories: cleanedCategories,
      // Only sent for a sport that books players. A saved rulebook on an event
      // switched to a cardless sport stays where it is, dormant, and comes back
      // if the sport does — silently deleting it would be a surprise.
      ...(tracksDiscipline(selectedSport) ? { rules_config: { discipline: cleanedDiscipline } } : {}),
    });
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
              isSingleElim={engineOf(c.tournament_format) === "knockout_single"}
              sport={sports.find((s) => s.slug === sportValue)}
              teamsCap={teamsCap}
              locked={(c._teamsCount ?? 0) > 0}
              nameError={catErrors[c._key]}
              onChange={(patch) => updateCat(c._key, patch)}
              onRemove={() => removeCat(c._key)}
            />
          ))}
          <Button
            type="button"
            variant="outline"
            className="gap-2"
            disabled={categoryCap !== null && categories.length >= categoryCap}
            onClick={addCat}
          >
            <Plus className="h-4 w-4" />
            Tambah kategori
          </Button>
          {plan && (categoryCap !== null || teamsCap !== null) && (
            <p className="text-xs text-muted-foreground">
              Paket {plan.name}:{" "}
              {categoryCap !== null ? `maks ${categoryCap} kategori` : "kategori tanpa batas"},{" "}
              {teamsCap !== null
                ? `${teamsCap} peserta per kategori`
                : "peserta tanpa batas"}
              .
            </p>
          )}
        </CardContent>
      </Card>

      {/* Only for a sport that books players. Volleyball and basketball field
        squads and keep a leaderboard, yet have no card column — which is why the
        test is the sport's own stats, not whether it is a racket sport. */}
      {tracksDiscipline(selectedSport) && (
        <Card>
          <SectionHeader
            icon={RectangleVertical}
            title="Disiplin & Sanksi"
            description="Kapan akumulasi kartu membuat pemain tidak boleh bermain."
          />
          <CardContent className="grid gap-4">
            {/* items-end, because a label that wraps to two lines would
                otherwise push its own input below its neighbours'. */}
            <div className="grid items-end gap-4 sm:grid-cols-3">
              <div className="grid gap-2">
                {/* The marker sits beside the Label, never inside it: a <label>
                    may not wrap interactive content, and nested there a tap on
                    the icon would also focus the input behind it. */}
                <div className="flex items-center gap-1.5">
                  <Label htmlFor="yellow-threshold" className="font-semibold">
                    {sportCards.yellow?.label ?? "Kartu kuning"} untuk 1 larangan
                  </Label>
                  <InfoHint label="Penjelasan akumulasi kartu kuning">
                    Berapa {yellowWord} yang <b>menumpuk sepanjang turnamen</b> sebelum
                    pemain kena larangan. Terkumpul di laga mana saja — 1 di laga 2, 1 di
                    laga 5, 1 di laga 9 sudah cukup. Begitu larangan terbit, hitungannya
                    kembali ke nol.
                  </InfoHint>
                </div>
                <Input
                  id="yellow-threshold"
                  type="number"
                  min={1}
                  max={20}
                  inputMode="numeric"
                  value={discipline.yellow_threshold}
                  onChange={(e) =>
                    setDiscipline((d) => ({ ...d, yellow_threshold: e.target.value }))
                  }
                  placeholder={sportDiscipline.yellow_threshold?.toString() ?? "3"}
                />
              </div>
              <div className="grid gap-2">
                <div className="flex items-center gap-1.5">
                  <Label htmlFor="yellow-ban" className="font-semibold">
                    Lama larangan (akumulasi)
                  </Label>
                  <InfoHint label="Penjelasan lama larangan akumulasi">
                    Berapa pertandingan pemain absen setelah {yellowWord}-nya menumpuk
                    sampai batas di sebelah kiri. Isi <b>0</b> kalau akumulasi di turnamen
                    ini tidak berbuah larangan sama sekali.
                  </InfoHint>
                </div>
                <Input
                  id="yellow-ban"
                  type="number"
                  min={0}
                  max={10}
                  inputMode="numeric"
                  value={discipline.yellow_ban_matches}
                  onChange={(e) =>
                    setDiscipline((d) => ({ ...d, yellow_ban_matches: e.target.value }))
                  }
                  placeholder={sportDiscipline.yellow_ban_matches?.toString() ?? "1"}
                />
              </div>
              <div className="grid gap-2">
                <div className="flex items-center gap-1.5">
                  <Label htmlFor="red-ban" className="font-semibold">
                    Lama larangan ({redWord})
                  </Label>
                  {/* Last column on desktop, so the panel hangs from the right
                      edge instead of running off the card. */}
                  <InfoHint label="Penjelasan lama larangan kartu merah" align="end">
                    Berapa pertandingan pemain absen setelah menerima {redWord}. Berlaku
                    langsung, tanpa menunggu kartu lain. Kalau di satu laga tercatat{" "}
                    {redWord} <b>dan</b> {yellowWord} sekaligus, sistem membacanya sebagai
                    satu kejadian — larangannya tidak dobel.
                  </InfoHint>
                </div>
                <Input
                  id="red-ban"
                  type="number"
                  min={0}
                  max={10}
                  inputMode="numeric"
                  value={discipline.red_ban_matches}
                  onChange={(e) =>
                    setDiscipline((d) => ({ ...d, red_ban_matches: e.target.value }))
                  }
                  placeholder={sportDiscipline.red_ban_matches?.toString() ?? "1"}
                />
              </div>
              <div className="grid gap-2">
                <div className="flex items-center gap-1.5">
                  <Label htmlFor="yellow-expulsion" className="font-semibold">
                    {sportCards.yellow?.label ?? "Kartu kuning"} 1 laga = dikeluarkan
                  </Label>
                  <InfoHint label="Penjelasan dikeluarkan karena kartu kuning">
                    Berapa {yellowWord} <b>di dalam satu pertandingan</b> yang dihitung
                    sebagai pengusiran — bukan penumpukan lintas laga seperti kolom paling
                    kiri. {yellowWord} yang berujung pengusiran{" "}
                    <b>tidak ikut dihitung lagi</b> ke akumulasi, supaya satu kejadian tidak
                    dihukum dua kali. Isi <b>0</b> untuk mematikan aturan ini.
                  </InfoHint>
                </div>
                <Input
                  id="yellow-expulsion"
                  type="number"
                  min={0}
                  max={10}
                  inputMode="numeric"
                  value={discipline.yellows_per_expulsion}
                  onChange={(e) =>
                    setDiscipline((d) => ({ ...d, yellows_per_expulsion: e.target.value }))
                  }
                  placeholder={sportDiscipline.yellows_per_expulsion?.toString() ?? "2"}
                />
              </div>
              <div className="grid gap-2">
                <div className="flex items-center gap-1.5">
                  <Label htmlFor="expulsion-ban" className="font-semibold">
                    Lama larangan (dikeluarkan)
                  </Label>
                  <InfoHint label="Penjelasan lama larangan dikeluarkan">
                    Berapa pertandingan pemain absen setelah dikeluarkan karena{" "}
                    {yellowWord} di laga yang sama. Terpisah dari larangan akumulasi —
                    keduanya bisa berjalan berurutan kalau memang dua kejadian berbeda.
                  </InfoHint>
                </div>
                <Input
                  id="expulsion-ban"
                  type="number"
                  min={0}
                  max={10}
                  inputMode="numeric"
                  value={discipline.expulsion_ban_matches}
                  onChange={(e) =>
                    setDiscipline((d) => ({ ...d, expulsion_ban_matches: e.target.value }))
                  }
                  placeholder={sportDiscipline.expulsion_ban_matches?.toString() ?? "1"}
                />
              </div>
            </div>
            {/* The per-field markers above carry the rules now; what is left here
                is the one thing that belongs to the whole block. */}
            <FieldHint>
              Kosongkan untuk mengikuti aturan bawaan cabang — angka pada placeholder itulah
              yang sedang berlaku. Larangan dianggap dijalani di pertandingan resmi tim
              berikutnya, dan sistem hanya memperingatkan: keputusan menurunkan pemain tetap
              di tangan panitia.
            </FieldHint>

            <label className="flex items-start gap-3">
              <Switch
                checked={discipline.reset_yellow_on_knockout}
                onCheckedChange={(checked) =>
                  setDiscipline((d) => ({ ...d, reset_yellow_on_knockout: checked }))
                }
              />
              <span className="grid gap-1">
                <span className="text-sm font-semibold">
                  Hapus akumulasi kartu kuning saat masuk babak gugur
                </span>
                <FieldHint>
                  Hanya berlaku untuk kategori Grup + Knockout — cuma format itu yang menandai
                  laganya sebagai babak gugur. Larangan yang sudah terbit tetap harus dijalani.
                </FieldHint>
              </span>
            </label>
          </CardContent>
        </Card>
      )}

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

          <div className="grid gap-2">
            <Label className="font-semibold">Lapangan</Label>
            {(v.courts ?? []).length > 0 && (
              <div className="grid gap-2">
                {(v.courts ?? []).map((court, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <Input
                      value={court}
                      onChange={(e) => {
                        const next = [...(v.courts ?? [])];
                        next[i] = e.target.value;
                        set("courts", next);
                      }}
                      placeholder={`Lapangan ${i + 1}`}
                      aria-label={`Nama lapangan ${i + 1}`}
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      onClick={() => set("courts", (v.courts ?? []).filter((_, j) => j !== i))}
                      aria-label={`Hapus lapangan ${i + 1}`}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
              </div>
            )}
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="justify-self-start"
              onClick={() => set("courts", [...(v.courts ?? []), ""])}
            >
              <Plus className="h-4 w-4" />
              Tambah lapangan
            </Button>
            <FieldHint>
              Dipakai sebagai pilihan lapangan saat menjadwalkan pertandingan. Kosongkan jika tidak
              perlu.
            </FieldHint>
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
