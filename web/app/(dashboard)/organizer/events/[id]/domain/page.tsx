"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { AlertTriangle, CheckCircle2, Clock, Copy, Globe } from "lucide-react";

import { getEvent } from "@/lib/api/events";
import { getPlanOrders } from "@/lib/api/organizations";
import { checkoutDomainAddon } from "@/lib/api/domains";
import { parseApiError } from "@/lib/api/errors";
import { checkoutOutcome } from "@/lib/checkout";
import { domainAddonStage, domainKindLabel, domainOrders } from "@/lib/domain";
import { rupiah } from "@/lib/labels";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { useSiteSettings } from "@/lib/hooks/use-site-settings";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { ChannelPicker } from "@/components/payment/channel-picker";
import { DomainAddonField } from "@/components/domain/domain-addon-field";
import { PageHeader } from "@/components/shared/page-header";
import type { DomainAddonPreview } from "@/types/api";

/**
 * The domain an event is served on: what it is, how far it has got, and how to
 * buy one.
 *
 * Its own page rather than a panel on the edit form, because what it has to say
 * is sequential — buy, point DNS, wait for the certificate — and most of it is
 * instructions rather than fields. The edit form is where an organizer changes
 * things that take effect on save; nothing here does.
 *
 * Buying from here is the "buy later" half of the add-on. The other half rides
 * along with the plan at checkout, and both go through `DomainAddonField` so the
 * price quoted is the price charged.
 */
