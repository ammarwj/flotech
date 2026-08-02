import type { APIRequestContext } from "@playwright/test";

import { createHash } from "node:crypto";

import { API_URL } from "../playwright.config";

/** Every API response is wrapped in the same envelope (see App\Support\ApiResponse). */
interface Envelope<T> {
  success: boolean;
  message: string;
  data: T;
}

export interface Account {
  token: string;
  email: string;
  password: string;
  userId: string;
  fullName: string;
}

export interface Org {
  id: string;
  slug: string;
  name: string;
}

export interface Event {
  id: string;
  slug: string;
  name: string;
  /** Each event runs one-or-more competition categories; every team joins one. */
  categories: Array<{ id: string; slug: string; name: string }>;
}

export interface Team {
  id: string;
  name: string;
}

/** Seeded platform admin — the only account the suite reuses (see UserSeeder). */
export const SUPER_ADMIN = { email: "admin@flo-event.id", password: "password" };

/**
 * The plan a fixture organization is born on.
 *
 * `pro` by default because it is the roomiest plan that still isn't unlimited:
 * `qr_tickets` on (`basic` has it *off*, so it cannot sell a ticket at all),
 * 10 active events, 128 teams per event — no spec bumps a cap by accident.
 * Override per spec when the cap is what's being tested.
 */
export type PlanSlug = "basic" | "starter" | "pro" | "professional";

/** Passwords must carry a letter and a digit (Password::min(8)->letters()->numbers()). */
export const PASSWORD = "rahasia123";

let counter = 0;

/**
 * Unique per test, per worker *and* per run. The suite shares one dev database
 * with the developer's own data, so a name may never collide with a previous
 * run's leftovers — and workers are separate processes, so a timestamp plus a
 * process-local counter isn't enough: two workers starting in the same
 * millisecond would both produce `...-1`. Hence the random tail.
 */
export function unique(prefix: string): string {
  counter += 1;
  const rand = Math.random().toString(36).slice(2, 8);
  return `${prefix}-${Date.now().toString(36)}${counter}${rand}`;
}

/**
 * Thin wrapper over the REST API used to *arrange* state. Assertions belong in
 * the browser: what a test is proving lives in the UI, what it merely needs to
 * exist beforehand is built here, where it costs one request instead of a page
 * load. Read `specs/` top-down and the API calls are always the setup.
 */
export class Api {
  constructor(private readonly request: APIRequestContext) {}

  private auth(token: string) {
    return { Authorization: `Bearer ${token}`, Accept: "application/json" };
  }

  private async unwrap<T>(res: { ok: () => boolean; status: () => number; json: () => Promise<unknown>; text: () => Promise<string> }, what: string): Promise<T> {
    if (!res.ok()) {
      throw new Error(`${what} gagal (HTTP ${res.status()}): ${await res.text()}`);
    }
    return (await res.json() as Envelope<T>).data;
  }

  // ---- Auth ----

  /**
   * Registers a brand-new user. Signing in through the API rather than the login
   * form keeps each spec focused: only auth.spec.ts asserts the form itself.
   * `defaultMode` seeds `users.default_mode` — which dashboard a login opens in.
   */
  async registerUser(namePrefix = "e2e", defaultMode?: "organizer" | "participant"): Promise<Account> {
    const email = `${unique(namePrefix)}@e2e.test`;
    const fullName = `E2E ${namePrefix}`;

    const res = await this.request.post(`${API_URL}/auth/register`, {
      data: {
        full_name: fullName,
        email,
        // Required since RegisterRequest gained it; the value is never asserted
        // on, it just has to be a plausible Indonesian number.
        phone: "081234567890",
        password: PASSWORD,
        password_confirmation: PASSWORD,
        ...(defaultMode ? { default_mode: defaultMode } : {}),
      },
    });

    const data = await this.unwrap<{ access_token: string; user: { id: string } }>(res, `Register ${email}`);
    return { token: data.access_token, email, password: PASSWORD, userId: data.user.id, fullName };
  }

