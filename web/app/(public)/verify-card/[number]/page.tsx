"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { BadgeCheck, ShieldX } from "lucide-react";

import { verifyIdCard } from "@/lib/api/id-cards";
import { Nav } from "@/components/landing/nav";
import { Footer } from "@/components/landing/footer";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

const fmtDate = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" }) : "—";

/**
 * What the QR printed on every ID card opens. A marshal at the gate can check
 * the plastic in their hand against the issuer's record — no login, nothing to
 * install.
 *
 * Its own route rather than a branch inside /verify/[number]: the two prove
 * different documents and print different rows, and one page deciding which
 * kind of number it was handed would be a second reader of that rule.
 *
 * There is deliberately no photo here. The card itself carries it, and this
 * page takes no authentication — publishing the headshot would make a card
 * number a way to pull participants' photos.
 */
export default function VerifyIdCardPage() {
  const { number } = useParams<{ number: string }>();

  const query = useQuery({
    queryKey: ["verify-id-card", number],
    queryFn: () => verifyIdCard(number),
    retry: false,
  });

  const card = query.data;

  return (
    <>
      <Nav />

      <main>
        <section className="section-sm">
          <div className="container" style={{ maxWidth: 640 }}>
            {query.isLoading ? (
              <Skeleton className="h-[320px] w-full rounded-xl" />
            ) : query.isError || !card ? (
              <Card className="p-8 text-center">
                <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[color-mix(in_srgb,var(--danger)_14%,transparent)] text-[var(--danger)]">
                  <ShieldX className="h-7 w-7" />
                </span>
                <h1 className="mt-5 text-xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
                  Kartu tidak ditemukan
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                  Nomor <code className="text-xs">{number}</code> tidak terdaftar. Periksa kembali
                  nomornya, atau pindai ulang QR pada kartu.
                </p>
              </Card>
            ) : (
              <Card className="p-8">
                <div className="text-center">
                  <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[color-mix(in_srgb,var(--success)_14%,transparent)] text-[var(--success)]">
                    <BadgeCheck className="h-7 w-7" />
                  </span>
                  <h1
                    className="mt-5 text-xl font-bold"
                    style={{ fontFamily: "var(--font-display)" }}
                  >
                    Kartu terverifikasi
                  </h1>
                  <p className="mt-2 text-sm text-muted-foreground">
                    Kartu ini terdaftar dan diterbitkan lewat flo-event.
                  </p>
                </div>

                <dl className="mt-8 flex flex-col gap-3 border-t border-border pt-6 text-sm">
                  {[
                    ["Nama", card.recipient_name],
                    ["Sebagai", card.role_label],
                    ["Tim", card.team_name],
                    ["Event", card.event.name],
                    ["Tanggal event", fmtDate(card.event.start_date)],
                    ["Penyelenggara", card.organization.name],
                    ["Nomor kartu", card.card_number],
                    ["Diterbitkan", fmtDate(card.issued_at)],
                  ]
                    .filter(([, value]) => value)
                    .map(([label, value]) => (
                      <div key={label} className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="text-right font-medium">{value}</dd>
                      </div>
                    ))}
                </dl>

                <p className="mt-6 border-t border-border pt-5 text-xs text-muted-foreground">
                  Halaman ini memastikan kartu diterbitkan oleh penyelenggara, bukan identitas
                  orang yang memegangnya — cocokkan foto pada kartu dengan orang di depanmu.
                </p>

                {card.organization.slug && (
                  <div className="mt-4 text-center">
                    <Link
                      href={`/${card.organization.slug}`}
                      className="text-sm font-medium text-[var(--brand-600)] hover:underline"
                    >
                      Lihat penyelenggara →
                    </Link>
                  </div>
                )}
              </Card>
            )}
          </div>
        </section>
      </main>

      <Footer />
    </>
  );
}
