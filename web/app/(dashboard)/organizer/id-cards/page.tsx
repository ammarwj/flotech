"use client";

import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ArrowUpRight, Building2, IdCard, LayoutTemplate, Plus, Trash2 } from "lucide-react";

import { deleteIdCardTemplate, getIdCardTemplates } from "@/lib/api/id-cards";
import { getEvents } from "@/lib/api/events";
import { anyEventAllows } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";

const DESCRIPTION = "Cetak kartu identitas pemain, wasit, dan staf dari desainmu sendiri.";

export default function IdCardsPage() {
  const queryClient = useQueryClient();
  const { orgId, hasNoOrg, isLoading: orgLoading } = useActiveOrg();

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });

  // Templates are org-scoped rows reused across events, so the page opens as
  // soon as *any* event carries the entitlement — the frontend mirror of
  // PlanGate::orgAllows(). Printing a particular event's cards stays event-keyed.
  const enabled = anyEventAllows(eventsQuery.data, "id_card_generator");

  const templatesQuery = useQuery({
    queryKey: ["id-card-templates", orgId],
    queryFn: () => getIdCardTemplates(orgId!),
    enabled: !!orgId && enabled,
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteIdCardTemplate(orgId!, id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["id-card-templates", orgId] });
      toast.success("Template dihapus.");
    },
    onError: () => toast.error("Gagal menghapus template."),
  });

  if (orgLoading) {
    return (
      <div>
        <PageHeader title="ID Card" description={DESCRIPTION} />
        <Skeleton className="h-[200px] w-full rounded-xl" />
      </div>
    );
  }

  if (hasNoOrg) {
    return (
      <div>
        <PageHeader title="ID Card" description={DESCRIPTION} />
        <EmptyState
          icon={Building2}
          title="Belum punya organisasi"
          description="Buat organisasi terlebih dahulu untuk memakai generator ID card."
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
        <PageHeader title="ID Card" description={DESCRIPTION} />
        <EmptyState
          icon={IdCard}
          title="Generator ID card belum aktif di paketmu"
          description="Upgrade paketmu untuk mengunggah desain kartu, mengatur posisi foto dan nama, lalu mencetak ratusan kartu sekaligus."
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
        title="ID Card"
        description={DESCRIPTION}
        actions={
          <Button asChild>
            <Link href="/organizer/id-cards/templates/new">
              <Plus className="h-4 w-4" />
              Template baru
            </Link>
          </Button>
        }
      />

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
          description="Unggah desain kartumu, lalu atur posisi foto, nama, dan perannya di atasnya."
          action={
            <Button asChild>
              <Link href="/organizer/id-cards/templates/new">
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
                    <Link href={`/organizer/id-cards/templates/${tpl.id}`}>Edit</Link>
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => remove.mutate(tpl.id)}>
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
