"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Info } from "lucide-react";

import { getPlatformSettings, updatePlatformSettings } from "@/lib/api/admin-wallet";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import type { PlatformSetting } from "@/types/api";

export default function AdminSettingsPage() {
  const qc = useQueryClient();
  const [values, setValues] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const query = useQuery({
    queryKey: ["platform-settings"],
    queryFn: getPlatformSettings,
  });

  const mutation = useMutation({
    mutationFn: (payload: Record<string, number>) => updatePlatformSettings(payload),
    onSuccess: () => {
      setFieldErrors({});
      qc.invalidateQueries({ queryKey: ["platform-settings"] });
      toast.success("Pengaturan disimpan", {
        description: "Berlaku untuk penarikan baru. Penarikan lama tetap memakai aturan lamanya.",
      });
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan pengaturan.");
      setFieldErrors(parsed.fieldErrors);
      if (Object.keys(parsed.fieldErrors).length === 0) toast.error(parsed.message);
    },
  });

  const settings = query.data ?? [];

  // `values` holds only what the admin has typed; anything untouched reads
  // straight from the server, so no effect is needed to seed the form.
  const shown = (s: PlatformSetting) => values[s.key] ?? String(s.value);

  const preview = (s: PlatformSetting) => {
    const n = Number(shown(s));
    if (!Number.isFinite(n)) return null;
    return s.type === "money" ? rupiah(n) : `${n} hari`;
  };

  const submit = () => {
    const payload: Record<string, number> = {};
    for (const s of settings) {
      const n = Number(shown(s));
      if (Number.isFinite(n)) payload[s.key] = n;
    }
    mutation.mutate(payload);
  };

  return (
    <>
      <PageHeader
        title="Pengaturan Platform"
        description="Aturan pencairan dana yang berlaku untuk semua organizer."
      />

      <Card className="mb-6 flex items-start gap-3 p-4">
        <Info className="mt-0.5 h-5 w-5 shrink-0 text-[var(--brand-600)]" />
        <p className="text-sm text-muted-foreground">
          Perubahan berlaku untuk <strong className="text-foreground">penarikan baru</strong>.
          Penarikan yang sudah diajukan tetap memakai minimal dan biaya admin yang berlaku saat itu.
        </p>
      </Card>

      {query.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[92px] rounded-xl" />
          ))}
        </div>
      ) : (
        <div className="grid gap-4">
          {settings.map((s) => (
            <Card key={s.key} className="p-5">
              <div className="grid gap-1.5">
                <Label htmlFor={s.key}>{s.label}</Label>
                <Input
                  id={s.key}
                  inputMode="numeric"
                  value={shown(s)}
                  onChange={(e) =>
                    setValues((v) => ({ ...v, [s.key]: e.target.value.replace(/[^0-9]/g, "") }))
                  }
                />
                {fieldErrors[s.key] ? (
                  <p className="text-xs font-medium text-[var(--danger)]">{fieldErrors[s.key]}</p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    {preview(s)} &middot; bawaan{" "}
                    {s.type === "money" ? rupiah(s.default) : `${s.default} hari`}
                    {s.is_overridden && " · sudah diubah dari bawaan"}
                  </p>
                )}
              </div>
            </Card>
          ))}

          <div>
            <Button disabled={mutation.isPending} onClick={submit}>
              {mutation.isPending ? "Menyimpan…" : "Simpan pengaturan"}
            </Button>
          </div>
        </div>
      )}
    </>
  );
}
