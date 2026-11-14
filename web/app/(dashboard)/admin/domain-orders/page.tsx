"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Globe, Inbox } from "lucide-react";

import { fulfilDomainOrder, getDomainOrders } from "@/lib/api/domains";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { domainKindLabel } from "@/lib/domain";
import { rupiah } from "@/lib/labels";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { FieldError } from "@/components/ui/field-error";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { PillTabs } from "@/components/event/pill-tabs";
import type { EventPlanOrder } from "@/types/api";

const fmtDateTime = (iso: string) =>
  new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });

/**
 * Domains organizers have paid for and we still have to buy at a registrar.
 *
 * Only the `register` kind reaches here — the other two are fulfilled by being
 * paid for, so a queue listing them would be a queue of work nobody has to do.
 *
 * Its own page rather than a tab on /admin/plan-orders: that one rules on
 * whether a transfer is genuine, this one records work done outside the system.
 * The two happen to share a table, which is an implementation detail, not a
 * reason to share a screen.
 */
export default function AdminDomainOrdersPage() {
  const qc = useQueryClient();
  const [tab, setTab] = useState("queue");
  const [fulfilling, setFulfilling] = useState<EventPlanOrder | null>(null);
  const [domainName, setDomainName] = useState("");
  const [note, setNote] = useState("");
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const query = useQuery({ queryKey: ["admin-domain-orders"], queryFn: getDomainOrders });

  const close = () => {
    setFulfilling(null);
    setDomainName("");
    setNote("");
    // Cleared on close as well as on open: a refusal belonging to the previous
    // row would mark a field this one has never been judged on.
    setFieldErrors({});
  };

  const fulfil = useMutation({
    mutationFn: () => fulfilDomainOrder(fulfilling!.id, domainName.trim(), note.trim() || undefined),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-domain-orders"] });
      // The event now carries a hostname, so the cross-org list that shows
      // domain status is stale too.
      qc.invalidateQueries({ queryKey: ["admin-events"] });
      toast.success("Domain ditandai sudah diregistrasi");
      close();
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan domain.");
      setFieldErrors(parsed.fieldErrors);
      if (!parsed.fieldErrors.domain_name) toast.error(parsed.message);
    },
  });

  const pending = query.data?.pending ?? [];
  const fulfilled = query.data?.fulfilled ?? [];
  const rows = tab === "queue" ? pending : fulfilled;

  const openFulfil = (order: EventPlanOrder) => {
    setFulfilling(order);
    // Seeded with what the organizer asked for: most of the time that is
    // exactly the name that gets registered, and retyping it is a chance to
    // mistype it.
    setDomainName(order.domain_name ?? "");
    setNote("");
    setFieldErrors({});
  };

  return (
    <div>
      <PageHeader
        title="Pesanan Domain"
        description="Domain yang sudah dibayar organizer dan perlu diregistrasi di registrar."
      />

      <PillTabs
        items={[
          { key: "queue", label: `Perlu diregistrasi (${pending.length})`, icon: Inbox },
          { key: "done", label: "Sudah diregistrasi", icon: Globe },
        ]}
        activeKey={tab}
        onSelect={setTab}
      />

      <div className="mt-4 grid gap-3">
        {query.isLoading ? (
          [0, 1].map((i) => <Skeleton key={i} className="h-24 w-full rounded-xl" />)
        ) : rows.length === 0 ? (
          <EmptyState
            icon={Globe}
            title={tab === "queue" ? "Tidak ada pesanan domain" : "Belum ada yang diregistrasi"}
            description={
              tab === "queue"
                ? "Pesanan domain yang dibeli dari flo-event akan muncul di sini begitu pembayarannya lunas."
                : "Domain yang sudah kamu registrasi akan tercatat di sini."
            }
          />
        ) : (
          rows.map((order) => (
            <Card key={order.id} className="p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-sm font-medium">
                      {order.domain_name ?? "(belum ditentukan)"}
                    </span>
                    <Badge variant="neutral">{domainKindLabel(order.domain_kind)}</Badge>
                  </div>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {order.organization?.name ?? "—"}
                    {order.domain_event ? ` · ${order.domain_event.name}` : ""}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {order.invoice_number} · {rupiah(order.domain_addon_amount)}
                    {order.paid_at ? ` · lunas ${fmtDateTime(order.paid_at)}` : ""}
                  </p>
                  {order.domain_fulfilled_at && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      Diregistrasi {fmtDateTime(order.domain_fulfilled_at)}
                      {order.domain_fulfilled_by ? ` oleh ${order.domain_fulfilled_by}` : ""}
                      {order.domain_event?.domain_status
                        ? ` · status domain: ${order.domain_event.domain_status}`
                        : ""}
                    </p>
                  )}
                  {order.domain_note && (
                    <p className="mt-1 text-xs text-muted-foreground">{order.domain_note}</p>
                  )}
                </div>
                {order.awaiting_domain_fulfilment && (
                  <Button onClick={() => openFulfil(order)}>Tandai sudah diregistrasi</Button>
                )}
              </div>
            </Card>
          ))
        )}
      </div>

      <Dialog open={!!fulfilling} onOpenChange={(next) => !fulfil.isPending && !next && close()}>
        <DialogContent>
          <DialogHeader
            title="Tandai domain sudah diregistrasi"
            description="Domainnya langsung dipasang ke event dan sertifikat HTTPS diterbitkan otomatis."
          />
          <DialogBody>
            <div className="grid gap-4">
              <div className="grid gap-2">
                <Label htmlFor="fulfil-domain">Domain yang diregistrasi</Label>
                <Input
                  id="fulfil-domain"
                  value={domainName}
                  placeholder="liga-anda.id"
                  autoCapitalize="none"
                  spellCheck={false}
                  aria-invalid={fieldErrors.domain_name ? true : undefined}
                  disabled={fulfil.isPending}
                  onChange={(e) => setDomainName(e.target.value)}
                />
                <FieldError message={fieldErrors.domain_name} />
                {/* The organizer may have asked for a name that was taken.
                    Saying so here is what makes the note field below obviously
                    worth filling in. */}
                {fulfilling?.domain_name && (
                  <p className="text-xs text-muted-foreground">
                    Yang diminta organizer:{" "}
                    <span className="font-mono">{fulfilling.domain_name}</span>
                  </p>
                )}
              </div>

              <div className="grid gap-2">
                <Label htmlFor="fulfil-note">Catatan (opsional)</Label>
                <Input
                  id="fulfil-note"
                  value={note}
                  placeholder="Nomor order registrar, atau alasan domain diganti"
                  disabled={fulfil.isPending}
                  onChange={(e) => setNote(e.target.value)}
                />
                <FieldError message={fieldErrors.domain_note} />
                <p className="text-xs text-muted-foreground">
                  Ditampilkan ke organizer. Isi kalau domainnya berbeda dari yang diminta — itu
                  satu-satunya penjelasan yang ia lihat.
                </p>
              </div>
            </div>
          </DialogBody>
          <DialogFooter>
            <Button variant="secondary" onClick={close} disabled={fulfil.isPending}>
              Batal
            </Button>
            <Button disabled={!domainName.trim() || fulfil.isPending} onClick={() => fulfil.mutate()}>
              {fulfil.isPending ? "Menyimpan…" : "Simpan"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
