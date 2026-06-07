"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { SPORT_LABELS, FORMAT_LABELS, rupiah } from "@/lib/labels";
import type { EventInput } from "@/lib/api/events";
import type { SportEvent } from "@/types/api";

export function EventForm({
  initial,
  submitLabel,
  onSubmit,
  pending,
}: {
  initial?: Partial<SportEvent>;
  submitLabel: string;
  onSubmit: (values: EventInput) => void;
  pending?: boolean;
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

  const set = <K extends keyof EventInput>(k: K, val: EventInput[K]) => setV((s) => ({ ...s, [k]: val }));

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit(v);
      }}
      className="grid max-w-2xl gap-5"
    >
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
              required
            />
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
              <Input id="start" type="date" value={v.start_date ?? ""} onChange={(e) => set("start_date", e.target.value)} required />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="end" className="font-semibold">
                Tanggal selesai
              </Label>
              <Input id="end" type="date" value={v.end_date ?? ""} onChange={(e) => set("end_date", e.target.value)} required />
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
            <Input id="loc" value={v.location_name ?? ""} onChange={(e) => set("location_name", e.target.value)} placeholder="GBK Soccer Field" />
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
              />
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
              />
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
            />
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
