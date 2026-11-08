"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Loader2, Save } from "lucide-react";

import {
  createBibTemplate,
  getBibFields,
  updateBibTemplate,
  type BibTemplateInput,
} from "@/lib/api/bibs";
import { parseApiError } from "@/lib/api/errors";
import { BibTemplateEditor } from "@/components/bib/template-editor";
import { PaperSizePicker, type SizePreset } from "@/components/shared/paper-size-picker";
import { ImageUploadField } from "@/components/shared/image-upload-field";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FieldError } from "@/components/ui/field-error";
import type { BibField, BibTemplate } from "@/types/api";

/**
 * Bib sizes, in millimetres. Landscape, unlike the card presets: a race number
 * is pinned across a chest and read from the front.
 */
const PRESETS: readonly SizePreset[] = [
  { label: "A5", hint: "Ukuran umum", w: 210, h: 148 },
  { label: "A6", hint: "Lomba pendek", w: 148, h: 105 },
] as const;

export function BibTemplateForm({
  orgId,
  template,
}: {
  orgId: string;
  /** Absent when creating. */
  template?: BibTemplate;
}) {
  const router = useRouter();
  const queryClient = useQueryClient();

  const [name, setName] = useState(template?.name ?? "");
  const [backgroundUrl, setBackgroundUrl] = useState(template?.background_url ?? "");
  // A5 landscape — the common race-bib size, and why this default differs from
  // the card form's CR80.
  const [size, setSize] = useState({
    width_mm: template?.width_mm ?? 210,
    height_mm: template?.height_mm ?? 148,
  });
  const [fields, setFields] = useState<BibField[]>(template?.fields ?? []);
  const [uploading, setUploading] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const fieldDefsQuery = useQuery({
    queryKey: ["bib-fields", orgId],
    queryFn: () => getBibFields(orgId),
    staleTime: Infinity,
  });

  const save = useMutation({
    mutationFn: (payload: BibTemplateInput) =>
      template
        ? updateBibTemplate(orgId, template.id, payload)
        : createBibTemplate(orgId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["bib-templates", orgId] });
      toast.success(template ? "Template diperbarui" : "Template dibuat");
      router.push("/organizer/bibs");
    },
    onError: (err) => {
      const { message, fieldErrors } = parseApiError(err);
      setFieldErrors(fieldErrors);
      toast.error(message);
    },
  });

  function onSubmit() {
    if (!backgroundUrl) return toast.error("Unggah desain e-BIB dulu.");
    if (fields.length === 0) return toast.error("Tambahkan minimal satu field.");

    save.mutate({ name, background_url: backgroundUrl, ...size, fields });
  }

  return (
    <div className="flex flex-col gap-6">
      <Card className="grid gap-5 p-5 sm:grid-cols-2">
        <div>
          <Label htmlFor="name">Nama template</Label>
          <Input
            id="name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="e-BIB Fun Run 2026"
            className="mt-1.5"
            aria-invalid={!!fieldErrors.name}
          />
          <FieldError message={fieldErrors.name} />
        </div>

        <PaperSizePicker
          label="Ukuran e-BIB"
          presets={PRESETS}
          widthMm={size.width_mm}
          heightMm={size.height_mm}
          onChange={setSize}
          errors={fieldErrors}
        />

        <div className="sm:col-span-2">
          <ImageUploadField
            label="Desain e-BIB"
            value={backgroundUrl}
            onChange={setBackgroundUrl}
            onBusyChange={setUploading}
            folder="bibs"
            maxDim={2400}
            previewClassName="aspect-[210/148] w-full max-w-sm"
            hint="Gambar latar nomor dada, rasio mengikuti ukuran di atas. Maksimal 5 MB."
          />
          <FieldError message={fieldErrors.background_url} />
        </div>
      </Card>

      {backgroundUrl ? (
        <Card className="p-5">
          <BibTemplateEditor
            backgroundUrl={backgroundUrl}
            widthMm={size.width_mm}
            heightMm={size.height_mm}
            fields={fields}
            fieldDefs={fieldDefsQuery.data ?? []}
            onChange={setFields}
          />
        </Card>
      ) : (
        <Card className="grid place-items-center border-dashed p-14 text-center text-sm text-muted-foreground">
          Unggah desain e-BIB dulu — field baru bisa ditempatkan di atas desainmu.
        </Card>
      )}

      {/* The catch-all: `fields.*` errors name their member ("fields.0.size"),
          which a plain lookup on `fields` never finds, and a refusal on a field
          scrolled off-screen would otherwise look like a submit that was
          ignored. */}
      {Object.entries(fieldErrors)
        .filter(([key]) => key.startsWith("fields"))
        .map(([key, message]) => (
          <FieldError key={key} message={message} />
        ))}

      <div className="flex gap-2">
        <Button onClick={onSubmit} disabled={save.isPending || uploading || !name}>
          {save.isPending ? (
            <Loader2 className="h-4 w-4 animate-spin" />
          ) : (
            <Save className="h-4 w-4" />
          )}
          Simpan template
        </Button>
        <Button variant="outline" onClick={() => router.back()}>
          Batal
        </Button>
      </div>
    </div>
  );
}
