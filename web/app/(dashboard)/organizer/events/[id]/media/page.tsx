"use client";

import { useRef, useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ArrowDown,
  ArrowUp,
  Handshake,
  ImagePlus,
  Images,
  Loader2,
  Trash2,
  X,
} from "lucide-react";
import { toast } from "sonner";

import {
  addPhotos,
  addSponsor,
  deletePhoto,
  deleteSponsor,
  getPhotos,
  getSponsors,
  updateSponsor,
  type SponsorInput,
} from "@/lib/api/media";
import { uploadImage } from "@/lib/api/events";
import { getEvent } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { compressToWebp } from "@/lib/image";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { SectionHeader } from "@/components/event/section-header";
import { PlanFeatureNotice } from "@/components/event/plan-feature-notice";
import {
  getGalleryLimit,
  isGalleryEnabled,
  isSponsorLogosEnabled,
} from "@/lib/plan";
import { useCatalog } from "@/lib/hooks/use-catalog";
import type { EventPhoto, EventSponsor, SponsorTier } from "@/types/api";

/**
 * Group sponsors by tier, in the catalogue's own order.
 *
 * Mirrors SponsorStrip on the public page: it walks `sponsor_tiers` and filters
 * within each, so the organizer arranges exactly the rows a visitor will read.
 * Tiers with nothing in them are dropped — a heading over an empty list reads
 * as broken rather than as "none yet".
 */
function byTier(
  sponsors: EventSponsor[],
  tiers: { key: string; label: string }[],
): [SponsorTier, EventSponsor[]][] {
  const known = new Set(tiers.map((t) => t.key));

  // Rows whose tier the admin has since dropped from the catalogue keep their
  // own key rather than being folded into a real tier: sponsorTierLabel() falls
  // back to the raw key, so they stay visible and removable under a heading
  // that is at least honest. Filing them under "Sponsor" would claim a tier
  // they do not have. Same call as index() returning non-approved adjustments.
  const orphanKeys = [...new Set(sponsors.map((s) => s.tier).filter((t) => !known.has(t)))];

  return [...tiers.map((t) => t.key as SponsorTier), ...orphanKeys]
    .map((key) => [key, sponsors.filter((s) => s.tier === key)] as [SponsorTier, EventSponsor[]])
    .filter(([, list]) => list.length > 0);
}

/** Group an event's photos by album, default album last. */
function byAlbum(photos: EventPhoto[]): [string, EventPhoto[]][] {
  const map = new Map<string, EventPhoto[]>();
  for (const p of photos) {
    const key = p.album ?? "";
    map.set(key, [...(map.get(key) ?? []), p]);
  }
  return [...map.entries()].sort((a, b) =>
    a[0] === "" ? 1 : b[0] === "" ? -1 : a[0].localeCompare(b[0]),
  );
}

