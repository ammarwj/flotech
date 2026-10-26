"use client";

import { useEffect, useId, useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ChevronRight,
  FileText,
  HelpCircle,
  ListChecks,
  Plus,
  Trash2,
  Users,
} from "lucide-react";
import { toast } from "sonner";

import {
  getEvent,
  getRegistrationForm,
  syncRegistrationForm,
} from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { isRaceEvent } from "@/lib/race";
import {
  ACCEPTS,
  EMPTY_SCHEMA,
  FIELD_TYPES,
  type CustomField,
  type DocumentAccept,
  uniqueKey,
  type DocumentSlot,
  type RegistrationFormSchema,
} from "@/lib/registration-form";
import { cn } from "@/lib/utils";
import { ImageUploadField } from "@/components/shared/image-upload-field";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { TagInput } from "@/components/ui/tag-input";
import { Textarea } from "@/components/ui/textarea";
import { PageHeader } from "@/components/shared/page-header";
import { SectionHeader } from "@/components/event/section-header";

/** Which of the six lists a row lives in. */
type FieldSection = "team_fields" | "player_fields" | "team_official_fields";
type DocSection =
  | "team_documents"
  | "player_documents"
  | "team_official_documents";

const EMPTY_FIELD: CustomField = {
  key: "",
  label: "",
  type: "short_text",
  required: false,
  // New fields start private. An organizer adding "No. KTP" should have to say
  // they want it on the public page, not remember to say they don't.
  is_public: false,
  in_album: false,
  in_schedule: false,
  options: [],
  help_text: null,
  help_image_url: null,
  // Always asked until the organizer says otherwise. A new field defaulting to
  // a condition would be one that renders nowhere until they find the box.
  visible_if: null,
};

const EMPTY_DOC: DocumentSlot = {
  key: "",
  label: "",
  required: false,
  accept: [...ACCEPTS],
};

