"use client";

import { useState } from "react";
import { AlertCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { SPORT_LABELS, FORMAT_LABELS, rupiah } from "@/lib/labels";
import type { EventInput } from "@/lib/api/events";
import type { FieldErrors } from "@/lib/api/errors";
import type { SportEvent } from "@/types/api";

/** Inline validation message shown under a field. */
function FieldError({ message }: { message?: string }) {
  if (!message) return null;
  return (
    <p className="flex items-center gap-1.5 text-xs text-destructive">
      <AlertCircle className="h-3.5 w-3.5 shrink-0" />
      {message}
    </p>
  );
}

export function EventForm({
  initial,
  submitLabel,
  onSubmit,
  pending,
  fieldErrors,
}: {
  initial?: Partial<SportEvent>;
  submitLabel: string;
  onSubmit: (values: EventInput) => void;
  pending?: boolean;
  /** Server-side validation errors (Laravel 422), keyed by field name. */
  fieldErrors?: FieldErrors;
}) {
  const [v, setV] = useState<EventInput>({
    name: initial?.name ?? "",
    sport_type: initial?.sport_type ?? "football",
    tournament_format: initial?.tournament_format ?? "league",
    start_date: initial?.start_date ?? "",
    end_date: initial?.end_date ?? "",
    location_name: initial?.location_name ?? "",
    location_address: initial?.location_address ?? "",
    description: initial?.description ?? "",
    max_teams: initial?.max_teams ?? undefined,
    registration_fee: initial?.registration_fee ?? 0,
  });

  // Client-side overrides: a key present here wins over the server error for
  // that field (`undefined`/"" = no error). Lets us validate instantly and
  // clear a stale server error the moment the user edits the field.
  const [clientErrors, setClientErrors] = useState<Record<string, string | undefined>>({});

  const errorFor = (k: keyof EventInput): string | undefined =>
    (k in clientErrors ? clientErrors[k as string] : fieldErrors?.[k as string]) || undefined;

  const set = <K extends keyof EventInput>(k: K, val: EventInput[K]) => {
    setV((s) => ({ ...s, [k]: val }));
    setClientErrors((e) => ({ ...e, [k as string]: undefined }));
  };

  // Red border + ring for an invalid field.
  const invalidCls = (k: keyof EventInput) =>
    errorFor(k) ? "border-destructive focus-visible:ring-destructive" : "";

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const next: Record<string, string | undefined> = {
      name: v.name?.trim() ? undefined : "Nama event wajib diisi.",
      start_date: v.start_date ? undefined : "Tanggal mulai wajib diisi.",
      end_date: !v.end_date
        ? "Tanggal selesai wajib diisi."
        : v.start_date && v.end_date < v.start_date
          ? "Tanggal selesai harus sama dengan atau setelah tanggal mulai."
          : undefined,
    };

    setClientErrors(next);

    if (next.name || next.start_date || next.end_date) {
      // Surface the first error to the user.
      document.querySelector<HTMLElement>("[aria-invalid='true']")?.focus();
      return;
    }

    onSubmit(v);
  };

  return (
    <form onSubmit={handleSubmit} className="grid max-w-2xl gap-5">
      <Card>
        <CardHeader>
          <CardTitle>Detail Event</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="name" className="font-semibold">
              Nama event
            </Label>
            <Input
              id="name"
              value={v.name ?? ""}
              onChange={(e) => set("name", e.target.value)}
              placeholder="Liga Komunitas Jakarta 2026"
              aria-invalid={!!errorFor("name")}
              className={invalidCls("name")}
            />
            <FieldError message={errorFor("name")} />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="sport" className="font-semibold">
                Cabang olahraga
              </Label>
              <Select
                id="sport"
                value={v.sport_type}
                onChange={(e) => set("sport_type", e.target.value as EventInput["sport_type"])}
              >
                {Object.entries(SPORT_LABELS).map(([k, label]) => (
                  <option key={k} value={k}>
                    {label}
                  </option>
                ))}
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="format" className="font-semibold">
                Format
              </Label>
              <Select
                id="format"
                value={v.tournament_format}
                onChange={(e) => set("tournament_format", e.target.value as EventInput["tournament_format"])}
              >
                {Object.entries(FORMAT_LABELS).map(([k, label]) => (
                  <option key={k} value={k}>
                    {label}
                  </option>
                ))}
              </Select>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="start" className="font-semibold">
                Tanggal mulai
              </Label>
              <Input
                id="start"
                type="date"
                value={v.start_date ?? ""}
                onChange={(e) => set("start_date", e.target.value)}
                aria-invalid={!!errorFor("start_date")}
                className={invalidCls("start_date")}
              />
              <FieldError message={errorFor("start_date")} />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="end" className="font-semibold">
                Tanggal selesai
              </Label>
              <Input
                id="end"
                type="date"
                value={v.end_date ?? ""}
                min={v.start_date || undefined}
                onChange={(e) => set("end_date", e.target.value)}
                aria-invalid={!!errorFor("end_date")}
                className={invalidCls("end_date")}
              />
              <FieldError message={errorFor("end_date")} />
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Lokasi & Pendaftaran</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="loc" className="font-semibold">
              Lokasi
            </Label>
            <Input
              id="loc"
              value={v.location_name ?? ""}
              onChange={(e) => set("location_name", e.target.value)}
              placeholder="GBK Soccer Field"
              aria-invalid={!!errorFor("location_name")}
              className={invalidCls("location_name")}
            />
            <FieldError message={errorFor("location_name")} />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="max" className="font-semibold">
                Maks. tim
              </Label>
              <Input
                id="max"
                type="number"
                min={2}
                value={v.max_teams ?? ""}
                onChange={(e) => set("max_teams", e.target.value ? Number(e.target.value) : undefined)}
                placeholder="Tidak dibatasi"
                aria-invalid={!!errorFor("max_teams")}
                className={invalidCls("max_teams")}
              />
              <FieldError message={errorFor("max_teams")} />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="fee" className="font-semibold">
                Biaya registrasi (Rp)
              </Label>
              <Input
                id="fee"
                type="number"
                min={0}
                value={v.registration_fee ?? 0}
                onChange={(e) => set("registration_fee", Number(e.target.value))}
                aria-invalid={!!errorFor("registration_fee")}
                className={invalidCls("registration_fee")}
              />
              <FieldError message={errorFor("registration_fee")} />
              <p className="text-xs text-muted-foreground">
                {v.registration_fee && v.registration_fee > 0 ? rupiah(v.registration_fee) : "Gratis untuk peserta"}
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Deskripsi</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-2">
            <Label htmlFor="desc" className="font-semibold">
              Tentang event
            </Label>
            <Textarea
              id="desc"
              value={v.description ?? ""}
              onChange={(e) => set("description", e.target.value)}
              placeholder="Jelaskan turnamen, syarat peserta, hadiah, dan informasi penting lainnya."
              aria-invalid={!!errorFor("description")}
              className={invalidCls("description")}
            />
            <FieldError message={errorFor("description")} />
          </div>
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button type="submit" size="lg" disabled={pending}>
          {pending ? "Menyimpan…" : submitLabel}
        </Button>
      </div>
    </form>
  );
}
