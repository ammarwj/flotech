"use client";

import { useState } from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  Banknote,
  CreditCard,
  Percent,
  TriangleAlert,
  Wallet,
} from "lucide-react";

import {
  getPlatformSettings,
  updatePlatformSettings,
} from "@/lib/api/admin-wallet";
import { getAdminSiteSettings } from "@/lib/api/landing";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { PageHeader } from "@/components/shared/page-header";
import { SectionHeader } from "@/components/event/section-header";
import type { PlatformSetting } from "@/types/api";

/** Draft edits: a string while it's in a text box, a boolean for a switch. */
type Draft = Record<string, string | boolean>;

export default function AdminSettingsPage() {
  const qc = useQueryClient();
  const [values, setValues] = useState<Draft>({});
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [manualWarningOpen, setManualWarningOpen] = useState(false);

  const query = useQuery({
    queryKey: ["platform-settings"],
    queryFn: getPlatformSettings,
  });

  const mutation = useMutation({
    mutationFn: (payload: Record<string, number | boolean>) =>
      updatePlatformSettings(payload),
    onSuccess: () => {
      setFieldErrors({});
      qc.invalidateQueries({ queryKey: ["platform-settings"] });
      toast.success("Pengaturan disimpan");
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan pengaturan.");
      setFieldErrors(parsed.fieldErrors);
      if (Object.keys(parsed.fieldErrors).length === 0)
        toast.error(parsed.message);
    },
  });

  // Same query key as /admin/site-settings, so opening both pages costs one
  // request. Needed here only to warn *before* the switch is flipped: without a
  // platform account nobody can buy a plan, and the reactive half of that rule
  // is a 422 the organizer sees at checkout.
  const siteSettings = useQuery({
    queryKey: ["admin-site-settings"],
    queryFn: getAdminSiteSettings,
  });

  const settings = query.data?.settings ?? [];
  const orgsWithoutBank = query.data?.orgs_without_bank_account ?? 0;
  const platformBankMissing =
    !!siteSettings.data &&
    !(
      siteSettings.data.bank_name &&
      siteSettings.data.account_number &&
      siteSettings.data.account_holder
    );

  // `values` holds only what the admin has touched; anything else reads straight
  // from the server, so no effect is needed to seed the form.
  const shownText = (s: PlatformSetting) =>
    (values[s.key] as string) ?? String(s.value);
  const shownBool = (s: PlatformSetting) =>
    (values[s.key] as boolean) ?? Boolean(s.value);

  // Scoped to this one key, not "any bool switch" — per-channel gateway-fee
  // and PPN toggles are also bools, and turning off e.g. VA's PPN alone must
  // not trigger the "everyone falls back to manual transfer" banner below.
  const gatewayOff = settings.some(
    (s) => s.key === "payment_gateway_enabled" && !shownBool(s),
  );

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

  // Four groups the admin actually thinks in terms of, derived from `key` —
  // the API still returns one flat list, this is presentation only. Channel
  // toggles (`gateway_fee_enabled_va`, `ppn_enabled_va`, …) are pulled out and
  // re-paired by channel below instead of rendered in this bucket directly.
  const walletSettings = settings.filter((s) => s.key.startsWith("wallet_"));
  const feeSettings = settings.filter(
    (s) =>
      s.key === "service_fee_amount" || s.key === "plan_service_fee_amount",
  );
  const gatewaySetting = settings.find(
    (s) => s.key === "payment_gateway_enabled",
  );
  const channelToggles = settings.filter(
    (s) =>
      s.key.startsWith("gateway_fee_enabled_") ||
      s.key.startsWith("ppn_enabled_"),
  );

  // Pair each channel's fee/PPN toggle by the suffix after the known
  // prefixes, preserving the order the API returned them in.
  const channelOrder: string[] = [];
  const channelPairs = new Map<
    string,
    { fee?: PlatformSetting; ppn?: PlatformSetting }
  >();
  for (const s of channelToggles) {
    const isFee = s.key.startsWith("gateway_fee_enabled_");
    const channel = s.key.replace(
      isFee ? "gateway_fee_enabled_" : "ppn_enabled_",
      "",
    );
    if (!channelPairs.has(channel)) {
      channelOrder.push(channel);
      channelPairs.set(channel, {});
    }
    const pair = channelPairs.get(channel)!;
    if (isFee) pair.fee = s;
    else pair.ppn = s;
  }
  // "PPN aktif — Virtual Account" -> "Virtual Account"
  const channelLabel = (pair: {
    fee?: PlatformSetting;
    ppn?: PlatformSetting;
  }) => (pair.fee ?? pair.ppn)?.label.split("—").pop()?.trim() ?? "";

  return (
    <>
      <PageHeader
        title="Pengaturan Platform"
        description="Aturan pencairan dana dan jalur pembayaran untuk semua organizer."
      />

      {gatewayOff && (
        <Card className="mb-6 flex items-center justify-between gap-3 border-[color-mix(in_srgb,var(--warning)_45%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] p-4">
          <div className="flex items-center gap-3">
            <TriangleAlert className="h-5 w-5 shrink-0 text-[var(--warning)]" />
            <p className="text-sm font-semibold">
              Semua organizer akan memakai transfer manual
            </p>
          </div>
          <Button
            variant="outline"
            size="sm"
            onClick={() => setManualWarningOpen(true)}
          >
            Lihat detail
          </Button>
        </Card>
      )}

      <Dialog open={manualWarningOpen} onOpenChange={setManualWarningOpen}>
        <DialogContent>
          <DialogHeader
            icon={TriangleAlert}
            tone="danger"
            title="Semua organizer akan memakai transfer manual"
            description="Berlaku selama payment gateway dimatikan."
          />
          <DialogBody>
            <ul className="grid gap-3 text-sm text-muted-foreground">
              <li className="flex gap-2">
                <span
                  aria-hidden
                  className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-current"
                />
                <span>
                  Ini menimpa pilihan tiap event — event yang memilih pembayaran
                  online ikut dialihkan selama gateway mati.
                </span>
              </li>
              <li className="flex gap-2">
                <span
                  aria-hidden
                  className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-current"
                />
                <span>
                  Pembeli transfer ke rekening organizer, lalu unggah bukti
                  untuk diverifikasi.
                </span>
              </li>
              {orgsWithoutBank > 0 && (
                <li className="flex gap-2 text-foreground">
                  <span
                    aria-hidden
                    className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-current"
                  />
                  <span>
                    <strong>
                      {orgsWithoutBank} organisasi belum punya rekening
                    </strong>{" "}
                    dan tidak akan bisa menerima pembayaran sama sekali sampai
                    mereka mengisinya.
                  </span>
                </li>
              )}
              <li className="flex gap-2">
                <span
                  aria-hidden
                  className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-current"
                />
                <span>
                  Pembelian paket juga beralih ke transfer manual — organizer
                  transfer ke rekening flo-event lalu kamu verifikasi di{" "}
                  <Link
                    href="/admin/plan-orders"
                    className="font-medium text-foreground underline"
                    onClick={() => setManualWarningOpen(false)}
                  >
                    Verifikasi Pembelian Paket
                  </Link>
                  .
                </span>
              </li>
              {platformBankMissing && (
                <li className="flex gap-2 text-foreground">
                  <span
                    aria-hidden
                    className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-current"
                  />
                  <span>
                    <strong>
                      Rekening penerima pembayaran paket belum diisi
                    </strong>{" "}
                    — tidak ada organizer yang bisa membeli paket selama gateway
                    mati.{" "}
                    <Link
                      href="/admin/site-settings"
                      className="underline"
                      onClick={() => setManualWarningOpen(false)}
                    >
                      Isi sekarang
                    </Link>
                    .
                  </span>
                </li>
              )}
            </ul>
          </DialogBody>
          <DialogFooter>
            <Button onClick={() => setManualWarningOpen(false)}>Tutup</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {query.isPending ? (
        <div className="grid gap-4">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-40 rounded-xl" />
          ))}
        </div>
      ) : (
        <div className="grid gap-6">
          {gatewaySetting && (
            <Card>
              <SectionHeader
                icon={CreditCard}
                title="Payment Gateway"
                description="Jalur pembayaran online lewat Midtrans untuk semua event."
                action={
                  <Badge
                    variant={shownBool(gatewaySetting) ? "success" : "warning"}
                  >
                    {shownBool(gatewaySetting) ? "Aktif" : "Nonaktif"}
                  </Badge>
                }
              />
              <CardContent className="flex items-start justify-between gap-4 pt-0">
                <p className="text-sm text-muted-foreground">
                  {gatewaySetting.description}
                </p>
                <Switch
                  id={gatewaySetting.key}
                  checked={shownBool(gatewaySetting)}
                  aria-label={gatewaySetting.label}
                  onCheckedChange={(checked) =>
                    setValues((v) => ({ ...v, [gatewaySetting.key]: checked }))
                  }
                />
              </CardContent>
            </Card>
          )}

          {channelOrder.length > 0 && (
            <Card>
              <SectionHeader
                icon={Percent}
                title="PPN & Biaya per Channel"
                description="Biaya gateway dan PPN bisa dimatikan sendiri-sendiri untuk tiap channel pembayaran."
              />
              <CardContent className="pt-0">
                <div className="divide-y divide-border rounded-(--r-md) border border-border">
                  {channelOrder.map((channel) => {
                    const pair = channelPairs.get(channel)!;
                    return (
                      <div
                        key={channel}
                        className="flex flex-wrap items-center justify-between gap-x-8 gap-y-3 px-4 py-3"
                      >
                        <p className="min-w-32 text-sm font-semibold">
                          {channelLabel(pair)}
                        </p>
                        <div className="flex flex-wrap items-center gap-x-8 gap-y-2">
                          {pair.fee && (
                            <ChannelToggle
                              label="Biaya gateway"
                              setting={pair.fee}
                              checked={shownBool(pair.fee)}
                              onChange={(checked) =>
                                setValues((v) => ({
                                  ...v,
                                  [pair.fee!.key]: checked,
                                }))
                              }
                            />
                          )}
                          {pair.ppn && (
                            <ChannelToggle
                              label="PPN"
                              setting={pair.ppn}
                              checked={shownBool(pair.ppn)}
                              onChange={(checked) =>
                                setValues((v) => ({
                                  ...v,
                                  [pair.ppn!.key]: checked,
                                }))
                              }
                            />
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </CardContent>
            </Card>
          )}

          {feeSettings.length > 0 && (
            <Card>
              <SectionHeader
                icon={Wallet}
                title="Fee Platform"
                description="Margin platform, nominal tetap per transaksi."
              />
              <CardContent className="grid gap-4 pt-0 sm:grid-cols-2">
                {feeSettings.map((s) => (
                  <NumRow
                    key={s.key}
                    setting={s}
                    value={shownText(s)}
                    error={fieldErrors[s.key]}
                    onChange={(raw) =>
                      setValues((v) => ({ ...v, [s.key]: raw }))
                    }
                  />
                ))}
              </CardContent>
            </Card>
          )}

          {walletSettings.length > 0 && (
            <Card>
              <SectionHeader
                icon={Banknote}
                title="Aturan Pencairan Dana Wallet"
                description="Berlaku untuk penarikan baru — penarikan yang sudah diajukan memakai aturan saat itu."
              />
              <CardContent className="grid gap-4 pt-0 sm:grid-cols-2">
                {walletSettings.map((s) => (
                  <NumRow
                    key={s.key}
                    setting={s}
                    value={shownText(s)}
                    error={fieldErrors[s.key]}
                    onChange={(raw) =>
                      setValues((v) => ({ ...v, [s.key]: raw }))
                    }
                  />
                ))}
              </CardContent>
            </Card>
          )}

          <div className="flex justify-end">
            <Button disabled={mutation.isPending} onClick={submit}>
              {mutation.isPending ? "Menyimpan…" : "Simpan pengaturan"}
            </Button>
          </div>
        </div>
      )}
    </>
  );
}

/**
 * One fee/PPN toggle inside a channel row — just the switch and its short
 * label ("Biaya gateway" / "PPN"); the channel name is the row's own heading,
 * and the full description is one hover/focus away via `title` rather than
 * repeated per switch, since the same two sentences would otherwise appear
 * three times down the card (once per channel).
 */
function ChannelToggle({
  label,
  setting,
  checked,
  onChange,
}: {
  label: string;
  setting: PlatformSetting;
  checked: boolean;
  onChange: (checked: boolean) => void;
}) {
  return (
    <div
      className="flex items-center gap-2"
      title={setting.description ?? undefined}
    >
      <Label htmlFor={setting.key} className="text-xs text-muted-foreground">
        {label}
      </Label>
      <Switch
        id={setting.key}
        checked={checked}
        aria-label={setting.label}
        onCheckedChange={onChange}
      />
    </div>
  );
}

/** One numeric field — label, input, and either its helper text or an inline field error. */
function NumRow({
  setting,
  value,
  error,
  onChange,
}: {
  setting: PlatformSetting;
  value: string;
  error?: string;
  onChange: (raw: string) => void;
}) {
  const isMoney = setting.type === "money";
  const isInt = setting.type === "int";

  return (
    <div className="grid gap-1.5">
      <Label htmlFor={setting.key}>{setting.label}</Label>
      <div className="relative">
        {isMoney && (
          <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center gap-2 pl-3">
            <span className="text-sm text-muted-foreground">Rp</span>
            <span className="h-4 w-px bg-border" />
          </span>
        )}
        <Input
          id={setting.key}
          inputMode={isInt ? "numeric" : "decimal"}
          value={groupThousands(value)}
          className={isMoney ? "pl-12" : undefined}
          onChange={(e) => {
            if (isInt) {
              const digits = e.target.value
                .replace(/[^0-9]/g, "")
                .replace(/^0+(?=\d)/, "");
              onChange(digits);
              return;
            }
            const cleaned = e.target.value
              .replace(/[^0-9,]/g, "")
              .replace(/(,.*),/g, "$1");
            const [intPart, decPart] = cleaned.split(",");
            const normalizedInt = intPart.replace(/^0+(?=\d)/, "") || "0";
            onChange(decPart !== undefined ? `${normalizedInt}.${decPart}` : normalizedInt);
          }}
        />
      </div>
      {error ? (
        <p className="text-xs font-medium text-[var(--danger)]">{error}</p>
      ) : (
        <p className="text-xs text-muted-foreground">
          Bawaan {formatNumeric(setting, setting.default)}
        </p>
      )}
    </div>
  );
}

/**
 * Live thousand-separator display while typing — "150000" -> "150.000",
 * decimals shown with a comma ("1500,5" -> "1.500,5") since the dot is
 * already spent on grouping. The underlying `value` stays a plain
 * dot-decimal string (what submit()/formatNumeric() expect); only the
 * input's displayed text goes through this.
 */
function groupThousands(raw: string): string {
  if (!raw) return raw;
  const [intPart, decPart] = raw.split(".");
  const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
  return decPart !== undefined ? `${grouped},${decPart}` : grouped;
}

function formatNumeric(
  setting: PlatformSetting,
  raw: string | number | boolean,
): string {
  const n = Number(raw);
  if (!Number.isFinite(n)) return "—";
  if (setting.type === "money") return rupiah(n);
  if (setting.type === "percent") return `${n}%`;
  return `${n} hari`;
}
