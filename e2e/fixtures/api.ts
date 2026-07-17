import type { APIRequestContext } from "@playwright/test";

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
   * An organization that can actually do something.
   *
   * There is no free tier: an org created without a plan has *no* entitlements
   * (PlanGate::withinLimit denies a planless org outright), so it cannot even
   * create an event — every spec downstream would fail in setup with a 403.
   *
   * The plan is set here rather than through `subscriptions/checkout` because
   * MIDTRANS_SERVER_KEY is populated in api/.env: checkout returns a real Snap
   * redirect and leaves the org *unpaid*, so it would arrange nothing. Buying a
   * plan is not what these specs prove; having one is their precondition.
   */
  async createOrg(token: string, name = unique("EO"), plan: PlanSlug = "pro"): Promise<Org> {
    const res = await this.request.post(`${API_URL}/organizations`, {
      headers: this.auth(token),
      data: { name, plan_id: await this.planId(plan) },
    });
    return this.unwrap<Org>(res, `Buat organisasi ${name}`);
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
   * Deletes every FAQ whose question contains `marker`.
   *
   * Landing content is global: unlike an org or an event, a leftover FAQ shows
   * up on the dev landing page for whoever opens it next. A spec that creates
   * one is responsible for taking it away again.
   */
  async purgeFaqs(adminToken: string, marker: string): Promise<void> {
    const res = await this.request.get(`${API_URL}/admin/faqs`, { headers: this.auth(adminToken) });
    const faqs = await this.unwrap<Array<{ id: string; question: string }>>(res, "Daftar FAQ");

    for (const faq of faqs.filter((f) => f.question.includes(marker))) {
      await this.request.delete(`${API_URL}/admin/faqs/${faq.id}`, { headers: this.auth(adminToken) });
    }
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
