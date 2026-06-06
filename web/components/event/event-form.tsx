"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SPORT_LABELS, FORMAT_LABELS } from "@/lib/labels";
import type { EventInput } from "@/lib/api/events";
import type { SportEvent } from "@/types/api";

const selectClass =
  "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

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
      className="grid max-w-2xl gap-4"
    >
      <div className="grid gap-2">
        <Label htmlFor="name">Nama event</Label>
        <Input id="name" value={v.name ?? ""} onChange={(e) => set("name", e.target.value)} required />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="grid gap-2">
          <Label htmlFor="sport">Cabang olahraga</Label>
          <select
            id="sport"
            className={selectClass}
            value={v.sport_type}
            onChange={(e) => set("sport_type", e.target.value as EventInput["sport_type"])}
          >
            {Object.entries(SPORT_LABELS).map(([k, label]) => (
              <option key={k} value={k}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="format">Format</Label>
          <select
            id="format"
            className={selectClass}
            value={v.tournament_format}
            onChange={(e) => set("tournament_format", e.target.value as EventInput["tournament_format"])}
          >
            {Object.entries(FORMAT_LABELS).map(([k, label]) => (
              <option key={k} value={k}>
                {label}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="grid gap-2">
          <Label htmlFor="start">Tanggal mulai</Label>
          <Input id="start" type="date" value={v.start_date ?? ""} onChange={(e) => set("start_date", e.target.value)} required />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="end">Tanggal selesai</Label>
          <Input id="end" type="date" value={v.end_date ?? ""} onChange={(e) => set("end_date", e.target.value)} required />
        </div>
      </div>

      <div className="grid gap-2">
        <Label htmlFor="loc">Lokasi</Label>
        <Input id="loc" value={v.location_name ?? ""} onChange={(e) => set("location_name", e.target.value)} placeholder="GBK Soccer Field" />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="grid gap-2">
          <Label htmlFor="max">Maks. tim</Label>
          <Input
            id="max"
            type="number"
            min={2}
            value={v.max_teams ?? ""}
            onChange={(e) => set("max_teams", e.target.value ? Number(e.target.value) : undefined)}
          />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="fee">Biaya registrasi (Rp)</Label>
          <Input
            id="fee"
            type="number"
            min={0}
            value={v.registration_fee ?? 0}
            onChange={(e) => set("registration_fee", Number(e.target.value))}
          />
        </div>
      </div>

      <div className="grid gap-2">
        <Label htmlFor="desc">Deskripsi</Label>
        <textarea
          id="desc"
          className={selectClass + " h-24 py-2"}
          value={v.description ?? ""}
          onChange={(e) => set("description", e.target.value)}
        />
      </div>

      <div>
        <Button type="submit" size="lg" disabled={pending}>
          {pending ? "Menyimpan…" : submitLabel}
        </Button>
      </div>
    </form>
  );
}
