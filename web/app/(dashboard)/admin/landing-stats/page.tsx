"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Gauge } from "lucide-react";

import {
  getAdminLandingStats,
  updateLandingStats,
  type LandingStatInput,
} from "@/lib/api/landing";
import { parseApiError } from "@/lib/api/errors";
import { compactCount } from "@/lib/landing";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { PageHeader } from "@/components/shared/page-header";
import { SectionHeader } from "@/components/event/section-header";
import type { LandingStatSetting } from "@/types/api";

/** Per-metric draft, holding only the fields the admin actually touched. */
type Draft = {
  label?: string;
  is_active?: boolean;
  sort_order?: string;
};

export default function AdminLandingStatsPage() {
  const qc = useQueryClient();
  const [draft, setDraft] = useState<Record<string, Draft>>({});

  const query = useQuery({
    queryKey: ["admin-landing-stats"],
    queryFn: getAdminLandingStats,
  });

  const mutation = useMutation({
    mutationFn: (metrics: LandingStatInput[]) => updateLandingStats(metrics),
    onSuccess: () => {
      setDraft({});
      qc.invalidateQueries({ queryKey: ["admin-landing-stats"] });
      // The landing strip reads its own query; invalidate it so a super admin
      // checking their work on / doesn't see the old set from cache.
      qc.invalidateQueries({ queryKey: ["public-stats"] });
      toast.success("Counter landing disimpan");
    },
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal menyimpan counter.").message),
  });

  const metrics = query.data ?? [];

  // Untouched fields read straight from the server, so no effect is needed to
  // seed the form — same shape as /admin/settings.
  const label = (m: LandingStatSetting) =>
    draft[m.key]?.label ?? (m.is_overridden && m.label !== m.default_label ? m.label : "");
  const active = (m: LandingStatSetting) => draft[m.key]?.is_active ?? m.is_active;
  const sort = (m: LandingStatSetting) =>
    draft[m.key]?.sort_order ?? String(m.sort_order);

  const set = (key: string, patch: Draft) =>
    setDraft((d) => ({ ...d, [key]: { ...d[key], ...patch } }));

  const shownCount = metrics.filter((m) => active(m)).length;

  const submit = () => {
    mutation.mutate(
      metrics.map((m) => {
        const typed = label(m).trim();

        return {
          metric_key: m.key,
          // An emptied label is sent as null, never as "": null is what makes
          // "blank means inherit the catalog" work, and it is also what lets the
          // override row disappear again.
          label: typed === "" ? null : typed,
          is_active: active(m),
          sort_order: Number(sort(m)) || 0,
        };
      })
    );
  };

  return (
    <>
      <PageHeader
        title="Counter Landing"
        description="Angka-angka di strip bukti halaman depan — pilih yang tampil, ganti labelnya, atur urutannya."
      />

      {query.isPending ? (
        <div className="grid gap-4">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-20 rounded-xl" />
          ))}
        </div>
      ) : (
        <div className="grid gap-6">
          <Card>
            <SectionHeader
              icon={Gauge}
              title="Metrik"
              description={`${shownCount} counter tampil di landing. Label kosong berarti ikut bawaan sistem; angkanya dihitung ulang tiap 10 menit.`}
            />
            <CardContent className="pt-0">
              <div className="divide-y divide-border rounded-(--r-md) border border-border">
                {metrics.map((m) => (
                  <div
                    key={m.key}
                    className="grid gap-3 px-4 py-4 sm:grid-cols-[auto_1fr_7rem_6rem] sm:items-end"
                  >
                    <div className="flex items-center gap-2 sm:pb-2">
                      <Switch
                        id={`active-${m.key}`}
                        checked={active(m)}
                        aria-label={`Tampilkan ${m.default_label}`}
                        onCheckedChange={(checked) =>
                          set(m.key, { is_active: checked })
                        }
                      />
                      <Label
                        htmlFor={`active-${m.key}`}
                        className="text-xs text-muted-foreground sm:hidden"
                      >
                        Tampilkan
                      </Label>
                    </div>

                    <div className="grid gap-1.5">
                      <Label htmlFor={`label-${m.key}`}>{m.default_label}</Label>
                      <Input
                        id={`label-${m.key}`}
                        value={label(m)}
                        placeholder={m.default_label}
                        maxLength={60}
                        onChange={(e) => set(m.key, { label: e.target.value })}
                      />
                    </div>

                    <div className="grid gap-1.5">
                      <Label htmlFor={`sort-${m.key}`}>Urutan</Label>
                      <Input
                        id={`sort-${m.key}`}
                        inputMode="numeric"
                        value={sort(m)}
                        onChange={(e) =>
                          set(m.key, {
                            sort_order: e.target.value.replace(/[^0-9]/g, ""),
                          })
                        }
                      />
                    </div>

                    {/* Preview, including for metrics that are switched off —
                        knowing the number is how an admin decides whether to
                        switch one on. */}
                    <div className="grid gap-1.5 sm:pb-2 sm:text-right">
                      <span className="text-xs text-muted-foreground">
                        Angka saat ini
                      </span>
                      <span className="font-semibold tabular-nums">
                        {compactCount(m.value)}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end">
            <Button disabled={mutation.isPending} onClick={submit}>
              {mutation.isPending ? "Menyimpan…" : "Simpan counter"}
            </Button>
          </div>
        </div>
      )}
    </>
  );
}