  async login(email: string, password: string): Promise<string> {
    const res = await this.request.post(`${API_URL}/auth/login`, { data: { email, password } });
    const data = await this.unwrap<{ access_token: string }>(res, `Login ${email}`);
    return data.access_token;
  }

  loginAsSuperAdmin(): Promise<string> {
    return this.login(SUPER_ADMIN.email, SUPER_ADMIN.password);
  }

  // ---- Organization & events ----

  /** slug → id, fetched once per worker. The catalog is seeded and immutable here. */
  private plans?: Map<string, string>;

  private async planId(slug: PlanSlug): Promise<string> {
    if (!this.plans) {
      const res = await this.request.get(`${API_URL}/plans`);
      const list = await this.unwrap<Array<{ id: string; slug: string }>>(res, "Ambil daftar paket");
      this.plans = new Map(list.map((p) => [p.slug, p.id]));
    }

    const id = this.plans.get(slug);
    if (!id) throw new Error(`Paket "${slug}" tidak ada. Jalankan: docker compose exec api php artisan db:seed`);
    return id;
  }

  /**
   * A bare organization. It owns nothing yet — entitlements live on events now,
   * and an event is created by spending a paid plan order (see `grantCredit`).
   */
  async createOrg(token: string, name = unique("EO")): Promise<Org> {
    const res = await this.request.post(`${API_URL}/organizations`, {
      headers: this.auth(token),
      data: { name },
    });
    return this.unwrap<Org>(res, `Buat organisasi ${name}`);
  }

  /**
   * A paid, unspent plan order — the credit `createEvent` spends.
   *
   * Settled by posting the Midtrans notification ourselves rather than by
   * paying: `plan-orders/checkout` returns a real Snap redirect and leaves the
   * order `past_due`, and nothing else moves it. The signature is just
   * sha512(order_id + status_code + gross_amount + server_key), so with the key
   * in hand this is exactly the callback Midtrans would send.
   *
   * Deliberately not the alternative of running the API with
   * MIDTRANS_SERVER_KEY blank: that makes openSnap() settle on the spot and
   * skips the webhook entirely, so neither the signature check nor the order-id
   * routing would ever be exercised. This way the fixture pays for itself by
   * covering both.
   *
   * Requires MIDTRANS_SERVER_KEY in the e2e environment — the same sandbox key
   * api/.env already holds.
   */
  async grantCredit(token: string, orgId: string, plan: PlanSlug = "pro"): Promise<string> {
    const serverKey = process.env.MIDTRANS_SERVER_KEY;
    if (!serverKey) {
      throw new Error(
        "MIDTRANS_SERVER_KEY tidak ada di environment e2e. Kredit paket disetel " +
          "lewat webhook Midtrans, dan signature-nya butuh key itu (lihat e2e/README.md)."
      );
    }

    const checkout = await this.request.post(`${API_URL}/organizations/${orgId}/plan-orders/checkout`, {
      headers: this.auth(token),
      data: { plan_id: await this.planId(plan) },
    });
    const result = await this.unwrap<{
      plan_order: { id: string; amount: number; midtrans_order_id: string | null };
    }>(checkout, `Checkout paket ${plan}`);

    const order = result.plan_order;
    if (!order.midtrans_order_id) {
      throw new Error("Checkout tidak menghasilkan order id Midtrans — gateway sedang mati?");
    }

    // Midtrans sends gross_amount with two decimals.
    const gross = order.amount.toFixed(2);
    const statusCode = "200";
    const signature = createHash("sha512")
      .update(order.midtrans_order_id + statusCode + gross + serverKey)
      .digest("hex");

    const webhook = await this.request.post(`${API_URL}/webhooks/midtrans`, {
      data: {
        order_id: order.midtrans_order_id,
        status_code: statusCode,
        gross_amount: gross,
        signature_key: signature,
        transaction_status: "settlement",
        payment_type: "bank_transfer",
      },
    });
    await this.unwrap(webhook, `Settle paket ${plan}`);

    return order.id;
  }

