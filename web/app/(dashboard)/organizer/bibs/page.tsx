"use client";

import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  ArrowUpRight,
  Building2,
  LayoutTemplate,
  Plus,
  Printer,
  Tag,
  Trash2,
} from "lucide-react";

import { deleteBibTemplate, getBibTemplates } from "@/lib/api/bibs";
import { getEvents } from "@/lib/api/events";
import { anyEventAllows } from "@/lib/plan";
import { isRaceEvent } from "@/lib/race";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { useConfirm } from "@/components/shared/confirm-provider";

const DESCRIPTION = "Cetak nomor dada peserta lomba dari desainmu sendiri, lengkap dengan QR check-in.";

export default function BibsPage() {
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const { orgId, hasNoOrg, isLoading: orgLoading } = useActiveOrg();

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });

  // Templates are org-scoped rows reused across seasons, so the page opens as
  // soon as *any* event carries the entitlement — the frontend mirror of
  // PlanGate::orgAllows(). Printing a particular race's bibs stays event-keyed.
  const enabled = anyEventAllows(eventsQuery.data, "bib_generator");

  // The second half of the gate, and it is about the ORG having a race at all
  // rather than about any one event: a template belongs to no event, so this
  // page is only useless to somebody who runs no races. Asked separately from
  // the plan because the two refusals send an organizer to different places.
  const hasRace = (eventsQuery.data ?? []).some(isRaceEvent);

  const templatesQuery = useQuery({
    queryKey: ["bib-templates", orgId],
    queryFn: () => getBibTemplates(orgId!),
    enabled: !!orgId && enabled,
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteBibTemplate(orgId!, id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["bib-templates", orgId] });
      toast.success("Template dihapus.");
    },
    onError: () => toast.error("Gagal menghapus template."),
  });

  if (orgLoading) {
    return (
      <div>
        <PageHeader title="e-BIB" description={DESCRIPTION} />
        <Skeleton className="h-[200px] w-full rounded-xl" />
      </div>
    );
  }

  if (hasNoOrg) {
    return (
      <div>
        <PageHeader title="e-BIB" description={DESCRIPTION} />
        <EmptyState
          icon={Building2}
          title="Belum punya organisasi"
          description="Buat organisasi terlebih dahulu untuk memakai generator e-BIB."
          action={
            <Button asChild>
              <Link href="/onboarding">Buat organisasi</Link>
            </Button>
          }
        />
      </div>
    );
  }

  if (!enabled) {
    return (
      <div>
        <PageHeader title="e-BIB" description={DESCRIPTION} />
        <EmptyState
          icon={Tag}
          title="Generator e-BIB belum aktif di paketmu"
          description="Upgrade paketmu untuk mengunggah desain nomor dada, mengatur posisi nomor dan QR check-in, lalu mencetak ratusan e-BIB sekaligus."
          action={
            <Button asChild>
              <Link href="/organizer/plans">
                <ArrowUpRight className="h-4 w-4" />
                Upgrade paket
              </Link>
            </Button>
          }
        />
      </div>
    );
  }

  const templates = templatesQuery.data;

  return (
    <div>
      <PageHeader
        title="e-BIB"
        description={DESCRIPTION}
        actions={
          <>
            <Button asChild variant="outline">
              <Link href="/organizer/bibs/generate">
                <Printer className="h-4 w-4" />
                Cetak e-BIB
              </Link>
            </Button>
            <Button asChild>
              <Link href="/organizer/bibs/templates/new">
                <Plus className="h-4 w-4" />
                Template baru
              </Link>
            </Button>
          </>
        }
      />

      {/* Said rather than enforced: templates are org-scoped, so laying one out
          before the first race exists is legitimate — and the organizer who
          does would otherwise wonder why nothing can be printed from it. */}
      {!hasRace && !eventsQuery.isLoading && (
        <Card className="mb-5 border-[var(--border-strong)] p-4 text-sm text-muted-foreground">
          Belum ada event lomba (cabang tanpa pertandingan) di organisasimu. Templatenya bisa
          disiapkan sekarang, tapi e-BIB baru bisa dicetak setelah ada event lomba dengan peserta
          yang sudah lunas.
        </Card>
      )}

      {templatesQuery.isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[220px] w-full rounded-xl" />
          ))}
        </div>
      ) : templates?.length === 0 ? (
        <EmptyState
          icon={LayoutTemplate}
          title="Belum ada template"
          description="Unggah desain nomor dadamu, lalu atur posisi nomor BIB, nama, dan QR check-in di atasnya."
          action={
            <Button asChild>
              <Link href="/organizer/bibs/templates/new">
                <Plus className="h-4 w-4" />
                Buat template
              </Link>
            </Button>
          }
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {templates?.map((tpl) => (
            <Card key={tpl.id} className="overflow-hidden">
              <div
                className="bg-[var(--bg-soft)]"
                style={{ aspectRatio: `${tpl.width_mm} / ${tpl.height_mm}` }}
              >
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={tpl.background_url}
                  alt=""
                  className="h-full w-full"
                  style={{ objectFit: "fill" }}
                />
              </div>
              <div className="flex items-center justify-between gap-2 p-4">
                <div className="min-w-0">
                  <p className="truncate font-semibold">{tpl.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {tpl.fields.length} field · {tpl.width_mm} × {tpl.height_mm} mm
                  </p>
                </div>
                <div className="flex gap-1">
                  <Button asChild size="sm" variant="outline">
                    <Link href={`/organizer/bibs/templates/${tpl.id}`}>Edit</Link>
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={async () => {
                      const ok = await confirm({
                        title: "Hapus template ini?",
                        description: `Template "${tpl.name}" akan dihapus permanen. e-BIB yang sudah dicetak tidak terpengaruh.`,
                        confirmLabel: "Hapus template",
                        tone: "danger",
                      });
                      if (ok) remove.mutate(tpl.id);
                    }}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