export default function EventDomainPage() {
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const router = useRouter();
  const qc = useQueryClient();
  const { org, orgId } = useActiveOrg();
  // Shared hook, shared cache key: the dashboard shell already holds this, so
  // the contact link below costs no request.
  const { data: settings } = useSiteSettings();

  const [addon, setAddon] = useState<DomainAddonPreview | null>(null);
  const [channel, setChannel] = useState<string | null>(null);

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const ordersQuery = useQuery({
    queryKey: ["plan-orders", orgId],
    queryFn: () => getPlanOrders(orgId!),
    enabled: !!orgId,
  });

  const ev = eventQuery.data;
  // Orders carrying a domain for THIS event, newest first. Both shapes land
  // here: one bought with the plan, and one bought on its own.
  const orders = domainOrders(ordersQuery.data ?? []).filter(
    (order) => order.domain_event?.id === eventId || order.event_id === eventId
  );
  const outstanding = orders.find((order) => order.status === "past_due") ?? null;
  const registering = orders.find((order) => order.awaiting_domain_fulfilment) ?? null;

  // The rail: `requires_payment_channel` is the server's own answer, never
  // recombined from the global switch and the event's choice.
  const needsChannel = (ev?.requires_payment_channel ?? false) && (addon?.amount ?? 0) > 0;

  const checkout = useMutation({
    mutationFn: () =>
      checkoutDomainAddon(
        orgId!,
        eventId,
        addon!.domain_kind,
        addon!.domain_name ?? undefined,
        needsChannel ? (channel ?? undefined) : undefined
      ),
    onSuccess: (res) => {
      const outcome = checkoutOutcome(res);

      if (outcome === "redirect") {
        window.location.assign(res.redirect_url!);
        return;
      }

      qc.invalidateQueries({ queryKey: ["plan-orders", orgId] });
      qc.invalidateQueries({ queryKey: ["event", orgId, eventId] });

      if (outcome === "manual") {
        toast.info("Selesaikan transfer manual", {
          description: "Transfer ke rekening flo-event dan unggah buktinya di Pembelian Paket.",
        });
        router.push("/organizer/billing");
        return;
      }

      if (outcome === "failed") {
        toast.error("Pembayaran belum bisa dibuka", {
          description: "Tagihannya sudah terbit. Coba lagi dari halaman Pembelian Paket.",
        });
        router.push("/organizer/billing");
        return;
      }

      setAddon(null);
      setChannel(null);
      toast.success("Domain dibeli — pemasangannya sedang diproses.");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memproses pembelian.").message),
  });

  if (!orgId || eventQuery.isLoading) {
    return (
      <div>
        <PageHeader title="Domain event" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }

  if (!ev) {
    return (
      <div>
        <PageHeader title="Domain event" backHref="/organizer/events" backLabel="Daftar event" />
        <Card className="p-6 text-sm text-muted-foreground">Event tidak ditemukan.</Card>
      </div>
    );
  }

  const copy = (text: string) => {
    void navigator.clipboard.writeText(text);
    toast.success("Disalin");
  };

  /**
   * Where "hubungi admin" goes. WhatsApp first — a DNS panel is something
   * people get stuck in halfway, and a phone thread survives that better than
   * an email round trip. Null when the platform has configured neither, which
   * is a real state: `SiteSetting::current()` returns an unsaved instance while
   * the table is empty.
   */
  const helpHref = settings?.contact_phone
    ? `https://wa.me/${settings.contact_phone.replace(/\D/g, "").replace(/^0/, "62")}`
    : settings?.contact_email
      ? `mailto:${settings.contact_email}`
      : null;

  return (
    <div>
      <PageHeader
        title="Domain event"
        description={ev.name}
        backHref={`/organizer/events/${eventId}/edit`}
        backLabel="Pengaturan event"
      />

      {/* ===== STATUS ===== */}
      {ev.custom_domain ? (
        <Card className="mb-6 p-6">
          <div className="flex items-start gap-3">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[color-mix(in_srgb,var(--brand-600)_14%,transparent)] text-[var(--brand-600)]">
              <Globe className="h-5 w-5" />
            </span>
            <div className="min-w-0">
              <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
                {ev.custom_domain}
              </h3>
              <p className="mt-1 text-sm text-muted-foreground">
                {ev.subdomain
                  ? "Subdomain yang diterbitkan flo-event."
                  : "Domain milikmu sendiri."}
              </p>

              {ev.domain_status === "active" && (
                <p className="mt-3 inline-flex items-center gap-2 text-sm text-[var(--success)]">
                  <CheckCircle2 className="h-4 w-4" />
                  Aktif — halaman event sudah dilayani di domain ini
                </p>
              )}

              {ev.domain_status === "pending" && (
                <p className="mt-3 inline-flex items-center gap-2 text-sm text-muted-foreground">
                  <Clock className="h-4 w-4" />
                  Menunggu sertifikat HTTPS. Pemeriksaan jalan otomatis setiap jam.
                </p>
              )}

              {/* The certbot or DNS message, verbatim. It is the only clue why
                  activation did not take, and paraphrasing it would throw away
                  the one actionable half ("Detail: …"). */}
              {ev.domain_status === "failed" && (
                <div className="mt-3 rounded-lg border border-[var(--danger)] bg-[color-mix(in_srgb,var(--danger)_8%,transparent)] p-3">
                  <p className="inline-flex items-center gap-2 text-sm font-medium text-[var(--danger)]">
                    <AlertTriangle className="h-4 w-4" />
                    Pemasangan belum berhasil
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">{ev.domain_error}</p>
                </div>
              )}

              {/* Only an organizer-owned domain needs a DNS record from them. A
                  subdomain resolves through our own wildcard, so printing these
                  instructions there would send someone to a registrar they have
                  no account at. */}
              {!ev.subdomain && ev.domain_status !== "active" && ev.domain_server_ip && (
                <div className="mt-4 rounded-lg bg-[var(--muted)] p-3 text-sm">
                  {/* Written for someone who has never opened a DNS panel: the
                      heading says where to go, not what the thing is called.
                      The three rows below are the jargon, and they are there to
                      be copied rather than understood — which is why the IP is
                      a copy button and not just text. */}
                  <p className="font-medium">Tambahkan setelan ini di tempat kamu beli domain</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Buka pengelola domainmu (Niagahoster, Rumahweb, Cloudflare, GoDaddy, dan
                    sejenisnya), cari menu <strong>DNS</strong> atau <strong>DNS Records</strong>,
                    lalu tambahkan satu baris dengan isi berikut.
                  </p>
                  <div className="mt-2 grid gap-1 font-mono text-xs">
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-muted-foreground">Type</span>
                      <span>A</span>
                    </div>
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-muted-foreground">Name</span>
                      <span>@</span>
                    </div>
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-muted-foreground">Value</span>
                      <button
                        type="button"
                        className="inline-flex items-center gap-1.5 hover:underline"
                        onClick={() => copy(ev.domain_server_ip)}
                      >
                        {ev.domain_server_ip}
                        <Copy className="h-3 w-3" />
                      </button>
                    </div>
                  </div>
                  <p className="mt-3 text-xs text-muted-foreground">
                    Setelah disimpan, biarkan saja — pemeriksaan jalan otomatis setiap jam dan
                    sertifikat HTTPS-nya terbit sendiri. Biasanya beberapa menit sampai beberapa
                    jam.
                  </p>
                  {/* The way out for someone who cannot do it, and the reason
                      the copy above can stay short. Without it the page is a
                      set of instructions with no answer to "saya tidak bisa" —
                      and the organizer has already paid.
                      The contact comes from site_settings, the same source the
                      footer reads, so it cannot name an address nobody checks.
                      Offered as a plain sentence when none is configured: the
                      offer of help still stands, it just has nowhere to point. */}
                  <p className="mt-2 text-xs text-muted-foreground">
                    Tidak menemukan menunya atau ragu?{" "}
                    {helpHref ? (
                      <a href={helpHref} className="font-medium text-[var(--brand-600)] underline">
                        Hubungi admin flo-event
                      </a>
                    ) : (
                      "Hubungi admin flo-event"
                    )}{" "}
                    — kami bisa memandu langkahnya, atau mengerjakannya kalau kamu berikan akses
                    ke pengelola domainmu.
                  </p>
                </div>
              )}

              {ev.white_label && (
                <p className="mt-4 text-xs text-muted-foreground">
                  Event ini tidak lagi muncul di katalog{" "}
                  <span className="font-mono">/event</span>, dan halaman publiknya tanpa footer
                  maupun tautan &ldquo;Didukung flo-event&rdquo;.
                </p>
              )}

              {ev.domain_status === "active" && org?.slug && (
                <p className="mt-2 text-xs text-muted-foreground">
                  Tautan lama <span className="font-mono">/{org.slug}/{ev.slug}</span> tetap
                  hidup — pengunjung yang membukanya dialihkan ke domain ini.
                </p>
              )}
            </div>
          </div>
        </Card>
      ) : registering ? (
        /* Paid, name not bought yet. The one state the organizer cannot work
           out from the event row — there is no hostname on it to read. */
        <Card className="mb-6 p-6">
          <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Domain sedang diregistrasi
          </h3>
          <p className="mt-1 text-sm text-muted-foreground">
            Pembayaranmu sudah lunas
            {registering.domain_name ? (
              <>
                {" "}
                untuk <span className="font-mono">{registering.domain_name}</span>
              </>
            ) : null}
            . Tim flo-event yang membelikan domainnya — biasanya selesai dalam 1×24 jam, dan
            halaman ini otomatis menampilkannya begitu terpasang.
          </p>
        </Card>
      ) : null}

      {/* ===== BUY ===== */}
      {/* Still offered once a domain is live: the organizer may want to move to
          a different name. The outstanding-bill branch comes first, though —
          raising a second one for the same event would let them pay twice. */}
      {outstanding ? (
        <Card className="p-6">
          <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Ada tagihan domain yang belum lunas
          </h3>
          <p className="mt-1 text-sm text-muted-foreground">
            {domainKindLabel(outstanding.domain_kind)}
            {outstanding.domain_name ? ` — ${outstanding.domain_name}` : ""} ·{" "}
            {rupiah(outstanding.gross_amount)}
          </p>
          <Button asChild className="mt-4">
            <Link href="/organizer/billing">Selesaikan pembayaran</Link>
          </Button>
        </Card>
      ) : ev.status === "draft" ? (
        /* A draft 404s publicly, so it cannot hold a certificate — and asking
           Let's Encrypt for one burns quota the whole platform shares. Said here
           rather than refused at the till. */
        <Card className="p-6 text-sm text-muted-foreground">
          Publikasikan event ini dulu sebelum memasang domain. Sertifikat HTTPS hanya bisa
          diterbitkan untuk halaman yang sudah bisa dibuka publik.
        </Card>
      ) : registering ? null : (
        <Card className="p-6">
          <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {ev.custom_domain ? "Ganti domain" : "Pasang domain sendiri"}
          </h3>
          <p className="mb-4 mt-1 text-sm text-muted-foreground">
            {ev.custom_domain
              ? "Membeli domain baru akan menggantikan yang sekarang setelah pembayaran lunas."
              : "Pilih salah satu dari tiga cara di bawah."}
          </p>

          <div className="grid gap-4">
            <DomainAddonField
              orgId={orgId}
              applied={addon}
              optional={false}
              disabled={checkout.isPending}
              onApply={(preview) => {
                setAddon(preview);
                // An add-on changes the amount a percentage fee is taken from.
                setChannel(null);
              }}
              onClear={() => {
                setAddon(null);
                setChannel(null);
              }}
            />

            {addon && (
              <div className="flex justify-between rounded-lg border p-3 text-sm font-medium">
                <span>Yang dibayar</span>
                <span>{rupiah(addon.amount)}</span>
              </div>
            )}

            {needsChannel && addon && (
              <ChannelPicker
                amount={addon.amount}
                audience="organizer"
                value={channel}
                onChange={setChannel}
              />
            )}

            <div>
              <Button
                disabled={!addon || (needsChannel && !channel) || checkout.isPending}
                onClick={() => checkout.mutate()}
              >
                {checkout.isPending ? "Memproses…" : "Beli domain"}
              </Button>
            </div>
          </div>
        </Card>
      )}

      {/* ===== HISTORY ===== */}
      {orders.length > 0 && (
        <Card className="mt-6 p-6">
          <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Riwayat pembelian domain
          </h3>
          <div className="mt-3 grid gap-3">
            {orders.map((order) => (
              <div key={order.id} className="flex flex-wrap items-baseline justify-between gap-2 border-b pb-3 text-sm last:border-b-0 last:pb-0">
                <div className="min-w-0">
                  <div className="font-medium">
                    {domainKindLabel(order.domain_kind)}
                    {order.domain_name ? (
                      <span className="font-mono text-muted-foreground"> · {order.domain_name}</span>
                    ) : null}
                  </div>
                  <div className="text-xs text-muted-foreground">
                    {order.invoice_number} ·{" "}
                    {domainAddonStage(order) === "unpaid"
                      ? "Belum lunas"
                      : domainAddonStage(order) === "registering"
                        ? "Sedang diregistrasi"
                        : "Terpasang"}
                  </div>
                  {/* Why the name they got is not the name they asked for. The
                      only thing on screen that accounts for it. */}
                  {order.domain_note && (
                    <div className="mt-1 text-xs text-muted-foreground">{order.domain_note}</div>
                  )}
                </div>
                <span className="font-medium">{rupiah(order.domain_addon_amount)}</span>
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}