  /**
   * Settle a bill that already exists — the top-up an upgrade raises.
   *
   * Same self-signed Midtrans notification as grantCredit(), split out because
   * an upgrade's bill is created by the UI under test rather than by the
   * fixture, so only the settling half can be arranged here.
   */
  async settleOrder(orderId: string, orgId: string, token: string): Promise<void> {
    const serverKey = process.env.MIDTRANS_SERVER_KEY;
    if (!serverKey) throw new Error("MIDTRANS_SERVER_KEY tidak ada di environment e2e.");

    const list = await this.request.get(`${API_URL}/organizations/${orgId}/plan-orders`, {
      headers: this.auth(token),
    });
    const orders = await this.unwrap<Array<{ id: string; amount: number; midtrans_order_id: string | null }>>(
      list,
      "Ambil daftar order paket",
    );
    const order = orders.find((o) => o.id === orderId);
    if (!order?.midtrans_order_id) throw new Error(`Order ${orderId} tidak punya order id Midtrans.`);

    const gross = order.amount.toFixed(2);
    const signature = createHash("sha512")
      .update(order.midtrans_order_id + "200" + gross + serverKey)
      .digest("hex");

    const webhook = await this.request.post(`${API_URL}/webhooks/midtrans`, {
      data: {
        order_id: order.midtrans_order_id,
        status_code: "200",
        gross_amount: gross,
        signature_key: signature,
        transaction_status: "settlement",
        payment_type: "bank_transfer",
      },
    });
    await this.unwrap(webhook, "Settle tagihan upgrade");
  }

  /** Paid plan orders of an organization, newest first. */
  async planOrders(token: string, orgId: string): Promise<
    Array<{ id: string; status: string; amount: number; event_id: string | null; upgrade_of_id: string | null; superseded: boolean; plan?: { slug: string } }>
  > {
    const res = await this.request.get(`${API_URL}/organizations/${orgId}/plan-orders`, {
      headers: this.auth(token),
    });
    return this.unwrap(res, "Ambil daftar order paket");
  }

  /**
   * Registration is open by default: every flow downstream of §5.2 needs a team
   * to be able to sign up, and an event whose window is shut fails in a way that
   * looks like a UI bug rather than a fixture bug.
   *
   * Format, fee and team cap live on each category now, so a fixture event runs a
   * single default category. `overrides` merge into *that category* (the only
   * overrides any spec uses — `max_teams`, `registration_fee` — are category-level).
   */
  async createEvent(token: string, orgId: string, overrides: Record<string, unknown> = {}): Promise<Event> {
    // Creating an event spends a paid plan order, so arrange one first. `pro` is
    // the roomiest catalogue plan that still isn't unlimited, which is what the
    // cap-related specs downstream want.
    await this.grantCredit(token, orgId);

    const res = await this.request.post(`${API_URL}/organizations/${orgId}/events`, {
      headers: this.auth(token),
      data: {
        name: unique("Turnamen"),
        sport_type: "futsal",
        start_date: daysFromNow(7),
        end_date: daysFromNow(8),
        registration_open: daysFromNow(-1),
        registration_close: daysFromNow(6),
        location_name: "GBK Arena",
        categories: [
          {
            name: "Umum",
            tournament_format: "league",
            registration_fee: 0,
            max_teams: 8,
            ...overrides,
          },
        ],
      },
    });
    return this.unwrap<Event>(res, "Buat event");
  }

  async publishEvent(token: string, orgId: string, eventId: string): Promise<void> {
    const res = await this.request.post(`${API_URL}/organizations/${orgId}/events/${eventId}/publish`, {
      headers: this.auth(token),
    });
    await this.unwrap(res, "Publish event");
  }

