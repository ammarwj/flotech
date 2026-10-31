"use client";

import { useState } from "react";
import { Download, ExternalLink, FileText, ImageOff } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader } from "@/components/ui/dialog";

/**
 * Whether to render this URL as a PDF.
 *
 * The extension decides, not a MIME type: these URLs are what UploadController
 * stored, and it writes exactly two — `.pdf` passed through, everything else
 * re-encoded to `.webp`. Anything unrecognised is treated as an image, since
 * that is the branch that can admit it was wrong.
 */
const isPdfUrl = (url: string) => /\.pdf(\?|#|$)/i.test(url);

/** One uploaded file, as the viewer needs it. */
export interface ViewerDocument {
  /** The slot it answers — "Bukti Mahasiswa", not "IMG_2043.webp". */
  title: string;
  url: string;
  fileName?: string | null;
}

/**
 * An uploaded registration document, shown in place instead of in a new tab.
 *
 * A new tab is the wrong tool for the job this one does. An organizer checking
 * a hundred entrants is comparing the scan against the row it belongs to, and a
 * tab throws that row away: it loses the scroll position, the open detail
 * panel, and the filter that got them there, and coming back means finding all
 * three again. A popup blocker swallowing the click is the same story with no
 * feedback at all.
 *
 * Not the sibling of `DocumentPreviewDialog`: that one previews a *generated*
 * PDF it already holds as a blob, because the API needs a bearer token to issue
 * it. These files are public storage URLs, so there is nothing to fetch — the
 * `<img>`/`<iframe>` goes straight at the URL and the download is a plain
 * anchor. Two dialogs rather than one widened to both because the blob one owns
 * an object URL it must revoke, and that lifecycle has nothing to say here.
 */
export function DocumentViewerDialog({
  document: doc,
  onClose,
}: {
  document: ViewerDocument | null;
  onClose: () => void;
}) {
  return (
    // Keyed by url so switching documents without closing remounts the body:
    // otherwise a failed image leaves `broken` set and the next one renders as
    // broken too, however fine it is.
    <Dialog open={!!doc} onOpenChange={(open) => !open && onClose()}>
      {doc && (
        <DialogContent className="max-h-[92vh] max-w-3xl">
          {/* The description always renders, which is why no
              `aria-describedby={undefined}` is needed to silence Radix: an
              upload with no stored filename falls back to the format rather
              than to nothing. */}
          <DialogHeader
            icon={FileText}
            title={doc.title}
            description={doc.fileName ?? (isPdfUrl(doc.url) ? "Dokumen PDF" : "Gambar")}
          />

          <Body key={doc.url} doc={doc} />

          <div className="flex flex-col-reverse gap-2 border-t border-border p-4 sm:flex-row sm:items-center sm:justify-end">
            <Button variant="ghost" onClick={onClose}>
              Tutup
            </Button>
            {/* Both doors kept, because the dialog answers "what is on this
                scan" and neither of these does: a new tab is for reading a
                multi-page letter at full height, and the download is for
                filing it. They are the escape hatches, not the default. */}
            <Button variant="outline" asChild>
              <a href={doc.url} target="_blank" rel="noreferrer">
                <ExternalLink className="h-4 w-4" />
                Buka di tab baru
              </a>
            </Button>
            <Button asChild>
              {/* `download` is advisory across origins — storage may serve the
                  file inline regardless. The anchor still works, which is why
                  this is a link and not a fetch the token-less URL does not
                  need. */}
              <a href={doc.url} download={doc.fileName ?? undefined}>
                <Download className="h-4 w-4" />
                Unduh
              </a>
            </Button>
          </div>
        </DialogContent>
      )}
    </Dialog>
  );
}

/**
 * The file itself.
 *
 * Two render paths, because the two formats fail differently. An image gets an
 * `<img>` that can report `onError`, so a dead link says so; a PDF gets an
 * `<iframe>`, which cannot — a browser with no built-in viewer shows its own
 * blank frame and there is no event to hear. That asymmetry is why the fallback
 * below is only wired to the image: a spinner or an error state on the iframe
 * would be guessing.
 */
function Body({ doc }: { doc: ViewerDocument }) {
  const [broken, setBroken] = useState(false);
  const isPdf = isPdfUrl(doc.url);

  if (broken) {
    return (
      <div className="grid min-h-0 flex-1 place-items-center gap-2 bg-[var(--bg-alt)] p-8 text-center">
        <ImageOff className="h-8 w-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">
          Berkasnya tidak bisa dimuat. Coba buka di tab baru.
        </p>
      </div>
    );
  }

  if (isPdf) {
    return (
      <iframe
        src={doc.url}
        title={doc.title}
        className="min-h-[60vh] flex-1 bg-[var(--bg-alt)]"
      />
    );
  }

  return (
    // Capped in `vh`, not `max-h-full`. The ceiling has to come from the
    // viewport: inside a flex child whose own height is content-derived,
    // `max-h-full` resolves against that content and the image sizes itself
    // first, so a tall KTP scan simply ran past the panel and got clipped. The
    // number is the panel's 92vh less its header and footer.
    //
    // `object-contain` rather than `w-full`: a KTP scan is wider than it is
    // tall and a passport photo is the reverse, and stretching either to the
    // panel's width is what makes the small print unreadable.
    <div className="grid min-h-0 flex-1 place-items-center bg-[var(--bg-alt)] p-3">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={doc.url}
        alt={doc.title}
        onError={() => setBroken(true)}
        className="max-h-[70vh] w-auto max-w-full object-contain"
      />
    </div>
  );
}
