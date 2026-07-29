/**
 * A deliberately oversized image, built in memory rather than committed.
 *
 * The receipt upload is only interesting when the source is *bigger than the
 * pipeline allows* — the whole point of `compressToWebp` + `/uploads/image` is
 * that a 12 MB phone photo becomes something the endpoint's `max:5120` rule
 * will accept. `fixtures/transfer-proof.png` is a 16×16 PNG, so uploading it
 * proves the plumbing but never the compression: it is already smaller than
 * every limit involved.
 *
 * BMP, not PNG or JPEG, for one reason: it is uncompressed, so the source byte
 * count is a pure function of the dimensions and cannot drift with the encoder
 * or the pixel content. 2000×2000 is always ~12 MB, which makes "the request
 * was an order of magnitude smaller than the file picked" an assertion with a
 * fixed baseline instead of a guess. Chromium decodes BMP, which is all
 * `createImageBitmap` in `lib/image.ts` needs.
 *
 * The pixels are a smooth gradient rather than noise so the *result* is small
 * too — noise is incompressible and would leave the WebP size dominated by
 * entropy rather than by the downscale being tested.
 */
export interface LargeImage {
  name: string;
  mimeType: string;
  buffer: Buffer;
}

export function largeBitmap(width = 2000, height = 2000): LargeImage {
  // Rows are padded to a multiple of 4 bytes; a width divisible by 4 avoids it.
  const rowBytes = width * 3;
  if (rowBytes % 4 !== 0) throw new Error(`width ${width} would need row padding`);

  const pixels = Buffer.allocUnsafe(rowBytes * height);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = y * rowBytes + x * 3;
      // BGR, and bottom-up — irrelevant to the test, but a real decoder is
      // reading this, so it may as well be a real gradient.
      pixels[i] = (x * 255) / width;
      pixels[i + 1] = (y * 255) / height;
      pixels[i + 2] = 128;
    }
  }

  const fileHeader = Buffer.alloc(14);
  fileHeader.write("BM", 0, "ascii");
  fileHeader.writeUInt32LE(14 + 40 + pixels.length, 2); // total file size
  fileHeader.writeUInt32LE(14 + 40, 10); // offset to pixel data

  const dib = Buffer.alloc(40);
  dib.writeUInt32LE(40, 0); // BITMAPINFOHEADER size
  dib.writeInt32LE(width, 4);
  dib.writeInt32LE(height, 8);
  dib.writeUInt16LE(1, 12); // planes
  dib.writeUInt16LE(24, 14); // bits per pixel
  dib.writeUInt32LE(0, 16); // BI_RGB, no compression
  dib.writeUInt32LE(pixels.length, 20);

  return {
    name: "struk-transfer.bmp",
    mimeType: "image/bmp",
    buffer: Buffer.concat([fileHeader, dib, pixels]),
  };
}
