"use client";

import { Suspense, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { AlertCircle, Banknote, ImageIcon, Mail, Share2 } from "lucide-react";

import { PillTabs } from "@/components/event/pill-tabs";
import { SectionHeader } from "@/components/event/section-header";
import { PageHeader } from "@/components/shared/page-header";
import { SocialIcon } from "@/components/shared/social-icon";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { ImageUploadField } from "@/components/shared/image-upload-field";
import {
  getAdminSiteSettings,
  updateSiteSettings,
  uploadFavicon,
  type SiteSettingsInput,
} from "@/lib/api/landing";
import { SITE_SETTINGS_KEY } from "@/lib/hooks/use-site-settings";
import { SOCIAL_PLATFORMS } from "@/lib/social";
import type { SocialPlatform } from "@/types/api";

/**
 * The Save button for one tab, in that tab's own card footer.
 *
 * Each tab submits only its own fields (see `payloadFor`), so the button
 * belongs to the card it saves rather than floating under all of them.
 */
function SaveRow({
  pending,
  disabled,
  onSave,
}: {
  pending: boolean;
  disabled: boolean;
  onSave: () => void;
}) {
  return (
    <div className="flex justify-end border-t border-border px-6 py-4">
      <Button disabled={disabled} onClick={onSave}>
        {pending ? "Menyimpan…" : "Simpan"}
      </Button>
    </div>
  );
}

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

type ContactField = "contact_email" | "contact_phone" | "sales_email";
type BankField =
  | "bank_name"
  | "bank_code"
  | "account_number"
  | "account_holder";
/** Uploaded, not typed — but stored and submitted as plain URLs like the rest. */
type BrandField = "logo_url" | "favicon_url";
/** Every plain-text field on this page — they all share one `draft` map. */
type TextField = ContactField | BankField | BrandField;

const EMPTY_SOCIALS = Object.fromEntries(
  SOCIAL_PLATFORMS.map((p) => [p.key, ""]),
) as Record<SocialPlatform, string>;

/**
 * Four groups that share a page but not a subject. `brand` is the default, so
 * it carries no query param.
 */
const TABS = [
  { key: "brand", label: "Identitas", icon: ImageIcon },
  { key: "contact", label: "Kontak", icon: Mail },
  { key: "social", label: "Media Sosial", icon: Share2 },
  { key: "bank", label: "Rekening", icon: Banknote },
];

function AdminSiteSettingsPage() {
  const qc = useQueryClient();
  const router = useRouter();
  const params = useSearchParams();
  const query = useQuery({
    queryKey: ["admin-site-settings"],
    queryFn: getAdminSiteSettings,
  });

  // Only what the admin has touched; everything else reads straight from the
  // server, so no effect is needed to seed the form (pattern from /admin/settings).
  const [draft, setDraft] = useState<Partial<Record<TextField, string>>>({});
  const [socialDraft, setSocialDraft] = useState<
    Partial<Record<SocialPlatform, string>>
  >({});
  const [serverErrors, setServerErrors] = useState<FieldErrors>({});
  // Disables Save while a file is still on its way up, so the form cannot be
  // submitted with a URL that does not exist yet.
  const [uploading, setUploading] = useState<Record<BrandField, boolean>>({
    logo_url: false,
    favicon_url: false,
  });

  const contact = (field: TextField) =>
    draft[field] ?? query.data?.[field] ?? "";
  const social = (key: SocialPlatform) =>
    socialDraft[key] ?? query.data?.social_links?.[key] ?? EMPTY_SOCIALS[key];

  /**
   * Each tab saves only its own fields.
   *
   * The API fills just the keys it was sent, so an omitted field keeps its
   * stored value — that is what makes a per-tab payload safe, and what stops
   * one tab's blank inputs from wiping another's.
   */
  const payloadFor = (group: string): SiteSettingsInput => {
    if (group === "brand") {
      return {
        logo_url: contact("logo_url").trim() || null,
        favicon_url: contact("favicon_url").trim() || null,
      };
    }

    if (group === "contact") {
      return {
        contact_email: contact("contact_email").trim() || null,
        contact_phone: contact("contact_phone").trim() || null,
        sales_email: contact("sales_email").trim() || null,
      };
    }

    if (group === "social") {
      return {
        social_links: Object.fromEntries(
          SOCIAL_PLATFORMS.map((p) => [p.key, social(p.key).trim() || null]),
        ),
      };
    }

    return {
      bank_name: contact("bank_name").trim() || null,
      bank_code: contact("bank_code").trim() || null,
      account_number: contact("account_number").trim() || null,
      account_holder: contact("account_holder").trim() || null,
    };
  };

  const save = useMutation({
    mutationFn: (group: string) => updateSiteSettings(payloadFor(group)),
    onSuccess: () => {
      setServerErrors({});
      // Drop the local draft so the normalized values ("@floevent" → the full
      // URL) come back from the server instead of the raw text still on screen.
      setDraft({});
      setSocialDraft({});
      qc.invalidateQueries({ queryKey: ["admin-site-settings"] });
      // The footer in this same tab reads the public endpoint.
      qc.invalidateQueries({ queryKey: SITE_SETTINGS_KEY });
      toast.success("Pengaturan disimpan");
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan pengaturan.");
      setServerErrors(parsed.fieldErrors);
      if (Object.keys(parsed.fieldErrors).length === 0)
        toast.error(parsed.message);
    },
  });

  // The open tab lives in the URL, not in state: a reload has to land back on
  // the same group rather than throwing the admin to the top of the page.
  const tab = TABS.some((t) => t.key === params.get("tab")) ? params.get("tab")! : "brand";

  const setTab = (key: string) => {
    const next = new URLSearchParams(params.toString());
    if (key === "brand") next.delete("tab");
    else next.set("tab", key);
    const qs = next.toString();
    router.replace(qs ? `/admin/site-settings?${qs}` : "/admin/site-settings", { scroll: false });
  };

  const invalidCls = (key: string) =>
    serverErrors[key]
      ? "border-destructive focus-visible:ring-destructive"
      : "";

  return (
    <>
      <PageHeader
        title="Pengaturan Situs"
        description="Identitas visual platform, cara pengunjung menghubungi floevent, dan rekening penerima pembayaran paket. Kontak & sosmed tampil di footer semua halaman publik — yang dikosongkan tidak ditampilkan."
      />

      {query.isError && (
        <p className="mb-6 text-sm text-destructive">
          Tidak bisa memuat pengaturan (butuh akses Super Admin &amp; API
          berjalan).
        </p>
      )}

      {query.isPending ? (
        <div className="grid gap-4">
          <Skeleton className="h-[260px] rounded-xl" />
          <Skeleton className="h-[340px] rounded-xl" />
        </div>
      ) : (
        <>
          <PillTabs items={TABS} activeKey={tab} onSelect={setTab} />

          <div className="mt-5">
          {tab === "brand" && (
          <Card>
            <SectionHeader
              icon={ImageIcon}
              title="Identitas Platform"
              description="Logo dan favicon yang dipakai di seluruh aplikasi, halaman publik, dan email."
            />
            <CardContent className="grid gap-5 sm:grid-cols-2">
              <ImageUploadField
                label="Logo"
                value={contact("logo_url")}
                onChange={(url) => setDraft((d) => ({ ...d, logo_url: url }))}
                onBusyChange={(b) => setUploading((u) => ({ ...u, logo_url: b }))}
                folder="platform"
                maxDim={512}
                previewClassName="h-24 w-24"
                hint="PNG atau JPEG, maksimal 5 MB — otomatis dikonversi ke WebP. Kosongkan untuk memakai logo bawaan."
              />

              <ImageUploadField
                label="Favicon"
                value={contact("favicon_url")}
                onChange={(url) => setDraft((d) => ({ ...d, favicon_url: url }))}
                onBusyChange={(b) => setUploading((u) => ({ ...u, favicon_url: b }))}
                folder="platform"
                maxDim={128}
                // Sent as the original file, not a WebP blob: the server
                // re-encodes it to a real .ico, which it cannot do from WebP.
                upload={uploadFavicon}
                previewClassName="h-16 w-16"
                hint="Ikon tab browser. Disimpan sebagai .ico 64×64 — pakai gambar persegi agar tidak terpotong."
              />

              <p className="text-xs text-muted-foreground sm:col-span-2">
                Perubahan favicon mungkin baru terlihat setelah hard reload —
                browser menyimpannya secara agresif.
              </p>
            </CardContent>
            <SaveRow
              pending={save.isPending}
              disabled={save.isPending || uploading.logo_url || uploading.favicon_url}
              onSave={() => save.mutate("brand")}
            />
          </Card>
          )}

          {tab === "contact" && (
          <Card>
            <SectionHeader
              icon={Mail}
              title="Kontak"
              description="Ditampilkan di footer sebagai tautan yang bisa langsung diklik."
            />
            <CardContent className="grid gap-5 sm:grid-cols-2">
              <div className="grid gap-2">
                <Label htmlFor="contact_email">Email</Label>
                <Input
                  id="contact_email"
                  type="email"
                  value={contact("contact_email")}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, contact_email: e.target.value }))
                  }
                  aria-invalid={!!serverErrors.contact_email}
                  className={invalidCls("contact_email")}
                  placeholder="halo@floevent.id"
                />
                <FieldError message={serverErrors.contact_email} />
              </div>

              <div className="grid gap-2">
                <Label htmlFor="contact_phone">Nomor telepon</Label>
                <Input
                  id="contact_phone"
                  value={contact("contact_phone")}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, contact_phone: e.target.value }))
                  }
                  aria-invalid={!!serverErrors.contact_phone}
                  className={invalidCls("contact_phone")}
                  placeholder="+62 812-3456-7890"
                />
                <FieldError message={serverErrors.contact_phone} />
              </div>

              <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="sales_email">Email sales</Label>
                <Input
                  id="sales_email"
                  type="email"
                  value={contact("sales_email")}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, sales_email: e.target.value }))
                  }
                  aria-invalid={!!serverErrors.sales_email}
                  className={invalidCls("sales_email")}
                  placeholder="sales@floevent.id"
                />
                <FieldError message={serverErrors.sales_email} />
                <p className="text-xs text-muted-foreground">
                  Tujuan tombol &ldquo;Hubungi Sales&rdquo; di kartu paket
                  Professional. Kosongkan untuk memakai email kontak di atas.
                </p>
              </div>
            </CardContent>
            <SaveRow
              pending={save.isPending}
              disabled={save.isPending}
              onSave={() => save.mutate("contact")}
            />
          </Card>
          )}

          {tab === "social" && (
          <Card>
            <SectionHeader
              icon={Share2}
              title="Media Sosial"
              description="Tampil sebagai ikon di footer. Hanya yang diisi yang muncul."
            />
            <CardContent className="grid gap-5 sm:grid-cols-2">
              {SOCIAL_PLATFORMS.map(({ key, label }) => {
                const error = serverErrors[`social_links.${key}`] || undefined;
                return (
                  <div key={key} className="grid gap-2">
                    <Label
                      htmlFor={`social-${key}`}
                      className="flex items-center gap-2"
                    >
                      <SocialIcon
                        platform={key}
                        className="text-muted-foreground"
                      />
                      {label}
                    </Label>
                    <Input
                      id={`social-${key}`}
                      value={social(key)}
                      onChange={(e) =>
                        setSocialDraft((s) => ({ ...s, [key]: e.target.value }))
                      }
                      aria-invalid={!!error}
                      className={
                        error
                          ? "border-destructive focus-visible:ring-destructive"
                          : ""
                      }
                      placeholder={`@floevent atau URL profil ${label}`}
                    />
                    <FieldError message={error} />
                  </div>
                );
              })}
              <p className="text-xs text-muted-foreground sm:col-span-2">
                Isi username saja atau tempel tautan profil lengkap — keduanya
                akan disimpan sebagai tautan. Kosongkan untuk menghapus.
              </p>
            </CardContent>
            <SaveRow
              pending={save.isPending}
              disabled={save.isPending}
              onSave={() => save.mutate("social")}
            />
          </Card>
          )}

          {tab === "bank" && (
          <Card>
            <SectionHeader
              icon={Banknote}
              title="Rekening Penerima Pembayaran Paket"
              description="Rekening floevent sendiri. Dipakai hanya saat payment gateway dimatikan di Pengaturan Platform — organizer transfer ke sini lalu mengunggah bukti untuk kamu verifikasi."
            />
            <CardContent className="grid gap-5 sm:grid-cols-2">
              <div className="grid gap-2">
                <Label htmlFor="bank_name">Nama bank</Label>
                <Input
                  id="bank_name"
                  value={contact("bank_name")}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, bank_name: e.target.value }))
                  }
                  aria-invalid={!!serverErrors.bank_name}
                  className={invalidCls("bank_name")}
                  placeholder="BCA"
                />
                <FieldError message={serverErrors.bank_name} />
              </div>

              <div className="grid gap-2">
                <Label htmlFor="bank_code">Kode bank</Label>
                <Input
                  id="bank_code"
                  value={contact("bank_code")}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, bank_code: e.target.value }))
                  }
                  aria-invalid={!!serverErrors.bank_code}
                  className={invalidCls("bank_code")}
                  placeholder="014"
                />
                <FieldError message={serverErrors.bank_code} />
                <p className="text-xs text-muted-foreground">Opsional.</p>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="account_number">Nomor rekening</Label>
                <Input
                  id="account_number"
                  value={contact("account_number")}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, account_number: e.target.value }))
                  }
                  aria-invalid={!!serverErrors.account_number}
                  className={invalidCls("account_number")}
                  placeholder="1234567890"
                />
                <FieldError message={serverErrors.account_number} />
              </div>

              <div className="grid gap-2">
                <Label htmlFor="account_holder">Atas nama</Label>
                <Input
                  id="account_holder"
                  value={contact("account_holder")}
                  onChange={(e) =>
                    setDraft((d) => ({ ...d, account_holder: e.target.value }))
                  }
                  aria-invalid={!!serverErrors.account_holder}
                  className={invalidCls("account_holder")}
                  placeholder="PT Flo Event Indonesia"
                />
                <FieldError message={serverErrors.account_holder} />
              </div>

              <p className="text-xs text-muted-foreground sm:col-span-2">
                Nama bank, nomor rekening, dan atas nama wajib terisi ketiganya.
                Kalau kosong, organizer tidak bisa membeli paket sama sekali
                selama gateway dimatikan.
              </p>
            </CardContent>
            <SaveRow
              pending={save.isPending}
              disabled={save.isPending}
              onSave={() => save.mutate("bank")}
            />
          </Card>
          )}

          </div>
        </>
      )}
    </>
  );
}

export default function Page() {
  // useSearchParams() needs a Suspense boundary or the build fails.
  return (
    <Suspense fallback={null}>
      <AdminSiteSettingsPage />
    </Suspense>
  );
}