  /** Creates a published event in one call — the starting point of most specs. */
  async liveEvent(token: string, orgId: string, overrides: Record<string, unknown> = {}): Promise<Event> {
    const event = await this.createEvent(token, orgId, overrides);
    await this.publishEvent(token, orgId, event.id);
    return event;
  }

  // ---- Teams (§5.2) ----

  /**
   * `managerToken` is not optional: registration requires an account, because
   * the team belongs to whoever filed it and that link is what puts it in their
   * "Tim Saya". An anonymous POST here is a 401.
   */
  async registerTeam(
    orgSlug: string,
    event: Event,
    managerToken: string,
    name = unique("Tim"),
  ): Promise<Team> {
    const res = await this.request.post(`${API_URL}/public/events/${orgSlug}/${event.slug}/register`, {
      headers: this.auth(managerToken),
      data: {
        // Every team joins a category; the fixture event has exactly one.
        category_id: event.categories[0].id,
        name,
        contact_name: "Kontak E2E",
        contact_phone: "081234567890",
        players: [
          { full_name: "Pemain Satu", jersey_number: "7" },
          { full_name: "Pemain Dua", jersey_number: "9" },
        ],
      },
    });

    // The payload carries the payment alongside the team, so the team is nested.
    const { team } = await this.unwrap<{ team: Team }>(res, `Daftar tim ${name}`);
    return team;
  }

  async setTeamStatus(
    token: string,
    orgId: string,
    eventId: string,
    teamId: string,
    status: "approved" | "rejected",
  ): Promise<void> {
    const res = await this.request.patch(
      `${API_URL}/organizations/${orgId}/events/${eventId}/registrations/${teamId}`,
      { headers: this.auth(token), data: { status } },
    );
    await this.unwrap(res, `Set status tim ${status}`);
  }

  /**
   * N approved teams — the precondition for generating a schedule (§5.3).
   *
   * Entered through the organizer's own offline-registration endpoint: it lands
   * teams straight in `approved`, which is all these tests need, and it skips the
   * participant account each team would otherwise have to be filed under.
   */
  async approvedTeams(token: string, org: Org, event: Event, count: number): Promise<Team[]> {
    const teams: Team[] = [];
    for (let i = 0; i < count; i++) {
      teams.push(await this.addTeamManually(token, org.id, event, `Tim ${String.fromCharCode(65 + i)}`));
    }
    return teams;
  }

  /** Offline registration (organizer types the team in): approved + settled on arrival. */
  async addTeamManually(
    token: string,
    orgId: string,
    event: Event,
    name = unique("Tim Offline"),
  ): Promise<Team> {
    const res = await this.request.post(`${API_URL}/organizations/${orgId}/events/${event.id}/registrations`, {
      headers: this.auth(token),
      data: {
        // Offline entries pick a category too; the fixture event has exactly one.
        category_id: event.categories[0].id,
        name,
        contact_name: "Kontak Offline",
        contact_phone: "081200000000",
        // A position is a key from the sport's master (sport_positions), not free text.
        players: [{ full_name: "Pemain Offline", jersey_number: "1", position: "goalkeeper" }],
      },
    });
    return this.unwrap<Team>(res, `Tambah tim manual ${name}`);
  }

  // ---- Landing content ----

  /**
   * Deletes landing content whose `field` contains `marker`.
   *
   * Landing content is global: unlike an org or an event, a leftover FAQ or
   * testimonial shows up on the dev landing page for whoever opens it next. A
   * spec that creates one is responsible for taking it away again.
   */
  async purgeLandingContent(
    adminToken: string,
    resource: "faqs" | "testimonials",
    field: "question" | "name",
    marker: string,
  ): Promise<void> {
    const res = await this.request.get(`${API_URL}/admin/${resource}`, { headers: this.auth(adminToken) });
    const rows = await this.unwrap<Array<Record<string, string>>>(res, `Daftar ${resource}`);

    for (const row of rows.filter((r) => r[field]?.includes(marker))) {
      await this.request.delete(`${API_URL}/admin/${resource}/${row.id}`, { headers: this.auth(adminToken) });
    }
  }

