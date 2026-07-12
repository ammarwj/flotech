import type {
  EventStatus,
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
