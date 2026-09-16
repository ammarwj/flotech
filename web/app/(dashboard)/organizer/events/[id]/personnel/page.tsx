"use client";

import { useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ShieldCheck } from "lucide-react";
import { toast } from "sonner";

import { getEvent } from "@/lib/api/events";
import { getEventPersonnel, syncEventPersonnel } from "@/lib/api/personnel";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { SectionHeader } from "@/components/event/section-header";
import {
  PersonnelEditor,
  type PersonnelRow,
} from "@/components/event/personnel-editor";
import type { EventPersonnel } from "@/types/api";

/**
 * Bind to `role_label`, not `role_display`: the latter carries the fallback the
 * card would print, and saving it back would write "Wasit" into a column the
 * organizer deliberately left blank.
 */
function toRow(p: EventPersonnel): PersonnelRow {
  return {
    id: p.id,
    full_name: p.full_name,
    kind: p.kind,
    role_label: p.role_label ?? "",
    email: p.email ?? "",
    has_account: p.has_account,
    photo_url: p.photo_url,
  };
}

export default function EventPersonnelPage() {
  const { orgId } = useActiveOrg();
  const { id: eventId } = useParams<{ id: string }>();
  const qc = useQueryClient();

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const personnelQuery = useQuery({
    queryKey: ["event-personnel", orgId, eventId],
    queryFn: () => getEventPersonnel(orgId!, eventId),
    enabled: !!orgId,
  });

  // The saved list is the editor's starting point, derived rather than copied
  // into state by an effect: an effect that seeds on every `data` identity
  // would also wipe whatever the organizer had typed the moment any background
  // refetch landed. `draft` stays null until the first edit, so a fresh page
  // shows exactly what the server holds.
  const [draft, setDraft] = useState<PersonnelRow[] | null>(null);
  const saved = useMemo(
    () => personnelQuery.data?.map(toRow) ?? [],
    [personnelQuery.data],
  );
  const rows = draft ?? saved;

  const save = useMutation({
    mutationFn: () =>
      syncEventPersonnel(
        orgId!,
        eventId,
        // Send the whole list — the API deletes whatever is left out. Blank
        // titles go as null so the column keeps meaning "no title".
        rows.map((r) => ({
          id: r.id,
          full_name: r.full_name.trim(),
          kind: r.kind,
          role_label: r.role_label.trim() || null,
          // Null, not "": the server reads a blank address as "no login" and
          // unlinks whatever account the row had. An empty string would fail
          // the `email` rule and 422 the whole save over a row nobody meant to
          // give access to.
          email: r.email.trim() || null,
          photo_url: r.photo_url ?? null,
        })),
      ),
    onSuccess: (list) => {
      // The response is the whole saved list, so seed the cache with it and
      // drop the draft: what is on screen goes back to being what the server
      // holds, new ids and all.
      qc.setQueryData(["event-personnel", orgId, eventId], list);
      setDraft(null);
      toast.success("Petugas disimpan");
    },
    onError: (err) => toast.error(parseApiError(err).message),
  });

  const incomplete = rows.some((r) => !r.full_name.trim());
  const uploading = rows.some((r) => r.photo_uploading);

  if (personnelQuery.isLoading) {
    return (
      <div className="grid gap-4">
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Petugas Pertandingan"
        description={
          eventQuery.data?.name ??
          "Wasit dan staf pertandingan event ini — orang-orang yang tidak terdaftar di tim mana pun."
        }
        backHref={`/organizer/events/${eventId}/edit`}
        backLabel="Kelola event"
      />

      <Card>
        <SectionHeader
          icon={ShieldCheck}
          title="Wasit & Staf"
          description="Foto dan jabatan dipakai saat mencetak ID card. Jabatan boleh dikosongkan — kartunya akan tertulis “Wasit” atau “Staf”. Isi email untuk memberi akses login: undangan berisi password sementara dikirim ke alamat itu saat disimpan."
        />
        <CardContent className="grid gap-4">
          <PersonnelEditor personnel={rows} onChange={setDraft} />

          <div className="flex justify-end">
            <Button
              onClick={() => save.mutate()}
              disabled={save.isPending || incomplete || uploading}
            >
              {save.isPending ? "Menyimpan…" : "Simpan petugas"}
            </Button>
          </div>

          {incomplete && (
            <p className="text-right text-xs text-muted-foreground">
              Setiap petugas harus punya nama.
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
