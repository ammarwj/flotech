"use client";

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  ArrowUpRight,
  Award,
  Building2,
  Download,
  FileArchive,
  LayoutTemplate,
  Loader2,
  Mail,
  Plus,
  Trash2,
  Users,
} from "lucide-react";

import {
  deleteCertificate,
  deleteCertificateTemplate,
  downloadCertificate,
  downloadCertificateZipBatch,
  getCertificates,
  getCertificateTemplates,
  getCertificateZipBatch,
  sendCertificate,
  zipCertificates,
} from "@/lib/api/certificates";
import { getEvents } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { anyEventAllows } from "@/lib/plan";
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
  // In the URL alongside the filter it belongs to, not component state: the
  // filter already lives there, and a page number held locally would survive a
  // back-navigation that changed the filter under it.
  const page = Math.max(1, Number(params.get("page")) || 1);

  const [batchId, setBatchId] = useState<string | null>(null);

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });

  // Templates are org-scoped rows reused across events, so the page is open as
  // soon as *any* event carries the entitlement — the frontend mirror of
  // PlanGate::orgAllows(). Issuing is still gated per event, below.
  const enabled = anyEventAllows(eventsQuery.data, "certificate_generator");
  const canEmail = anyEventAllows(eventsQuery.data, "certificate_email");

  const certificatesQuery = useQuery({
    queryKey: ["certificates", orgId, eventFilter, page],
    queryFn: () => getCertificates(orgId!, { eventId: eventFilter || undefined, page }),
    enabled: !!orgId && enabled,
    // Keeps the rows on screen while the next page loads, so paging does not
    // collapse the list to a skeleton and jump the scroll position.
    placeholderData: (prev) => prev,
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

  const zipBatchQuery = useQuery({
    queryKey: ["certificate-zip-batch", orgId, batchId],
    queryFn: () => getCertificateZipBatch(orgId!, batchId!),
    enabled: !!orgId && !!batchId,
    // Stop the moment it settles. A batch that failed is as final as one that
    // finished — polling either forever would keep a tab hitting the API for as
    // long as it stays open.
    refetchInterval: (q) => {
      const status = q.state.data?.status;
      return status === "done" || status === "failed" ? false : 2000;
    },
  });

  const batch = zipBatchQuery.data;
  const zipping = !!batch && batch.status !== "done" && batch.status !== "failed";

  const buildZip = useMutation({
    mutationFn: () => zipCertificates(orgId!, eventFilter || undefined),
    onSuccess: (result) => {
      setBatchId(result.batch_id);
      toast.success(`${result.total} sertifikat sedang dikemas.`);
    },
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const downloadZip = useMutation({
    mutationFn: () => downloadCertificateZipBatch(orgId!, batchId!),
    onError: (err) => toast.error(parseApiError(err).message),
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

  // Deleting the last row of the last page leaves the URL pointing past the end:
  // Laravel answers an out-of-range page with an empty `items` and a `last_page`
  // behind it, so the organizer gets a blank list under a pager reading "5 dari
  // 4" and no control that takes them back. Reading the server's own
  // `last_page` rather than recomputing it keeps this from becoming a second
  // opinion about how many pages there are.
  const lastPage = certificatesQuery.data?.meta.last_page;

  useEffect(() => {
    if (lastPage !== undefined && page > lastPage) {
      setParam({ page: lastPage > 1 ? String(lastPage) : "" });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastPage, page]);

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
              <Link href="/organizer/plans">
                <ArrowUpRight className="h-4 w-4" />
                Upgrade paket
              </Link>
            </Button>
          }
        />
      </div>
    );
  }

  const certificates = certificatesQuery.data?.items;
  // The count over the whole filter, not this page: it is what says whether
  // there is anything to zip, and whether the list is empty at all.
  const certMeta = certificatesQuery.data?.meta;
  const templates = templatesQuery.data;

  return (
    <div>
      <PageHeader
        title="Sertifikat"
        description="Terbitkan sertifikat dari desainmu sendiri."
        actions={
          // flex-wrap, not the plain row this was: three buttons at these
          // labels overflow a phone, and PageHeader only drops the whole group
          // to its own line — the wrapping inside it has to be asked for here.
          <div className="flex flex-wrap gap-2">
            {tab === "issued" && (
              <Button
                variant="outline"
                disabled={!certMeta?.total || buildZip.isPending || zipping}
                onClick={() => buildZip.mutate()}
              >
                {buildZip.isPending || zipping ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <FileArchive className="h-4 w-4" />
                )}
                {eventFilter ? "Unduh event ini (.zip)" : "Unduh semua (.zip)"}
              </Button>
            )}
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
            onClick={() => setParam({ tab: key === "issued" ? undefined : key, page: undefined })}
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
          {batch && (
            <Card className="mb-4 flex flex-wrap items-center justify-between gap-3 p-4">
              <div className="min-w-0 text-sm">
                {batch.status === "failed" ? (
                  <p className="text-[var(--danger)]">
                    Gagal mengemas sertifikat{batch.error ? `: ${batch.error}` : "."}
                  </p>
                ) : zipping ? (
                  <p className="flex items-center gap-2 text-muted-foreground">
                    <Loader2 className="h-4 w-4 animate-spin" />
                    Mengemas sertifikat… {batch.done}/{batch.total}
                  </p>
                ) : (
                  <p className="text-muted-foreground">
                    {batch.total} sertifikat siap diunduh sebagai satu .zip.
                  </p>
                )}
              </div>

              {batch.status === "done" && (
                <Button
                  variant="outline"
                  className="w-full sm:w-auto"
                  onClick={() => downloadZip.mutate()}
                  disabled={downloadZip.isPending}
                >
                  {downloadZip.isPending ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <Download className="h-4 w-4" />
                  )}
                  Unduh .zip
                </Button>
              )}
            </Card>
          )}

          <div className="mb-4 max-w-xs">
            <Select
              value={eventFilter}
              onChange={(e) => {
                // The zip on screen was packed from the old filter. Leaving its
                // download button live would hand over a file that no longer
                // matches what the page says — same reason the ID card page
                // resets its batch when either input changes.
                setBatchId(null);
                // `page: undefined` is load-bearing: page 4 of "semua event" is
                // usually past the end of one event's list, and the row that
                // comes back is an empty table on a filter that has results.
                setParam({ event_id: e.target.value || undefined, page: undefined });
              }}
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
          ) : certMeta?.total === 0 ? (
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
                  className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                >
                  <div className="flex min-w-0 items-start gap-3 sm:items-center sm:gap-4">
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[var(--tint)] text-[var(--brand-600)] sm:h-11 sm:w-11">
                      {cert.recipient_type === "team" ? (
                        <Users className="h-5 w-5" />
                      ) : (
                        <Award className="h-5 w-5" />
                      )}
                    </span>
                    <div className="min-w-0 flex-1">
                      {/* The name takes its own line on a phone. In a flex row
                          with the badges it keeps about a third of the width and
                          truncates to two syllables, which is the one field on
                          this card nobody can act without. */}
                      <p className="truncate font-semibold">{cert.recipient_name}</p>
                      <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <Badge variant="neutral">{cert.award_title}</Badge>
                        {cert.sent_at && <Badge variant="success">Terkirim</Badge>}
                      </div>
                      <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm text-muted-foreground">
                        <code className="text-xs">{cert.certificate_number}</code>
                        {cert.event_name && <span className="truncate">{cert.event_name}</span>}
                        {cert.team_name && <span className="truncate">{cert.team_name}</span>}
                        <span>{fmtDate(cert.issued_at)}</span>
                      </div>
                    </div>
                  </div>

                  {/* Full-width buttons on a phone, hugging their labels from
                      `sm` up. `shrink-0` on the row keeps the actions from being
                      squeezed to icons by a long recipient name. */}
                  <div className="flex shrink-0 gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      className="flex-1 sm:flex-none"
                      disabled={!cert.has_pdf}
                      onClick={() => downloadCertificate(orgId!, cert.id)}
                    >
                      <Download className="h-4 w-4" />
                      PDF
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="flex-1 sm:flex-none"
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
                      aria-label={`Hapus sertifikat ${cert.recipient_name}`}
                      onClick={() => removeCertificate.mutate(cert.id)}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </Card>
              ))}
            </div>
          )}

          {certMeta && certMeta.last_page > 1 && (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
              <span className="text-muted-foreground">
                Halaman {certMeta.page} dari {certMeta.last_page} · {certMeta.total} sertifikat
              </span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  // Page 1 drops out of the URL rather than riding along as
                  // `?page=1`: setParam() deletes a falsy value, so the empty
                  // string is how "back to the first page" is spelled.
                  onClick={() => setParam({ page: page > 2 ? String(page - 1) : "" })}
                  disabled={certMeta.page <= 1}
                >
                  Sebelumnya
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setParam({ page: String(page + 1) })}
                  disabled={certMeta.page >= certMeta.last_page}
                >
                  Berikutnya
                </Button>
              </div>
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
                <div className="flex shrink-0 gap-1">
                  <Button asChild size="sm" variant="outline">
                    <Link href={`/organizer/certificates/templates/${tpl.id}`}>Edit</Link>
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    aria-label={`Hapus template ${tpl.name}`}
                    onClick={() => removeTemplate.mutate(tpl.id)}
                  >
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
