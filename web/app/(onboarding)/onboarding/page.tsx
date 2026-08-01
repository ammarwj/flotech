"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { createOrganization } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { useAuthStore } from "@/stores/auth-store";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card } from "@/components/ui/card";
import { PageHeader } from "@/components/shared/page-header";

/**
 * One step: name the organization.
 *
 * This used to be three — organization, plan, payment — because an org was
 * inert until someone bought a subscription for it. Under per-event billing an
 * organization needs to buy nothing to exist: the plan is chosen when the first
 * event is created, on the page where its caps actually mean something. That
 * also removes the "abandoned checkout" state this page used to have to resume
 * into.
 */
export default function OnboardingPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const [orgName, setOrgName] = useState("");

  useEffect(() => {
    if (!isAuthenticated) router.replace("/login");
  }, [isAuthenticated, router]);

  const { org } = useActiveOrg();

  // Nothing on the API side stops a second organization, but useActiveOrg()
  // only ever reads data[0] — a duplicate would be an invisible ghost. Anyone
  // who already has one has no business here.
  useEffect(() => {
    if (org) router.replace("/organizer");
  }, [org, router]);

  const createOrg = useMutation({
    mutationFn: () => createOrganization({ name: orgName }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["organizations"] });
      toast.success("Organisasi berhasil dibuat!", {
        description: "Buat event pertamamu dan pilih paketnya di sana.",
      });
      router.replace("/organizer/events/new");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuat organisasi.").message),
  });

  return (
    <div>
      <PageHeader
        title="Buat organisasi"
        description="Organisasi adalah ruang kerja untuk semua turnamenmu. Paket dibeli belakangan, per event."
      />

      <Card className="max-w-md p-6">
        <form
          onSubmit={(e) => {
            e.preventDefault();
            createOrg.mutate();
          }}
          className="grid gap-4"
        >
          <div className="grid gap-2">
            <Label htmlFor="org" className="font-semibold">
              Nama organisasi
            </Label>
            <Input
              id="org"
              value={orgName}
              onChange={(e) => setOrgName(e.target.value)}
              placeholder="Jakarta Sports EO"
              required
            />
          </div>
          <Button type="submit" size="lg" disabled={createOrg.isPending || orgName.length < 2}>
            {createOrg.isPending ? "Membuat…" : "Selesai"}
          </Button>
        </form>
      </Card>
    </div>
  );
}
