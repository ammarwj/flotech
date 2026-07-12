import type { EventStatus, TeamStatus, TicketOrderStatus } from "@/types/api";

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

export const rupiah = (n: number) => "Rp " + new Intl.NumberFormat("id-ID").format(n);
