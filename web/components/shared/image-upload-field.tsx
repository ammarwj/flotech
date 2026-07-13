"use client";

import { useRef, useState } from "react";
import { ImagePlus, Loader2, X } from "lucide-react";
import { toast } from "sonner";

import { uploadImage } from "@/lib/api/events";
import { compressToWebp } from "@/lib/image";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";

/**
 * Pick an image → compress to WebP → upload → hand the stored URL back.
 *
 * The API re-encodes to WebP as well; compressing here just keeps the upload
 * small and gives an instant local preview from the same blob we send.
 */
export function ImageUploadField({
  label,
  value,
  onChange,
  onBusyChange,
  folder,
  maxDim,
  hint,
  className,
  previewClassName,
  placeholder,
}: {
  label: string;
  /** Stored URL, or "" when unset. */
  value: string;
  onChange: (url: string) => void;
  /** Lets the form disable Save while an upload is in flight. */
  onBusyChange?: (busy: boolean) => void;
  folder: string;
  /** Longest side kept, in pixels. */
  maxDim: number;
  hint?: React.ReactNode;
  className?: string;
  /** Shape of the preview box, e.g. "h-20 w-20" or "aspect-[3/1] w-full". */
  previewClassName?: string;
  /** Rendered inside the empty preview box. */
  placeholder?: React.ReactNode;
}) {
  const [preview, setPreview] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const setBusy = (busy: boolean) => {
    setUploading(busy);
    onBusyChange?.(busy);
  };

  // Local blob first, else the stored http(s) URL (a mock:// one won't render).
  const shown = preview ?? (value && /^https?:\/\//.test(value) ? value : null);

  const pick = async (file?: File | null) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      toast.error("File harus berupa gambar.");
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast.error("Ukuran gambar maksimal 5 MB.");
      return;
    }

    setBusy(true);
    try {
      const webp = await compressToWebp(file, { maxDim, quality: 0.85 });
      setPreview(URL.createObjectURL(webp));
      onChange(await uploadImage(webp, folder));
    } catch {
      toast.error(`Gagal mengunggah ${label.toLowerCase()}. Coba lagi.`);
      setPreview(null);
    } finally {
      setBusy(false);
    }
  };

  const clear = () => {
    setPreview(null);
    onChange("");
    if (inputRef.current) inputRef.current.value = "";
  };

  return (
    <div className={cn("grid gap-2", className)}>
      <Label>{label}</Label>

      <div
        className={cn(
          "relative grid place-items-center overflow-hidden rounded-xl border border-border bg-muted",
          previewClassName
        )}
      >
        {shown ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={shown} alt={label} className="h-full w-full object-cover" />
        ) : (
          (placeholder ?? <ImagePlus className="h-6 w-6 text-muted-foreground" />)
        )}
        {uploading && (
          <div className="absolute inset-0 grid place-items-center bg-background/70">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
          </div>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={uploading}
          onClick={() => inputRef.current?.click()}
        >
          <ImagePlus className="h-4 w-4" />
          {shown ? `Ganti ${label.toLowerCase()}` : `Unggah ${label.toLowerCase()}`}
        </Button>
        {shown && (
          <Button type="button" variant="ghost" size="sm" disabled={uploading} onClick={clear}>
            <X className="h-4 w-4" />
            Hapus
          </Button>
        )}
      </div>

      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}

      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        className="hidden"
        onChange={(e) => pick(e.target.files?.[0])}
      />
    </div>
  );
}