  // ---- Platform rails & the platform's own bank account ----

  /**
   * The payment-gateway kill switch.
   *
   * Platform-wide with no per-org override, so this is the one piece of state
   * the suite cannot isolate: turning it off reroutes payments for every spec
   * running beside it. Only the `@gateway-off` specs may call it, and only
   * while the run is serialized — see e2e/README.md.
   */
  async setGateway(adminToken: string, enabled: boolean): Promise<void> {
    // `sometimes` on every rule, so one key alone is a valid payload — the
    // payout rules keep whatever the developer has set them to.
    const res = await this.request.put(`${API_URL}/admin/settings`, {
      headers: this.auth(adminToken),
      data: { payment_gateway_enabled: enabled },
    });
    await this.unwrap(res, `Set payment gateway ${enabled ? "on" : "off"}`);
  }

  async siteSettings(adminToken: string): Promise<Record<string, unknown>> {
    const res = await this.request.get(`${API_URL}/admin/site-settings`, {
      headers: this.auth(adminToken),
    });
    return this.unwrap<Record<string, unknown>>(res, "Baca site settings");
  }

  /**
   * Where an organizer transfers for a plan while the gateway is off.
   *
   * Global like landing content, so a spec that writes it owns putting it back
   * — `siteSettings()` first, restore in teardown. Harmless while the gateway
   * is up (PaymentRails::platformDestination returns before reading it), but a
   * leftover would still show up in the next developer's admin form.
   */
  async updateSiteSettings(adminToken: string, payload: Record<string, unknown>): Promise<void> {
    const res = await this.request.put(`${API_URL}/admin/site-settings`, {
      headers: this.auth(adminToken),
      data: payload,
    });
    await this.unwrap(res, "Simpan site settings");
  }

  // ---- Wallet (§5.7) ----

  /**
   * Money normally arrives through a paid ticket or registration, which means a
   * Midtrans webhook — not reproducible in a browser test. The platform admin's
   * ledger adjustment is the supported way to move money without a gateway, so
   * the wallet spec starts from a funded wallet and tests what the PRD actually
   * describes: the payout, not the earning.
   */
  async creditWallet(
    adminToken: string,
    orgToken: string,
    orgId: string,
    amount: number,
    description = "Saldo uji E2E",
  ): Promise<void> {
    // A wallet row is created on first read, not when the org is. Without this
    // the admin list simply wouldn't contain the brand-new organization.
    await this.walletRules(orgToken, orgId);

    const list = await this.request.get(`${API_URL}/admin/wallets`, { headers: this.auth(adminToken) });
    const wallets = await this.unwrap<Array<{ id: string; organization_id: string }>>(list, "Daftar dompet");

    const wallet = wallets.find((w) => w.organization_id === orgId);
    if (!wallet) throw new Error(`Dompet untuk organisasi ${orgId} tidak ditemukan.`);

    const res = await this.request.post(`${API_URL}/admin/wallets/${wallet.id}/adjust`, {
      headers: this.auth(adminToken),
      data: { amount, description },
    });
    await this.unwrap(res, "Kredit dompet");
  }

  /** The payout rules (minimum, admin fee) are config, not constants — read them. */
  async walletRules(token: string, orgId: string): Promise<{ minimum_withdrawal: number; admin_fee: number }> {
    const res = await this.request.get(`${API_URL}/organizations/${orgId}/wallet`, { headers: this.auth(token) });
    const wallet = await this.unwrap<{ rules: { minimum_withdrawal: number; admin_fee: number } }>(res, "Baca dompet");
    return wallet.rules;
  }
}

/** ISO date (YYYY-MM-DD) offset from today; negative goes into the past. */
export function daysFromNow(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}
