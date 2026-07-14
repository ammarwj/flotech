/**
 * Suggested player positions per sport.
 *
 * Suggestions, not a closed list: the field is free text (players.position is a
 * plain string on the API), because grassroots tournaments name roles their own
 * way and a locked dropdown would just block them. These fill the datalist so
 * the common cases are one click away and spelled consistently.
 */
const POSITIONS: Record<string, string[]> = {
  football: ["Kiper", "Bek", "Bek Sayap", "Gelandang", "Gelandang Serang", "Sayap", "Penyerang"],
  mini_soccer: ["Kiper", "Bek", "Gelandang", "Sayap", "Penyerang"],
  futsal: ["Kiper", "Anchor", "Flank", "Pivot"],
  badminton: ["Tunggal", "Ganda"],
  padel: ["Drive", "Reves"],
  volleyball: ["Setter", "Outside Hitter", "Middle Blocker", "Opposite", "Libero"],
};

/** Empty when the sport is unknown — the input still accepts anything typed. */
export function positionsFor(sport?: string | null): string[] {
  return (sport && POSITIONS[sport]) || [];
}
