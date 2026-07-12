import type {
  DrawMethod,
  EventStatus,
  KnockoutRound,
  SponsorTier,
  SportType,
  TeamStatus,
  Tiebreaker,
  TicketOrderStatus,
  TournamentFormat,
} from "@/types/api";

export const SPORT_LABELS: Record<SportType, string> = {
  football: "Sepak Bola",
  mini_soccer: "Mini Soccer",
  futsal: "Futsal",
  badminton: "Badminton",
  padel: "Padel",
  volleyball: "Voli",
};

export const FORMAT_LABELS: Record<TournamentFormat, string> = {
  league: "Liga",
  knockout_single: "Knockout",
  knockout_double: "Knockout Ganda",
  hybrid: "Grup + Knockout (Hybrid)",
};

export const KNOCKOUT_ROUND_LABELS: Record<KnockoutRound, string> = {
  final: "Final",
  semifinal: "Semifinal",
  quarter_final: "Perempat Final",
  round_of_16: "16 Besar",
  round_of_32: "32 Besar",
  round_of_64: "64 Besar",
};

export const TIEBREAKER_LABELS: Record<Tiebreaker, string> = {
  head_to_head: "Head to Head",
  goal_difference: "Selisih Gol",
  goals_scored: "Gol Memasukkan",
  fair_play: "Fair Play",
  drawing_lots: "Undian",
};

export const SPONSOR_TIER_LABELS: Record<SponsorTier, string> = {
  host: "Diselenggarakan oleh",
  sponsor: "Sponsor",
  media_partner: "Media Partner",
  supporter: "Didukung oleh",
};

export const DRAW_METHOD_LABELS: Record<DrawMethod, string> = {
  random: "Undian Acak",
  manual: "Atur Manual",
  pot: "Seeding Pot",
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

export const SPORT_COLORS: Record<SportType, string> = {
  football: "var(--sport-football)",
  mini_soccer: "var(--sport-mini-soccer)",
  futsal: "var(--sport-futsal)",
  badminton: "var(--sport-badminton)",
  padel: "var(--sport-padel)",
  volleyball: "var(--sport-volleyball)",
};

export const TICKET_ORDER_STATUS_LABELS: Record<TicketOrderStatus, string> = {
  pending: "Menunggu Pembayaran",
  paid: "Lunas",
  cancelled: "Dibatalkan",
  refunded: "Dikembalikan",
};

export const rupiah = (n: number) => "Rp " + new Intl.NumberFormat("id-ID").format(n);
