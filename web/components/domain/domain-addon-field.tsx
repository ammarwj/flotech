"use client";

import { useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { Check, Globe } from "lucide-react";

import { getDomainAddons, previewDomainAddon } from "@/lib/api/domains";
import { parseApiError } from "@/lib/api/errors";
import {
  DOMAIN_KINDS,
  DOMAIN_KIND_META,
  catalogueTotal,
  domainNameRequired,
  subdomainPreview,
} from "@/lib/domain";
import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FieldError } from "@/components/ui/field-error";
import type { DomainAddonKind, DomainAddonPreview } from "@/types/api";

/**
 * Pick a domain add-on and have the server price it.
 *
 * One component for both doors — the plan checkout dialog and the buy-later page
 * — for the reason PlanCheckoutDialog's own docblock gives: two copies of a
 * priced field drift, and the drift looks like a working form.
 *
 * What is NOT inside it is the applied preview. That belongs to the parent,
 * because `applied.amount` is what gets handed to ChannelPicker and
 * `applied.domain_kind`/`domain_name` is what the submit sends — the same split
 * `DiscountCodeField` makes.
 *
 * Every `onApply` MUST null the chosen payment channel: an add-on changes the
 * amount a percentage fee is taken from.
 *
 * Cards rather than a `<select>`, following ChannelPicker: three options whose
 * whole difference is a price and a sentence of explanation, and a dropdown can
 * show one of them at a time. The radio input is real and merely visually
 * hidden — arrow keys, Tab and screen readers all come from the browser, which
 * is not true of a div with an onClick.
 */
