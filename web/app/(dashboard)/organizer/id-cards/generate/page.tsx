"use client";

import { useState } from "react";
import Link from "next/link";
import { useMutation, useQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { Download, IdCard, Loader2, Printer, Users } from "lucide-react";

import {
  downloadIdCardBatch,
  generateIdCards,
  getIdCardBatch,
  getIdCardRecipients,
  getIdCardTemplates,
} from "@/lib/api/id-cards";
import { getEvents } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { isIdCardEnabled } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
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

export default function GenerateIdCardsPage() {
  const { orgId, isLoading: orgLoading } = useActiveOrg();

  const [eventId, setEventId] = useState("");
  const [templateId, setTemplateId] = useState("");
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
                setEventId(e.target.value);
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
                setTemplateId(e.target.value);
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
      </div>
    </div>
  );
}
