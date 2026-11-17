"use client";

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  Download,
  IdCard,
  Loader2,
  Mail,
  Printer,
  QrCode,
  ScanLine,
  Search,
  Send,
  Users,
} from "lucide-react";

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
import { EmptyState } from "@/components/shared/empty-state";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { useConfirm } from "@/components/shared/confirm-provider";
import { PillTabs } from "@/components/event/pill-tabs";
import { StepSection } from "@/components/id-card/step-section";
import { IssuedCardRow } from "@/components/id-card/issued-card-row";
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

const TABS = [
  { key: "print", label: "Cetak", icon: Printer },
  { key: "deliver", label: "Kirim & Riwayat", icon: Send },
];

const keyOf = (r: IdCardRecipient) => `${r.type}:${r.id}`;

function GenerateIdCardsPage() {
  const router = useRouter();
  const params = useSearchParams();
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const { orgId, isLoading: orgLoading } = useActiveOrg();

  /**
   * Event, template and tab all live in the URL, not in component state.
   *
   * Sending takes minutes to finish (the mails are queued and the provider is
   * rate-limited), so the one thing an organizer will certainly do is reload
   * this page — and with the selection held locally that reload dropped them
   * back to "pilih event dulu" over the batch they were watching. Same
   * reasoning as the certificates page keeping its filter in the URL.
   */
  const eventId = params.get("event_id") ?? "";
  const templateId = params.get("template_id") ?? "";
  const tab = params.get("tab") === "deliver" ? "deliver" : "print";

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
  const [sending, setSending] = useState(false);
  /**
   * Narrows the pool by name or team. A 578-player event is an ordinary one
   * here, and without this the only way to reach one person is to scroll past
   * everyone else — on a phone that is hundreds of swipes. Local to the page:
   * the whole list is already in hand, so asking the server would be a round
   * trip to filter what is on screen.
   */
  const [search, setSearch] = useState("");

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

  /**
   * Cards already issued for this event, which is what the deliver tab sends.
   *
   * Read from the server rather than derived from `picked`: the send goes out
   * to every card this event has ever issued, and an organizer who printed in
   * two sittings would otherwise be told the wrong number. `meta.total` counts
   * the whole event, not the page, which is exactly the figure wanted here.
   */
  const issuedQuery = useQuery({
    queryKey: ["id-cards-issued", orgId, eventId],
    queryFn: () => getIssuedIdCards(orgId!, { event_id: eventId, per_page: 100 }),
    enabled: !!orgId && !!eventId,
    /**
     * Polled while a send is in flight, because the send is genuinely not done
     * when the request returns: the mails are queued and deliberately spaced
     * past the provider's per-second limit, so a 26-card event takes most of a
     * minute. Without this the page looked frozen and the only way to see
     * progress was a reload — which is also what made losing the filter hurt.
     *
     * `sent_at` on the rows is the only thing that knows a mail actually left,
     * so it is what drives the counter rather than the POST's own reply.
     */
    refetchInterval: sending ? 3000 : false,
  });

  const issued = issuedQuery.data;
  const issuedTotal = issued?.meta.total ?? 0;

  // Counted from the rows, not asked of the server separately: the address is a
  // column on each card, so "how many can be sent" is already in hand and a
  // second endpoint answering it could disagree with the one that refuses.
  const sendable = issued?.items.filter((c) => c.recipient_email && c.has_png) ?? [];
  const addresses = new Set(sendable.map((c) => c.recipient_email)).size;
  const alreadySent = sendable.filter((c) => c.sent_at).length;

  // Rendering is what issues the rows, so the deliver tab has to re-ask once
  // the batch settles. Without this it keeps reading the count from before the
  // print and offers to email nothing over a zip that just finished.
  useEffect(() => {
    if (batch?.status === "done") {
      queryClient.invalidateQueries({ queryKey: ["id-cards-issued", orgId, eventId] });
    }
  }, [batch?.status, queryClient, orgId, eventId]);

  // Stop polling once every sendable card has left, so a tab left open does not
  // keep asking forever.
  useEffect(() => {
    if (sending && sendable.length > 0 && alreadySent === sendable.length) {
      setSending(false);
      toast.success(`${alreadySent} kartu terkirim.`);
    }
  }, [sending, alreadySent, sendable.length]);

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

  /**
   * The pool after the search box, which is what every count, every heading and
   * select-all below reads. Deriving them from the unfiltered list instead
   * would offer "Pilih semua (578)" over four visible rows — a button whose
   * number contradicts the list under it.
   */
  const needle = search.trim().toLowerCase();
  const visible = needle
    ? recipients.filter((r) =>
        [r.name, r.team_name, r.role_label]
          .filter(Boolean)
          .some((v) => v!.toLowerCase().includes(needle))
      )
    : recipients;

  const toggle = (key: string) =>
    setPicked((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));

  /** Select-all scoped to one heading, so "semua wasit" is one click. */
  const toggleGroup = (type: IdCardRecipient["type"]) => {
    const keys = visible.filter((r) => r.type === type).map(keyOf);
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
    // The needle belonged to the old event's pool; carried over it hides a list
    // the organizer has not seen yet behind a word they did not type here.
    setSearch("");
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
  const ready = !!eventId && !!templateId && picked.length > 0 && allowed;

  return (
    <div>
      <PageHeader
        title="Cetak ID card"
        description="Cetak sendiri, atau kirim kartunya lewat email — tidak semua penyelenggara mencetak."
      />

      <div className="flex flex-col gap-5">
        {/* Event stays above the tabs: both tabs are about one event, and a
            selector that lived inside a tab would have to be answered twice. */}
        <Card className="p-5">
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
        </Card>

        {!allowed && (
          <Card className="flex flex-wrap items-center justify-between gap-3 border-[var(--border-strong)] p-5">
            <div>
              <p className="font-semibold">Paket event ini belum mencakup generator ID card</p>
              <p className="text-sm text-muted-foreground">
                Naikkan paket event tersebut, atau pilih event lain yang paketnya sudah
                mencakupnya.
              </p>
            </div>
            <Button asChild variant="outline" className="w-full sm:w-auto">
              <Link href="/organizer/billing">Lihat paket</Link>
            </Button>
          </Card>
        )}

        {!eventId ? (
          <EmptyState
            icon={IdCard}
            title="Pilih event dulu"
            description="Kartu dicetak per event — pemain, ofisial tim, dan petugasnya diambil dari event yang kamu pilih di atas."
          />
        ) : (
          <>
            {/* The count rides in the tab's own label: an organizer who just
                printed needs to see there is something to send without opening
                the other tab to find out. */}
            <PillTabs
              items={[
                TABS[0],
                {
                  ...TABS[1],
                  label: issuedTotal > 0 ? `${TABS[1].label} (${issuedTotal})` : TABS[1].label,
                },
              ]}
              activeKey={tab}
              onSelect={(key) => setParams({ tab: key === "print" ? null : key })}
            />

            {tab === "print" ? (
              <Card className="flex flex-col gap-7 p-5">
                <StepSection
                  step={1}
                  title="Pilih template"
                  hint="Desain kartumu, berikut posisi foto, nama, dan QR-nya."
                  done={!!templateId}
                  aside={
                    <Button asChild size="sm" variant="ghost">
                      <Link href="/organizer/id-cards">Kelola template</Link>
                    </Button>
                  }
                >
                  {templatesQuery.data?.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border px-4 py-5 text-center text-sm text-muted-foreground">
                      Belum ada template.{" "}
                      <Link
                        href="/organizer/id-cards/templates/new"
                        className="font-medium text-[var(--brand-600)] hover:underline"
                      >
                        Buat satu dulu →
                      </Link>
                    </div>
                  ) : (
                    <Select
                      id="template"
                      value={templateId}
                      onChange={(e) => {
                        setParams({ template_id: e.target.value || null });
                        setBatchId(null);
                      }}
                    >
                      <option value="">— pilih template —</option>
                      {templatesQuery.data?.map((tpl) => (
                        <option key={tpl.id} value={tpl.id}>
                          {tpl.name} · {tpl.width_mm} × {tpl.height_mm} mm
                        </option>
                      ))}
                    </Select>
                  )}
                </StepSection>

                <StepSection
                  step={2}
                  title="Pilih siapa yang dicetak"
                  hint="Boleh sebagian — mencetak ulang orang yang sama tidak membuat nomor kartu baru."
                  done={picked.length > 0}
                  muted={!templateId}
                  aside={
                    picked.length > 0 ? (
                      <Button size="sm" variant="ghost" onClick={() => setPicked([])}>
                        Kosongkan ({picked.length})
                      </Button>
                    ) : undefined
                  }
                >
                  {recipientsQuery.isLoading ? (
                    <Skeleton className="h-40 w-full rounded-lg" />
                  ) : recipients.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                      <Users className="mx-auto mb-2 h-6 w-6" />
                      Belum ada pemain, ofisial, atau petugas di event ini.
                    </div>
                  ) : (
                    <div className="flex flex-col gap-4">
                      <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                          value={search}
                          onChange={(e) => setSearch(e.target.value)}
                          placeholder={`Cari di ${recipients.length} nama…`}
                          className="pl-9"
                        />
                      </div>

                      {visible.length === 0 ? (
                        <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                          Tidak ada yang cocok dengan “{search.trim()}”.
                        </p>
                      ) : (
                        <div className="flex flex-col gap-5">
                          {GROUPS.map(({ type, label }) => {
                            const pool = visible.filter((r) => r.type === type);
                            if (pool.length === 0) return null;

                            const chosen = pool.filter((r) => picked.includes(keyOf(r))).length;

                            return (
                              <div key={type}>
                                {/* The heading and its select-all share a row on
                                    a phone too: at 375px the label is three
                                    words and the button two, so stacking them
                                    would cost a line without buying legibility.
                                    `min-w-0` + `truncate` is what keeps the long
                                    count from pushing the button off screen. */}
                                <div className="mb-2.5 flex items-center justify-between gap-2">
                                  <h3 className="min-w-0 truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {label} ({chosen}/{pool.length})
                                  </h3>
                                  <Button
                                    size="sm"
                                    variant="ghost"
                                    className="shrink-0"
                                    onClick={() => toggleGroup(type)}
                                  >
                                    {chosen === pool.length ? "Kosongkan" : "Pilih semua"}
                                  </Button>
                                </div>

                                {/* `grid-cols-1` is load-bearing, not the
                                    default it looks like. Without it the single
                                    implicit column is sized `auto` — floored at
                                    the row's max-content, which is the longest
                                    name plus its subtitle on one line — and the
                                    cards paint 126px outside the card at 390px.
                                    `truncate` inside never gets a chance: the
                                    track grew to fit it. From `sm` up the
                                    explicit 2/3 columns already pinned this. */}
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
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
                                          className="h-4 w-4 shrink-0 accent-[var(--brand-600)]"
                                        />
                                        <span className="min-w-0">
                                          <span className="block truncate font-medium">
                                            {person.name}
                                          </span>
                                          <span className="block truncate text-xs text-muted-foreground">
                                            {[person.role_label, person.team_name]
                                              .filter(Boolean)
                                              .join(" · ")}
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
                    </div>
                  )}
                </StepSection>

                <StepSection
                  step={3}
                  title="Buat kartunya"
                  hint="Dirender di latar belakang, lalu diunduh sebagai satu .zip berisi 1 PNG per orang."
                  done={batch?.status === "done"}
                  muted={!ready && batch?.status !== "done"}
                >
                  <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-[var(--bg-soft)] px-4 py-3">
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
                          {batch.total} kartu siap diunduh.{" "}
                          <button
                            type="button"
                            onClick={() => setParams({ tab: "deliver" })}
                            className="font-medium text-[var(--brand-600)] hover:underline"
                          >
                            Kirim lewat email →
                          </button>
                        </p>
                      ) : !templateId ? (
                        <p className="text-muted-foreground">Pilih template dulu di langkah 1.</p>
                      ) : picked.length === 0 ? (
                        <p className="text-muted-foreground">
                          Pilih minimal satu orang di langkah 2.
                        </p>
                      ) : (
                        <p className="text-muted-foreground">
                          {picked.length} orang siap dicetak.
                        </p>
                      )}
                    </div>

                    {/* Full width on a phone, where two buttons side by side at
                        375px wrap mid-label; auto from `sm` up. */}
                    <div className="flex w-full gap-2 sm:w-auto">
                      {batch?.status === "done" && (
                        <Button
                          variant="outline"
                          className="flex-1 sm:flex-none"
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
                        className="flex-1 sm:flex-none"
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
                  </div>
                </StepSection>
              </Card>
            ) : issuedTotal === 0 ? (
              <EmptyState
                icon={Printer}
                title="Belum ada kartu terbit"
                description="Buat kartunya dulu di tab Cetak. Setiap kartu yang terbit dapat nomor dan QR verifikasinya sendiri, dan baru setelah itu bisa dikirim lewat email."
                action={
                  <Button onClick={() => setParams({ tab: null })}>
                    <Printer className="h-4 w-4" />
                    Ke tab Cetak
                  </Button>
                }
              />
            ) : (
              <>
                {/* What the QR is for, answered where the cards are. This was
                    the single most confusing thing about the feature: the code
                    is printed, nothing in the app scans it, and nothing said
                    so. There is deliberately no in-app scanner — a card has no
                    check-in, so any phone camera is the reader and the gate
                    staff need no account at all. */}
                <Card className="flex gap-3 p-5 sm:gap-4">
                  <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-[var(--tint)] text-[var(--brand-600)] sm:h-10 sm:w-10">
                    <QrCode className="h-4 w-4 sm:h-5 sm:w-5" />
                  </span>
                  <div className="min-w-0 text-sm">
                    <p className="font-semibold">Cara memindai QR di kartu</p>
                    <p className="mt-1 text-muted-foreground">
                      Pakai <strong>kamera ponsel biasa</strong> — tanpa aplikasi, tanpa akun.
                      QR-nya membuka halaman verifikasi berisi nama, peran, tim, dan nomor kartu.
                    </p>
                    {/* Not `flex`. A flex container turns each run of text
                        between inline elements into its own anonymous flex
                        item, so this paragraph — text, <strong>, text — laid
                        itself out as three columns and read as three broken
                        sentences side by side. The icon is floated inline
                        instead, which is all the row ever wanted. */}
                    <p className="mt-1.5 text-xs text-muted-foreground">
                      <ScanLine className="mr-1.5 inline-block h-3.5 w-3.5 shrink-0 align-[-2px]" />
                      Ini membuktikan kartunya asli, bukan identitas pemegangnya — cocokkan
                      fotonya. Ikon <strong>↗</strong> di bawah membuka halaman yang sama.
                    </p>
                  </div>
                </Card>

                <Card className="p-5">
                  {/* `flex-wrap` alone put the button on its own line at 390px
                      but kept it stretched by `items-start`, so it sat hard
                      against the paragraph above with no row of its own.
                      Explicit column under `sm` gives it one. */}
                  <div className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
                    <div className="min-w-0 text-sm">
                      <p className="font-semibold">Kirim kartu via email</p>

                      {/* The counts are stated before the click, not discovered
                          from the result. The gap between them is ordinary — a
                          team registered without a manager account has nowhere
                          to send — and an organizer who only learns it
                          afterwards reads it as a bug. */}
                      {/* Three figures, each kept whole: run together as one
                          sentence they wrap mid-figure at 390px ("41 /
                          email"), and a number split from its unit reads as a
                          different number. The separator is drawn rather than
                          typed so the dots never start a line. */}
                      <p className="mt-1 flex flex-wrap gap-x-1.5 gap-y-0.5 text-muted-foreground">
                        <span className="whitespace-nowrap">{issuedTotal} kartu terbit</span>
                        <span aria-hidden>·</span>
                        <span className="whitespace-nowrap">
                          {sendable.length} punya alamat email
                        </span>
                        <span aria-hidden>·</span>
                        <span className="whitespace-nowrap">{addresses} email</span>
                      </p>

                      {issuedTotal > sendable.length && (
                        <p className="mt-1 text-xs text-[var(--warning)]">
                          {issuedTotal - sendable.length} kartu tanpa alamat email — harus
                          diserahkan langsung.
                        </p>
                      )}

                      {sending ? (
                        <p className="mt-2 flex items-center gap-2 text-sm font-medium text-[var(--brand-600)]">
                          <Loader2 className="h-4 w-4 animate-spin" />
                          Mengirim… {alreadySent}/{sendable.length} kartu terkirim
                        </p>
                      ) : alreadySent > 0 ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                          {alreadySent} dari {sendable.length} kartu sudah terkirim.
                        </p>
                      ) : null}

                      <p className="mt-2 text-xs text-muted-foreground">
                        Satu email per tujuan: manajer tim menerima kartu seluruh anggotanya,
                        wasit dan staf menerima kartunya sendiri. Butuh beberapa menit untuk event
                        besar.
                      </p>
                    </div>

                    <Button
                      className="w-full sm:w-auto"
                      disabled={sendable.length === 0 || send.isPending || sending}
                      onClick={async () => {
                        const ok = await confirm({
                          title: `Kirim ${sendable.length} kartu ke ${addresses} alamat email?`,
                          description:
                            alreadySent > 0
                              ? `${alreadySent} kartu sudah pernah dikirim dan akan dikirim ulang ke alamat yang sama.`
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
                      {sending
                        ? "Mengirim…"
                        : alreadySent === sendable.length && alreadySent > 0
                          ? "Kirim ulang semua"
                          : "Kirim via email"}
                    </Button>
                  </div>
                </Card>

                <Card className="p-5">
                  <h3 className="text-sm font-semibold">Kartu terbit</h3>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Nomor kartu ikut tercetak di QR-nya, jadi kartu fisik bisa dilacak kembali ke
                    baris ini. Mencetak ulang orang yang sama memakai nomor yang sama.
                  </p>

                  <div className="mt-4 flex flex-col divide-y divide-border">
                    {issued!.items.map((card) => (
                      <IssuedCardRow
                        key={card.id}
                        card={card}
                        onDownload={(id) => downloadCard.mutate(id)}
                        downloading={downloadCard.isPending && downloadCard.variables === card.id}
                      />
                    ))}
                  </div>

                  {issuedTotal > issued!.items.length && (
                    <p className="mt-3 text-xs text-muted-foreground">
                      Menampilkan {issued!.items.length} dari {issuedTotal} kartu terbit.
                    </p>
                  )}
                </Card>
              </>
            )}
          </>
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
