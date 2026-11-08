"use client";

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Download, IdCard, Loader2, Mail, Printer, Users } from "lucide-react";

import {
  downloadIdCard,
  downloadIdCardBatch,
  generateIdCards,
  getIdCardBatch,
  getIdCardRecipients,
  getIdCardTemplates,
  getIssuedIdCards,
  sendIdCards,
} from "@/lib/api/id-cards";
import { getEvents } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { isIdCardEnabled } from "@/lib/plan";
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
import type { IdCardRecipient } from "@/types/api";

/**
 * The three pools the API flattens, given their headings back.
 *
 * Grouping is presentation only — the request sends one flat list of
 * `type`/`id` pairs, exactly what came down. The order matches
 * IdCardService::recipients(), so the numbering inside the zip reads the same
 * way down this page.
 */
const GROUPS: Array<{ type: IdCardRecipient["type"]; label: string }> = [
  { type: "player", label: "Pemain" },
  { type: "official", label: "Ofisial tim" },
  { type: "personnel", label: "Wasit & staf" },
];

const keyOf = (r: IdCardRecipient) => `${r.type}:${r.id}`;

function GenerateIdCardsPage() {
  const router = useRouter();
  const params = useSearchParams();
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const { orgId, isLoading: orgLoading } = useActiveOrg();

  /**
   * Event and template live in the URL, not in component state.
   *
   * Sending takes minutes to show up (the mails are queued and the provider is
   * rate-limited), so the one thing an organizer will certainly do is reload
   * this page — and with the selection held locally that reload dropped them
   * back to "pilih event dulu" over the batch they were watching. Same
   * reasoning as the certificates page keeping its filter in the URL.
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
    queryKey: ["id-card-templates", orgId],
    queryFn: () => getIdCardTemplates(orgId!),
    enabled: !!orgId,
  });

  const recipientsQuery = useQuery({
    queryKey: ["id-card-recipients", orgId, eventId],
    queryFn: () => getIdCardRecipients(orgId!, eventId),
    enabled: !!orgId && !!eventId,
  });

  // The entitlement belongs to the event being printed for, not to the
  // organization — picking a different event can change the answer, which is
  // why this is read off the selection rather than once at the top.
  const selectedEvent = eventsQuery.data?.find((e) => e.id === eventId);
  const allowed = !eventId || isIdCardEnabled(selectedEvent);

  const batchQuery = useQuery({
    queryKey: ["id-card-batch", orgId, batchId],
    queryFn: () => getIdCardBatch(orgId!, batchId!),
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

  // Rendering is what issues the rows, so the send panel has to re-ask once the
  // batch settles. Without this it keeps reading the count from before the
  // print and offers to email nothing over a zip that just finished.
  useEffect(() => {
    if (batch?.status === "done") {
      queryClient.invalidateQueries({ queryKey: ["id-cards-issued", orgId, eventId] });
    }
  }, [batch?.status, queryClient, orgId, eventId]);

  const generate = useMutation({
    mutationFn: () => {
      const wanted = new Set(picked);

      return generateIdCards(orgId!, eventId, {
        id_card_template_id: templateId,
        recipients: (recipientsQuery.data ?? [])
          .filter((r) => wanted.has(keyOf(r)))
          .map((r) => ({ type: r.type, id: r.id })),
      });
    },
    onSuccess: (result) => {
      setBatchId(result.batch_id);
      toast.success(`${result.total} kartu sedang dibuat.`);
    },
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const download = useMutation({
    mutationFn: () => downloadIdCardBatch(orgId!, batchId!),
    onError: (err) => toast.error(parseApiError(err).message),
  });

  /**
   * Cards already issued for this event, which is what "Kirim via email" sends.
   *
   * Read from the server rather than derived from `picked`: the send goes out to
   * every card this event has ever issued, and an organizer who printed in two
   * sittings would otherwise be told the wrong number. `meta.total` is the count
   * over the whole event, not the page, which is exactly the figure wanted here.
   */
  const [sending, setSending] = useState(false);

  const issuedQuery = useQuery({
    queryKey: ["id-cards-issued", orgId, eventId],
    queryFn: () => getIssuedIdCards(orgId!, { event_id: eventId, per_page: 100 }),
    enabled: !!orgId && !!eventId,
    /**
     * Polled while a send is in flight, because the send is genuinely not done
     * when the request returns: the mails are queued and deliberately spaced
     * out past the provider's per-second limit, so a 26-card event takes most
     * of a minute. Without this the page looked frozen and the only way to see
     * progress was a reload — which is also what made losing the filter hurt.
     *
     * `sent_at` on the rows is the only thing that knows a mail actually left,
     * so it is what drives the counter rather than the POST's own reply.
     */
    refetchInterval: sending ? 3000 : false,
  });

  const issued = issuedQuery.data;
  // Counted from the rows, not asked of the server separately: the address is a
  // column on each card, so "how many can be sent" is already in hand and a
  // second endpoint answering it could disagree with the one that refuses.
  const sendable = issued?.items.filter((c) => c.recipient_email && c.has_png) ?? [];
  const addresses = new Set(sendable.map((c) => c.recipient_email)).size;
  const alreadySent = sendable.filter((c) => c.sent_at).length;

  // Stop polling once every sendable card has left, so a tab left open does not
  // keep asking forever.
  useEffect(() => {
    if (sending && sendable.length > 0 && alreadySent === sendable.length) {
      setSending(false);
      toast.success(`${alreadySent} kartu terkirim.`);
    }
  }, [sending, alreadySent, sendable.length]);

  const downloadCard = useMutation({
    mutationFn: (cardId: string) => downloadIdCard(orgId!, cardId),
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const send = useMutation({
    mutationFn: () => sendIdCards(orgId!, eventId),
    onSuccess: (result) => {
      // Watch the rows rather than declaring victory. The POST only queued the
      // jobs, and the provider can still refuse one — a green "terkirim" over
      // mail that a 550 rejected is exactly what went wrong before this.
      setSending(true);
      queryClient.invalidateQueries({ queryKey: ["id-cards-issued", orgId, eventId] });
      toast.info(
        `${result.cards} kartu masuk antrean ke ${result.emails} alamat email.` +
          (result.skipped > 0 ? ` ${result.skipped} dilewati (tanpa alamat email).` : "")
      );
    },
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const recipients = recipientsQuery.data ?? [];

  const toggle = (key: string) =>
    setPicked((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));

  /** Select-all scoped to one heading, so "semua wasit" is one click. */
  const toggleGroup = (type: IdCardRecipient["type"]) => {
    const keys = recipients.filter((r) => r.type === type).map(keyOf);
    const allPicked = keys.every((k) => picked.includes(k));

    setPicked((prev) =>
      allPicked ? prev.filter((k) => !keys.includes(k)) : [...new Set([...prev, ...keys])]
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
        <PageHeader title="Cetak ID card" />
        <Skeleton className="h-[400px] w-full rounded-xl" />
      </div>
    );
  }

  const running = !!batch && batch.status !== "done" && batch.status !== "failed";
  const ready = eventId && templateId && picked.length > 0 && allowed;

  return (
    <div>
      <PageHeader
        title="Cetak ID card"
        description="Pilih event, template, lalu siapa saja yang dicetak kartunya."
      />

      <div className="flex flex-col gap-5">
        <Card className="grid gap-4 p-5 sm:grid-cols-2">
          <div>
            <Label htmlFor="event">Event</Label>
            <Select
              id="event"
              value={eventId}
              onChange={(e) => {
                setParams({ event_id: e.target.value || null });
                reset();
              }}
              className="mt-1.5"
            >
              <option value="">— pilih event —</option>
              {eventsQuery.data?.map((ev) => (
                <option key={ev.id} value={ev.id}>
                  {ev.name}
                </option>
              ))}
            </Select>
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
          </div>
        </Card>

        {!allowed && (
          <Card className="flex flex-wrap items-center justify-between gap-3 border-[var(--border-strong)] p-5">
            <div>
              <p className="font-semibold">Paket event ini belum mencakup generator ID card</p>
              <p className="text-sm text-muted-foreground">
                Naikkan paket event tersebut, atau pilih event lain yang paketnya sudah mencakupnya.
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
              Pilih event dulu untuk melihat calon penerima kartu.
            </p>
          ) : recipientsQuery.isLoading ? (
            <Skeleton className="h-40 w-full rounded-lg" />
          ) : recipients.length === 0 ? (
            <div className="py-10 text-center text-sm text-muted-foreground">
              <Users className="mx-auto mb-2 h-6 w-6" />
              Belum ada pemain, ofisial, atau petugas di event ini.
            </div>
          ) : (
            <div className="flex flex-col gap-6">
              {GROUPS.map(({ type, label }) => {
                const pool = recipients.filter((r) => r.type === type);
                if (pool.length === 0) return null;

                const chosen = pool.filter((r) => picked.includes(keyOf(r))).length;

                return (
                  <div key={type}>
                    <div className="mb-3 flex items-center justify-between gap-3">
                      <h3 className="text-sm font-semibold">
                        {label}{" "}
                        <span className="font-normal text-muted-foreground">
                          ({chosen}/{pool.length})
                        </span>
                      </h3>
                      <Button size="sm" variant="outline" onClick={() => toggleGroup(type)}>
                        {chosen === pool.length ? "Kosongkan" : "Pilih semua"}
                      </Button>
                    </div>

                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                      {pool.map((person) => {
                        const key = keyOf(person);

                        return (
                          <label
                            key={key}
                            className={cn(
                              "flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 text-sm transition-colors",
                              picked.includes(key)
                                ? "border-[var(--brand-600)] bg-[var(--tint)]"
                                : "border-border hover:border-[var(--border-strong)]"
                            )}
                          >
                            <input
                              type="checkbox"
                              checked={picked.includes(key)}
                              onChange={() => toggle(key)}
                            />
                            <span className="min-w-0">
                              <span className="block truncate font-medium">{person.name}</span>
                              <span className="block truncate text-xs text-muted-foreground">
                                {[person.role_label, person.team_name].filter(Boolean).join(" · ")}
                                {!person.photo_url && " · tanpa foto"}
                              </span>
                            </span>
                          </label>
                        );
                      })}
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
                Gagal membuat kartu{batch.error ? `: ${batch.error}` : "."}
              </p>
            ) : running ? (
              <p className="flex items-center gap-2 text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" />
                Membuat kartu… {batch.done}/{batch.total}
              </p>
            ) : batch?.status === "done" ? (
              <p className="text-muted-foreground">
                {batch.total} kartu siap diunduh sebagai satu .zip.
              </p>
            ) : (
              <p className="text-muted-foreground">
                {picked.length > 0 ? `${picked.length} orang dipilih.` : "Belum ada yang dipilih."}
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
                <IdCard className="h-4 w-4" />
              )}
              {batch?.status === "done" ? "Cetak ulang" : "Buat kartu"}
              {picked.length > 0 && batch?.status !== "done" ? ` (${picked.length})` : ""}
            </Button>
          </div>
        </Card>

        {/* Only once this event has cards. A panel offering to email nothing is
            an announcement about a door that isn't there — the same call made
            for `online_registration_enabled`. */}
        {eventId && (issued?.meta.total ?? 0) > 0 && (
          <Card className="flex flex-wrap items-center justify-between gap-4 p-5">
            <div className="min-w-0 text-sm">
              <p className="font-semibold">Kirim kartu via email</p>
              <p className="text-muted-foreground">
                {/* The counts are stated before the click, not discovered from
                    the result. The gap between them is ordinary — a team
                    registered without a manager account has nowhere to send —
                    and an organizer who only learns it afterwards reads it as a
                    bug. */}
                {issued!.meta.total} kartu terbit, {sendable.length} punya alamat email (
                {addresses} email).
                {issued!.meta.total > sendable.length &&
                  ` ${issued!.meta.total - sendable.length} harus dicetak — tim tanpa akun manajer dan petugas tanpa email tidak punya tujuan.`}
              </p>

              {sending ? (
                <p className="mt-1.5 flex items-center gap-2 text-sm text-muted-foreground">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Mengirim… {alreadySent}/{sendable.length} kartu terkirim
                </p>
              ) : alreadySent > 0 ? (
                <p className="mt-1.5 text-sm text-muted-foreground">
                  {alreadySent} dari {sendable.length} kartu sudah pernah terkirim.
                </p>
              ) : null}

              <p className="mt-1 text-xs text-muted-foreground">
                Satu email per tujuan: manajer tim menerima kartu seluruh anggotanya sekaligus,
                wasit dan staf menerima kartunya sendiri. Pengiriman dijarakkan agar tidak ditolak
                penyedia email, jadi butuh beberapa menit untuk event besar.
              </p>
            </div>

            <Button
              variant="outline"
              disabled={sendable.length === 0 || send.isPending || sending}
              onClick={async () => {
                const resend = sendable.some((c) => c.sent_at);
                const ok = await confirm({
                  title: `Kirim ${sendable.length} kartu ke ${addresses} alamat email?`,
                  description: resend
                    ? "Sebagian kartu sudah pernah dikirim dan akan dikirim ulang ke alamat yang sama."
                    : "Setiap penerima mendapat kartunya sebagai lampiran PNG, berikut QR verifikasinya.",
                  confirmLabel: "Kirim sekarang",
                });
                if (ok) send.mutate();
              }}
            >
              {send.isPending || sending ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Mail className="h-4 w-4" />
              )}
              {sending ? "Mengirim…" : alreadySent > 0 ? "Kirim ulang" : "Kirim via email"}
            </Button>
          </Card>
        )}

        {eventId && (issued?.meta.total ?? 0) > 0 && (
          <Card className="p-5">
            <h3 className="text-sm font-semibold">Kartu terbit</h3>
            <p className="mt-1 text-xs text-muted-foreground">
              Nomor kartu tercetak di QR-nya, jadi kartu fisik bisa dilacak kembali ke baris ini.
              Mencetak ulang orang yang sama memakai nomor yang sama.
            </p>

            <div className="mt-4 flex flex-col divide-y divide-border">
              {issued!.items.map((card) => (
                <div key={card.id} className="flex items-center justify-between gap-3 py-2.5">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{card.recipient_name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                      {[card.role_label, card.team_name].filter(Boolean).join(" · ")}
                      {" · "}
                      <code className="text-[11px]">{card.card_number}</code>
                    </p>
                  </div>

                  <div className="flex shrink-0 items-center gap-2">
                    {/* Three states, not two: never sent, sent, and no address
                        at all. The third is the one an organizer acts on — it
                        means this card has to be printed. */}
                    {card.sent_at ? (
                      <Badge variant="success">Terkirim</Badge>
                    ) : card.recipient_email ? (
                      <Badge variant="outline">Belum dikirim</Badge>
                    ) : (
                      <Badge variant="outline">Tanpa email</Badge>
                    )}

                    {card.has_png && (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => downloadCard.mutate(card.id)}
                        disabled={downloadCard.isPending}
                      >
                        <Download className="h-4 w-4" />
                      </Button>
                    )}
                  </div>
                </div>
              ))}
            </div>

            {issued!.meta.total > issued!.items.length && (
              <p className="mt-3 text-xs text-muted-foreground">
                Menampilkan {issued!.items.length} dari {issued!.meta.total} kartu terbit.
              </p>
            )}
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
      <GenerateIdCardsPage />
    </Suspense>
  );
}
