"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Eye, FileText, ListChecks, Plus, Trash2, Users } from "lucide-react";
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
import { ImageUploadField } from "@/components/shared/image-upload-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
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
                <Label className="text-xs">Pilihan (satu per baris)</Label>
                <textarea
                  className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  value={row.options.join("\n")}
                  placeholder={"Putra\nPutri"}
                  onChange={(e) =>
                    onChange(section, i, {
                      options: e.target.value
                        .split("\n")
                        .map((o) => o.trim())
                        .filter(Boolean),
                    })
                  }
                />
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
            </div>

            {/* Guidance. Stored as null when blank, so '' is sent back as null
                rather than as an empty string the forms would have to defend
                against — the API normalizes the same way. */}
            <div className="grid gap-2 md:grid-cols-2">
              <div className="grid gap-1.5">
                <Label className="text-xs text-muted-foreground">
                  Teks bantuan (opsional)
                </Label>
                <Input
                  value={row.help_text ?? ""}
                  placeholder="Lihat panduan ukuran di bawah."
                  onChange={(e) =>
                    onChange(section, i, { help_text: e.target.value || null })
                  }
                />
              </div>
              <ImageUploadField
                label="Gambar panduan (opsional)"
                value={row.help_image_url ?? ""}
                onChange={(url) =>
                  onChange(section, i, { help_image_url: url || null })
                }
                onBusyChange={onBusyChange}
                folder="registration-form"
                maxDim={1200}
                previewClassName="aspect-[3/1] w-full"
                hint="Misalnya tabel ukuran jersey."
              />
            </div>

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