export function DomainAddonField({
  orgId,
  applied,
  onApply,
  onClear,
  disabled,
  /**
   * Can the organizer decline? False on the buy-later page, where declining is
   * the same as not being on the page — a "tanpa domain" option there is a
   * control whose only outcome is to disable the submit button.
   */
  optional = true,
}: {
  orgId: string | null;
  applied: DomainAddonPreview | null;
  onApply: (preview: DomainAddonPreview) => void;
  onClear: () => void;
  disabled?: boolean;
  optional?: boolean;
}) {
  const [kind, setKind] = useState<DomainAddonKind | null>(optional ? null : "subdomain");
  /**
   * Is the picker on screen at all?
   *
   * Collapsed behind a one-line teaser wherever the add-on is optional, because
   * there it shares a modal with the plan price, the discount field and the
   * channel picker — three cards of domain options above all that pushes the
   * Lanjutkan button off a phone screen for the majority who are not buying a
   * domain. Open from the start where it is NOT optional: the buy-later page
   * exists for nothing else, and asking someone who navigated to "Domain" to
   * click "Pasang domain" is a step that answers a question they already did.
   */
  const [open, setOpen] = useState(!optional);
  /**
   * Which extension the organizer is buying — only `register` has one.
   *
   * Held apart from `name` because it prices the add-on on its own: the card
   * has to quote .com against .id before any name has been typed, and the TLD
   * the server charges for is the one taken off the name at the till
   * (`DomainAddons::tldOf`). Appended on submit rather than expected inside
   * `name`, so the two cannot disagree.
   */
  const [tld, setTld] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [error, setError] = useState<string | null>(null);

  const catalogue = useQuery({ queryKey: ["domain-addons"], queryFn: getDomainAddons });
  const quote = catalogue.data ?? null;

  const preview = useMutation({
    mutationFn: ({ kind, domain }: { kind: DomainAddonKind; domain: string }) =>
      previewDomainAddon(orgId!, kind, domain || undefined),
    onSuccess: (result) => {
      setError(null);
      onApply(result);
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Add-on domain itu belum bisa dipakai.");
      onClear();
      setError(parsed.fieldErrors.domain_name ?? parsed.fieldErrors.domain_kind ?? parsed.message);
    },
  });

  // Typing again invalidates whatever the server last priced — the parent's
  // channel choice was made against that number.
  const dirty = (next: () => void) => {
    setError(null);
    onClear();
    next();
  };

  const platformDomain = quote?.platform_domain ?? "floevent.id";

  /**
   * The name as it will be sent. `register` carries its extension from the
   * chip, so the organizer types the label alone and cannot type a .com while a
   * .id is selected — the price on screen and the price at the till are the
   * same number by construction.
   */
  const fullName = (() => {
    const typed = name.trim();
    if (typed === "") return "";
    if (kind === "register") return tld ? `${typed}.${tld}` : "";

    return typed;
  })();

  const host = kind === "subdomain" ? subdomainPreview(name, platformDomain) : null;
  const ready =
    kind !== null &&
    !!orgId &&
    // `register` may be bought without a name — the organizer can leave the
    // choice to us — but never without an extension, which is what it costs.
    (kind === "register" ? tld !== null : true) &&
    (!domainNameRequired(kind) || fullName !== "");

  /** The price this card advertises, before anything is typed. */
  const cardPrice = (option: DomainAddonKind) =>
    catalogueTotal(quote, option, option === "register" ? (tld ?? undefined) : undefined);

  /**
   * The teaser. One row, one price, one button — enough to decide whether to
   * read on, and nothing more. The price is the cheapest of the three, which is
   * the subdomain's configuration fee.
   */
  if (!open) {
    const from = quote?.setup_fee ?? null;

    return (
      <button
        type="button"
        disabled={disabled}
        // Opening it is the answer to "do you want one?", so the first kind is
        // picked with it: an open picker with nothing selected asks the same
        // question again, one step further in. Subdomain is the seed because it
        // is the cheapest and the only one that needs nothing from anyone else.
        onClick={() => {
          setOpen(true);
          setKind("subdomain");
        }}
        className="flex w-full items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:border-[var(--border-strong)] disabled:cursor-not-allowed disabled:opacity-60"
      >
        <Globe className="size-4 shrink-0 text-muted-foreground" />
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-medium">
            Pakai domain sendiri{" "}
            <span className="font-normal text-muted-foreground">(opsional)</span>
          </span>
          <span className="mt-0.5 block text-xs text-muted-foreground">
            Tanpa merek flo-event
            {from === null ? "" : ` · mulai ${rupiah(from)}`}
          </span>
        </span>
        <span className="shrink-0 text-sm font-medium text-[var(--brand-600)]">Pilih</span>
      </button>
    );
  }

  return (
    <div className="grid gap-3 rounded-lg border p-3">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2 text-sm font-medium">
          <Globe className="size-4 text-muted-foreground" />
          Domain sendiri
        </div>
        {/* Only where declining is a real answer. On the buy-later page there is
            nothing to collapse back to. */}
        {optional && (
          <button
            type="button"
            disabled={disabled || preview.isPending}
            onClick={() =>
              dirty(() => {
                setOpen(false);
                setKind(null);
                setName("");
                setTld(null);
              })
            }
            className="shrink-0 text-xs font-medium text-muted-foreground hover:underline disabled:opacity-60"
          >
            Tanpa domain
          </button>
        )}
      </div>

      {/* Three clauses became one. The teaser above already said what this
          buys, and the three kinds below each carry their own line — repeating
          the catalogue and footer details here spent the top of the modal on
          something nobody rereads. */}
      <p className="text-xs text-muted-foreground">Tanpa merek flo-event di halaman event.</p>

      {/* A real radio group: one `name`, so the browser gives arrow-key
          navigation and announces "1 of 3" without any of it being rebuilt here. */}
      <fieldset className="grid gap-2" disabled={disabled || preview.isPending}>
        <legend className="sr-only">Jenis domain</legend>

        {DOMAIN_KINDS.map((option) => {
          const active = kind === option;
          const price = cardPrice(option);

          return (
            <label
              key={option}
              className={cn(
                "flex cursor-pointer gap-3 rounded-xl border p-3 transition-colors",
                active
                  ? "border-[var(--brand-600)] bg-[var(--tint)]"
                  : "border-border hover:border-[var(--border-strong)]",
                (disabled || preview.isPending) && "cursor-not-allowed opacity-60",
              )}
            >
              <input
                type="radio"
                name="domain-kind"
                value={option}
                checked={active}
                // `sr-only`, not `hidden`: a hidden input is unfocusable, which
                // would take the keyboard behaviour away again.
                className="sr-only"
                onChange={() =>
                  dirty(() => {
                    setKind(option);
                    setName("");
                    // Seeded with the cheapest extension so the card stops
                    // quoting "mulai dari" the moment it is picked; cleared for
                    // the other two, which have no extension to carry.
                    setTld(option === "register" ? (quote?.tlds[0]?.tld ?? null) : null);
                  })
                }
              />

              {/* Drawn, not a native dot: the input is visually hidden so the
                  ring has to come from here. */}
              <span
                aria-hidden
                className={cn(
                  "mt-0.5 grid size-4 shrink-0 place-items-center rounded-full border",
                  active ? "border-[var(--brand-600)]" : "border-[var(--border-strong)]",
                )}
              >
                {active && <span className="size-2 rounded-full bg-[var(--brand-600)]" />}
              </span>

              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                  <span className="text-sm font-medium">{DOMAIN_KIND_META[option].label}</span>
                  {price !== null && (
                    <span className="shrink-0 text-sm font-semibold tabular-nums">
                      {/* "mulai" only while no extension is chosen: once it is,
                          the figure is exact and hedging it would read as a
                          price that may still move. */}
                      {option === "register" && !tld ? "mulai " : ""}
                      {rupiah(price)}
                    </span>
                  )}
                </span>
                <span className="mt-1 block text-xs text-muted-foreground">
                  {DOMAIN_KIND_META[option].description}
                </span>
              </span>
            </label>
          );
        })}

        {/* No fourth "Tanpa domain" radio: collapsing the block IS that answer,
            and the link in the header above does it. Two controls for one
            outcome would leave the picker open and empty, which reads as a
            choice still to be made. */}
      </fieldset>

      {kind !== null && (
        <>
          {/* The extension chips. Their own control rather than something to be
              typed into the name, because the price follows them: .com and .id
              are different numbers, and this is where the organizer sees that. */}
          {kind === "register" && quote && (
            <div className="grid gap-2">
              <span className="text-sm font-medium">Ekstensi domain</span>
              <div className="flex flex-wrap gap-2">
                {quote.tlds.map((row) => {
                  const active = tld === row.tld;

                  return (
                    <label
                      key={row.tld}
                      className={cn(
                        "flex cursor-pointer items-baseline gap-2 rounded-lg border px-3 py-2 transition-colors",
                        active
                          ? "border-[var(--brand-600)] bg-[var(--tint)]"
                          : "border-border hover:border-[var(--border-strong)]",
                        (disabled || preview.isPending) && "cursor-not-allowed opacity-60",
                      )}
                    >
                      <input
                        type="radio"
                        name="domain-tld"
                        value={row.tld}
                        checked={active}
                        className="sr-only"
                        disabled={disabled || preview.isPending}
                        onChange={() => dirty(() => setTld(row.tld))}
                      />
                      <span className="font-mono text-sm font-medium">.{row.tld}</span>
                      <span className="text-xs text-muted-foreground tabular-nums">
                        {rupiah(row.total)}
                      </span>
                    </label>
                  );
                })}
              </div>
              <p className="text-xs text-muted-foreground">
                Sudah termasuk biaya konfigurasi {rupiah(quote.setup_fee)} dan registrasi satu
                tahun.
              </p>
            </div>
          )}

          <div className="grid gap-2">
            <Label htmlFor="domain-name">
              {DOMAIN_KIND_META[kind].prompt}
              {!domainNameRequired(kind) && (
                <span className="font-normal text-muted-foreground"> (bisa diisi nanti)</span>
              )}
            </Label>
            <div className="flex gap-2">
              {/* The suffix sits outside the input for the two kinds that have
                  one, so the organizer types a name and not a hostname —
                  nothing they can get wrong, and the full name is composed in
                  one place. */}
              <div className="flex min-w-0 flex-1 items-center gap-1.5">
                <Input
                  id="domain-name"
                  value={name}
                  placeholder={DOMAIN_KIND_META[kind].placeholder}
                  autoCapitalize="none"
                  spellCheck={false}
                  aria-invalid={error ? true : undefined}
                  disabled={disabled || preview.isPending}
                  onChange={(e) => dirty(() => setName(e.target.value))}
                />
                {kind === "register" && tld && (
                  <span className="shrink-0 font-mono text-sm text-muted-foreground">.{tld}</span>
                )}
                {kind === "subdomain" && (
                  <span className="shrink-0 font-mono text-sm text-muted-foreground">
                    .{platformDomain}
                  </span>
                )}
              </div>
              <Button
                variant="secondary"
                disabled={!ready || disabled || preview.isPending}
                onClick={() => preview.mutate({ kind, domain: fullName })}
              >
                {preview.isPending ? "Memeriksa…" : "Pakai"}
              </Button>
            </div>
            {/* The composed hostname, so the organizer sees what they are
                buying rather than the label they typed. Display only — the
                server composes the stored name itself. */}
            {host && !applied && (
              <p className="text-xs text-muted-foreground">
                Alamatnya jadi <span className="font-mono">{host}</span>
              </p>
            )}
            <FieldError message={error ?? undefined} />
          </div>
        </>
      )}

      {applied && (
        <div className="grid gap-1 rounded-lg bg-[var(--muted)] p-3 text-sm">
          <div className="flex items-center gap-2 font-medium">
            <Check className="size-4 text-[var(--success)]" />
            {applied.domain_name ?? DOMAIN_KIND_META[applied.domain_kind].label}
          </div>
          {applied.register_amount > 0 && (
            <div className="flex justify-between text-xs text-muted-foreground">
              <span>Registrasi domain 1 tahun</span>
              <span>{rupiah(applied.register_amount)}</span>
            </div>
          )}
          <div className="flex justify-between text-xs text-muted-foreground">
            <span>Biaya konfigurasi</span>
            <span>{rupiah(applied.setup_amount)}</span>
          </div>
          {/* `register` is the one kind that waits on a human, and saying so
              here is cheaper than letting the organizer discover it from a paid
              order with no domain on it. */}
          {applied.domain_kind === "register" && (
            <p className="text-xs text-muted-foreground">
              Kami belikan setelah lunas, biasanya 1×24 jam.
            </p>
          )}
          {/* The one kind that needs something from the organizer afterwards,
              and the one place with room to say what. "Satu setelan" rather
              than "A record": the panel on the domain page spells out the
              record itself, and naming it here only asks them to understand a
              term before they can decide. */}
          {applied.domain_kind === "own" && (
            <p className="text-xs text-muted-foreground">
              Setelah lunas, ada satu setelan yang perlu diubah di tempat kamu beli domainnya.
              Panduannya ada di halaman domain event, dan admin flo-event siap membantu kalau
              bingung.
            </p>
          )}
        </div>
      )}
    </div>
  );
}
