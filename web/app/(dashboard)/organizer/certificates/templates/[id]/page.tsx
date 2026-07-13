"use client";

import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";

import { getCertificateTemplates } from "@/lib/api/certificates";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { TemplateForm } from "@/components/certificate/template-form";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { Skeleton } from "@/components/ui/skeleton";
import { LayoutTemplate } from "lucide-react";

export default function EditCertificateTemplatePage() {
  const { id } = useParams<{ id: string }>();
  const { orgId, isLoading: orgLoading } = useActiveOrg();

  const templatesQuery = useQuery({
    queryKey: ["certificate-templates", orgId],
    queryFn: () => getCertificateTemplates(orgId!),
    enabled: !!orgId,
  });

  const template = templatesQuery.data?.find((t) => t.id === id);
  const loading = orgLoading || templatesQuery.isLoading;

  return (
    <div>
      <PageHeader
        title={template ? template.name : "Edit template"}
        description="Geser field untuk mengubah posisinya di atas desainmu."
      />

      {loading || !orgId ? (
        <Skeleton className="h-[400px] w-full rounded-xl" />
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
