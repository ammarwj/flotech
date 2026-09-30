/**
 * Mirror of `App\Support\PayoutChannels`: the two kinds of payout destination an
 * organizer can register, and the words each one needs.
 *
 * The API stores both in one shape — institution, number, name — so everything
 * that only *renders* a destination (the admin payout queue, the withdrawal
 * history, the buyer's manual-transfer panel) reads its labels from here instead
 * of branching on the type itself. Adding a provider is one entry below plus one
 * in `PayoutChannels::PROVIDERS`.
 */
import type { PayoutAccountType } from "@/types/api";

/** Provider key => label. The key is what the form submits; the label is what the API stores. */
export const EWALLET_PROVIDERS: Record<string, string> = {
  gopay: "GoPay",
  dana: "DANA",
  ovo: "OVO",
  shopeepay: "ShopeePay",
  linkaja: "LinkAja",
};

/** Field labels per kind, so no component spells "nomor rekening" over a phone number. */
export const PAYOUT_LABELS: Record<
  PayoutAccountType,
  { kind: string; institution: string; number: string; holder: string }
> = {
  bank: {
    kind: "Rekening bank",
    institution: "Nama bank",
    number: "Nomor rekening",
    holder: "Nama pemilik rekening",
  },
  ewallet: {
    kind: "E-wallet",
    institution: "Penyedia e-wallet",
    number: "Nomor HP",
    holder: "Nama pemilik akun",
  },
};

/**
 * `account_type` is optional on older/narrower payloads, and a missing one means
 * bank — the column's own default, so the two readings cannot drift.
 */
export function payoutLabels(type: PayoutAccountType | null | undefined) {
  return PAYOUT_LABELS[type ?? "bank"];
}

/** Provider key for a stored label, so "Ganti" can preselect what is saved. */
export function providerKeyFor(label: string): string {
  return (
    Object.keys(EWALLET_PROVIDERS).find((key) => EWALLET_PROVIDERS[key] === label) ?? ""
  );
}
