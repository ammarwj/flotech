export interface CompressOptions {
  /** Cap for the longest side, in pixels. */
  maxDim?: number;
  /** WebP quality, 0..1. */
  quality?: number;
}

/**
 * Downscale and re-encode an image to WebP in the browser before upload —
 * smaller payloads, consistent format. Falls back to the original file if the
 * browser can't produce a WebP blob.
 */
export async function compressToWebp(file: File, opts: CompressOptions = {}): Promise<File> {
  if (typeof window === "undefined" || !file.type.startsWith("image/")) return file;

  const { maxDim = 1280, quality = 0.8 } = opts;

  try {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, maxDim / Math.max(bitmap.width, bitmap.height));
    const w = Math.max(1, Math.round(bitmap.width * scale));
    const h = Math.max(1, Math.round(bitmap.height * scale));

    const canvas = document.createElement("canvas");
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext("2d");
    if (!ctx) return file;
    ctx.drawImage(bitmap, 0, 0, w, h);
    bitmap.close?.();

    const blob = await new Promise<Blob | null>((resolve) =>
      canvas.toBlob(resolve, "image/webp", quality)
    );
    if (!blob) return file;

    const name = file.name.replace(/\.[^.]+$/, "") + ".webp";
    return new File([blob], name, { type: "image/webp" });
  } catch {
    return file;
  }
}
