import Link from "next/link";
import { Trophy, Users, Ticket, Award, Plus, ArrowRight } from "lucide-react";

import { PageHeader } from "@/components/shared/page-header";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";

const STATS = [
  { label: "Event Aktif", value: "0", icon: Trophy, color: "var(--brand-600)" },
  {
    label: "Tim Terdaftar",
    value: "0",
    icon: Users,
    color: "var(--sport-volleyball)",
  },
  {
    label: "Tiket Terjual",
    value: "0",
    icon: Ticket,
    color: "var(--sport-futsal)",
  },
  {
    label: "Sertifikat",
    value: "0",
    icon: Award,
    color: "var(--plan-professional)",
  },
];

export default function DashboardPage() {
  return (
    <div>
      <PageHeader
        title="Selamat datang 👋"
        description="Ringkasan aktivitas turnamenmu. Buat event, kelola pendaftaran, dan pantau semuanya dari sini."
        actions={
          <Button asChild>
            <Link href="/dashboard/events/new">
              <Plus className="h-4 w-4" />
              Buat Event
            </Link>
          </Button>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {STATS.map(({ label, value, icon: Icon, color }) => (
          <Card key={label} className="p-5">
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">{label}</span>
              <span
                className="grid h-9 w-9 place-items-center rounded-lg"
                style={{
                  background: `color-mix(in srgb, ${color} 14%, transparent)`,
                  color,
                }}
              >
                <Icon className="h-[18px] w-[18px]" />
              </span>
            </div>
            <div
              className="mt-3 text-3xl font-extrabold"
              style={{ fontFamily: "var(--font-display)" }}
            >
              {value}
            </div>
          </Card>
        ))}
      </div>

      <Card className="mt-6 flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h3
            className="text-base font-bold"
            style={{ fontFamily: "var(--font-display)" }}
          >
            Mulai turnamen pertamamu
          </h3>
          <p className="mt-1 text-sm text-muted-foreground">
            Buat event, atur format, lalu buka pendaftaran tim dalam hitungan
            menit.
          </p>
        </div>
        <Button asChild variant="outline" className="shrink-0">
          <Link href="/dashboard/events">
            Kelola Event
            <ArrowRight className="h-4 w-4" />
          </Link>
        </Button>
      </Card>
    </div>
  );
}
