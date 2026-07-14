"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Award, Loader2, Users } from "lucide-react";

import {
  generateCertificates,
  getCertificateRecipients,
  getCertificateTemplates,
} from "@/lib/api/certificates";
import { getEvents } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { isCertificateEmailEnabled } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

type RecipientMode = "team" | "player";

export default function GenerateCertificatesPage() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { org, orgId, isLoading: orgLoading } = useActiveOrg();

  const canEmail = isCertificateEmailEnabled(org);

  const [eventId, setEventId] = useState("");
  const [templateId, setTemplateId] = useState("");
  const [award, setAward] = useState("");
  const [mode, setMode] = useState<RecipientMode>("team");
  const [picked, setPicked] = useState<string[]>([]);
  const [sendEmail, setSendEmail] = useState(false);

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });

  const templatesQuery = useQuery({
    queryKey: ["certificate-templates", orgId],
    queryFn: () => getCertificateTemplates(orgId!),
    enabled: !!orgId,
  });

  const recipientsQuery = useQuery({
    queryKey: ["certificate-recipients", orgId, eventId],
    queryFn: () => getCertificateRecipients(orgId!, eventId),
    enabled: !!orgId && !!eventId,
  });

  const generate = useMutation({
    mutationFn: () =>
      generateCertificates(orgId!, eventId, {
        certificate_template_id: templateId,
        award_title: award,
        recipients: picked.map((id) => ({ type: mode, id })),
        send_email: sendEmail,
      }),
    onSuccess: (issued) => {
      queryClient.invalidateQueries({ queryKey: ["certificates", orgId] });
      toast.success(`${issued.length} sertifikat diterbitkan.`);
      router.push("/organizer/certificates");
    },
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const teams = recipientsQuery.data?.teams ?? [];
  const players = recipientsQuery.data?.players ?? [];
  const options =
    mode === "team"
      ? teams.map((t) => ({ id: t.id, name: t.name, hint: "" }))
      : players.map((p) => ({
          id: p.id,
          name: p.name,
          hint: teams.find((t) => t.id === p.team_id)?.name ?? "",
        }));

  const toggle = (id: string) =>
    setPicked((prev) => (prev.includes(id) ? prev.filter((p) => p !== id) : [...prev, id]));

  const switchMode = (next: RecipientMode) => {
    setMode(next);
    setPicked([]); // ids from the other pool would be rejected by the API
  };

  if (orgLoading) {
    return (
      <div>
        <PageHeader title="Terbitkan sertifikat" />
        <Skeleton className="h-[400px] w-full rounded-xl" />
      </div>
    );
  }

  const ready = eventId && templateId && award && picked.length > 0;

  return (
    <div>
      <PageHeader
        title="Terbitkan sertifikat"
        description="Pilih event, template, penghargaan, lalu penerimanya."
      />

      <div className="flex flex-col gap-5">
        <Card className="grid gap-4 p-5 sm:grid-cols-3">
          <div>
            <Label htmlFor="event">Event</Label>
            <Select
              id="event"
              value={eventId}
              onChange={(e) => {
                setEventId(e.target.value);
                setPicked([]);
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
              onChange={(e) => setTemplateId(e.target.value)}
              className="mt-1.5"
            >
              <option value="">— pilih template —</option>
              {templatesQuery.data?.map((tpl) => (
                <option key={tpl.id} value={tpl.id}>
                  {tpl.name}
                </option>
              ))}
            </Select>
          </div>

          <div>
            <Label htmlFor="award">Penghargaan</Label>
            <Input
              id="award"
              value={award}
              onChange={(e) => setAward(e.target.value)}
              placeholder="Juara 1 / Peserta"
              className="mt-1.5"
            />
          </div>
        </Card>

        <Card className="p-5">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div className="flex gap-1 rounded-lg border border-border p-1">
              {(
                [
                  ["team", "Tim", Users],
                  ["player", "Pemain", Award],
                ] as const
              ).map(([key, label, Icon]) => (
                <button
                  key={key}
                  onClick={() => switchMode(key)}
                  className={cn(
                    "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors",
                    mode === key
                      ? "bg-[var(--tint)] text-[var(--brand-600)]"
                      : "text-muted-foreground hover:text-foreground"
                  )}
                >
                  <Icon className="h-4 w-4" />
                  {label}
                </button>
              ))}
            </div>

            {options.length > 0 && (
              <Button
                size="sm"
                variant="outline"
                onClick={() =>
                  setPicked(picked.length === options.length ? [] : options.map((o) => o.id))
                }
              >
                {picked.length === options.length ? "Kosongkan" : "Pilih semua"}
              </Button>
            )}
          </div>

          {!eventId ? (
            <p className="py-10 text-center text-sm text-muted-foreground">
              Pilih event dulu untuk melihat calon penerima.
            </p>
          ) : recipientsQuery.isLoading ? (
            <Skeleton className="h-40 w-full rounded-lg" />
          ) : options.length === 0 ? (
            <p className="py-10 text-center text-sm text-muted-foreground">
              {mode === "team"
                ? "Belum ada tim yang disetujui di event ini."
                : "Belum ada pemain aktif di tim-tim event ini."}
            </p>
          ) : (
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {options.map((opt) => (
                <label
                  key={opt.id}
                  className={cn(
                    "flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 text-sm transition-colors",
                    picked.includes(opt.id)
                      ? "border-[var(--brand-600)] bg-[var(--tint)]"
                      : "border-border hover:border-[var(--border-strong)]"
                  )}
                >
                  <input
                    type="checkbox"
                    checked={picked.includes(opt.id)}
                    onChange={() => toggle(opt.id)}
                  />
                  <span className="min-w-0">
                    <span className="block truncate font-medium">{opt.name}</span>
                    {opt.hint && (
                      <span className="block truncate text-xs text-muted-foreground">
                        {opt.hint}
                      </span>
                    )}
                  </span>
                </label>
              ))}
            </div>
          )}
        </Card>

        <Card className="flex flex-wrap items-center justify-between gap-4 p-5">
          <label
            className={cn(
              "flex items-center gap-2 text-sm",
              !canEmail && "cursor-not-allowed opacity-60"
            )}
            title={canEmail ? undefined : "Paketmu belum mencakup pengiriman email"}
          >
            <input
              type="checkbox"
              checked={sendEmail}
              disabled={!canEmail}
              onChange={(e) => setSendEmail(e.target.checked)}
            />
            Kirim ke email penerima setelah terbit
            <span className="text-xs text-muted-foreground">
              (dikirim ke email manajer tim)
            </span>
          </label>

          <Button onClick={() => generate.mutate()} disabled={!ready || generate.isPending}>
            {generate.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Award className="h-4 w-4" />
            )}
            Terbitkan {picked.length > 0 ? `${picked.length} sertifikat` : ""}
          </Button>
        </Card>
      </div>
    </div>
  );
}
