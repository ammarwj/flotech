"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { ChevronLeft, Ticket, Check, Minus, Plus, Loader2 } from "lucide-react";

import { getPublicTicketCategories, purchaseTickets } from "@/lib/api/tickets";
import { getPublicEvent } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card } from "@/components/ui/card";
import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";
import "../../../event-shell.css";

export default function BuyTicketsPage() {
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  const router = useRouter();
  const base = `/${params.orgSlug}/${params.eventSlug}`;

  const [selected, setSelected] = useState<string | null>(null);
  const [quantity, setQuantity] = useState(1);
  const [buyer, setBuyer] = useState({ name: "", email: "", phone: "" });
  const [error, setError] = useState<string | null>(null);

  const eventQuery = useQuery({
    queryKey: ["public-event", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicEvent(params.orgSlug, params.eventSlug),
    retry: false,
  });

  const catsQuery = useQuery({
    queryKey: ["public-tickets", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicTicketCategories(params.orgSlug, params.eventSlug),
    retry: false,
  });

  const categories = catsQuery.data ?? [];
  const onSale = categories.filter((c) => c.is_on_sale && (c.remaining === null || c.remaining > 0));
  const selectedCat = categories.find((c) => c.id === selected) ?? null;

  const maxQty = Math.min(
    20,
    selectedCat?.remaining === null || selectedCat?.remaining === undefined
      ? 20
      : selectedCat.remaining
  );
  const total = (selectedCat?.price ?? 0) * quantity;

  const mutation = useMutation({
    mutationFn: () =>
      purchaseTickets(params.orgSlug, params.eventSlug, {
        ticket_category_id: selected!,
        quantity,
        buyer_name: buyer.name,
        buyer_email: buyer.email,
        buyer_phone: buyer.phone || undefined,
      }),
    onSuccess: (res) => {
      if (!res.mock && res.redirect_url) {
        window.location.href = res.redirect_url;
        return;
      }
      router.push(`/tickets/${res.order.id}`);
    },
    onError: (err) => setError(parseApiError(err, "Gagal memproses pembelian.").message),
  });

  const canSubmit = selected && quantity > 0 && buyer.name.trim() && buyer.email.trim();

  if (eventQuery.isError) {
    return (
      <div className="container" style={{ paddingBlock: 96, textAlign: "center" }}>
        <h1 className="section-title">Event tidak ditemukan</h1>
        <Link href="/" className="btn btn-primary btn-lg" style={{ marginTop: 24 }}>
          Ke beranda
        </Link>
      </div>
    );
  }

  return (
    <div className="container" style={{ paddingBlock: 48, maxWidth: 760 }}>
      <Link
        href={base}
        className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <ChevronLeft className="h-4 w-4" />
        Kembali ke halaman event
      </Link>

      <div className="mt-4 mb-8">
        <h1 className="text-2xl font-bold sm:text-3xl" style={{ fontFamily: "var(--font-display)" }}>
          Beli Tiket
        </h1>
        {eventQuery.data && (
          <p className="mt-1.5 text-muted-foreground">{eventQuery.data.name}</p>
        )}
      </div>

      {catsQuery.isLoading ? (
        <p className="text-muted-foreground">Memuat kategori tiket…</p>
      ) : onSale.length === 0 ? (
        <Card className="p-8 text-center">
          <div className="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-xl bg-[var(--tint)] text-[var(--brand-600)]">
            <Ticket className="h-6 w-6" />
          </div>
          <h2 className="text-lg font-semibold" style={{ fontFamily: "var(--font-display)" }}>
            Belum ada tiket dijual
          </h2>
          <p className="mt-1.5 text-sm text-muted-foreground">
            Penyelenggara belum membuka penjualan tiket untuk event ini.
          </p>
        </Card>
      ) : (
        <div className="grid gap-6">
          {/* Category picker */}
          <div className="grid gap-3">
            {onSale.map((cat) => {
              const active = selected === cat.id;
              return (
                <button
                  key={cat.id}
                  type="button"
                  onClick={() => {
                    setSelected(cat.id);
                    setQuantity(1);
                  }}
                  className={cn(
                    "w-full rounded-xl border p-4 text-left transition-colors",
                    active
                      ? "border-[var(--brand-600)] bg-[var(--tint)]"
                      : "border-border hover:border-[var(--border-strong)]"
                  )}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                          {cat.name}
                        </span>
                        {active && <Check className="h-4 w-4 text-[var(--brand-600)]" />}
                      </div>
                      {cat.description && (
                        <p className="mt-1 text-sm text-muted-foreground">{cat.description}</p>
                      )}
                      {cat.benefits.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                          {cat.benefits.map((b) => (
                            <span
                              key={b}
                              className="rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-[var(--text-2)]"
                            >
                              {b}
                            </span>
                          ))}
                        </div>
                      )}
                      {cat.remaining !== null && (
                        <p className="mt-2 text-xs text-muted-foreground">Sisa {cat.remaining} tiket</p>
                      )}
                    </div>
                    <span className="shrink-0 font-bold" style={{ fontFamily: "var(--font-display)" }}>
                      {cat.price > 0 ? rupiah(cat.price) : "Gratis"}
                    </span>
                  </div>
                </button>
              );
            })}
          </div>

          {/* Quantity + buyer */}
          {selectedCat && (
            <Card className="grid gap-4 p-5">
              <div className="flex items-center justify-between">
                <Label>Jumlah tiket</Label>
                <div className="flex items-center gap-3">
                  <Button
                    type="button"
                    size="icon"
                    variant="outline"
                    onClick={() => setQuantity((q) => Math.max(1, q - 1))}
                    disabled={quantity <= 1}
                  >
                    <Minus className="h-4 w-4" />
                  </Button>
                  <span className="w-8 text-center font-semibold">{quantity}</span>
                  <Button
                    type="button"
                    size="icon"
                    variant="outline"
                    onClick={() => setQuantity((q) => Math.min(maxQty, q + 1))}
                    disabled={quantity >= maxQty}
                  >
                    <Plus className="h-4 w-4" />
                  </Button>
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label htmlFor="b-name">Nama pembeli</Label>
                  <Input
                    id="b-name"
                    value={buyer.name}
                    onChange={(e) => setBuyer((b) => ({ ...b, name: e.target.value }))}
                    placeholder="Nama lengkap"
                  />
                </div>
                <div>
                  <Label htmlFor="b-email">Email</Label>
                  <Input
                    id="b-email"
                    type="email"
                    value={buyer.email}
                    onChange={(e) => setBuyer((b) => ({ ...b, email: e.target.value }))}
                    placeholder="email@contoh.com"
                  />
                </div>
              </div>
              <div>
                <Label htmlFor="b-phone">No. HP (opsional)</Label>
                <Input
                  id="b-phone"
                  value={buyer.phone}
                  onChange={(e) => setBuyer((b) => ({ ...b, phone: e.target.value }))}
                  placeholder="08xxxxxxxxxx"
                />
              </div>

              <div className="flex items-center justify-between border-t border-border pt-4">
                <span className="text-sm text-muted-foreground">Total</span>
                <span className="text-xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
                  {total > 0 ? rupiah(total) : "Gratis"}
                </span>
              </div>

              {error && <p className="text-sm text-[var(--danger)]">{error}</p>}

              <Button
                size="lg"
                disabled={!canSubmit || mutation.isPending}
                onClick={() => {
                  setError(null);
                  mutation.mutate();
                }}
              >
                {mutation.isPending ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    Memproses…
                  </>
                ) : total > 0 ? (
                  "Lanjut ke pembayaran"
                ) : (
                  "Dapatkan tiket"
                )}
              </Button>
            </Card>
          )}
        </div>
      )}
    </div>
  );
}
