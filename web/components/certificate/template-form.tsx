"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ImageUp, Loader2, Save } from "lucide-react";

import {
  createCertificateTemplate,
  getCertificateFields,
  updateCertificateTemplate,
  type CertificateTemplateInput,
} from "@/lib/api/certificates";
import { uploadImage } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { TemplateEditor } from "@/components/certificate/template-editor";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import type { CertificateField, CertificateTemplate } from "@/types/api";

export function TemplateForm({
  orgId,
  template,
}: {
  orgId: string;
  /** Absent when creating. */
  template?: CertificateTemplate;
}) {
  const router = useRouter();
  const queryClient = useQueryClient();

  const [name, setName] = useState(template?.name ?? "");
  const [orientation, setOrientation] = useState<"landscape" | "portrait">(
    template?.orientation ?? "landscape"
  );
  const [backgroundUrl, setBackgroundUrl] = useState(template?.background_url ?? "");
  const [fields, setFields] = useState<CertificateField[]>(template?.fields ?? []);
  const [uploading, setUploading] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const fieldDefsQuery = useQuery({
    queryKey: ["certificate-fields", orgId],
    queryFn: () => getCertificateFields(orgId),
    staleTime: Infinity,
  });

  const save = useMutation({
    mutationFn: (payload: CertificateTemplateInput) =>
      template
        ? updateCertificateTemplate(orgId, template.id, payload)
        : createCertificateTemplate(orgId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["certificate-templates", orgId] });
      toast.success(template ? "Template diperbarui" : "Template dibuat");
      router.push("/organizer/certificates?tab=templates");
    },
    onError: (err) => {
      const { message, fieldErrors } = parseApiError(err);
      setFieldErrors(fieldErrors);
      toast.error(message);
    },
  });

  async function onUpload(file: File) {
    setUploading(true);
    try {
      setBackgroundUrl(await uploadImage(file, "certificates"));
    } catch {
      toast.error("Gagal mengunggah background.");
    } finally {
      setUploading(false);
    }
  }

  function onSubmit() {
    if (!backgroundUrl) return toast.error("Unggah background sertifikat dulu.");
    if (fields.length === 0) return toast.error("Tambahkan minimal satu field.");

    save.mutate({ name, background_url: backgroundUrl, orientation, fields });
  }

  return (
    <div className="flex flex-col gap-6">
      <Card className="grid gap-4 p-5 sm:grid-cols-2">
        <div>
          <Label htmlFor="name">Nama template</Label>
          <Input
            id="name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Sertifikat Juara 2026"
            className="mt-1.5"
          />
          {fieldErrors.name && <p className="mt-1 text-sm text-destructive">{fieldErrors.name}</p>}
        </div>

        <div>
          <Label htmlFor="orientation">Orientasi</Label>
          <Select
            id="orientation"
            value={orientation}
            onChange={(e) => setOrientation(e.target.value as "landscape" | "portrait")}
            className="mt-1.5"
          >
            <option value="landscape">Landscape (A4)</option>
            <option value="portrait">Portrait (A4)</option>
          </Select>
        </div>

        <div className="sm:col-span-2">
          <Label htmlFor="background">Background</Label>
          <div className="mt-1.5 flex items-center gap-3">
            <Button asChild variant="outline" disabled={uploading}>
              <label htmlFor="background" className="cursor-pointer">
                {uploading ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <ImageUp className="h-4 w-4" />
                )}
                {backgroundUrl ? "Ganti background" : "Unggah background"}
              </label>
            </Button>
            <input
              id="background"
              type="file"
              accept="image/*"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) onUpload(file);
              }}
            />
            <span className="text-sm text-muted-foreground">
              JPG atau PNG resolusi tinggi, rasio A4.
            </span>
          </div>
          {fieldErrors.background_url && (
            <p className="mt-1 text-sm text-destructive">{fieldErrors.background_url}</p>
          )}
        </div>
      </Card>

      {backgroundUrl ? (
        <Card className="p-5">
          <TemplateEditor
            backgroundUrl={backgroundUrl}
            orientation={orientation}
            fields={fields}
            fieldDefs={fieldDefsQuery.data ?? []}
            onChange={setFields}
          />
        </Card>
      ) : (
        <Card className="grid place-items-center border-dashed p-14 text-center text-sm text-muted-foreground">
          Unggah background dulu — field baru bisa ditempatkan di atas desainmu.
        </Card>
      )}

      <div className="flex gap-2">
        <Button onClick={onSubmit} disabled={save.isPending || !name}>
          {save.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
          Simpan template
        </Button>
        <Button variant="outline" onClick={() => router.back()}>
          Batal
        </Button>
      </div>
    </div>
  );
}