export default function RegistrationFormPage() {
  const { orgId } = useActiveOrg();
  const { id: eventId } = useParams<{ id: string }>();
  const qc = useQueryClient();

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const formQuery = useQuery({
    queryKey: ["registration-form", orgId, eventId],
    queryFn: () => getRegistrationForm(orgId!, eventId),
    enabled: !!orgId,
  });

  const [schema, setSchema] = useState<RegistrationFormSchema>(EMPTY_SCHEMA);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  /**
   * How many guidance images are still uploading.
   *
   * Save has to wait for them, and this page was the one caller of
   * ImageUploadField that never passed `onBusyChange` — the prop exists for
   * exactly this. Saving mid-upload sends the schema as it stands, which is
   * the row WITHOUT its `help_image_url`, and reports success: the organizer
   * sees a green toast, the picture they just picked still on screen from the
   * local preview, and nothing on the entry form. That is the bug this fixes.
   *
   * A count rather than a boolean, keyed on nothing: there is one of these per
   * field row and any of them may be in flight, so a shared flag would be
   * cleared by whichever upload finished first while another was still going.
   */
  const [uploading, setUploading] = useState(0);

  /**
   * Keys that already exist on the server. Their labels stay editable, but the
   * key underneath must not move: the API refuses to rename one that teams have
   * already answered, and with the key hidden the organizer would have no way to
   * see why a label change suddenly failed to save. A row still being typed has
   * no saved key, so its key keeps tracking the label.
   */
  const savedKeys = new Set(
    formQuery.data
      ? [
          ...formQuery.data.team_fields,
          ...formQuery.data.player_fields,
          ...formQuery.data.team_official_fields,
          ...formQuery.data.team_documents,
          ...formQuery.data.player_documents,
          ...formQuery.data.team_official_documents,
        ].map((r) => r.key)
      : [],
  );

  // Seed the editor once the saved schema arrives. Keyed on the fetched object
  // so a refetch after save re-syncs, and local edits aren't clobbered between.
  useEffect(() => {
    if (formQuery.data) setSchema(formQuery.data);
  }, [formQuery.data]);

  const save = useMutation({
    mutationFn: () => syncRegistrationForm(orgId!, eventId, schema),
    onSuccess: (saved) => {
      setSchema(saved);
      setFieldErrors({});
      qc.invalidateQueries({ queryKey: ["registration-form", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["event", orgId, eventId] });
      toast.success("Formulir pendaftaran diperbarui");
    },
    onError: (err) => {
      const { message, fieldErrors } = parseApiError(err);
      setFieldErrors(fieldErrors);
      toast.error(message);
    },
  });

  // ---- Row helpers, one pair per shape ----

  const setField = (
    section: FieldSection,
    i: number,
    patch: Partial<CustomField>,
  ) =>
    setSchema((s) => ({
      ...s,
      [section]: s[section].map((row, idx) =>
        idx === i ? { ...row, ...patch } : row,
      ),
    }));

  const setDoc = (
    section: DocSection,
    i: number,
    patch: Partial<DocumentSlot>,
  ) =>
    setSchema((s) => ({
      ...s,
      [section]: s[section].map((row, idx) =>
        idx === i ? { ...row, ...patch } : row,
      ),
    }));

  const addRow = (section: keyof RegistrationFormSchema) =>
    setSchema((s) => ({
      ...s,
      [section]:
        section === "team_fields" ||
        section === "player_fields" ||
        section === "team_official_fields"
          ? [...s[section], { ...EMPTY_FIELD }]
          : [...s[section], { ...EMPTY_DOC, accept: [...ACCEPTS] }],
    }));

  const removeRow = (section: keyof RegistrationFormSchema, i: number) =>
    setSchema((s) => ({
      ...s,
      [section]: s[section].filter((_, idx) => idx !== i),
    }));

  if (formQuery.isLoading) {
    return (
      <div className="grid gap-4">
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  /**
   * A race keeps the two team sections and loses the four roster ones.
   *
   * This page is deliberately NOT behind a RaceRouteGuard, unlike its four
   * neighbours: a fun run's entry form reads the very lists edited here.
   * PublicRaceController::assertFormComplete() validates every required
   * `team_fields` row and refuses the entry without it, cleanAnswers() filters
   * the stored answers to those same keys, and the public race form renders
   * both `team_fields` and `team_documents`. Gating the page shut left the one
   * screen that configures a live form unreachable for the sport using it.
   *
   * The four that go are the ones a runner has no counterpart for — there is no
   * roster and no bench to ask anything of, and PublicRaceController reads
   * neither. "Tim" is the wrong word for a solo entrant, so the two surviving
   * sections are retitled rather than left talking about teams; the stored keys
   * are untouched, since the schema column is shared and renaming a section
   * would orphan answers an organizer already collected.
   */
  const isRace = isRaceEvent(eventQuery.data);

  return (
    <div>
      <PageHeader
        title="Formulir Pendaftaran"
        description={
          eventQuery.data?.name ??
          (isRace
            ? "Dokumen dan field tambahan yang diminta saat peserta mendaftar di lomba ini."
            : "Dokumen dan field tambahan yang diminta saat tim mendaftar di event ini.")
        }
        backHref={`/organizer/events/${eventId}/edit`}
        backLabel="Kelola event"
      />

      <div className="grid gap-6">
        <Card>
          <SectionHeader
            icon={ListChecks}
            title={isRace ? "Field Informasi Peserta" : "Field Informasi Tim"}
            description={
              isRace
                ? "Pertanyaan tambahan tentang peserta — ukuran jersey, komunitas, golongan darah, apa pun. Nama, email, nomor HP, jenis kelamin, tanggal lahir, dan kontak darurat sudah selalu ditanyakan."
                : "Pertanyaan tambahan tentang tim — alamat, nama sekolah, apa pun."
            }
          />
          <CardContent>
            <FieldRows
              section="team_fields"
              rows={schema.team_fields}
              onChange={setField}
              onRemove={removeRow}
              onAdd={addRow}
              errors={fieldErrors}
              addLabel={isRace ? "Tambah field peserta" : "Tambah field tim"}
              labelPlaceholder={isRace ? "Ukuran Jersey" : "Alamat Tim"}
              savedKeys={savedKeys}
              onBusyChange={(b) => setUploading((n) => n + (b ? 1 : -1))}
            />
          </CardContent>
        </Card>

        {/* The four roster sections. A runner has no players and no bench, and
            PublicRaceController reads neither list — so they go together rather
            than one by one. */}
        {!isRace && (
        <>
        <Card>
          <SectionHeader
            icon={Users}
            title="Field Pemain"
            description="Ditanyakan pada setiap pemain — tanggal lahir, no. KTP, dan sejenisnya."
          />
          <CardContent>
            <FieldRows
              section="player_fields"
              rows={schema.player_fields}
              onChange={setField}
              onRemove={removeRow}
              onAdd={addRow}
              errors={fieldErrors}
              addLabel="Tambah field pemain"
              labelPlaceholder="No. KTP"
              savedKeys={savedKeys}
              onBusyChange={(b) => setUploading((n) => n + (b ? 1 : -1))}
            />
          </CardContent>
        </Card>

        <Card>
          <SectionHeader
            icon={Users}
            title="Field Pelatih & Ofisial"
            description="Pertanyaan tambahan tentang tiap pelatih/ofisial — no. lisensi, sertifikasi, dsb."
          />
          <CardContent>
            <FieldRows
              section="team_official_fields"
              rows={schema.team_official_fields}
              onChange={setField}
              onRemove={removeRow}
              onAdd={addRow}
              errors={fieldErrors}
              addLabel="Tambah field ofisial"
              labelPlaceholder="No. Lisensi Pelatih"
              savedKeys={savedKeys}
              onBusyChange={(b) => setUploading((n) => n + (b ? 1 : -1))}
            />
          </CardContent>
        </Card>
        </>
        )}

        <Card>
          <SectionHeader
            icon={FileText}
            title={isRace ? "Dokumen Peserta" : "Dokumen Tim"}
            description={
              isRace
                ? "Berkas yang diunggah peserta saat mendaftar, misalnya surat keterangan sehat."
                : "Berkas yang dikumpulkan sekali untuk satu tim, misalnya surat mandat."
            }
          />
          <CardContent>
            <DocRows
              section="team_documents"
              rows={schema.team_documents}
              onChange={setDoc}
              onRemove={removeRow}
              onAdd={addRow}
              errors={fieldErrors}
              addLabel={
                isRace ? "Tambah dokumen peserta" : "Tambah dokumen tim"
              }
              labelPlaceholder={
                isRace ? "Surat Keterangan Sehat" : "Surat Mandat"
              }
              savedKeys={savedKeys}
            />
          </CardContent>
        </Card>

        {!isRace && (
        <>
        <Card>
          <SectionHeader
            icon={FileText}
            title="Dokumen Pemain"
            description="Berkas yang diminta dari setiap pemain, misalnya KTP atau kartu pelajar."
          />
          <CardContent>
            <DocRows
              section="player_documents"
              rows={schema.player_documents}
              onChange={setDoc}
              onRemove={removeRow}
              onAdd={addRow}
              errors={fieldErrors}
              addLabel="Tambah dokumen pemain"
              labelPlaceholder="KTP"
              savedKeys={savedKeys}
            />
          </CardContent>
        </Card>

        <Card>
          <SectionHeader
            icon={FileText}
            title="Dokumen Pelatih & Ofisial"
            description="Berkas yang diminta dari setiap pelatih/ofisial, misalnya KTP atau sertifikat lisensi."
          />
          <CardContent>
            <DocRows
              section="team_official_documents"
              rows={schema.team_official_documents}
              onChange={setDoc}
              onRemove={removeRow}
              onAdd={addRow}
              errors={fieldErrors}
              addLabel="Tambah dokumen ofisial"
              labelPlaceholder="KTP Pelatih"
              savedKeys={savedKeys}
            />
          </CardContent>
        </Card>
        </>
        )}

        <div className="flex items-center justify-end gap-3">
          {/* Said out loud, not just shown as a dead button: a disabled Save
              with nothing explaining it reads as broken, and the upload it is
              waiting for is happening in a row that may be scrolled off. */}
          {uploading > 0 && (
            <span className="text-sm text-muted-foreground">
              Menunggu gambar panduan selesai diunggah…
            </span>
          )}
          <Button
            onClick={() => save.mutate()}
            disabled={save.isPending || uploading > 0}
          >
            {save.isPending ? "Menyimpan…" : "Simpan formulir"}
          </Button>
        </div>
      </div>
    </div>
  );
}

function FieldRows({
  section,
  rows,
  onChange,
  onRemove,
  onAdd,
  errors,
  addLabel,
  labelPlaceholder,
  savedKeys,
  onBusyChange,
}: {
  section: FieldSection;
  rows: CustomField[];
  onChange: (
    section: FieldSection,
    i: number,
    patch: Partial<CustomField>,
  ) => void;
  onRemove: (section: keyof RegistrationFormSchema, i: number) => void;
  onAdd: (section: keyof RegistrationFormSchema) => void;
  errors: Record<string, string>;
  addLabel: string;
  labelPlaceholder: string;
  savedKeys: Set<string>;
  /** Raised while a guidance image is uploading, so Save can wait for it. */
  onBusyChange: (busy: boolean) => void;
}) {
  return (
    <div className="grid gap-3">
      {rows.map((row, i) => {
        const error = errors[`${section}.${i}.key`] ?? errors[section];

        return (
          <div
            key={i}
            className="grid gap-2 rounded-xl border border-border p-3"
          >
            <div className="grid gap-2 md:grid-cols-[1fr_170px_auto]">
              <div className="grid gap-1.5">
                <Label className="text-xs text-muted-foreground">
                  Pertanyaan
                </Label>
                <Input
                  value={row.label}
                  placeholder={labelPlaceholder}
                  aria-invalid={!!error}
                  onChange={(e) =>
                    onChange(section, i, {
                      label: e.target.value,
                      // A key already on the server stays put — teams have
                      // answered under it, and the API refuses to rename it.
                      ...(savedKeys.has(row.key)
                        ? {}
                        : { key: uniqueKey(e.target.value, rows, i) }),
                    })
                  }
                />
              </div>
              <div className="grid gap-1.5">
                <Label className="text-xs text-muted-foreground">Tipe</Label>
                <Select
                  value={row.type}
                  aria-label="Tipe field"
                  onChange={(e) =>
                    onChange(section, i, {
                      type: e.target.value as CustomField["type"],
                      // Options only mean anything for a select; dropping them
                      // keeps a stale list from riding along on another type.
                      ...(e.target.value === "select" ? {} : { options: [] }),
                    })
                  }
                >
                  {FIELD_TYPES.map((t) => (
                    <option key={t.value} value={t.value}>
                      {t.label}
                    </option>
                  ))}
                </Select>
              </div>
              {/* Sits on the inputs' row, not the labels'. */}
              <div className="flex items-end">
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => onRemove(section, i)}
                  aria-label="Hapus field"
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            </div>

            {row.type === "select" && (
              <div className="grid gap-1.5">
                <Label className="text-xs">Pilihan</Label>
                {/* Chips, not a textarea. The list is held as committed values
                    rather than as text, so there is no half-typed entry in the
                    array for the trigger picker or the save to defend against
                    — see TagInput's own note on why the textarea could not
                    hold more than one option. */}
                <TagInput
                  value={row.options}
                  onChange={(options) => onChange(section, i, { options })}
                  placeholder="Putra, lalu Enter"
                  aria-label="Pilihan jawaban"
                />
                <p className="text-xs text-muted-foreground">
                  Ketik satu pilihan lalu tekan Enter. Bisa juga tempel
                  beberapa sekaligus, dipisah koma.
                </p>
              </div>
            )}

            <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
              <label className="flex w-fit items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-input"
                  checked={row.required}
                  onChange={(e) =>
                    onChange(section, i, { required: e.target.checked })
                  }
                />
                Wajib diisi
              </label>

              <label className="flex w-fit items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-input"
                  checked={row.is_public ?? false}
                  onChange={(e) =>
                    onChange(section, i, { is_public: e.target.checked })
                  }
                />
                <span className="inline-flex items-center gap-1.5">
                  Tampilkan di halaman publik
                </span>
              </label>

              {/* Only the person sections: the album renders one block per
                  official and per player, so offering the box on Data Tim
                  would promise a row nothing ever prints. */}
              {section !== "team_fields" && (
                <label className="flex w-fit items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-input"
                    checked={row.in_album ?? false}
                    onChange={(e) =>
                      onChange(section, i, { in_album: e.target.checked })
                    }
                  />
                  <span className="inline-flex items-center gap-1.5">
                    Tampilkan di album pemain
                  </span>
                </label>
              )}

              {/* Offered on every section, unlike the album box above: the card
                  prints one line under an ENTRY's name, and the team's answers
                  and its players' are folded into that one line by the server
                  (Team::scheduleTags). Which list the question lives in is
                  exactly what differs between a squad event asking the team and
                  a tunggal/ganda category asking the player — in both cases the
                  answer lands on the same line. */}
              <label className="flex w-fit items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-input"
                  checked={row.in_schedule ?? false}
                  onChange={(e) =>
                    onChange(section, i, { in_schedule: e.target.checked })
                  }
                />
                <span className="inline-flex items-center gap-1.5">
                  Tampilkan di jadwal
                </span>
              </label>
            </div>

            {/* Said here rather than left to be discovered from the schedule: a
                card has one line, and three ticked fields spend it on a list
                nobody can read at a glance. */}
            {row.in_schedule && (
              <p className="text-xs text-muted-foreground">
                Jawabannya dicetak di bawah nama peserta pada kartu jadwal —
                tanpa label, jadi pilih field yang berdiri sendiri seperti nama
                klub. Jawaban kosong tidak dicetak.
              </p>
            )}

            <FieldGuidance
              row={row}
              onChange={(patch) => onChange(section, i, patch)}
              onBusyChange={onBusyChange}
            />

            {/* The condition. Offered only when a field above this one can act
                as a trigger: the API refuses anything else, and a picker whose
                only options would be refused is a trap rather than a feature. */}
            {(() => {
              const triggers = rows
                .slice(0, i)
                .filter((r) => r.key && r.type === "select" && r.options.length > 0);

              if (triggers.length === 0) return null;

              const rule = row.visible_if ?? null;
              const trigger = triggers.find((t) => t.key === rule?.field) ?? null;
              const condError = errors[`${section}.${i}.visible_if.field`];

              return (
                <div className="grid gap-2 rounded-lg bg-muted/40 p-2.5">
                  <Label className="text-xs text-muted-foreground">
                    Tampilkan hanya jika (opsional)
                  </Label>
                  <Select
                    value={rule?.field ?? ""}
                    aria-label="Field pemicu"
                    aria-invalid={!!condError}
                    onChange={(e) =>
                      onChange(section, i, {
                        // Switching the trigger drops the old values: they came
                        // from another field's option list and would never
                        // match, hiding this field forever.
                        visible_if: e.target.value
                          ? { field: e.target.value, values: [] }
                          : null,
                      })
                    }
                  >
                    <option value="">Selalu ditampilkan</option>
                    {triggers.map((t) => (
                      <option key={t.key} value={t.key}>
                        {t.label || t.key}
                      </option>
                    ))}
                  </Select>

                  {trigger && (
                    <div className="flex flex-wrap gap-x-4 gap-y-1.5">
                      {trigger.options.map((opt) => (
                        <label
                          key={opt}
                          className="flex items-center gap-2 text-sm"
                        >
                          <input
                            type="checkbox"
                            className="h-4 w-4 rounded border-input"
                            checked={rule?.values.includes(opt) ?? false}
                            onChange={(e) =>
                              onChange(section, i, {
                                visible_if: {
                                  field: trigger.key,
                                  values: e.target.checked
                                    ? [...(rule?.values ?? []), opt]
                                    : (rule?.values ?? []).filter((v) => v !== opt),
                                },
                              })
                            }
                          />
                          {opt}
                        </label>
                      ))}
                    </div>
                  )}

                  {/* A rule with no value ticked can never match, so the API
                      stores it as "always visible". Said out loud here rather
                      than left as a silent normalization the organizer only
                      discovers from the public form. */}
                  {rule && rule.values.length === 0 && (
                    <p className="text-xs text-muted-foreground">
                      Pilih minimal satu jawaban, kalau tidak field ini tetap
                      selalu ditampilkan.
                    </p>
                  )}

                  {condError && (
                    <p className="text-xs text-destructive">{condError}</p>
                  )}
                </div>
              );
            })()}

            {error && <p className="text-xs text-destructive">{error}</p>}
          </div>
        );
      })}

      <div>
        <Button
          type="button"
          size="sm"
          variant="outline"
          onClick={() => onAdd(section)}
        >
          <Plus className="h-4 w-4" />
          {addLabel}
        </Button>
      </div>
    </div>
  );
}

/** Matches `help_text`'s `max:500` on the API. */
const HELP_TEXT_MAX = 500;

/**
 * The optional guidance attached to one question — a sentence, a picture, or
 * both.
 *
 * Collapsed by default, and that is the point. Guidance is set on a minority
 * of fields, but the open form rendered a full-width 3:1 upload box on *every*
 * row: eight questions meant eight grey placeholders and a page nobody could
 * scan for the questions themselves. A summary that names what is already set
 * keeps the information without the height — closed, the row still says
 * "Teks + gambar", so nothing is hidden, only folded.
 *
 * Opens on its own when something is already stored, so an organizer returning
 * to a field never has to discover that their own help text is behind a
 * toggle.
 *
 * Both values are stored as null when blank rather than as '': the entry forms
 * branch on "is there guidance at all", and two spellings of "no" are two
 * branches that will disagree. The API normalizes the same way.
 */
function FieldGuidance({
  row,
  onChange,
  onBusyChange,
}: {
  row: CustomField;
  onChange: (patch: Partial<CustomField>) => void;
  onBusyChange: (busy: boolean) => void;
}) {
  const hasText = !!row.help_text?.trim();
  const hasImage = !!row.help_image_url;
  const [open, setOpen] = useState(hasText || hasImage);
  // Not row.key: that is empty until a label is typed, so two fresh rows would
  // both own `help-` and clicking one label would focus the other's box.
  const textId = useId();

  const text = row.help_text ?? "";
  const remaining = HELP_TEXT_MAX - text.length;

  const summary = [hasText && "Teks", hasImage && "Gambar"]
    .filter(Boolean)
    .join(" + ");

  return (
    <div className="rounded-lg border border-dashed border-border">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full items-center gap-2 px-2.5 py-2 text-left text-xs text-muted-foreground transition-colors hover:text-foreground"
      >
        <ChevronRight
          className={cn("h-3.5 w-3.5 shrink-0 transition-transform", open && "rotate-90")}
          aria-hidden
        />
        <HelpCircle className="h-3.5 w-3.5 shrink-0" aria-hidden />
        <span className="font-medium">Panduan pengisian</span>
        {/* Closed, this is the only trace of what is set — so it names the
            parts rather than showing a bare dot. */}
        {summary ? (
          <Badge variant="neutral" className="ml-auto">
            {summary}
          </Badge>
        ) : (
          <span className="ml-auto">Opsional</span>
        )}
      </button>

      {open && (
        <div className="grid gap-3 border-t border-dashed border-border p-2.5 md:grid-cols-2">
          <div className="grid gap-1.5">
            <Label htmlFor={textId} className="text-xs text-muted-foreground">
              Teks bantuan
            </Label>
            {/* A textarea, not an Input: the API takes 500 characters and the
                text this holds is a sizing note or a waiver's full wording,
                which a one-line box shows ten characters of at a time. */}
            <Textarea
              id={textId}
              value={text}
              maxLength={HELP_TEXT_MAX}
              placeholder="Lihat tabel ukuran di samping. Pilih satu ukuran lebih besar jika ragu."
              className="min-h-19 resize-y text-sm"
              onChange={(e) => onChange({ help_text: e.target.value || null })}
            />
            <div className="flex items-start justify-between gap-2">
              <p className="text-xs text-muted-foreground">
                Ditampilkan di bawah pertanyaan pada formulir pendaftaran.
              </p>
              {/* Only once it matters. A counter sitting at 500/500 on an
                  empty box is noise on every row of the page. */}
              {remaining <= 100 && (
                <span
                  className={cn(
                    "shrink-0 text-xs tabular-nums",
                    remaining === 0 ? "text-destructive" : "text-muted-foreground",
                  )}
                >
                  {remaining}
                </span>
              )}
            </div>
          </div>

          <ImageUploadField
            label="Gambar panduan"
            value={row.help_image_url ?? ""}
            onChange={(url) => onChange({ help_image_url: url || null })}
            onBusyChange={onBusyChange}
            folder="registration-form"
            maxDim={1200}
            previewClassName="aspect-[3/1] w-full"
            placeholder={
              <span className="px-3 text-center text-xs text-muted-foreground">
                Misalnya tabel ukuran jersey
              </span>
            }
            hint="PNG/JPG, maksimal 5 MB."
          />
        </div>
      )}
    </div>
  );
}

function DocRows({
  section,
  rows,
  onChange,
  onRemove,
  onAdd,
  errors,
  addLabel,
  labelPlaceholder,
  savedKeys,
}: {
  section: DocSection;
  rows: DocumentSlot[];
  onChange: (
    section: DocSection,
    i: number,
    patch: Partial<DocumentSlot>,
  ) => void;
  onRemove: (section: keyof RegistrationFormSchema, i: number) => void;
  onAdd: (section: keyof RegistrationFormSchema) => void;
  errors: Record<string, string>;
  addLabel: string;
  labelPlaceholder: string;
  savedKeys: Set<string>;
}) {
  const toggleAccept = (row: DocumentSlot, i: number, kind: DocumentAccept) => {
    const next = row.accept.includes(kind)
      ? row.accept.filter((a) => a !== kind)
      : [...row.accept, kind];
    // An empty accept list is a slot nobody can fill; the API rejects it, so
    // refuse to produce one rather than let it fail at save.
    if (next.length === 0) return;
    onChange(section, i, { accept: ACCEPTS.filter((a) => next.includes(a)) });
  };

  return (
    <div className="grid gap-3">
      {rows.map((row, i) => {
        const error = errors[`${section}.${i}.key`] ?? errors[section];

        return (
          <div
            key={i}
            className="grid gap-2 rounded-xl border border-border p-3"
          >
            <div className="grid gap-2 md:grid-cols-[1fr_auto]">
              <div className="grid gap-1.5">
                <Label className="text-xs text-muted-foreground">
                  Nama dokumen
                </Label>
                <Input
                  value={row.label}
                  placeholder={labelPlaceholder}
                  aria-invalid={!!error}
                  onChange={(e) =>
                    onChange(section, i, {
                      label: e.target.value,
                      // See FieldRows: a saved key is frozen.
                      ...(savedKeys.has(row.key)
                        ? {}
                        : { key: uniqueKey(e.target.value, rows, i) }),
                    })
                  }
                />
              </div>
              <div className="flex items-end">
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => onRemove(section, i)}
                  aria-label="Hapus dokumen"
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-4">
              <span className="text-sm text-muted-foreground">
                Format diterima:
              </span>
              {ACCEPTS.map((kind) => (
                <label key={kind} className="flex items-center gap-1.5 text-sm">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-input"
                    checked={row.accept.includes(kind)}
                    onChange={() => toggleAccept(row, i, kind)}
                  />
                  {kind.toUpperCase()}
                </label>
              ))}
            </div>

            <label className="flex w-fit items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-input"
                checked={row.required}
                onChange={(e) =>
                  onChange(section, i, { required: e.target.checked })
                }
              />
              Wajib diunggah
            </label>

            {error && <p className="text-xs text-destructive">{error}</p>}
          </div>
        );
      })}

      <div>
        <Button
          type="button"
          size="sm"
          variant="outline"
          onClick={() => onAdd(section)}
        >
          <Plus className="h-4 w-4" />
          {addLabel}
        </Button>
      </div>
    </div>
  );
}
