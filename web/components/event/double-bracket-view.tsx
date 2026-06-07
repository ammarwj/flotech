import { BracketView } from "./bracket-view";
import type { Match } from "@/types/api";

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="mb-3 text-xs font-bold uppercase tracking-wide text-muted-foreground">{title}</div>
      {children}
    </div>
  );
}

/** Double-elimination layout: Winners + Losers brackets and the Grand Final. */
export function DoubleBracketView({ matches }: { matches: Match[] }) {
  const winners = matches.filter((m) => m.bracket === "winners");
  const losers = matches.filter((m) => m.bracket === "losers");
  // Hide the reset match when it was cancelled (WB side won outright).
  const grandFinal = matches.filter((m) => m.bracket === "grand_final" && m.status !== "cancelled");

  return (
    <div className="grid gap-7">
      {winners.length > 0 && (
        <Section title="Winners Bracket">
          <BracketView matches={winners} />
        </Section>
      )}
      {losers.length > 0 && (
        <Section title="Losers Bracket">
          <BracketView matches={losers} roundLabel={(_, r) => `Babak ${r}`} />
        </Section>
      )}
      {grandFinal.length > 0 && (
        <Section title="Grand Final">
          <BracketView matches={grandFinal} roundLabel={(_, r) => (r === 2 ? "Reset" : "Final")} />
        </Section>
      )}
    </div>
  );
}
