import type {
  EventStatus,
  SubscriptionStatus,
  TeamStatus,
  TicketOrderStatus,
  WalletTxCategory,
  WalletTxStatus,
  WithdrawalStatus,
} from "@/types/api";

// Sports, formats, tiebreakers, draw methods and sponsor tiers are admin-managed
// data now — read them through useCatalog(), not from a map here.

export const EVENT_STATUS_LABELS: Record<EventStatus, string> = {
  draft: "Draf",
  open: "Pendaftaran Dibuka",
  registration_closed: "Pendaftaran Ditutup",
  ongoing: "Berlangsung",
  finished: "Selesai",
  cancelled: "Dibatalkan",
};

export const TEAM_STATUS_LABELS: Record<TeamStatus, string> = {
  pending: "Menunggu",
  approved: "Disetujui",
  rejected: "Ditolak",
  disqualified: "Didiskualifikasi",
  withdrawn: "Mengundurkan diri",
};

export const SUBSCRIPTION_STATUS_LABELS: Record<SubscriptionStatus, string> = {
  active: "Aktif",
  past_due: "Menunggu Pembayaran",
  cancelled: "Dibatalkan",
  expired: "Kedaluwarsa",
};

export const BILLING_CYCLE_LABELS: Record<"monthly" | "yearly", string> = {
  monthly: "Bulanan",
  yearly: "Tahunan",
};

export const TICKET_ORDER_STATUS_LABELS: Record<TicketOrderStatus, string> = {
  pending: "Menunggu Pembayaran",
  paid: "Lunas",
  cancelled: "Dibatalkan",
  refunded: "Dikembalikan",
};

// "Penarikan Dana" is money leaving the wallet — not to be confused with a team
// withdrawing from an event (TEAM_STATUS_LABELS.withdrawn).
export const WITHDRAWAL_STATUS_LABELS: Record<WithdrawalStatus, string> = {
  pending: "Menunggu Diproses",
  processing: "Sedang Diproses",
  completed: "Selesai",
  rejected: "Ditolak",
};

export const WALLET_TX_CATEGORY_LABELS: Record<WalletTxCategory, string> = {
  ticket_sale: "Penjualan Tiket",
  registration_fee: "Biaya Pendaftaran",
  refund: "Refund",
  withdrawal: "Penarikan Dana",
  withdrawal_reversal: "Pengembalian Penarikan",
  adjustment: "Penyesuaian",
};

export const WALLET_TX_STATUS_LABELS: Record<WalletTxStatus, string> = {
  pending: "Tertahan",
  available: "Tersedia",
  cancelled: "Dibatalkan",
};

export const rupiah = (n: number) =>
  (n < 0 ? "-Rp " : "Rp ") + new Intl.NumberFormat("id-ID").format(Math.abs(n));

/**
 * Abbreviated amount for the marketing pricing table: 149000 → "149rb",
 * 1430000 → "1,43jt". Lossy by design — never use it for an amount being billed.
 */
export const rupiahCompact = (n: number) => {
  if (n >= 1_000_000) {
    const millions = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(
      n / 1_000_000
    );
    return `${millions}jt`;
  }
  if (n >= 1_000) return `${Math.round(n / 1_000)}rb`;
  return new Intl.NumberFormat("id-ID").format(n);
};
