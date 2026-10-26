import { cn } from "@/lib/utils";

/**
 * What an entry prints under its name on a fixture card — the club a badminton
 * pair represents, the school a squad is from.
 *
 * One component rather than a line pasted into each card, for the same reason
 * MatchCardHeader exists: five surfaces show a fixture (the organizer's
 * schedule, the public one, the detail dialog, the scoreboard, the manager's
 * match list), and a separator or a truncation rule spelled out in each is one
 * that will disagree with the other four.
 *
 * The values arrive already joined to the schema and deduplicated by the server
 * (RegistrationForm::scheduleTags), so there is nothing to decide here: no
 * labels, because a card has one line of room and "Nama PB: PB. Uncrown Medan"
 * spends half of it saying what the reader can already see, and nothing at all
 * when the list is empty — which is every event that ticked no field.
 *
 * Renders plain text, never a control: two of its callers are `<button>`s.
 */
export function TeamTags({
  tags,
  className,
}: {
  /** Optional so a payload cached from before the feature still renders. */
  tags: string[] | undefined;
  className?: string;
}) {
  if (!tags?.length) {
    return null;
  }

  return (
    <span
      className={cn(
        "block truncate text-[11px] font-normal leading-tight text-muted-foreground",
        className,
      )}
      // The separator is a middle dot, which reads as "and also" rather than as
      // a list the reader has to parse — and the title carries the full text, so
      // a truncated line is still recoverable on hover.
      title={tags.join(" · ")}
    >
      {tags.join(" · ")}
    </span>
  );
}
