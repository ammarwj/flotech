"use client";

import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Wallet } from "lucide-react";

import { getEventDeposits } from "@/lib/api/events";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { DepositTable } from "@/components/event/deposit-table";

export default function EventDepositsPage() {
  const params = useParams<{ id: string }>();
  const { orgId } = useActiveOrg();

  const query = useQuery({
    queryKey: ["deposits", orgId, params.id],
    queryFn: () => getEventDeposits(orgId!, params.id),
    enabled: !!orgId,
  });

  return (
    <div>
      <PageHeader
        title="Monitoring jaminan"
        description="Saldo uang jaminan tiap tim, terpotong otomatis sesuai kartu kuning/merah yang mereka terima di pertandingan yang sudah selesai dan dikonfirmasi."
        backHref="/organizer/events"
        backLabel="Daftar event"
      />

      {query.isPending && (
        <div className="grid gap-3">
          {[0, 1].map((i) => (
            <Skeleton key={i} className="h-12 rounded-xl" />
          ))}
        </div>
      )}

      {query.isError && (
        <p className="text-sm text-[var(--danger)]">Gagal memuat data jaminan.</p>
      )}

      {query.data && !query.data.enabled && (
        <Card className="flex flex-col items-center gap-2 p-10 text-center">
          <Wallet className="h-8 w-8 text-muted-foreground" />
          <p className="font-semibold">Jaminan belum diaktifkan</p>
          <p className="text-sm text-muted-foreground">
            Atur nominal jaminan dan potongan kartu di halaman Edit Event untuk mulai memantau
            saldo tiap tim.
          </p>
        </Card>
      )}

      {query.data && query.data.enabled && (
        <Card className="p-4">
          <DepositTable
            teams={query.data.teams}
            amount={query.data.amount}
            yellowDeduction={query.data.yellow_deduction}
            redDeduction={query.data.red_deduction}
          />
        </Card>
      )}
    </div>
  );
}
