"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { TagInput } from "@/components/ui/tag-input";
import type { FieldErrors } from "@/lib/api/errors";
import type { RaceCategoryInput } from "@/lib/api/race";
import type { RaceCategory, RacePeriod } from "@/types/api";

/**
 * One race category and its price in every sale window.
 *
 * The price matrix is the whole reason this is not the ticket category form: a
 * ticket has one price, and a race category has one per window. The empty cell
 * is load-bearing — a window left blank means NOT SOLD then, never free and
 * never inherited (see the race_category_prices migration), so the input starts
 * empty rather than at 0 and an empty string is sent as no row at all.
 */
export function RaceCategoryForm({
  category,
  periods,
  fieldErrors,
  pending,
  onClose,
  onSubmit,
}: {
  category: RaceCategory | null;
  periods: RacePeriod[];
  fieldErrors: FieldErrors;
  pending: boolean;
  onClose: () => void;
  onSubmit: (payload: RaceCategoryInput) => void;
}) {
  const [name, setName] = useState(category?.name ?? "");
  const [description, setDescription] = useState(category?.description ?? "");
  const [quota, setQuota] = useState(category?.quota?.toString() ?? "");
  /**
   * Who may enter. Empty = open to anyone, which is what every existing
   * category is — the server stores that as null, so a blank field here is not
   * a condition that admits nobody.
   */
  const [domains, setDomains] = useState<string[]>(
    category?.email_domains ?? [],
  );
  const [isActive, setIsActive] = useState(category?.is_active ?? true);

  /**
   * period id => price as TYPED, kept as a string.
   *
   * Strings rather than numbers for the reason event-form.tsx keeps its
   * discipline numbers as strings: an empty input has to stay distinguishable
   * from a zero. Here the two mean opposite things — blank is "not sold in this
   * window", 0 is "free" — so a `Number("")` collapsing to 0 would silently put
   * every unpriced window on sale for nothing.
   */
  const [prices, setPrices] = useState<Record<string, string>>(() => {
    const seed: Record<string, string> = {};
    for (const row of category?.price_schedule ?? []) {
      seed[row.period_id] = row.price === null ? "" : String(row.price);
    }
    return seed;
  });

  const submit = () => {
    onSubmit({
      name,
      description: description || null,
      quota: quota ? Number(quota) : null,
      is_active: isActive,
      // Always sent, including as `[]`: this dialog IS the whole condition
      // list, so an emptied field has to clear what was stored. The key is
      // omitted only by callers that are not editing conditions at all.
      email_domains: domains,
      // Only the windows that carry a typed price. A blank cell is sent as no
      // row, which is exactly how "not sold then" is said on the wire.
      prices: periods
        .filter(
          (p) => prices[p.id]?.trim() !== "" && prices[p.id] !== undefined,
        )
        .map((p) => ({ race_period_id: p.id, price: Number(prices[p.id]) })),
    });
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader
          title={category ? "Edit kategori lomba" : "Kategori lomba baru"}
          description="Harga diisi per periode. Periode yang dibiarkan kosong berarti kategori ini tidak dijual pada periode tersebut."
        />
        <DialogBody className="space-y-4">
          <div className="grid gap-1.5">
            <Label htmlFor="race-cat-name">Nama kategori</Label>
            <Input
              id="race-cat-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Mahasiswa"
              aria-invalid={!!fieldErrors.name}
            />
            {fieldErrors.name && (
              <p className="text-xs text-[var(--danger)]">{fieldErrors.name}</p>
            )}
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="race-cat-desc">Keterangan</Label>
            <Input
              id="race-cat-desc"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Wajib unggah kartu mahasiswa"
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="race-cat-quota">Kuota</Label>
            <Input
              id="race-cat-quota"
              type="number"
              min={1}
              value={quota}
              onChange={(e) => setQuota(e.target.value)}
              placeholder="Kosongkan untuk tanpa batas"
            />
            <p className="text-xs text-muted-foreground">
              Pendaftar yang belum lunas tetap menempati kuota sampai tenggat
              pembayarannya lewat.
            </p>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="race-cat-domains">
              Domain email yang diizinkan
            </Label>
            <TagInput
              id="race-cat-domains"
              value={domains}
              onChange={setDomains}
              maxTags={20}
              maxLength={253}
              placeholder="ub.ac.id"
              aria-invalid={!!fieldErrors.email_domains}
            />
            {fieldErrors.email_domains && (
              <p className="text-xs text-[var(--danger)]">
                {fieldErrors.email_domains}
              </p>
            )}
            <p className="text-xs text-muted-foreground">
              Kosongkan = terbuka untuk semua.
            </p>
          </div>

          <div className="space-y-2">
            <Label>Harga per periode</Label>
            {periods.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                Belum ada periode. Atur periode pendaftaran dulu.
              </p>
            ) : (
              <div className="grid gap-2">
                {periods.map((p) => (
                  <div key={p.id} className="flex items-center gap-2">
                    <span className="w-28 shrink-0 text-sm">
                      {p.name}
                      {p.is_current && (
                        <span className="text-[var(--success)]"> ·</span>
                      )}
                    </span>
                    <div className="relative flex-1">
                      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
                        Rp
                      </span>
                      <Input
                        type="number"
                        min={0}
                        className="pl-9"
                        value={prices[p.id] ?? ""}
                        onChange={(e) =>
                          setPrices((prev) => ({
                            ...prev,
                            [p.id]: e.target.value,
                          }))
                        }
                        placeholder="Tidak dijual"
                      />
                    </div>
                  </div>
                ))}
              </div>
            )}
            {fieldErrors.prices && (
              <p className="text-xs text-[var(--danger)]">
                {fieldErrors.prices}
              </p>
            )}
            <p className="text-xs text-muted-foreground">
              Isi 0 untuk gratis. Kosongkan kalau kategori ini belum/tidak
              dijual pada periode itu.
            </p>
          </div>

          <label className="flex cursor-pointer items-center gap-2 text-sm">
            <input
              type="checkbox"
              className="h-4 w-4 accent-[var(--brand-600)]"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
            />
            Kategori aktif (bisa dipilih peserta)
          </label>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={pending}>
            Batal
          </Button>
          <Button onClick={submit} disabled={pending || !name.trim()}>
            {pending ? "Menyimpan…" : "Simpan"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
