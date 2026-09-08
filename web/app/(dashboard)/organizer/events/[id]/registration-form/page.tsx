"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { FileText, ListChecks, Plus, Trash2, Users } from "lucide-react";
import { toast } from "sonner";

import { getEvent, getRegistrationForm, syncRegistrationForm } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import {
  ACCEPTS,
  EMPTY_SCHEMA,
  FIELD_TYPES,
  type CustomField,
  type DocumentAccept,
  type DocumentSlot,
  type RegistrationFormSchema,
} from "@/lib/registration-form";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { SectionHeader } from "@/components/event/section-header";

/** Which of the four lists a row lives in. */
type FieldSection = "team_fields" | "player_fields";
type DocSection = "team_documents" | "player_documents";

const EMPTY_FIELD: CustomField = {
  key: "",
  label: "",
  type: "short_text",
  required: false,
  options: [],
};

const EMPTY_DOC: DocumentSlot = { key: "", label: "", required: false, accept: [...ACCEPTS] };

/**
 * Suggest a key from the label, so the common case needs no thought about it.
 * Only ever fills a key the organizer hasn't typed into — the key is what is
 * stored on every answer, and overwriting one they chose would rename it.
 */
function keyFrom(label: string): string {
  return label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 50);
}

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

  const setField = (section: FieldSection, i: number, patch: Partial<CustomField>) =>
    setSchema((s) => ({
      ...s,
      [section]: s[section].map((row, idx) => (idx === i ? { ...row, ...patch } : row)),
    }));

  const setDoc = (section: DocSection, i: number, patch: Partial<DocumentSlot>) =>
    setSchema((s) => ({
      ...s,
      [section]: s[section].map((row, idx) => (idx === i ? { ...row, ...patch } : row)),
    }));

  const addRow = (section: keyof RegistrationFormSchema) =>
    setSchema((s) => ({
      ...s,
      [section]:
        section === "team_fields" || section === "player_fields"
          ? [...s[section], { ...EMPTY_FIELD }]
          : [...s[section], { ...EMPTY_DOC, accept: [...ACCEPTS] }],
    }));

  const removeRow = (section: keyof RegistrationFormSchema, i: number) =>
    setSchema((s) => ({ ...s, [section]: s[section].filter((_, idx) => idx !== i) }));

  if (formQuery.isLoading) {
    return (
      <div className="grid gap-4">
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Formulir Pendaftaran"
        description={
          eventQuery.data?.name ??
          "Dokumen dan field tambahan yang diminta saat tim mendaftar di event ini."
        }
        backHref={`/organizer/events/${eventId}/edit`}
        backLabel="Kelola event"
        actions={
          <Button onClick={() => save.mutate()} disabled={save.isPending}>
            {save.isPending ? "Menyimpan…" : "Simpan formulir"}
          </Button>
        }
      />

      <div className="grid gap-6">
        <Card>
          <CardContent className="pt-6">
            <p className="text-sm text-muted-foreground">
              Kosongkan bagian yang tidak dibutuhkan — bagian yang kosong{" "}
              <strong>tidak muncul sama sekali</strong> di form pendaftaran, bukan tampil kosong.
              Kunci adalah yang tersimpan di tiap jawaban dan berkas: mengganti{" "}
              <em>label</em> aman dan langsung berlaku, tapi kunci yang sudah dipakai peserta tidak
              bisa diganti nama atau dihapus.
            </p>
            <p className="mt-2 text-sm text-muted-foreground">
              Peserta wajib melengkapi field dan dokumen wajib{" "}
              <strong>per baris pemain</strong>: begitu sebuah nama pemain diketik, baris itu harus
              lengkap. Tim tetap boleh mendaftar dengan roster kosong dan melengkapinya nanti.
            </p>
          </CardContent>
        </Card>

        <Card>
          <SectionHeader
            icon={ListChecks}
            title="Field Informasi Tim"
            description="Pertanyaan tambahan tentang tim — alamat, nama sekolah, apa pun."
          />
          <CardContent>
            <FieldRows
              section="team_fields"
              rows={schema.team_fields}
              onChange={setField}
              onRemove={removeRow}
              onAdd={addRow}
              errors={fieldErrors}
              addLabel="Tambah field tim"
              keyPlaceholder="alamat_tim"
              labelPlaceholder="Alamat Tim"
            />
          </CardContent>
        </Card>

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
              keyPlaceholder="no_ktp"
              labelPlaceholder="No. KTP"
            />
          </CardContent>
        </Card>

        <Card>
          <SectionHeader
            icon={FileText}
            title="Dokumen Tim"
            description="Berkas yang dikumpulkan sekali untuk satu tim, misalnya surat mandat."
          />
          <CardContent>
            <DocRows
              section="team_documents"
              rows={schema.team_documents}
              onChange={setDoc}
              onRemove={removeRow}
              onAdd={addRow}
              errors={fieldErrors}
              addLabel="Tambah dokumen tim"
              keyPlaceholder="surat_mandat"
              labelPlaceholder="Surat Mandat"
            />
          </CardContent>
        </Card>

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
              keyPlaceholder="ktp"
              labelPlaceholder="KTP"
            />
          </CardContent>
        </Card>

        <div className="flex justify-end">
          <Button onClick={() => save.mutate()} disabled={save.isPending}>
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
  keyPlaceholder,
  labelPlaceholder,
}: {
  section: FieldSection;
  rows: CustomField[];
  onChange: (section: FieldSection, i: number, patch: Partial<CustomField>) => void;
  onRemove: (section: keyof RegistrationFormSchema, i: number) => void;
  onAdd: (section: keyof RegistrationFormSchema) => void;
  errors: Record<string, string>;
  addLabel: string;
  keyPlaceholder: string;
  labelPlaceholder: string;
}) {
  return (
    <div className="grid gap-3">
      {rows.map((row, i) => {
        const error = errors[`${section}.${i}.key`] ?? errors[section];

        return (
          <div key={i} className="grid gap-2 rounded-xl border border-border p-3">
            <div className="grid gap-2 md:grid-cols-[1fr_1fr_170px_auto]">
              <Input
                value={row.label}
                placeholder={labelPlaceholder}
                aria-label="Label field"
                onChange={(e) =>
                  onChange(section, i, {
                    label: e.target.value,
                    // Only auto-fill a key the organizer never typed into.
                    ...(row.key === "" || row.key === keyFrom(row.label)
                      ? { key: keyFrom(e.target.value) }
                      : {}),
                  })
                }
              />
              <Input
                value={row.key}
                placeholder={keyPlaceholder}
                aria-label="Kunci field"
                aria-invalid={!!error}
                onChange={(e) => onChange(section, i, { key: e.target.value })}
              />
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

            {row.type === "select" && (
              <div className="grid gap-1.5">
                <Label className="text-xs">Pilihan (satu per baris)</Label>
                <textarea
                  className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  value={row.options.join("\n")}
                  placeholder={"Putra\nPutri"}
                  onChange={(e) =>
                    onChange(section, i, {
                      options: e.target.value.split("\n").map((o) => o.trim()).filter(Boolean),
                    })
                  }
                />
              </div>
            )}

            <label className="flex w-fit items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-input"
                checked={row.required}
                onChange={(e) => onChange(section, i, { required: e.target.checked })}
              />
              Wajib diisi
            </label>

            {error && <p className="text-xs text-destructive">{error}</p>}
          </div>
        );
      })}

      <div>
        <Button type="button" size="sm" variant="outline" onClick={() => onAdd(section)}>
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
  keyPlaceholder,
  labelPlaceholder,
}: {
  section: DocSection;
  rows: DocumentSlot[];
  onChange: (section: DocSection, i: number, patch: Partial<DocumentSlot>) => void;
  onRemove: (section: keyof RegistrationFormSchema, i: number) => void;
  onAdd: (section: keyof RegistrationFormSchema) => void;
  errors: Record<string, string>;
  addLabel: string;
  keyPlaceholder: string;
  labelPlaceholder: string;
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
          <div key={i} className="grid gap-2 rounded-xl border border-border p-3">
            <div className="grid gap-2 md:grid-cols-[1fr_1fr_auto]">
              <Input
                value={row.label}
                placeholder={labelPlaceholder}
                aria-label="Label dokumen"
                onChange={(e) =>
                  onChange(section, i, {
                    label: e.target.value,
                    ...(row.key === "" || row.key === keyFrom(row.label)
                      ? { key: keyFrom(e.target.value) }
                      : {}),
                  })
                }
              />
              <Input
                value={row.key}
                placeholder={keyPlaceholder}
                aria-label="Kunci dokumen"
                aria-invalid={!!error}
                onChange={(e) => onChange(section, i, { key: e.target.value })}
              />
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

            <div className="flex flex-wrap items-center gap-4">
              <span className="text-sm text-muted-foreground">Format diterima:</span>
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
                onChange={(e) => onChange(section, i, { required: e.target.checked })}
              />
              Wajib diunggah
            </label>

            {error && <p className="text-xs text-destructive">{error}</p>}
          </div>
        );
      })}

      <div>
        <Button type="button" size="sm" variant="outline" onClick={() => onAdd(section)}>
          <Plus className="h-4 w-4" />
          {addLabel}
        </Button>
      </div>
    </div>
  );
}
