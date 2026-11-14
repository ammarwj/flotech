import type { DomainAddonKind, DomainAddonQuote, EventPlanOrder } from "@/types/api";

/**
 * Mirror of `App\Support\DomainAddons` — the client half of the domain add-on.
 *
 * Prices are NOT here. They are super-admin settings, fetched from
 * `/domain-addons`, and a literal in this file would be a second answer that
 * drifts the day someone changes one — the same reason there is no hardcoded
 * plan list anywhere in `web/`. What lives here is only presentation the
 * catalogue does not carry: what each kind is called, and what it asks the
 * organizer for.
 */

export const DOMAIN_KINDS = ["own", "register", "subdomain"] as const;

/**
 * What each kind means, in the organizer's words.
 *
 * `prompt` is the question the input asks, and it differs per kind for a reason:
 * one wants a domain the organizer already owns, one wants a name to go and buy,
 * and one wants a label, not a hostname. Writing "Nama domain" above a field
 * that only takes a label is the "Nomor rekening" over a phone number again.
 */
export const DOMAIN_KIND_META: Record<
  DomainAddonKind,
  { label: string; description: string; prompt: string; placeholder: string }
> = {
  own: {
    label: "Sudah punya domain",
    // Not "arahkan DNS-nya": most organizers have never opened a DNS panel, and
    // a line that names the task without saying who does it reads as homework
    // they may not be able to do. What they actually have to supply is one
    // setting at the place they bought the domain, and we walk them through it.
    description: "Dibantu admin sampai aktif.",
    prompt: "Domain yang kamu punya",
    placeholder: "liga-anda.com",
  },
  register: {
    label: "Beli domain dari floevent",
    description: "Berlaku 1 tahun.",
    prompt: "Nama domain yang diinginkan",
    // No extension: the field renders the chosen one as a suffix beside the
    // input, so typing one here would put two in the name.
    placeholder: "liga-anda",
  },
  subdomain: {
    label: "Pakai subdomain floevent.id",
    // Not "langsung aktif": the certificate still has to be issued, which
    // `domains:sync` does on its next pass. What it genuinely skips is the
    // waiting that belongs to someone else — no registrar, no DNS to point,
    // nothing the organizer has to go and do.
    description: "Tanpa setelan DNS, aktif beberapa menit.",
    prompt: "Nama subdomain",
    placeholder: "liga-anda",
  },
};

export function domainKindLabel(kind: DomainAddonKind | null | undefined): string {
  return kind ? DOMAIN_KIND_META[kind].label : "—";
}

/**
 * Does this kind need a name before it can be bought?
 *
 * `register` does not: an organizer may buy "a .id domain" and tell us the name
 * later, or leave the choice to us. Mirrors the `requiredIf` in
 * `DomainAddonRules`; the refusal itself still belongs to the server, this only
 * decides whether the submit button waits for the field.
 */
export function domainNameRequired(kind: DomainAddonKind): boolean {
  return kind === "own" || kind === "subdomain";
}

/**
 * The hostname a label will become, for showing the organizer what they are
 * buying while they type it.
 *
 * Display only. The server composes the stored name itself
 * (`DomainAddonRules::hostFor`), which is what keeps the invoice, the
 * certificate and the routing map naming one string — this is the preview, not
 * the value.
 */
export function subdomainPreview(label: string, platformDomain: string): string | null {
  const clean = label
    .trim()
    .toLowerCase()
    .replace(/^[a-z]+:\/\//, "")
    .split("/")[0]
    .split(".")[0]
    .replace(/[\s_]+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "");

  return clean === "" ? null : `${clean}.${platformDomain}`;
}

/**
 * Total for an add-on from the catalogue, when no server preview has been
 * fetched yet.
 *
 * Used for the price shown beside each kind *before* the organizer has typed a
 * name — the figure that actually gets charged always comes from
 * `previewDomainAddon()`, for the reason the discount preview gives: a price
 * worked out in the browser makes the channel picker quote a fee on an amount
 * nobody is charged.
 *
 * `tld` is what makes the `register` card's price follow the extension chips:
 * with one chosen the figure is that extension's exact total, without one it is
 * the cheapest, which the caller labels "mulai". Both come off the catalogue
 * row's own `total`, which the server already added the configuration fee into
 * — so the card and the till add it exactly once, in one place.
 */
export function catalogueTotal(
  quote: DomainAddonQuote | null,
  kind: DomainAddonKind,
  tld?: string
): number | null {
  if (!quote) return null;

  if (kind !== "register") return quote.setup_fee;

  // No extension picked yet: the cheapest one is what the card advertises
  // ("mulai dari"), because quoting the dearest reads as the price of the
  // feature rather than of one TLD.
  const prices = tld
    ? quote.tlds.filter((row) => row.tld === tld).map((row) => row.total)
    : quote.tlds.map((row) => row.total);

  return prices.length === 0 ? null : Math.min(...prices);
}

/**
 * Where a paid add-on has got to, for the organizer's own screen.
 *
 * Four states, and they are deliberately not the same four as
 * `event.domain_status`: that one describes a hostname we already hold, while
 * this one starts one step earlier — an order can be paid with no name attached
 * yet, which is the single state the organizer most needs explained.
 *
 * Derived from the order rather than stored, mirroring
 * `isAwaitingDomainFulfilment()`.
 */
export type DomainAddonStage = "unpaid" | "registering" | "attached";

export function domainAddonStage(order: EventPlanOrder): DomainAddonStage {
  if (order.status !== "paid") return "unpaid";
  if (order.awaiting_domain_fulfilment) return "registering";

  return "attached";
}

/** Orders that bought a domain, newest first. */
export function domainOrders(orders: EventPlanOrder[]): EventPlanOrder[] {
  return orders.filter((order) => order.domain_kind !== null);
}
