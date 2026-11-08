"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Download, Loader2, Mail, Printer, Tag, Users } from "lucide-react";

import {
  downloadBib,
  downloadBibBatch,
  generateBibs,
  getBibBatch,
  getBibRecipients,
  getBibTemplates,
  sendBibs,
} from "@/lib/api/bibs";
import { getEvents } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { isBibEnabled } from "@/lib/plan";
import { isRaceEvent } from "@/lib/race";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { useConfirm } from "@/components/shared/confirm-provider";
import { cn } from "@/lib/utils";
import type { BibRecipient } from "@/types/api";

function GenerateBibsPage() {
  const router = useRouter();
  const params = useSearchParams();
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const { orgId, isLoading: orgLoading } = useActiveOrg();

  /**
   * Event and template live in the URL, not in component state.
   *
   * Same reasoning the ID card page gives: sending takes minutes to show up
   * (the mails are queued and the provider is rate-limited), so the one thing
   * an organizer will certainly do is reload — and with the selection held
   * locally that reload dropped them back to "pilih event dulu" over the batch
   * they were watching.
   */
  const eventId = params.get("event_id") ?? "";
  const templateId = params.get("template_id") ?? "";

  const setParams = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params.toString());

    for (const [key, value] of Object.entries(patch)) {
      if (value) next.set(key, value);
      else next.delete(key);
    }

    router.replace(`?${next.toString()}`, { scroll: false });
  };

  const [picked, setPicked] = useState<string[]>([]);
  const [batchId, setBatchId] = useState<string | null>(null);

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });

  const templatesQuery = useQuery({
    queryKey: ["bib-templates", orgId],
    queryFn: () => getBibTemplates(orgId!),
    enabled: !!orgId,
  });

  const recipientsQuery = useQuery({
    queryKey: ["bib-recipients", orgId, eventId],
    queryFn: () => getBibRecipients(orgId!, eventId),
    enabled: !!orgId && !!eventId,
  });

  // Only races are offered at all — a football event has no runners, so listing
  // it would be a choice that can only answer 403.
  const events = (eventsQuery.data ?? []).filter(isRaceEvent);

  // The entitlement belongs to the event being printed for, not to the
  // organization — picking a different event can change the answer, which is
  // why this is read off the selection rather than once at the top.
  const selectedEvent = events.find((e) => e.id === eventId);
  const allowed = !eventId || isBibEnabled(selectedEvent);

  const batchQuery = useQuery({
    queryKey: ["bib-batch", orgId, batchId],
    queryFn: () => getBibBatch(orgId!, batchId!),
    enabled: !!orgId && !!batchId,
    // Stop the moment it settles. A batch that failed is as final as one that
    // finished — polling either forever would keep a tab hitting the API for as
    // long as it stays open.
    refetchInterval: (q) => {
      const status = q.state.data?.status;
      return status === "done" || status === "failed" ? false : 2000;
    },
  });

  const batch = batchQuery.data;

  const generate = useMutation({
    mutationFn: () =>
      generateBibs(orgId!, eventId, {
        bib_template_id: templateId,
        entry_ids: picked,
      }),
    onSuccess: (result) => {
      setBatchId(result.batch_id);
      // The recipient list just changed under them: generating is what assigns
      // the race numbers, so the rows on screen are now stale about the one
      // column this page prints largest.
      queryClient.invalidateQueries({ queryKey: ["bib-recipients", orgId, eventId] });
      toast.success(
        `${result.total} e-BIB sedang dibuat.` +
          (result.numbered > 0 ? ` ${result.numbered} nomor BIB baru diberikan.` : "")
      );
    },
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const download = useMutation({
    mutationFn: () => downloadBibBatch(orgId!, batchId!),
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const downloadOne = useMutation({
    mutationFn: (entryId: string) => downloadBib(orgId!, eventId, entryId),
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const send = useMutation({
    mutationFn: () => sendBibs(orgId!, eventId),
    onSuccess: (result) => {
      toast.info(
        `${result.emails} e-BIB masuk antrean email.` +
          (result.skipped > 0 ? ` ${result.skipped} dilewati (tanpa alamat email).` : "")
      );
    },
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const recipients = recipientsQuery.data ?? [];

  const toggle = (id: string) =>
    setPicked((prev) => (prev.includes(id) ? prev.filter((k) => k !== id) : [...prev, id]));

  /** Select-all scoped to one category, so "semua 5K" is one click. */
  const toggleCategory = (category: string) => {
    const ids = recipients.filter((r) => r.category_name === category).map((r) => r.id);
    const allPicked = ids.every((k) => picked.includes(k));

    setPicked((prev) =>
      allPicked ? prev.filter((k) => !ids.includes(k)) : [...new Set([...prev, ...ids])]
    );
  };

  // Changing either input invalidates the batch on screen: it was rendered from
  // the old pair, and leaving its download button live would hand over a zip
  // that no longer matches what the page says.
  const reset = () => {
    setPicked([]);
    setBatchId(null);
  };

  if (orgLoading) {
    return (
      <div>
        <PageHeader title="Cetak e-BIB" />
        <Skeleton className="h-[400px] w-full rounded-xl" />
      </div>
    );
  }

  const running = !!batch && batch.status !== "done" && batch.status !== "failed";
  const ready = eventId && templateId && picked.length > 0 && allowed;

  // Grouped by category, which is also how the numbers are assigned — each
  // category runs its own sequence, so these headings are the unit an organizer
  // actually prints in. Presentation only: the request sends one flat list of
  // ids, exactly what came down.
  const categories = [...new Set(recipients.map((r) => r.category_name))];

  // Counted from the rows already in hand, not asked of the server separately:
  // the address is a column on each entry, so "how many can be emailed" is
  // already here and a second endpoint answering it could disagree with the one
  // that refuses.
  const printed = recipients.filter((r) => r.bib_number);
  const withEmail = recipients.filter((r) => r.email);

  return (
    <div>
      <PageHeader
        title="Cetak e-BIB"
        description="Pilih event lomba, template, lalu pelari mana saja yang dicetak nomor dadanya."
      />

      <div className="flex flex-col gap-5">
        <Card className="grid gap-4 p-5 sm:grid-cols-2">
          <div>
            <Label htmlFor="event">Event lomba</Label>
            <Select
              id="event"
              value={eventId}
              onChange={(e) => {
                setParams({ event_id: e.target.value || null });
                reset();
              }}
              className="mt-1.5"
              disabled={events.length === 0}
            >
              <option value="">
                {events.length === 0 ? "— belum ada event lomba —" : "— pilih event —"}
              </option>
              {events.map((ev) => (
                <option key={ev.id} value={ev.id}>
                  {ev.name}
                </option>
              ))}
            </Select>
            {/* Said where the empty select is, not as a toast after a click:
                e-BIB only exists for sports that hold no matches, and an
                organizer with only tournaments has nothing to fix here. */}
            {events.length === 0 && !eventsQuery.isLoading && (
              <p className="mt-1.5 text-xs text-muted-foreground">
                e-BIB hanya untuk event cabang tanpa pertandingan, seperti fun run.
              </p>
            )}
          </div>

          <div>
            <Label htmlFor="template">Template</Label>
            <Select
              id="template"
              value={templateId}
              onChange={(e) => {
                setParams({ template_id: e.target.value || null });
                setBatchId(null);
              }}
              className="mt-1.5"
            >
              <option value="">— pilih template —</option>
              {templatesQuery.data?.map((tpl) => (
                <option key={tpl.id} value={tpl.id}>
                  {tpl.name} · {tpl.width_mm} × {tpl.height_mm} mm
                </option>
              ))}
            </Select>
            {templatesQuery.data?.length === 0 && (
              <p className="mt-1.5 text-xs text-muted-foreground">
                Belum ada template.{" "}
                <Link href="/organizer/bibs/templates/new" className="underline">
                  Buat satu dulu
                </Link>
                .
              </p>
            )}
          </div>
        </Card>

        {!allowed && (
          <Card className="flex flex-wrap items-center justify-between gap-3 border-[var(--border-strong)] p-5">
            <div>
              <p className="font-semibold">Paket event ini belum mencakup generator e-BIB</p>
              <p className="text-sm text-muted-foreground">
                Naikkan paket event tersebut, atau pilih event lain yang paketnya sudah
                mencakupnya.
              </p>
            </div>
            <Button asChild variant="outline">
              <Link href="/organizer/billing">Lihat paket</Link>
            </Button>
          </Card>
        )}

        <Card className="p-5">
          {!eventId ? (
            <p className="py-10 text-center text-sm text-muted-foreground">
              Pilih event dulu untuk melihat pelari yang bisa dicetak e-BIB-nya.
            </p>
          ) : recipientsQuery.isLoading ? (
            <Skeleton className="h-40 w-full rounded-lg" />
          ) : recipients.length === 0 ? (
            <div className="py-10 text-center text-sm text-muted-foreground">
              <Users className="mx-auto mb-2 h-6 w-6" />
              Belum ada pelari lunas di event ini.
              {/* The reason, not just the fact: an unpaid entry has no check-in
                  code, so its sheet would be a race-pack ticket that admits
                  nobody while looking exactly like one that works. */}
              <p className="mt-1 text-xs">
                e-BIB hanya dicetak untuk pendaftaran yang sudah lunas — QR-nya adalah kredensial
                check-in, dan itu baru terbit setelah pembayaran masuk.
              </p>
            </div>
          ) : (
            <div className="flex flex-col gap-6">
              {categories.map((category) => {
                const pool = recipients.filter((r) => r.category_name === category);
                const chosen = pool.filter((r) => picked.includes(r.id)).length;

                return (
                  <div key={category || "tanpa-kategori"}>
                    <div className="mb-3 flex items-center justify-between gap-3">
                      <h3 className="text-sm font-semibold">
                        {category || "Tanpa kategori"}{" "}
                        <span className="font-normal text-muted-foreground">
                          ({chosen}/{pool.length})
                        </span>
                      </h3>
                      <Button size="sm" variant="outline" onClick={() => toggleCategory(category)}>
                        {chosen === pool.length ? "Kosongkan" : "Pilih semua"}
                      </Button>
                    </div>

                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                      {pool.map((runner: BibRecipient) => (
                        <label
                          key={runner.id}
                          className={cn(
                            "flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 text-sm transition-colors",
                            picked.includes(runner.id)
                              ? "border-[var(--brand-600)] bg-[var(--tint)]"
                              : "border-border hover:border-[var(--border-strong)]"
                          )}
                        >
                          <input
                            type="checkbox"
                            checked={picked.includes(runner.id)}
                            onChange={() => toggle(runner.id)}
                          />
                          <span className="min-w-0 flex-1">
                            <span className="block truncate font-medium">{runner.name}</span>
                            <span className="block truncate text-xs text-muted-foreground">
                              {/* Said plainly rather than left blank: generating
                                  is what assigns the number, so "belum bernomor"
                                  is a state that resolves itself on the next
                                  click — not something to fix first. */}
                              {runner.bib_number ?? "belum bernomor"}
                              {!runner.email && " · tanpa email"}
                            </span>
                          </span>
                          {runner.bib_number && (
                            <Button
                              size="sm"
                              variant="ghost"
                              type="button"
                              onClick={(e) => {
                                e.preventDefault();
                                downloadOne.mutate(runner.id);
                              }}
                              disabled={downloadOne.isPending}
                            >
                              <Download className="h-4 w-4" />
                            </Button>
                          )}
                        </label>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </Card>

        <Card className="flex flex-wrap items-center justify-between gap-4 p-5">
          <div className="min-w-0 text-sm">
            {batch?.status === "failed" ? (
              <p className="text-[var(--danger)]">
                Gagal membuat e-BIB{batch.error ? `: ${batch.error}` : "."}
              </p>
            ) : running ? (
              <p className="flex items-center gap-2 text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" />
                Membuat e-BIB… {batch.done}/{batch.total}
              </p>
            ) : batch?.status === "done" ? (
              <p className="text-muted-foreground">
                {batch.total} e-BIB siap diunduh sebagai satu .zip.
              </p>
            ) : (
              <p className="text-muted-foreground">
                {picked.length > 0
                  ? `${picked.length} pelari dipilih.`
                  : "Belum ada yang dipilih."}
              </p>
            )}
          </div>

          <div className="flex gap-2">
            {batch?.status === "done" && (
              <Button
                variant="outline"
                onClick={() => download.mutate()}
                disabled={download.isPending}
              >
                {download.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Download className="h-4 w-4" />
                )}
                Unduh .zip
              </Button>
            )}

            <Button
              onClick={() => generate.mutate()}
              disabled={!ready || generate.isPending || running}
            >
              {generate.isPending || running ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : batch?.status === "done" ? (
                <Printer className="h-4 w-4" />
              ) : (
                <Tag className="h-4 w-4" />
              )}
              {batch?.status === "done" ? "Cetak ulang" : "Buat e-BIB"}
              {picked.length > 0 && batch?.status !== "done" ? ` (${picked.length})` : ""}
            </Button>
          </div>
        </Card>

        {/* Only once this event has sheets. A panel offering to email nothing is
            an announcement about a door that isn't there — the same call made
            for `online_registration_enabled`. */}
        {eventId && printed.length > 0 && (
          <Card className="flex flex-wrap items-center justify-between gap-4 p-5">
            <div className="min-w-0 text-sm">
              <p className="font-semibold">Kirim e-BIB via email</p>
              <p className="text-muted-foreground">
                {/* The counts are stated before the click, not discovered from
                    the result. */}
                {printed.length} pelari bernomor, {withEmail.length} punya alamat email.
                {printed.length > withEmail.length &&
                  ` ${printed.length - withEmail.length} harus diberikan langsung.`}
              </p>

              <p className="mt-1 text-xs text-muted-foreground">
                Satu email per pelari, berisi e-BIB-nya sebagai lampiran PNG. Pengiriman
                dijarakkan agar tidak ditolak penyedia email, jadi butuh beberapa menit untuk
                lomba besar. Hanya e-BIB yang sudah dibuat yang dikirim.
              </p>
            </div>

            <Button
              variant="outline"
              disabled={withEmail.length === 0 || send.isPending}
              onClick={async () => {
                const ok = await confirm({
                  title: `Kirim e-BIB ke ${withEmail.length} pelari?`,
                  description:
                    "Setiap pelari menerima e-BIB-nya sebagai lampiran PNG. QR di dalamnya adalah kredensial check-in mereka, jadi email ini bersifat pribadi.",
                  confirmLabel: "Kirim sekarang",
                });
                if (ok) send.mutate();
              }}
            >
              {send.isPending ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Mail className="h-4 w-4" />
              )}
              Kirim via email
            </Button>
          </Card>
        )}

        {eventId && printed.length > 0 && (
          <Card className="p-5">
            <h3 className="text-sm font-semibold">Nomor BIB terbit</h3>
            <p className="mt-1 text-xs text-muted-foreground">
              Nomor diberikan sekali dan tidak pernah berubah — mencetak ulang pelari yang sama
              memakai nomor yang sama, jadi kertas yang sudah dibagikan tetap cocok dengan start
              list. Nomor bisa dikoreksi manual dari daftar peserta lomba.
            </p>

            <div className="mt-4 flex flex-col divide-y divide-border">
              {printed.map((runner) => (
                <div key={runner.id} className="flex items-center justify-between gap-3 py-2.5">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{runner.name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                      <code className="text-[11px]">{runner.bib_number}</code>
                      {runner.category_name ? ` · ${runner.category_name}` : ""}
                    </p>
                  </div>

                  <div className="flex shrink-0 items-center gap-2">
                    {runner.email ? (
                      <Badge variant="outline">{runner.email}</Badge>
                    ) : (
                      <Badge variant="outline">Tanpa email</Badge>
                    )}

                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => downloadOne.mutate(runner.id)}
                      disabled={downloadOne.isPending}
                    >
                      <Download className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}

export default function Page() {
  // useSearchParams() needs a Suspense boundary or the build fails.
  return (
    <Suspense fallback={null}>
      <GenerateBibsPage />
    </Suspense>
  );
}
