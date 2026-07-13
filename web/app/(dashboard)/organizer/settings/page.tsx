"use client";

import { useState } from "react";
import Link from "next/link";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  AlertCircle,
  Building2,
  Check,
  Copy,
  CreditCard,
  ExternalLink,
  Loader2,
  Mail,
  Share2,
  Wallet,
} from "lucide-react";

import { updateOrganization, type UpdateOrgPayload } from "@/lib/api/organizations";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { SOCIAL_PLATFORMS } from "@/lib/social";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { ImageUploadField } from "@/components/shared/image-upload-field";
import { SectionHeader } from "@/components/event/section-header";
import { SocialIcon } from "@/components/shared/social-icon";
import { RedirectIfAdmin } from "@/components/auth/redirect-if-admin";
import type { Organization, SocialPlatform } from "@/types/api";

const APP_URL = process.env.NEXT_PUBLIC_APP_URL ?? "";

/** Inline validation message under a field. */
function FieldError({ message }: { message?: string }) {
  if (!message) return null;
  return (
    <p className="flex items-center gap-1.5 text-xs text-destructive">
      <AlertCircle className="h-3.5 w-3.5 shrink-0" />
      {message}
    </p>
  );
}

type Values = {
  name: string;
  slug: string;
  logo_url: string;
  banner_url: string;
  description: string;
  contact_email: string;
  contact_phone: string;
};

type Socials = Record<SocialPlatform, string>;

export default function OrganizerSettingsPage() {
  const { org, hasNoOrg, isLoading } = useActiveOrg();

  if (isLoading) {
    return <Skeleton className="h-[420px] rounded-xl" />;
  }

  if (hasNoOrg || !org) {
    return (
      <EmptyState
        icon={Building2}
        title="Belum ada organisasi"
        description="Buat organisasi dulu sebelum mengatur profilnya."
        action={
          <Button asChild>
            <Link href="/onboarding">Buat organisasi</Link>
          </Button>
        }
      />
    );
  }

  // Keyed on the org so the form re-seeds if the active org ever changes.
  return <SettingsForm key={org.id} org={org} />;
}

