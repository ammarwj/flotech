"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { AlertCircle, Mail, Share2 } from "lucide-react";

import { SectionHeader } from "@/components/event/section-header";
import { PageHeader } from "@/components/shared/page-header";
import { SocialIcon } from "@/components/shared/social-icon";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { getAdminSiteSettings, updateSiteSettings } from "@/lib/api/landing";
import { SOCIAL_PLATFORMS } from "@/lib/social";
import type { SocialPlatform } from "@/types/api";

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

const EMPTY_SOCIALS = Object.fromEntries(SOCIAL_PLATFORMS.map((p) => [p.key, ""])) as Record<
  SocialPlatform,
  string
>;

export default function AdminSiteSettingsPage() {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ["admin-site-settings"], queryFn: getAdminSiteSettings });

  // Only what the admin has touched; everything else reads straight from the
  // server, so no effect is needed to seed the form (pattern from /admin/settings).
  const [draft, setDraft] = useState<Partial<Record<ContactField, string>>>({});
  const [socialDraft, setSocialDraft] = useState<Partial<Record<SocialPlatform, string>>>({});
  const [serverErrors, setServerErrors] = useState<FieldErrors>({});

  const contact = (field: ContactField) => draft[field] ?? query.data?.[field] ?? "";
  const social = (key: SocialPlatform) =>
    socialDraft[key] ?? query.data?.social_links?.[key] ?? EMPTY_SOCIALS[key];

  const save = useMutation({
    mutationFn: () =>
      updateSiteSettings({
        contact_email: contact("contact_email").trim() || null,
        contact_phone: contact("contact_phone").trim() || null,
        sales_email: contact("sales_email").trim() || null,
        social_links: Object.fromEntries(
          SOCIAL_PLATFORMS.map((p) => [p.key, social(p.key).trim() || null])
        ),
      }),
    onSuccess: () => {
      setServerErrors({});
      // Drop the local draft so the normalized values ("@floevent" → the full
      // URL) come back from the server instead of the raw text still on screen.
      setDraft({});
      setSocialDraft({});
      qc.invalidateQueries({ queryKey: ["admin-site-settings"] });
      // The footer in this same tab reads the public endpoint.
      qc.invalidateQueries({ queryKey: ["public-site-settings"] });
      toast.success("Kontak & sosmed disimpan");
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan kontak & sosmed.");
      setServerErrors(parsed.fieldErrors);
      if (Object.keys(parsed.fieldErrors).length === 0) toast.error(parsed.message);
    },
  });

  const invalidCls = (key: string) =>
    serverErrors[key] ? "border-destructive focus-visible:ring-destructive" : "";

  return (
    <>
      <PageHeader
        title="Kontak & Sosmed"
        description="Cara pengunjung menghubungi flo-event. Tampil di footer semua halaman publik — yang dikosongkan tidak ditampilkan."
      />

      {query.isError && (
        <p className="mb-6 text-sm text-destructive">
          Tidak bisa memuat pengaturan (butuh akses Super Admin &amp; API berjalan).
        </p>
      )}

      {query.isPending ? (
        <div className="grid gap-4">
          <Skeleton className="h-[260px] rounded-xl" />
          <Skeleton className="h-[340px] rounded-xl" />
        </div>
      ) : (
        <div className="grid gap-4">
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
                  onChange={(e) => setDraft((d) => ({ ...d, contact_email: e.target.value }))}
                  aria-invalid={!!serverErrors.contact_email}
                  className={invalidCls("contact_email")}
                  placeholder="halo@flo-event.id"
                />
                <FieldError message={serverErrors.contact_email} />
              </div>

              <div className="grid gap-2">
                <Label htmlFor="contact_phone">Nomor telepon</Label>
                <Input
                  id="contact_phone"
                  value={contact("contact_phone")}
                  onChange={(e) => setDraft((d) => ({ ...d, contact_phone: e.target.value }))}
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
                  onChange={(e) => setDraft((d) => ({ ...d, sales_email: e.target.value }))}
                  aria-invalid={!!serverErrors.sales_email}
                  className={invalidCls("sales_email")}
                  placeholder="sales@flo-event.id"
                />
                <FieldError message={serverErrors.sales_email} />
                <p className="text-xs text-muted-foreground">
                  Tujuan tombol &ldquo;Hubungi Sales&rdquo; di kartu paket Professional. Kosongkan
                  untuk memakai email kontak di atas.
                </p>
              </div>
            </CardContent>
          </Card>

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
                    <Label htmlFor={`social-${key}`} className="flex items-center gap-2">
                      <SocialIcon platform={key} className="text-muted-foreground" />
                      {label}
                    </Label>
                    <Input
                      id={`social-${key}`}
                      value={social(key)}
                      onChange={(e) => setSocialDraft((s) => ({ ...s, [key]: e.target.value }))}
                      aria-invalid={!!error}
                      className={error ? "border-destructive focus-visible:ring-destructive" : ""}
                      placeholder={`@floevent atau URL profil ${label}`}
                    />
                    <FieldError message={error} />
                  </div>
                );
              })}
              <p className="text-xs text-muted-foreground sm:col-span-2">
                Isi username saja atau tempel tautan profil lengkap — keduanya akan disimpan sebagai
                tautan. Kosongkan untuk menghapus.
              </p>
            </CardContent>
          </Card>

          <div>
            <Button disabled={save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? "Menyimpan…" : "Simpan"}
            </Button>
          </div>
        </div>
      )}
    </>
  );
}
