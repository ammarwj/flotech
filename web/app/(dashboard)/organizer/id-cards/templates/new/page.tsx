"use client";

import { useQuery } from "@tanstack/react-query";

import { getEvents } from "@/lib/api/events";
import { anyEventAllows } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { TemplateForm } from "@/components/id-card/template-form";
import { PlanFeatureNotice } from "@/components/event/plan-feature-notice";
import { PageHeader } from "@/components/shared/page-header";
import { Skeleton } from "@/components/ui/skeleton";

export default function NewIdCardTemplatePage() {
  const { orgId, isLoading } = useActiveOrg();

  // Templates are org-scoped rows reused across events, so this asks the
  // org-level question — the mirror of PlanGate::orgAllows(). The backend
  // refuses a save the same way, so without this the form would 403 on submit.
  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });
  const enabled = anyEventAllows(eventsQuery.data, "id_card_generator");

  return (
    <div>
      <PageHeader
        title="Template ID card baru"
        description="Unggah desain kartumu, lalu geser foto dan nama ke posisinya."
      />
      {isLoading || !orgId || eventsQuery.isLoading ? (
        <Skeleton className="h-[400px] w-full rounded-xl" />
      ) : !enabled ? (
        <PlanFeatureNotice feature="Generator ID card" />
      ) : (
        <TemplateForm orgId={orgId} />
      )}
    </div>
  );
}
