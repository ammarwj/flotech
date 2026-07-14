"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  ArrowUpRight,
  Award,
  Building2,
  Download,
  LayoutTemplate,
  Mail,
  Plus,
  Trash2,
  Users,
} from "lucide-react";

import {
  deleteCertificate,
  deleteCertificateTemplate,
  downloadCertificate,
  getCertificates,
  getCertificateTemplates,
  sendCertificate,
} from "@/lib/api/certificates";
import { getEvents } from "@/lib/api/events";
import { isCertificateEnabled, isCertificateEmailEnabled } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { cn } from "@/lib/utils";

const fmtDate = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString("id-ID", { dateStyle: "medium" }) : "—";

function CertificatesPage() {
  const router = useRouter();
  const params = useSearchParams();
  const queryClient = useQueryClient();
  const { org, orgId, hasNoOrg, isLoading: orgLoading } = useActiveOrg();

  const tab = params.get("tab") === "templates" ? "templates" : "issued";
  const eventFilter = params.get("event_id") ?? "";

  const enabled = isCertificateEnabled(org);
  const canEmail = isCertificateEmailEnabled(org);

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId && enabled,
  });

  const certificatesQuery = useQuery({
    queryKey: ["certificates", orgId, eventFilter],
    queryFn: () => getCertificates(orgId!, eventFilter || undefined),
    enabled: !!orgId && enabled,
  });

  const templatesQuery = useQuery({
    queryKey: ["certificate-templates", orgId],
    queryFn: () => getCertificateTemplates(orgId!),
    enabled: !!orgId && enabled,
  });

  const send = useMutation({
    mutationFn: (id: string) => sendCertificate(orgId!, id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["certificates", orgId] });
      toast.success("Sertifikat sedang dikirim.");
    },
    onError: () => toast.error("Gagal mengirim sertifikat."),
  });

  const removeCertificate = useMutation({
    mutationFn: (id: string) => deleteCertificate(orgId!, id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["certificates", orgId] });
      toast.success("Sertifikat dihapus.");
    },
    onError: () => toast.error("Gagal menghapus sertifikat."),
  });

  const removeTemplate = useMutation({
    mutationFn: (id: string) => deleteCertificateTemplate(orgId!, id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["certificate-templates", orgId] });
      toast.success("Template dihapus.");
    },
    onError: () => toast.error("Gagal menghapus template."),
  });

  const setParam = (patch: Record<string, string | undefined>) => {
    const next = new URLSearchParams(params.toString());
    for (const [key, value] of Object.entries(patch)) {
      if (!value) next.delete(key);
      else next.set(key, value);
    }
    const qs = next.toString();
    router.replace(qs ? `/organizer/certificates?${qs}` : "/organizer/certificates", {
      scroll: false,
    });
  };

  if (orgLoading) {
    return (
      <div>
        <PageHeader title="Sertifikat" description="Terbitkan sertifikat dari desainmu sendiri." />
        <Skeleton className="h-[200px] w-full rounded-xl" />
      </div>
    );
  }

  if (hasNoOrg) {
    return (
      <div>
        <PageHeader title="Sertifikat" description="Terbitkan sertifikat dari desainmu sendiri." />
        <EmptyState
          icon={Building2}
          title="Belum punya organisasi"
          description="Buat organisasi terlebih dahulu untuk memakai generator sertifikat."
          action={
            <Button asChild>
              <Link href="/onboarding">Buat organisasi</Link>
            </Button>
          }
        />
      </div>
    );
  }

  if (!enabled) {
    return (
      <div>
        <PageHeader title="Sertifikat" description="Terbitkan sertifikat dari desainmu sendiri." />
        <EmptyState
          icon={Award}
          title="Generator sertifikat belum aktif di paketmu"
          description="Upgrade paketmu untuk mengunggah desain sertifikat, mengatur posisi setiap field, dan menerbitkan ratusan sertifikat sekaligus."
          action={
            <Button asChild>
              <Link href="/organizer/upgrade">
                <ArrowUpRight className="h-4 w-4" />
                Upgrade paket
              </Link>
            </Button>
          }
        />
      </div>
    );
  }

  const certificates = certificatesQuery.data;
  const templates = templatesQuery.data;

  return (
    <div>
      <PageHeader
        title="Sertifikat"
        description="Terbitkan sertifikat dari desainmu sendiri."
        actions={
          <div className="flex gap-2">
            <Button asChild variant="outline">
              <Link href="/organizer/certificates/templates/new">
                <LayoutTemplate className="h-4 w-4" />
                Template baru
              </Link>
            </Button>
            <Button asChild disabled={!templates?.length}>
              <Link href="/organizer/certificates/generate">
                <Plus className="h-4 w-4" />
                Terbitkan
              </Link>
            </Button>
          </div>
        }
      />

      <div className="mb-5 flex gap-1 border-b border-border">
        {(
          [
            ["issued", "Diterbitkan"],
            ["templates", "Template"],
          ] as const
        ).map(([key, label]) => (
          <button
            key={key}
            onClick={() => setParam({ tab: key === "issued" ? undefined : key })}
            className={cn(
              "-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors",
              tab === key
                ? "border-[var(--brand-600)] text-[var(--brand-600)]"
                : "border-transparent text-muted-foreground hover:text-foreground"
            )}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === "issued" ? (
        <>
          <div className="mb-4 max-w-xs">
            <Select
              value={eventFilter}
              onChange={(e) => setParam({ event_id: e.target.value || undefined })}
              aria-label="Filter event"
            >
              <option value="">Semua event</option>
              {eventsQuery.data?.map((ev) => (
                <option key={ev.id} value={ev.id}>
                  {ev.name}
                </option>
              ))}
            </Select>
          </div>

          {certificatesQuery.isLoading ? (
            <div className="grid gap-3">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-[76px] w-full rounded-xl" />
              ))}
            </div>
          ) : certificates?.length === 0 ? (
            <EmptyState
              icon={Award}
              title="Belum ada sertifikat"
              description={
                templates?.length
                  ? "Pilih event, penerima, dan template untuk menerbitkan sertifikat pertamamu."
                  : "Buat template dulu — sertifikat dicetak di atas desainmu sendiri."
              }
              action={
                <Button asChild>
                  <Link
                    href={
                      templates?.length
                        ? "/organizer/certificates/generate"
                        : "/organizer/certificates/templates/new"
                    }
                  >
                    <Plus className="h-4 w-4" />
                    {templates?.length ? "Terbitkan sertifikat" : "Buat template"}
                  </Link>
                </Button>
              }
            />
          ) : (
            <div className="grid gap-3">
              {certificates?.map((cert) => (
                <Card
                  key={cert.id}
                  className="flex flex-wrap items-center justify-between gap-4 p-4"
                >
                  <div className="flex min-w-0 items-center gap-4">
                    <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--tint)] text-[var(--brand-600)]">
                      {cert.recipient_type === "team" ? (
                        <Users className="h-5 w-5" />
                      ) : (
                        <Award className="h-5 w-5" />
                      )}
                    </span>
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="truncate font-semibold">{cert.recipient_name}</span>
                        <Badge variant="neutral">{cert.award_title}</Badge>
                        {cert.sent_at && <Badge variant="success">Terkirim</Badge>}
                      </div>
                      <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                        <code className="text-xs">{cert.certificate_number}</code>
                        {cert.event_name && <span>{cert.event_name}</span>}
                        {cert.team_name && <span>{cert.team_name}</span>}
                        <span>{fmtDate(cert.issued_at)}</span>
                      </div>
                    </div>
                  </div>

                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={!cert.has_pdf}
                      onClick={() => downloadCertificate(orgId!, cert.id)}
                    >
                      <Download className="h-4 w-4" />
                      PDF
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={!canEmail || !cert.recipient_email || send.isPending}
                      title={
                        !canEmail
                          ? "Paketmu belum mencakup pengiriman email"
                          : !cert.recipient_email
                            ? "Penerima tidak punya email"
                            : `Kirim ke ${cert.recipient_email}`
                      }
                      onClick={() => send.mutate(cert.id)}
                    >
                      <Mail className="h-4 w-4" />
                      Kirim
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => removeCertificate.mutate(cert.id)}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </Card>
              ))}
            </div>
          )}
        </>
      ) : templatesQuery.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[220px] w-full rounded-xl" />
          ))}
        </div>
      ) : templates?.length === 0 ? (
        <EmptyState
          icon={LayoutTemplate}
          title="Belum ada template"
          description="Unggah desain sertifikatmu, lalu atur posisi nama, tim, dan penghargaan di atasnya."
          action={
            <Button asChild>
              <Link href="/organizer/certificates/templates/new">
                <Plus className="h-4 w-4" />
                Buat template
              </Link>
            </Button>
          }
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {templates?.map((tpl) => (
            <Card key={tpl.id} className="overflow-hidden">
              <div
                className="bg-[var(--bg-soft)]"
                style={{ aspectRatio: tpl.orientation === "portrait" ? "595 / 842" : "842 / 595" }}
              >
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={tpl.background_url}
                  alt=""
                  className="h-full w-full"
                  style={{ objectFit: "fill" }}
                />
              </div>
              <div className="flex items-center justify-between gap-2 p-4">
                <div className="min-w-0">
                  <p className="truncate font-semibold">{tpl.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {tpl.fields.length} field · {tpl.certificates_count ?? 0} sertifikat
                  </p>
                </div>
                <div className="flex gap-1">
                  <Button asChild size="sm" variant="outline">
                    <Link href={`/organizer/certificates/templates/${tpl.id}`}>Edit</Link>
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => removeTemplate.mutate(tpl.id)}>
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

export default function Page() {
  // useSearchParams() needs a Suspense boundary or the build fails.
  return (
    <Suspense fallback={null}>
      <CertificatesPage />
    </Suspense>
  );
}
