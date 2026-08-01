"use client";

import { useQuery } from "@tanstack/react-query";

import { getEvents } from "@/lib/api/events";
import { anyEventAllows } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { TemplateForm } from "@/components/certificate/template-form";
import { PlanFeatureNotice } from "@/components/event/plan-feature-notice";
import { PageHeader } from "@/components/shared/page-header";
import { Skeleton } from "@/components/ui/skeleton";

export default function NewCertificateTemplatePage() {
  const { orgId, isLoading } = useActiveOrg();

  // Templates are org-scoped rows reused across events, so this asks the
  // org-level question — the mirror of PlanGate::orgAllows(). The backend
  // refuses the same way; this page had no gate at all before.
  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });
  const enabled = anyEventAllows(eventsQuery.data, "certificate_generator");

  return (
    <div>
      <PageHeader
        title="Template baru"
        description="Unggah desainmu, lalu geser setiap field ke posisinya."
      />
      {isLoading || !orgId || eventsQuery.isLoading ? (
        <Skeleton className="h-[400px] w-full rounded-xl" />
      ) : !enabled ? (
        <PlanFeatureNotice feature="Generator sertifikat" />
      ) : (
        <TemplateForm orgId={orgId} />
      )}
    </div>
  );
}
