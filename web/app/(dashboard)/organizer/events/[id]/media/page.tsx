"use client";

import { useRef, useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Handshake, ImagePlus, Images, Loader2, Trash2, X } from "lucide-react";
import { toast } from "sonner";

import {
  addPhotos,
  addSponsor,
  deletePhoto,
  deleteSponsor,
  getPhotos,
  getSponsors,
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
import { useCatalog } from "@/lib/hooks/use-catalog";
import type { EventPhoto, SponsorTier } from "@/types/api";

/** Group an event's photos by album, default album last. */
function byAlbum(photos: EventPhoto[]): [string, EventPhoto[]][] {
  const map = new Map<string, EventPhoto[]>();
  for (const p of photos) {
    const key = p.album ?? "";
    map.set(key, [...(map.get(key) ?? []), p]);
  }
  return [...map.entries()].sort((a, b) => (a[0] === "" ? 1 : b[0] === "" ? -1 : a[0].localeCompare(b[0])));
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
      addPhotos(orgId!, eventId, album.trim() || null, urls.map((photo_url) => ({ photo_url }))),
    onSuccess: (photos) => {
      toast.success("Foto ditambahkan");
      qc.setQueryData(["photos", orgId, eventId], photos);
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan foto.").message),
  });

  const removePhoto = useMutation({
    mutationFn: (id: string) => deletePhoto(orgId!, id),
    onSuccess: () => {
      toast.success("Foto dihapus");
      qc.invalidateQueries({ queryKey: ["photos", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus foto.").message),
  });

  /** Compress every picked file, upload them, then store the URLs in one go. */
  const uploadPhotos = async (files: FileList | null) => {
    if (!files?.length) return;

    setUploading(true);
    try {
      const urls = await Promise.all(
        Array.from(files)
          .filter((f) => f.type.startsWith("image/"))
          .map(async (file) => uploadImage(await compressToWebp(file, { maxDim: 1600, quality: 0.82 }), "events"))
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
  const emptySponsor: SponsorInput = { name: "", logo_url: "", website_url: "", tier: "sponsor" };
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
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan sponsor.").message),
  });

  const removeSponsor = useMutation({
    mutationFn: (id: string) => deleteSponsor(orgId!, id),
    onSuccess: () => {
      toast.success("Sponsor dihapus");
      qc.invalidateQueries({ queryKey: ["sponsors", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus sponsor.").message),
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
  const sponsors = sponsorsQuery.data ?? [];
  const canSaveSponsor = sponsor.name.trim() !== "" && sponsor.logo_url !== "";

  return (
    <div>
      <PageHeader
        title="Galeri & Sponsor"
        description={eventQuery.data?.name ?? "Album foto dan logo sponsor yang tampil di halaman publik event."}
        backHref={`/organizer/events/${eventId}/edit`}
        backLabel="Kelola event"
      />

      <div className="grid gap-6">
        {/* ---- Photo albums ---- */}
        <Card>
          <SectionHeader
            icon={Images}
            title="Album Foto"
            description="Unggah beberapa foto sekaligus. Beri nama album untuk mengelompokkannya."
          />
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
                Belum ada foto. Foto yang diunggah tampil di halaman publik event.
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
        </Card>

        {/* ---- Sponsors ---- */}
        <Card>
          <SectionHeader
            icon={Handshake}
            title="Sponsor & Partner"
            description="Logo tampil di halaman publik event, dikelompokkan per tier: penyelenggara, sponsor, media partner, pendukung."
          />
          <CardContent className="grid gap-5">
            <div className="grid gap-4 md:grid-cols-[auto_1fr_1fr_auto_auto] md:items-end">
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
                    <img src={sponsor.logo_url} alt="" className="max-h-14 max-w-24 object-contain" />
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
                  onChange={(e) => setSponsor((s) => ({ ...s, name: e.target.value }))}
                  placeholder="Bank Jogja"
                />
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="sponsor-url" className="font-semibold">
                  Website (opsional)
                </Label>
                <Input
                  id="sponsor-url"
                  value={sponsor.website_url ?? ""}
                  onChange={(e) => setSponsor((s) => ({ ...s, website_url: e.target.value }))}
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
                  onChange={(e) => setSponsor((s) => ({ ...s, tier: e.target.value as SponsorTier }))}
                  className="w-40"
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
              <p className="text-sm text-muted-foreground">Belum ada sponsor.</p>
            ) : (
              <div className="grid gap-2">
                {sponsors.map((s) => (
                  <div
                    key={s.id}
                    className="flex items-center gap-4 rounded-lg border border-border px-3 py-2"
                  >
                    <span className="grid h-12 w-20 shrink-0 place-items-center rounded-md bg-[var(--bg-soft)]">
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img src={s.logo_url} alt={s.name} className="max-h-10 max-w-16 object-contain" />
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold">{s.name}</p>
                      <p className="truncate text-xs text-muted-foreground">
                        {sponsorTierLabel(s.tier)}
                        {s.website_url ? ` · ${s.website_url}` : ""}
                      </p>
                    </div>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => removeSponsor.mutate(s.id)}
                      aria-label={`Hapus ${s.name}`}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
