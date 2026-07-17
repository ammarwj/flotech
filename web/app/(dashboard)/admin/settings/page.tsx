"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Info, TriangleAlert } from "lucide-react";

import { getPlatformSettings, updatePlatformSettings } from "@/lib/api/admin-wallet";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { PageHeader } from "@/components/shared/page-header";
import type { PlatformSetting } from "@/types/api";

/** Draft edits: a string while it's in a text box, a boolean for a switch. */
type Draft = Record<string, string | boolean>;

export default function AdminSettingsPage() {
  const qc = useQueryClient();
  const [values, setValues] = useState<Draft>({});
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const query = useQuery({
    queryKey: ["platform-settings"],
    queryFn: getPlatformSettings,
  });

  const mutation = useMutation({
    mutationFn: (payload: Record<string, number | boolean>) => updatePlatformSettings(payload),
    onSuccess: () => {
      setFieldErrors({});
      qc.invalidateQueries({ queryKey: ["platform-settings"] });
      toast.success("Pengaturan disimpan");
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan pengaturan.");
      setFieldErrors(parsed.fieldErrors);
      if (Object.keys(parsed.fieldErrors).length === 0) toast.error(parsed.message);
    },
  });

  const settings = query.data?.settings ?? [];
  const orgsWithoutBank = query.data?.orgs_without_bank_account ?? 0;

  // `values` holds only what the admin has touched; anything else reads straight
  // from the server, so no effect is needed to seed the form.
  const shownText = (s: PlatformSetting) => (values[s.key] as string) ?? String(s.value);
  const shownBool = (s: PlatformSetting) => (values[s.key] as boolean) ?? Boolean(s.value);

  const gatewayOff = settings.some((s) => s.type === "bool" && !shownBool(s));

  const submit = () => {
    const payload: Record<string, number | boolean> = {};
    for (const s of settings) {
      if (s.type === "bool") {
        payload[s.key] = shownBool(s);
        continue;
      }
      const n = Number(shownText(s));
      if (Number.isFinite(n)) payload[s.key] = n;
    }
    mutation.mutate(payload);
  };

  return (
    <>
      <PageHeader
        title="Pengaturan Platform"
        description="Aturan pencairan dana dan jalur pembayaran untuk semua organizer."
      />

      <Card className="mb-6 flex items-start gap-3 p-4">
        <Info className="mt-0.5 h-5 w-5 shrink-0 text-[var(--brand-600)]" />
        <p className="text-sm text-muted-foreground">
          Perubahan aturan pencairan berlaku untuk{" "}
          <strong className="text-foreground">penarikan baru</strong>. Penarikan yang sudah diajukan
          tetap memakai minimal dan biaya admin yang berlaku saat itu.
        </p>
      </Card>

      {gatewayOff && (
        <Card className="mb-6 flex items-start gap-3 border-[color-mix(in_srgb,var(--warning)_45%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] p-4">
          <TriangleAlert className="mt-0.5 h-5 w-5 shrink-0 text-[var(--warning)]" />
          <div className="text-sm">
            <p className="font-semibold">Semua organizer akan memakai transfer manual</p>
            <p className="mt-1 text-muted-foreground">
              Pembeli transfer langsung ke rekening organizer dan mengunggah bukti untuk
              diverifikasi. Platform tidak memotong fee dari pembayaran itu.
              {orgsWithoutBank > 0 && (
                <>
                  {" "}
                  <strong className="text-foreground">
                    {orgsWithoutBank} organisasi belum punya rekening
                  </strong>{" "}
                  dan tidak akan bisa menerima pembayaran sama sekali sampai mereka mengisinya.
                </>
              )}
            </p>
          </div>
        </Card>
      )}

      {query.isPending ? (
        <div className="grid gap-3">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-[92px] rounded-xl" />
          ))}
        </div>
      ) : (
        <div className="grid gap-4">
          {settings.map((s) =>
            s.type === "bool" ? (
              <Card key={s.key} className="flex items-start justify-between gap-4 p-5">
                <div className="grid gap-1">
                  <Label htmlFor={s.key}>{s.label}</Label>
                  {s.description && (
                    <p className="text-xs text-muted-foreground">{s.description}</p>
                  )}
                  {s.is_overridden && (
                    <p className="text-xs text-muted-foreground">Sudah diubah dari bawaan</p>
                  )}
                </div>
                <Switch
                  id={s.key}
                  checked={shownBool(s)}
                  aria-label={s.label}
                  onCheckedChange={(checked) => setValues((v) => ({ ...v, [s.key]: checked }))}
                />
              </Card>
            ) : (
              <Card key={s.key} className="p-5">
                <div className="grid gap-1.5">
                  <Label htmlFor={s.key}>{s.label}</Label>
                  <Input
                    id={s.key}
                    inputMode="numeric"
                    value={shownText(s)}
                    onChange={(e) =>
                      setValues((v) => ({ ...v, [s.key]: e.target.value.replace(/[^0-9]/g, "") }))
                    }
                  />
                  {fieldErrors[s.key] ? (
                    <p className="text-xs font-medium text-[var(--danger)]">{fieldErrors[s.key]}</p>
                  ) : (
                    <p className="text-xs text-muted-foreground">
                      {formatNumeric(s, shownText(s))} &middot; bawaan{" "}
                      {formatNumeric(s, s.default)}
                      {s.is_overridden && " · sudah diubah dari bawaan"}
                    </p>
                  )}
                </div>
              </Card>
            )
          )}

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

function formatNumeric(setting: PlatformSetting, raw: string | number | boolean): string {
  const n = Number(raw);
  if (!Number.isFinite(n)) return "—";
  return setting.type === "money" ? rupiah(n) : `${n} hari`;
}
