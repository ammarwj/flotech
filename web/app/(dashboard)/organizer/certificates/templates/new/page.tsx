"use client";

import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { TemplateForm } from "@/components/certificate/template-form";
import { PageHeader } from "@/components/shared/page-header";
import { Skeleton } from "@/components/ui/skeleton";

export default function NewCertificateTemplatePage() {
  const { orgId, isLoading } = useActiveOrg();

  return (
    <div>
      <PageHeader
        title="Template baru"
        description="Unggah desainmu, lalu geser setiap field ke posisinya."
      />
      {isLoading || !orgId ? (
        <Skeleton className="h-[400px] w-full rounded-xl" />
      ) : (
        <TemplateForm orgId={orgId} />
      )}
    </div>
  );
}