export default function EventMediaPage() {
  const { sponsor_tiers, sponsorTierLabel } = useCatalog();
  const { orgId } = useActiveOrg();
  const { id: eventId } = useParams<{ id: string }>();
  const qc = useQueryClient();

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });
  const photosQuery = useQuery({
    queryKey: ["photos", orgId, eventId],
    queryFn: () => getPhotos(orgId!, eventId),
    enabled: !!orgId,
  });
  // Both are entitlements of this event's plan.
  const galleryEnabled = isGalleryEnabled(eventQuery.data);
  const sponsorsEnabled = isSponsorLogosEnabled(eventQuery.data);
  const galleryLimit = getGalleryLimit(eventQuery.data);

  const sponsorsQuery = useQuery({
    queryKey: ["sponsors", orgId, eventId],
    queryFn: () => getSponsors(orgId!, eventId),
    enabled: !!orgId,
  });

  // ---- Photos ----
  const [album, setAlbum] = useState("");
  const [uploading, setUploading] = useState(false);
  const photoInput = useRef<HTMLInputElement>(null);

  const savePhotos = useMutation({
    mutationFn: (urls: string[]) =>
      addPhotos(
        orgId!,
        eventId,
        album.trim() || null,
        urls.map((photo_url) => ({ photo_url })),
      ),
    onSuccess: (photos) => {
      toast.success("Foto ditambahkan");
      qc.setQueryData(["photos", orgId, eventId], photos);
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal menyimpan foto.").message),
  });

  const removePhoto = useMutation({
    mutationFn: (id: string) => deletePhoto(orgId!, id),
    onSuccess: () => {
      toast.success("Foto dihapus");
      qc.invalidateQueries({ queryKey: ["photos", orgId, eventId] });
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal menghapus foto.").message),
  });

  /** Compress every picked file, upload them, then store the URLs in one go. */
  const uploadPhotos = async (files: FileList | null) => {
    if (!files?.length) return;

    setUploading(true);
    try {
      const urls = await Promise.all(
        Array.from(files)
          .filter((f) => f.type.startsWith("image/"))
          .map(async (file) =>
            uploadImage(
              await compressToWebp(file, { maxDim: 1600, quality: 0.82 }),
              "events",
            ),
          ),
      );

      if (urls.length === 0) {
        toast.error("File harus berupa gambar.");
        return;
      }

      savePhotos.mutate(urls);
    } catch {
      toast.error("Gagal mengunggah foto. Coba lagi.");
    } finally {
      setUploading(false);
      if (photoInput.current) photoInput.current.value = "";
    }
  };

  // ---- Sponsors ----
  const emptySponsor: SponsorInput = {
    name: "",
    logo_url: "",
    website_url: "",
    tier: "sponsor",
  };
  const [sponsor, setSponsor] = useState<SponsorInput>(emptySponsor);
  const [logoUploading, setLogoUploading] = useState(false);
  const logoInput = useRef<HTMLInputElement>(null);

  const saveSponsor = useMutation({
    mutationFn: () => addSponsor(orgId!, eventId, sponsor),
    onSuccess: () => {
      toast.success("Sponsor ditambahkan");
      setSponsor(emptySponsor);
      qc.invalidateQueries({ queryKey: ["sponsors", orgId, eventId] });
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal menyimpan sponsor.").message),
  });

  /**
   * Renumber a whole tier from the order it should end up in.
   *
   * Writing the full list rather than swapping the two neighbours' values is
   * what makes this work on data that predates the feature: rows added before
   * `sort_order` was ever set share a value, and swapping two equal numbers
   * changes nothing — the list falls back to `created_at` and the arrow reports
   * success while doing nothing. Renumbering also repairs those rows on the
   * first move instead of leaving them to collide again.
   *
   * Only the rows whose position actually changed are sent; a tier of eight
   * where two swap is two PATCHes, not eight.
   *
   * Not optimistic: the requests can land out of order, and a list that snaps
   * back after appearing to move is worse than one that pauses.
   */
  const reorder = useMutation({
    mutationFn: async (ordered: EventSponsor[]) => {
      await Promise.all(
        ordered
          .map((s, i) => [s, i] as const)
          .filter(([s, i]) => s.sort_order !== i)
          .map(([s, i]) => updateSponsor(orgId!, s.id, { sort_order: i })),
      );
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["sponsors", orgId, eventId] }),
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal mengubah urutan sponsor.").message),
  });

  const moveSponsor = (list: EventSponsor[], index: number, dir: -1 | 1) => {
    const to = index + dir;
    if (to < 0 || to >= list.length) return;
    const next = [...list];
    [next[index], next[to]] = [next[to], next[index]];
    reorder.mutate(next);
  };

  const removeSponsor = useMutation({
    mutationFn: (id: string) => deleteSponsor(orgId!, id),
    onSuccess: () => {
      toast.success("Sponsor dihapus");
      qc.invalidateQueries({ queryKey: ["sponsors", orgId, eventId] });
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal menghapus sponsor.").message),
  });

  const uploadLogo = async (file?: File | null) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      toast.error("File harus berupa gambar.");
      return;
    }

    setLogoUploading(true);
    try {
      const webp = await compressToWebp(file, { maxDim: 600, quality: 0.9 });
      setSponsor((s) => ({ ...s, logo_url: "" }));
      const url = await uploadImage(webp, "sponsors");
      setSponsor((s) => ({ ...s, logo_url: url }));
    } catch {
      toast.error("Gagal mengunggah logo.");
    } finally {
      setLogoUploading(false);
    }
  };

  const photos = photosQuery.data ?? [];
  const photoCount = photos.length;
  const sponsors = sponsorsQuery.data ?? [];
  const canSaveSponsor = sponsor.name.trim() !== "" && sponsor.logo_url !== "";

  return (
    <div>
      <PageHeader
        title="Galeri & Sponsor"
        description={
          eventQuery.data?.name ??
          "Album foto dan logo sponsor yang tampil di halaman publik event."
        }
        backHref={`/organizer/events/${eventId}/edit`}
        backLabel="Kelola event"
      />

      {/* `min-w-0` on the cards, not just here: a grid track's implicit floor is
          the widest min-content among its items, so one unshrinkable row inside
          a card widens the track — and with it every other card — past the
          viewport. The dashboard's `overflow-x-clip` then hides the result
          rather than fixing it, which is how this shipped looking fine. */}
      <div className="grid gap-6 *:min-w-0">
        {/* ---- Photo albums ---- */}
        <Card>
          <SectionHeader
            icon={Images}
            title="Album Foto"
            description={
              galleryLimit !== null
                ? `Unggah beberapa foto sekaligus. Paket event ini: ${photoCount}/${galleryLimit} foto.`
                : "Unggah beberapa foto sekaligus. Beri nama album untuk mengelompokkannya."
            }
          />
          {!galleryEnabled ? (
            <CardContent>
              <PlanFeatureNotice feature="Galeri foto" />
            </CardContent>
          ) : (
            <CardContent className="grid gap-5">
              <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1.5">
                  <Label htmlFor="album" className="font-semibold">
                    Album
                  </Label>
                  <Input
                    id="album"
                    value={album}
                    onChange={(e) => setAlbum(e.target.value)}
                    placeholder="Opening Ceremony"
                    className="w-64"
                  />
                </div>
                <input
                  ref={photoInput}
                  type="file"
                  accept="image/*"
                  multiple
                  className="hidden"
                  onChange={(e) => uploadPhotos(e.target.files)}
                />
                <Button
                  variant="outline"
                  onClick={() => photoInput.current?.click()}
                  disabled={uploading || savePhotos.isPending}
                >
                  {uploading || savePhotos.isPending ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <ImagePlus className="h-4 w-4" />
                  )}
                  {uploading ? "Mengunggah…" : "Pilih Foto"}
                </Button>
                <p className="text-xs text-muted-foreground">
                  Kosongkan nama album untuk menaruh foto di galeri umum.
                </p>
              </div>

              {photosQuery.isLoading ? (
                <Skeleton className="h-32 w-full rounded-xl" />
              ) : photos.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  Belum ada foto. Foto yang diunggah tampil di halaman publik
                  event.
                </p>
              ) : (
                byAlbum(photos).map(([name, list]) => (
                  <section key={name || "umum"} className="grid gap-3">
                    <h3 className="text-xs font-bold uppercase tracking-wide text-muted-foreground">
                      {name || "Galeri umum"} · {list.length} foto
                    </h3>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                      {list.map((p) => (
                        <div
                          key={p.id}
                          className="group relative aspect-square overflow-hidden rounded-lg border border-border"
                        >
                          {/* eslint-disable-next-line @next/next/no-img-element */}
                          <img
                            src={p.photo_url}
                            alt={p.caption ?? ""}
                            className="h-full w-full object-cover"
                          />
                          <button
                            onClick={() => removePhoto.mutate(p.id)}
                            aria-label="Hapus foto"
                            className="absolute right-1.5 top-1.5 grid h-7 w-7 place-items-center rounded-md bg-black/60 text-white opacity-0 transition-opacity hover:bg-black/80 group-hover:opacity-100"
                          >
                            <X className="h-4 w-4" />
                          </button>
                        </div>
                      ))}
                    </div>
                  </section>
                ))
              )}
            </CardContent>
          )}
        </Card>

        {/* ---- Sponsors ---- */}
        <Card>
          <SectionHeader
            icon={Handshake}
            title="Sponsor & Partner"
            description="Logo tampil di halaman publik event, dikelompokkan per tier: penyelenggara, sponsor, media partner, pendukung."
          />
          {!sponsorsEnabled ? (
            <CardContent>
              <PlanFeatureNotice feature="Logo sponsor" />
            </CardContent>
          ) : (
            <CardContent className="grid gap-5">
              {/* `*:min-w-0` for the reason spelled out on CardContent: on a
                  phone this collapses to one column, and `1fr` still floors at
                  the min-content of the widest cell. */}
              <div className="grid gap-4 *:min-w-0 md:grid-cols-[auto_1fr_1fr_auto_auto] md:items-end">
                <div className="grid gap-1.5">
                  <Label className="font-semibold">Logo</Label>
                  <input
                    ref={logoInput}
                    type="file"
                    accept="image/*"
                    className="hidden"
                    onChange={(e) => uploadLogo(e.target.files?.[0])}
                  />
                  <button
                    type="button"
                    onClick={() => logoInput.current?.click()}
                    disabled={logoUploading}
                    className="grid h-16 w-28 place-items-center rounded-lg border border-dashed border-border bg-[var(--bg-soft)] text-muted-foreground transition-colors hover:border-[var(--brand-500)] hover:text-foreground"
                  >
                    {logoUploading ? (
                      <Loader2 className="h-5 w-5 animate-spin" />
                    ) : sponsor.logo_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={sponsor.logo_url}
                        alt=""
                        className="max-h-14 max-w-24 object-contain"
                      />
                    ) : (
                      <ImagePlus className="h-5 w-5" />
                    )}
                  </button>
                </div>

                <div className="grid gap-1.5">
                  <Label htmlFor="sponsor-name" className="font-semibold">
                    Nama sponsor
                  </Label>
                  <Input
                    id="sponsor-name"
                    value={sponsor.name}
                    onChange={(e) =>
                      setSponsor((s) => ({ ...s, name: e.target.value }))
                    }
                    placeholder="Nama sponsor"
                  />
                </div>

                <div className="grid gap-1.5">
                  <Label htmlFor="sponsor-url" className="font-semibold">
                    Website (opsional)
                  </Label>
                  <Input
                    id="sponsor-url"
                    value={sponsor.website_url ?? ""}
                    onChange={(e) =>
                      setSponsor((s) => ({ ...s, website_url: e.target.value }))
                    }
                    placeholder="https://…"
                  />
                </div>

                <div className="grid gap-1.5">
                  <Label htmlFor="sponsor-tier" className="font-semibold">
                    Tier
                  </Label>
                  <Select
                    id="sponsor-tier"
                    value={sponsor.tier}
                    onChange={(e) =>
                      setSponsor((s) => ({
                        ...s,
                        tier: e.target.value as SponsorTier,
                      }))
                    }
                    // Full width on a phone, where it is a row of its own;
                    // `w-40` only makes sense beside the other four columns.
                    className="w-full md:w-40"
                  >
                    {sponsor_tiers.map((t) => (
                      <option key={t.key} value={t.key}>
                        {t.label}
                      </option>
                    ))}
                  </Select>
                </div>

                <Button
                  onClick={() => saveSponsor.mutate()}
                  disabled={!canSaveSponsor || saveSponsor.isPending}
                >
                  {saveSponsor.isPending ? "Menyimpan…" : "Tambah"}
                </Button>
              </div>

              {sponsorsQuery.isLoading ? (
                <Skeleton className="h-24 w-full rounded-xl" />
              ) : sponsors.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  Belum ada sponsor.
                </p>
              ) : (
                /* Grouped by tier, because that is how the public page renders
                   them — SponsorStrip iterates the tiers and filters within
                   each. A flat list with arrows that cross a tier boundary
                   would offer a move that changes nothing a visitor can see. */
                <div className="grid gap-5 *:min-w-0">
                  {byTier(sponsors, sponsor_tiers).map(([tier, list]) => (
                    <section key={tier} className="grid gap-2">
                      <h3 className="text-xs font-bold uppercase tracking-wide text-muted-foreground">
                        {sponsorTierLabel(tier)} · {list.length} logo
                      </h3>
                      {list.map((s, i) => (
                        <div
                          key={s.id}
                          className="flex min-w-0 items-center gap-3 rounded-lg border border-border px-3 py-2"
                        >
                          {/* Narrower on a phone. At 80px wide the thumbnail,
                              the gaps and three controls left ~70px for the
                              name, which truncated every sponsor to two
                              syllables — the one thing the row exists to show. */}
                          <span className="grid h-10 w-12 shrink-0 place-items-center rounded-md bg-[var(--bg-soft)] sm:h-12 sm:w-20">
                            {/* eslint-disable-next-line @next/next/no-img-element */}
                            <img
                              src={s.logo_url}
                              alt={s.name}
                              className="max-h-8 max-w-10 object-contain sm:max-h-10 sm:max-w-16"
                            />
                          </span>
                          <div className="min-w-0 flex-1">
                            <p className="truncate font-semibold">{s.name}</p>
                            <p className="truncate text-xs text-muted-foreground">
                              {s.website_url || "Tanpa tautan"}
                            </p>
                          </div>
                          <div className="flex shrink-0 items-center">
                            {/* Arrows, not a sort_order input: the organizer is
                                arranging rows they can see, and a number that
                                only means something relative to its neighbours
                                makes them do the arithmetic the UI should.

                                Hidden outright in a tier of one — two dead
                                controls on every row of a one-logo tier read as
                                broken, and there is nothing to arrange. */}
                            {list.length > 1 && (
                              <>
                                <button
                                  type="button"
                                  onClick={() => moveSponsor(list, i, -1)}
                                  disabled={i === 0 || reorder.isPending}
                                  aria-label={`Naikkan ${s.name}`}
                                  className="rounded p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-30"
                                >
                                  <ArrowUp className="h-4 w-4" />
                                </button>
                                <button
                                  type="button"
                                  onClick={() => moveSponsor(list, i, 1)}
                                  disabled={i === list.length - 1 || reorder.isPending}
                                  aria-label={`Turunkan ${s.name}`}
                                  className="rounded p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-30"
                                >
                                  <ArrowDown className="h-4 w-4" />
                                </button>
                              </>
                            )}
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => removeSponsor.mutate(s.id)}
                              aria-label={`Hapus ${s.name}`}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </div>
                        </div>
                      ))}
                    </section>
                  ))}
                </div>
              )}
            </CardContent>
          )}
        </Card>
      </div>
    </div>
  );
}
