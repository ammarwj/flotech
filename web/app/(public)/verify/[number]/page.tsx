"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { BadgeCheck, ShieldX } from "lucide-react";

import { verifyCertificate } from "@/lib/api/certificates";
import { Nav } from "@/components/landing/nav";
import { Footer } from "@/components/landing/footer";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

const fmtDate = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" }) : "—";

/**
 * What the QR printed on every certificate opens. Anyone holding the paper can
 * check it against the issuer's record — no login, nothing to install.
 */
export default function VerifyCertificatePage() {
  const { number } = useParams<{ number: string }>();

  const query = useQuery({
    queryKey: ["verify-certificate", number],
    queryFn: () => verifyCertificate(number),
    retry: false,
  });

  const cert = query.data;

  return (
    <>
      <Nav />

      <main>
        <section className="section-sm">
          <div className="container" style={{ maxWidth: 640 }}>
            {query.isLoading ? (
              <Skeleton className="h-[320px] w-full rounded-xl" />
            ) : query.isError || !cert ? (
              <Card className="p-8 text-center">
                <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[color-mix(in_srgb,var(--danger)_14%,transparent)] text-[var(--danger)]">
                  <ShieldX className="h-7 w-7" />
                </span>
                <h1 className="mt-5 text-xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
                  Sertifikat tidak ditemukan
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                  Nomor <code className="text-xs">{number}</code> tidak terdaftar. Periksa kembali
                  nomornya, atau pindai ulang QR pada sertifikat.
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
                    Sertifikat terverifikasi
                  </h1>
                  <p className="mt-2 text-sm text-muted-foreground">
                    Dokumen ini terdaftar dan diterbitkan lewat flo-event.
                  </p>
                </div>

                <dl className="mt-8 flex flex-col gap-3 border-t border-border pt-6 text-sm">
                  {[
                    ["Penerima", cert.recipient_name],
                    ["Tim", cert.team_name],
                    ["Penghargaan", cert.award_title],
                    ["Event", cert.event.name],
                    ["Tanggal event", fmtDate(cert.event.start_date)],
                    ["Penyelenggara", cert.organization.name],
                    ["Nomor", cert.certificate_number],
                    ["Diterbitkan", fmtDate(cert.issued_at)],
                  ]
                    .filter(([, value]) => value)
                    .map(([label, value]) => (
                      <div key={label} className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="text-right font-medium">{value}</dd>
                      </div>
                    ))}
                </dl>

                {cert.organization.slug && (
                  <div className="mt-6 border-t border-border pt-5 text-center">
                    <Link
                      href={`/${cert.organization.slug}`}
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
