import type {
  EventPersonnelKind,
  EventStatus,
  MatchStatus,
  PlanOrderStatus,
  RaceEntryStatus,
  TeamStatus,
  TicketOrderStatus,
  TicketPaymentMethod,
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

/**
 * Petugas event. Nilai tersimpannya Inggris karena itu juga batas otorisasi di
 * PHP dan di rute (`event.staff`/`event.referee`); yang dibaca orang tetap
 * Indonesia. Cerminan `EventPersonnel::KIND_LABELS`.
 */
export const EVENT_PERSONNEL_KIND_LABELS: Record<EventPersonnelKind, string> = {
  referee: "Wasit",
  staff: "Staf",
};

export const MATCH_STATUS_LABELS: Record<MatchStatus, string> = {
  scheduled: "Terjadwal",
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

export const PLAN_ORDER_STATUS_LABELS: Record<PlanOrderStatus, string> = {
  past_due: "Menunggu Pembayaran",
  paid: "Lunas",
  cancelled: "Dibatalkan",
};

/**
 * The rail a ticket order was taken on, as a human reads it.
 *
 * Mirrors `TicketOrder::METHOD_LABELS` in the API, which the buyer export reads
 * for the same reason: `onsite` in a column is a word nobody outside the code
 * uses.
 */
export const TICKET_PAYMENT_METHOD_LABELS: Record<TicketPaymentMethod, string> = {
  gateway: "Pembayaran online",
  manual: "Transfer manual",
  onsite: "Tunai di loket",
};

export const TICKET_ORDER_STATUS_LABELS: Record<TicketOrderStatus, string> = {
  pending: "Menunggu Pembayaran",
  paid: "Lunas",
  cancelled: "Dibatalkan",
  refunded: "Dikembalikan",
};

/**
 * A runner's payment state in words.
 *
 * The same five words as a ticket order's wherever the two share a value, plus
 * `expired` — the hourly sweep writes it to abandoned manual entries, and a raw
 * "expired" was being rendered straight into the status column. Separate from
 * TICKET_ORDER_STATUS_LABELS rather than widened into it: that map is keyed by
 * TicketOrderStatus, which has no such member, and a cast to borrow it would be
 * a lie the next value gets caught by.
 *
 * `awaiting_verification` is absent on purpose — it is not a `status` value but
 * a derived flag, so the badge takes it as its own prop (the call
 * PlanOrderStatusBadge already makes).
 */
export const RACE_ENTRY_STATUS_LABELS: Record<RaceEntryStatus, string> = {
  pending: "Menunggu Pembayaran",
  paid: "Lunas",
  cancelled: "Dibatalkan",
  expired: "Kedaluwarsa",
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

/** Thousands-separated count: 1284 → "1.284". */
export const angka = (n: number) => new Intl.NumberFormat("id-ID").format(n);

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
