import type { EventStatus, SportType, TeamStatus, TournamentFormat } from "@/types/api";

export const SPORT_LABELS: Record<SportType, string> = {
  football: "Sepak Bola",
  futsal: "Futsal",
  badminton: "Badminton",
  padel: "Padel",
  volleyball: "Voli",
};

export const FORMAT_LABELS: Record<TournamentFormat, string> = {
  league: "Liga",
  knockout_single: "Knockout",
  knockout_double: "Knockout Ganda",
  hybrid: "Hybrid (Grup + Playoff)",
};

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

export const rupiah = (n: number) => "Rp " + new Intl.NumberFormat("id-ID").format(n);