function SettingsForm({ org }: { org: Organization }) {
  const qc = useQueryClient();

  const [v, setV] = useState<Values>({
    name: org.name ?? "",
    slug: org.slug ?? "",
    logo_url: org.logo_url ?? "",
    banner_url: org.banner_url ?? "",
    description: org.description ?? "",
    contact_email: org.contact_email ?? "",
    contact_phone: org.contact_phone ?? "",
  });

  const [socials, setSocials] = useState<Socials>(
    () =>
      Object.fromEntries(
        SOCIAL_PLATFORMS.map((p) => [p.key, org.social_links?.[p.key] ?? ""])
      ) as Socials
  );

  const [serverErrors, setServerErrors] = useState<FieldErrors>({});
  const [clientErrors, setClientErrors] = useState<Record<string, string | undefined>>({});
  const [uploading, setUploading] = useState({ logo: false, banner: false });
  const [copied, setCopied] = useState(false);

  const errorFor = (k: keyof Values): string | undefined =>
    (k in clientErrors ? clientErrors[k] : serverErrors[k]) || undefined;

  const set = <K extends keyof Values>(k: K, val: Values[K]) => {
    setV((s) => ({ ...s, [k]: val }));
    setClientErrors((e) => ({ ...e, [k]: undefined }));
    setServerErrors((e) => ({ ...e, [k]: "" }));
  };

  const setSocial = (key: SocialPlatform, val: string) => {
    setSocials((s) => ({ ...s, [key]: val }));
    setServerErrors((e) => ({ ...e, [`social_links.${key}`]: "" }));
  };

  const invalidCls = (k: keyof Values) =>
    errorFor(k) ? "border-destructive focus-visible:ring-destructive" : "";

  const save = useMutation({
    mutationFn: (payload: UpdateOrgPayload) => updateOrganization(org.id, payload),
    onSuccess: () => {
      setServerErrors({});
      qc.invalidateQueries({ queryKey: ["organizations"] });
      toast.success("Pengaturan disimpan");
    },
    onError: (err) => {
      const { message, fieldErrors } = parseApiError(err, "Gagal menyimpan pengaturan.");
      setServerErrors(fieldErrors);
      toast.error(message);
    },
  });

  const publicUrl = v.slug ? `${APP_URL}/${v.slug}` : "";

  const copyPublicUrl = async () => {
    if (!publicUrl) return;
    try {
      await navigator.clipboard.writeText(publicUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      toast.error("Gagal menyalin tautan.");
    }
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const next: Record<string, string | undefined> = {
      name: v.name.trim() ? undefined : "Nama organisasi wajib diisi.",
      slug: !v.slug.trim()
        ? "Slug wajib diisi."
        : /^[a-z0-9-]+$/.test(v.slug)
          ? undefined
          : "Slug hanya boleh huruf kecil, angka, dan tanda hubung.",
    };

    setClientErrors(next);

    if (next.name || next.slug) {
      document.querySelector<HTMLElement>("[aria-invalid='true']")?.focus();
      return;
    }

    save.mutate({
      name: v.name.trim(),
      slug: v.slug.trim(),
      logo_url: v.logo_url || null,
      banner_url: v.banner_url || null,
      description: v.description.trim() || null,
      contact_email: v.contact_email.trim() || null,
      contact_phone: v.contact_phone.trim() || null,
      social_links: Object.fromEntries(
        SOCIAL_PLATFORMS.map((p) => [p.key, socials[p.key].trim() || null])
      ),
    });
  };

  const slugChanged = v.slug !== org.slug;
  const busy = uploading.logo || uploading.banner;

  return (
    <div>
      <RedirectIfAdmin />

      <PageHeader
        title="Pengaturan"
        description="Profil organisasi, tautan halaman publik, dan kontak yang dilihat peserta."
        actions={
          <Button variant="outline" asChild>
            <Link href={`/${org.slug}`} target="_blank">
              <ExternalLink className="h-4 w-4" />
              Lihat halaman publik
            </Link>
          </Button>
        }
      />

      <form onSubmit={handleSubmit} className="grid gap-6">
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
          <div className="grid min-w-0 gap-6">
            <Card>
              <SectionHeader
                icon={Building2}
                title="Profil Organisasi"
                description="Identitas yang tampil di halaman publik dan e-tiket."
              />
              <CardContent className="grid gap-5">
                <ImageUploadField
                  label="Logo"
                  value={v.logo_url}
                  onChange={(url) => set("logo_url", url)}
                  onBusyChange={(b) => setUploading((s) => ({ ...s, logo: b }))}
                  folder="organizations"
                  maxDim={512}
                  previewClassName="h-24 w-24"
                  placeholder={<Building2 className="h-7 w-7 text-muted-foreground" />}
                  hint="Maksimal 5 MB, otomatis dikonversi ke WebP. Bentuk persegi paling rapi."
                />

                <ImageUploadField
                  label="Banner"
                  value={v.banner_url}
                  onChange={(url) => set("banner_url", url)}
                  onBusyChange={(b) => setUploading((s) => ({ ...s, banner: b }))}
                  folder="organizations"
                  maxDim={1600}
                  previewClassName="aspect-[3/1] w-full"
                  hint="Sampul halaman publik. Rasio 3:1 (mis. 1600×533), maksimal 5 MB, otomatis dikonversi ke WebP."
                />

                <div className="grid gap-2">
                  <Label htmlFor="name">Nama organisasi</Label>
                  <Input
                    id="name"
                    value={v.name}
                    onChange={(e) => set("name", e.target.value)}
                    aria-invalid={!!errorFor("name")}
                    className={invalidCls("name")}
                    placeholder="Contoh: Flo Sports Community"
                  />
                  <FieldError message={errorFor("name")} />
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="slug">Slug halaman publik</Label>
                  <div className="flex items-center gap-2">
                    <Input
                      id="slug"
                      value={v.slug}
                      onChange={(e) =>
                        set("slug", e.target.value.toLowerCase().replace(/\s+/g, "-"))
                      }
                      aria-invalid={!!errorFor("slug")}
                      className={invalidCls("slug")}
                      placeholder="flo-sports"
                    />
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      disabled={!publicUrl}
                      onClick={copyPublicUrl}
                      aria-label="Salin tautan halaman publik"
                    >
                      {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                    </Button>
                  </div>
                  <FieldError message={errorFor("slug")} />
                  {!errorFor("slug") && (
                    <p className="truncate text-xs text-muted-foreground">
                      {publicUrl || "Tautan akan muncul setelah slug diisi."}
                    </p>
                  )}
                  {slugChanged && (
                    <p className="flex items-start gap-1.5 text-xs text-[var(--warning)]">
                      <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                      Mengganti slug mengubah semua tautan publik event kamu — tautan lama tidak
                      akan bisa dibuka lagi.
                    </p>
                  )}
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="description">Deskripsi</Label>
                  <Textarea
                    id="description"
                    rows={4}
                    value={v.description}
                    onChange={(e) => set("description", e.target.value)}
                    placeholder="Ceritakan singkat tentang organisasimu."
                  />
                  <FieldError message={errorFor("description")} />
                </div>
              </CardContent>
            </Card>

            <Card>
              <SectionHeader
                icon={Mail}
                title="Kontak"
                description="Dipakai peserta dan pembeli tiket untuk menghubungimu."
              />
              <CardContent className="grid gap-5 sm:grid-cols-2">
                <div className="grid gap-2">
                  <Label htmlFor="contact_email">Email</Label>
                  <Input
                    id="contact_email"
                    type="email"
                    value={v.contact_email}
                    onChange={(e) => set("contact_email", e.target.value)}
                    aria-invalid={!!errorFor("contact_email")}
                    className={invalidCls("contact_email")}
                    placeholder="halo@organisasi.id"
                  />
                  <FieldError message={errorFor("contact_email")} />
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="contact_phone">Nomor telepon</Label>
                  <Input
                    id="contact_phone"
                    value={v.contact_phone}
                    onChange={(e) => set("contact_phone", e.target.value)}
                    aria-invalid={!!errorFor("contact_phone")}
                    className={invalidCls("contact_phone")}
                    placeholder="08xxxxxxxxxx"
                  />
                  <FieldError message={errorFor("contact_phone")} />
                </div>
              </CardContent>
            </Card>

            <Card>
              <SectionHeader
                icon={Share2}
                title="Media Sosial"
                description="Tampil sebagai ikon di halaman publik organisasimu."
              />
              <CardContent className="grid gap-5 sm:grid-cols-2">
                {SOCIAL_PLATFORMS.map(({ key, label, placeholder }) => {
                  const error = serverErrors[`social_links.${key}`] || undefined;
                  return (
                    <div key={key} className="grid gap-2">
                      <Label htmlFor={`social-${key}`} className="flex items-center gap-2">
                        <SocialIcon platform={key} className="text-muted-foreground" />
                        {label}
                      </Label>
                      <Input
                        id={`social-${key}`}
                        value={socials[key]}
                        onChange={(e) => setSocial(key, e.target.value)}
                        aria-invalid={!!error}
                        className={error ? "border-destructive focus-visible:ring-destructive" : ""}
                        placeholder={placeholder}
                      />
                      <FieldError message={error} />
                    </div>
                  );
                })}
                <p className="text-xs text-muted-foreground sm:col-span-2">
                  Isi username saja atau tempel tautan profil lengkap — keduanya akan disimpan
                  sebagai tautan. Kosongkan untuk menghapus.
                </p>
              </CardContent>
            </Card>
          </div>

          <div className="grid gap-4">
            <Card className="p-5">
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <CreditCard className="h-4 w-4" />
                Paket saat ini
              </div>
              <p className="mt-2 text-xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
                {org.plan?.name ?? "Tanpa paket"}
              </p>
              <div className="mt-4 grid gap-2">
                <Button variant="outline" size="sm" asChild>
                  <Link href="/organizer/subscription">Kelola langganan</Link>
                </Button>
                <Button variant="ghost" size="sm" asChild>
                  <Link href="/organizer/upgrade">Ubah paket</Link>
                </Button>
              </div>
            </Card>

            <Card className="p-5">
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Wallet className="h-4 w-4" />
                Rekening pencairan
              </div>
              <p className="mt-2 text-sm">
                Rekening tujuan penarikan dana diatur di halaman Dompet.
              </p>
              <Button variant="outline" size="sm" className="mt-4 w-full" asChild>
                <Link href="/organizer/wallet">Buka Dompet</Link>
              </Button>
            </Card>
          </div>
        </div>

        <div className="flex justify-end gap-2">
          <Button type="submit" disabled={save.isPending || busy}>
            {save.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            Simpan perubahan
          </Button>
        </div>
      </form>
    </div>
  );
}
