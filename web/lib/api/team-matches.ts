import { apiClient } from "./client";
import type {
  ApiEnvelope,
  LineupRosterOfficial,
  LineupRosterPlayer,
  Match,
  MatchLineup,
  MyTeamMatch,
} from "@/types/api";

/**
 * The manager's side of the team sheet.
 *
 * Everything here hangs off `my-teams/{team}/...`, the tier a participant
 * account already owns — a manager holds no organization membership and no
 * personnel row, and `managedTeams()` is the only thing that proves the team is
 * theirs. A team that is not theirs is simply not in the query, so these calls
 * answer 404 rather than 403 and the row's existence stays unconfirmed.
 */

/**
 * The team, and the two facts about its event that the screens cannot draw
 * without: the sport (official role labels come from its catalogue) and the
 * timezone (a kickoff is a fact about the venue's clock, never the reader's —
 * the rule `lib/match-dates.ts` states and makes non-optional).
 */
export interface TeamMatchRef {
  id: string;
  name: string;
  event_id: string;
  category_id: string;
  event_name: string | null;
  sport_type: string | null;
  timezone: string | null;
}

export interface TeamMatchesData {
  team: TeamMatchRef;
  matches: MyTeamMatch[];
}

/**
 * The sheet plus the pool it is drawn from.
 *
 * One request, not two: the editor cannot be drawn without both, and fetching
 * them separately would let it render a sheet naming players it has no names
 * for. The roster arrives whole rather than filtered to who is unnamed —
 * moving a player between the starting XI and the bench is the common edit, and
 * a list that shrinks as the manager works is one they cannot put anyone back
 * into.
 */
export interface TeamLineupData {
  team: TeamMatchRef;
  match: Match;
  lineup: MatchLineup;
  roster: LineupRosterPlayer[];
  officials: LineupRosterOfficial[];
}

/** One row of the sheet as the editor sends it back. */
export interface LineupPlayerInput {
  /**
   * The sheet row's id when the editor still holds it. Load-bearing exactly as
   * `players.*.id` is in the registration form: an id means "this is the row
   * you already have". The server falls back to the player id, so an editor
   * that rebuilds its list still updates rather than colliding — but sending
   * the id is what keeps the row, and anything that comes to point at it.
   */
  id?: string | null;
  player_id: string;
  role: "starter" | "substitute";
}

export interface LineupOfficialInput {
  id?: string | null;
  team_official_id: string;
}

export interface SaveLineupPayload {
  /** Full-list contract, same as the roster editor: what is left out is deleted. */
  players: LineupPlayerInput[];
  officials: LineupOfficialInput[];
}

export async function getTeamMatches(teamId: string): Promise<TeamMatchesData> {
  const { data } = await apiClient.get<ApiEnvelope<TeamMatchesData>>(
    `/my-teams/${teamId}/matches`,
  );
  return data.data;
}

export async function getTeamLineup(
  teamId: string,
  matchId: string,
): Promise<TeamLineupData> {
  const { data } = await apiClient.get<ApiEnvelope<TeamLineupData>>(
    `/my-teams/${teamId}/matches/${matchId}/lineup`,
  );
  return data.data;
}

export async function saveTeamLineup(
  teamId: string,
  matchId: string,
  payload: SaveLineupPayload,
): Promise<TeamLineupData> {
  const { data } = await apiClient.put<ApiEnvelope<TeamLineupData>>(
    `/my-teams/${teamId}/matches/${matchId}/lineup`,
    payload,
  );
  return data.data;
}

/**
 * Hand it to the referee. From here the sheet is locked until they answer —
 * that lock is the whole of what the approval means.
 */
export async function submitTeamLineup(
  teamId: string,
  matchId: string,
): Promise<TeamLineupData> {
  const { data } = await apiClient.post<ApiEnvelope<TeamLineupData>>(
    `/my-teams/${teamId}/matches/${matchId}/lineup/submit`,
    {},
  );
  return data.data;
}
