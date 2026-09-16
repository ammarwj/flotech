"use client";

import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { LayoutTemplate } from "lucide-react";

import { getIdCardTemplates } from "@/lib/api/id-cards";
import { getEvents } from "@/lib/api/events";
import { anyEventAllows } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { TemplateForm } from "@/components/id-card/template-form";
import { PlanFeatureNotice } from "@/components/event/plan-feature-notice";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { Skeleton } from "@/components/ui/skeleton";

export default function EditIdCardTemplatePage() {
  const { id } = useParams<{ id: string }>();
  const { orgId, isLoading: orgLoading } = useActiveOrg();

  const templatesQuery = useQuery({
    queryKey: ["id-card-templates", orgId],
    queryFn: () => getIdCardTemplates(orgId!),
    enabled: !!orgId,
  });

  // Same org-level gate as the create page: the backend gates updates too, so
  // without this a save would come back 403 with no warning. orgAllows() is
  // monotone, so an org that ever ran an event on a plan carrying ID cards
  // keeps its templates editable.
  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });
  const enabled = anyEventAllows(eventsQuery.data, "id_card_generator");

  const template = templatesQuery.data?.find((t) => t.id === id);
  const loading = orgLoading || templatesQuery.isLoading || eventsQuery.isLoading;

  return (
    <div>
      <PageHeader
        title={template ? template.name : "Edit template"}
        description="Geser field untuk mengubah posisinya di atas desain kartumu."
      />

      {loading || !orgId ? (
        <Skeleton className="h-[400px] w-full rounded-xl" />
      ) : !enabled ? (
        <PlanFeatureNotice feature="Generator ID card" />
      ) : template ? (
        <TemplateForm orgId={orgId} template={template} />
      ) : (
        <EmptyState
          icon={LayoutTemplate}
          title="Template tidak ditemukan"
          description="Template ini mungkin sudah dihapus."
        />
      )}
    </div>
  );
}
