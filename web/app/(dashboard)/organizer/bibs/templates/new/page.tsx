"use client";

import { useQuery } from "@tanstack/react-query";

import { getEvents } from "@/lib/api/events";
import { anyEventAllows } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { BibTemplateForm } from "@/components/bib/template-form";
import { PlanFeatureNotice } from "@/components/event/plan-feature-notice";
import { PageHeader } from "@/components/shared/page-header";
import { Skeleton } from "@/components/ui/skeleton";

export default function NewBibTemplatePage() {
  const { orgId, isLoading } = useActiveOrg();

  // Templates are org-scoped rows reused across seasons, so this asks the
  // org-level question — the mirror of PlanGate::orgAllows(). The backend
  // refuses a save the same way, so without this the form would 403 on submit.
  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });
  const enabled = anyEventAllows(eventsQuery.data, "bib_generator");

  return (
    <div>
      <PageHeader
        title="Template e-BIB baru"
        description="Unggah desain nomor dadamu, lalu geser nomor dan QR ke posisinya."
      />
      {isLoading || !orgId || eventsQuery.isLoading ? (
        <Skeleton className="h-[400px] w-full rounded-xl" />
      ) : !enabled ? (
        <PlanFeatureNotice feature="Generator e-BIB" />
      ) : (
        <BibTemplateForm orgId={orgId} />
      )}
    </div>
  );
}
