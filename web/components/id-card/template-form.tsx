"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Loader2, Save } from "lucide-react";

import {
  createIdCardTemplate,
  getIdCardFields,
  updateIdCardTemplate,
  type IdCardTemplateInput,
} from "@/lib/api/id-cards";
import { parseApiError } from "@/lib/api/errors";
import { CardSizePicker } from "@/components/id-card/card-size-picker";
import { TemplateEditor } from "@/components/id-card/template-editor";
import { ImageUploadField } from "@/components/shared/image-upload-field";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { IdCardField, IdCardTemplate } from "@/types/api";

export function TemplateForm({
  orgId,
  template,
}: {
  orgId: string;
  /** Absent when creating. */
  template?: IdCardTemplate;
}) {
  const router = useRouter();
  const queryClient = useQueryClient();

  const [name, setName] = useState(template?.name ?? "");
  const [backgroundUrl, setBackgroundUrl] = useState(template?.background_url ?? "");
  // CR80 — the credit-card size every lanyard holder is cut for.
  const [size, setSize] = useState({
    width_mm: template?.width_mm ?? 85.6,
    height_mm: template?.height_mm ?? 54,
  });
  const [fields, setFields] = useState<IdCardField[]>(template?.fields ?? []);
  const [uploading, setUploading] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const fieldDefsQuery = useQuery({
    queryKey: ["id-card-fields", orgId],
    queryFn: () => getIdCardFields(orgId),
    staleTime: Infinity,
  });

  const save = useMutation({
    mutationFn: (payload: IdCardTemplateInput) =>
      template
        ? updateIdCardTemplate(orgId, template.id, payload)
        : createIdCardTemplate(orgId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["id-card-templates", orgId] });
      toast.success(template ? "Template diperbarui" : "Template dibuat");
      router.push("/organizer/id-cards");
    },
    onError: (err) => {
      const { message, fieldErrors } = parseApiError(err);
      setFieldErrors(fieldErrors);
      toast.error(message);
    },
  });

  function onSubmit() {
    if (!backgroundUrl) return toast.error("Unggah desain kartu dulu.");
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
            placeholder="Kartu Panitia 2026"
            className="mt-1.5"
          />
          {fieldErrors.name && <p className="mt-1 text-sm text-destructive">{fieldErrors.name}</p>}
        </div>

        <CardSizePicker
          widthMm={size.width_mm}
          heightMm={size.height_mm}
          onChange={setSize}
          errors={fieldErrors}
        />

        <div className="sm:col-span-2">
          <ImageUploadField
            label="Desain kartu"
            value={backgroundUrl}
            onChange={setBackgroundUrl}
            onBusyChange={setUploading}
            folder="id-cards"
            maxDim={2000}
            previewClassName="aspect-[85.6/54] w-full max-w-xs"
            hint="Gambar latar kartu, rasio mengikuti ukuran di atas. Maksimal 5 MB."
          />
          {fieldErrors.background_url && (
            <p className="mt-1 text-sm text-destructive">{fieldErrors.background_url}</p>
          )}
        </div>
      </Card>

      {backgroundUrl ? (
        <Card className="p-5">
          <TemplateEditor
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
          Unggah desain kartu dulu — field baru bisa ditempatkan di atas desainmu.
        </Card>
      )}

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
