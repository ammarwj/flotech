import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Building2, Link2, Mail, Phone, Trophy } from "lucide-react";

import { Nav } from "@/components/landing/nav";
import { Footer } from "@/components/landing/footer";
import { OrganizerEvents } from "@/components/organization/organizer-events";
import { filledSocialLinks } from "@/lib/social";
import type { ApiEnvelope, PublicOrganization } from "@/types/api";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

/**
 * This route sits at the site root, so it catches every unknown top-level path.
 * The profile is fetched on the server so an unknown slug answers a real HTTP
 * 404 instead of a soft 200 — the events grid below stays client-side.
 */
async function fetchOrganization(orgSlug: string): Promise<PublicOrganization> {
  const res = await fetch(`${API_URL}/public/organizations/${orgSlug}`, { cache: "no-store" });

  if (res.status === 404) notFound();
  if (!res.ok) throw new Error(`Gagal memuat penyelenggara (${res.status})`);

  const body = (await res.json()) as ApiEnvelope<PublicOrganization>;
  return body.data;
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ orgSlug: string }>;
}): Promise<Metadata> {
  const { orgSlug } = await params;
  const org = await fetchOrganization(orgSlug);

  return {
    title: `${org.name} — flo-event`,
    description: org.description ?? `Event yang diselenggarakan oleh ${org.name}.`,
  };
}

export default async function PublicOrganizationPage({
  params,
}: {
  params: Promise<{ orgSlug: string }>;
}) {
  const { orgSlug } = await params;
  const org = await fetchOrganization(orgSlug);
  const socials = filledSocialLinks(org.social_links);

  return (
    <>
      <Nav />

      <main>
        <section className="section-sm">
          <div className="container">
            {org.banner_url && (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={org.banner_url}
                alt=""
                className="mb-8 aspect-[3/1] w-full rounded-xl border border-border object-cover"
              />
            )}

            <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
              <span className="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-2xl border border-border bg-[var(--tint)] text-[var(--brand-600)]">
                {org.logo_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={org.logo_url} alt="" className="h-full w-full object-cover" />
                ) : (
                  <Building2 className="h-8 w-8" />
                )}
              </span>

              <div className="min-w-0">
                <p className="eyebrow">Penyelenggara</p>
                <h1 className="section-title">{org.name}</h1>
                {org.description && <p className="section-sub">{org.description}</p>}

                <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted-foreground">
                  <span className="inline-flex items-center gap-1.5">
                    <Trophy className="h-4 w-4" />
                    {org.published_events_count} event
                  </span>
                  {org.contact_email && (
                    <a
                      href={`mailto:${org.contact_email}`}
                      className="inline-flex items-center gap-1.5 hover:text-[var(--brand-600)]"
                    >
                      <Mail className="h-4 w-4" />
                      {org.contact_email}
                    </a>
                  )}
                  {org.contact_phone && (
                    <a
                      href={`tel:${org.contact_phone}`}
                      className="inline-flex items-center gap-1.5 hover:text-[var(--brand-600)]"
                    >
                      <Phone className="h-4 w-4" />
                      {org.contact_phone}
                    </a>
                  )}
                </div>

                {socials.length > 0 && (
                  <div className="mt-3 flex flex-wrap gap-2">
                    {socials.map((s) => (
                      <a
                        key={s.key}
                        href={s.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-1 text-xs font-medium text-[var(--text-2)] transition-colors hover:border-[var(--border-strong)] hover:text-[var(--brand-600)]"
                      >
                        <Link2 className="h-3.5 w-3.5" />
                        {s.label}
                      </a>
                    ))}
                  </div>
                )}
              </div>
            </div>

            <h2 className="mt-12 text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Event
            </h2>

            <div className="mt-5">
              <OrganizerEvents orgSlug={orgSlug} />
            </div>
          </div>
        </section>
      </main>

      <Footer />
    </>
  );
}
