"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronLeft, Loader2, CreditCard, LogOut, Users, UserCog, FileText } from "lucide-react";
import { toast } from "sonner";

import {
  getMyTeam,
  updateMyTeam,
  withdrawMyTeam,
  payRegistration,
  type UpdateMyTeamPayload,
} from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { nameInput } from "@/lib/name";
import { phoneInput } from "@/lib/phone";
import {
  hasIncompletePlayer,
  missingFor,
  schemaOf,
  type CustomFieldAnswers,
  type DocumentRow,
} from "@/lib/registration-form";
import { useConfirm } from "@/components/shared/confirm-provider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { TeamStatusBadge } from "@/components/shared/status-badge";
import { RosterEditor, fixedRoster, type PlayerRow } from "@/components/team/roster-editor";
import { OfficialEditor, type OfficialRow } from "@/components/team/official-editor";
import { CustomFieldEditor } from "@/components/team/custom-field-editor";
import { DocumentUploadFields } from "@/components/team/document-upload-field";

const LOCKED = ["rejected", "disqualified", "withdrawn"];

export default function ManageTeamPage() {
  const confirm = useConfirm();
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const qc = useQueryClient();

  const query = useQuery({ queryKey: ["my-team", params.id], queryFn: () => getMyTeam(params.id) });
  const team = query.data;
  const editable = team ? !LOCKED.includes(team.status) : false;
  // Tunggal/ganda: the entry is its players, so the roster is a fixed pair of
  // slots and there is no team name to type.
  const rosterSize = team?.category?.roster_size ?? null;
  const isFixed = typeof rosterSize === "number";
  const schema = schemaOf(team?.event);

  const [info, setInfo] = useState({ name: "", contact_name: "", contact_phone: "" });
  const [teamFields, setTeamFields] = useState<CustomFieldAnswers>({});
  const [players, setPlayers] = useState<PlayerRow[]>([]);
  const [officials, setOfficials] = useState<OfficialRow[]>([]);
  const [docs, setDocs] = useState<DocumentRow[]>([]);
  const [uploading, setUploading] = useState(false);

  const teamMissing = missingFor(schema.team_fields, schema.team_documents, teamFields, docs);

  // Seed the form once the team loads.
  useEffect(() => {
    if (!team) return;
    setInfo({
      name: team.name,
      contact_name: team.contact_name ?? "",
      contact_phone: team.contact_phone ?? "",
    });
    setTeamFields(team.custom_fields ?? {});
    setPlayers(
      (team.players ?? []).map((p) => ({
        id: p.id,
        full_name: p.full_name,
        jersey_number: p.jersey_number ?? "",
        position: p.position ?? "",
        photo_url: p.photo_url ?? null,
        custom_fields: p.custom_fields ?? {},
        documents: p.documents ?? [],
      }))
    );
    setOfficials(
      (team.officials ?? []).map((o) => ({
        id: o.id,
        full_name: o.full_name,
        role: o.role ?? "",
        photo_url: o.photo_url ?? null,
      }))
    );
    // Documents predating this feature carry no type. Once the event defines
    // slots the server refuses them, and the schema-driven UI has nowhere to
    // show them — so they'd sit here invisibly and 422 every save. Dropping them
    // is what saving does anyway; when the event defines no slots they pass
    // through untouched, as they always have.
    const slots = schemaOf(team.event).team_documents;
    setDocs(
      (team.documents ?? [])
        .filter((d) => slots.length === 0 || slots.some((s) => s.key === d.document_type))
        .map((d) => ({
          id: d.id,
          document_type: d.document_type,
          file_name: d.file_name ?? "Dokumen",
          file_url: d.file_url,
        }))
    );
  }, [team]);

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["my-team", params.id] });
    qc.invalidateQueries({ queryKey: ["my-teams"] });
  };

  const save = useMutation({
    mutationFn: () => {
      const roster = isFixed ? fixedRoster(players, rosterSize) : players;
      const payload: UpdateMyTeamPayload = {
        ...info,
        // A tunggal/ganda entry is its players — the backend renames it from the
        // roster, so what travels here is only a placeholder.
        name: isFixed ? roster.map((p) => p.full_name.trim()).join(" / ") : info.name,
        custom_fields: teamFields,
        players: roster
          .filter((p) => p.full_name.trim())
          .map((p) => ({
            id: p.id,
            full_name: p.full_name,
            jersey_number: p.jersey_number,
            position: p.position,
            photo_url: p.photo_url,
            custom_fields: p.custom_fields ?? {},
            documents: p.documents ?? [],
          })),
        officials: officials
          .filter((o) => o.full_name.trim())
          .map((o) => ({
            id: o.id,
            full_name: o.full_name,
            role: o.role || null,
            photo_url: o.photo_url,
          })),
        documents: docs,
      };
      return updateMyTeam(params.id, payload);
    },
    onSuccess: () => {
      toast.success("Data tim diperbarui.");
      refresh();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan perubahan.").message),
  });

  const withdraw = useMutation({
    mutationFn: () => withdrawMyTeam(params.id),
    onSuccess: () => {
      toast.success("Tim ditarik dari turnamen.");
      refresh();
      router.push("/participant");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menarik tim.").message),
  });

  const pay = useMutation({
    mutationFn: () => payRegistration(params.id),
    onSuccess: (res) => {
      if (!res.mock && res.redirect_url) {
        window.location.href = res.redirect_url;
        return;
      }
      toast.success("Pembayaran berhasil.");
      refresh();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memproses pembayaran.").message),
  });

  if (query.isLoading) {
    return (
      <div className="grid gap-3">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-[200px] w-full rounded-xl" />
      </div>
    );
  }

  if (query.isError || !team) {
    return (
      <div className="py-16 text-center">
        <p className="text-muted-foreground">Tim tidak ditemukan.</p>
        <Button asChild variant="outline" className="mt-4">
          <Link href="/participant">Kembali</Link>
        </Button>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-3xl">
      <Link
        href="/participant"
        className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <ChevronLeft className="h-4 w-4" />
        Kembali ke Tim Saya
      </Link>

      <div className="mb-6 mt-3 flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
          {team.name}
        </h1>
        <TeamStatusBadge status={team.status} />
        {team.category && <Badge variant="neutral">{team.category.name}</Badge>}
      </div>
      <p className="mb-6 text-sm text-muted-foreground">{team.event?.name}</p>

      {/* Payment notice */}
      {team.payment_status === "unpaid" && (
        <Card className="mb-6 border-[color-mix(in_srgb,var(--danger)_40%,transparent)]">
          <CardContent className="flex flex-wrap items-center justify-between gap-4 p-5">
            <div className="flex items-start gap-3">
              <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[color-mix(in_srgb,var(--danger)_12%,transparent)] text-[var(--danger)]">
                <CreditCard className="h-5 w-5" />
              </span>
              <div>
                <h3 className="font-semibold">Biaya pendaftaran belum dibayar</h3>
                <p className="mt-0.5 text-sm text-muted-foreground">
                  Bayar {rupiah(team.payment_amount)} untuk menyelesaikan pendaftaran.
                </p>
              </div>
            </div>
            <Button onClick={() => pay.mutate()} disabled={pay.isPending}>
              {pay.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <CreditCard className="h-4 w-4" />}
              Bayar sekarang
            </Button>
          </CardContent>
        </Card>
      )}

      {!editable && (
        <p className="mb-6 rounded-md border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm text-muted-foreground">
          Tim ini berstatus <b>{team.status}</b> dan tidak dapat diubah lagi.
        </p>
      )}

      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
        className="grid gap-5"
      >
        <Card>
          <CardHeader>
            <CardTitle>Informasi Tim</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            {!isFixed && (
              <div className="grid gap-2">
                <Label>Nama tim</Label>
                <Input value={info.name} onChange={(e) => setInfo({ ...info, name: nameInput(e.target.value) })} disabled={!editable} required />
              </div>
            )}
            <div className="grid gap-2">
              <Label>Nama kontak</Label>
              <Input value={info.contact_name} onChange={(e) => setInfo({ ...info, contact_name: e.target.value })} disabled={!editable} />
            </div>
            <div className="grid gap-2">
              <Label>No. HP kontak</Label>
              <Input
                value={info.contact_phone}
                inputMode="tel"
                onChange={(e) => setInfo({ ...info, contact_phone: phoneInput(e.target.value) })}
                disabled={!editable}
              />
            </div>
            <div className="sm:col-span-2">
              <CustomFieldEditor
                fields={schema.team_fields}
                value={teamFields}
                onChange={setTeamFields}
                disabled={!editable}
                idPrefix="team"
              />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex-row items-center justify-between">
            <div>
              <CardTitle className="inline-flex items-center gap-2">
                <Users className="h-4 w-4" />{" "}
                {isFixed ? (rosterSize === 1 ? "Data Peserta" : "Data Pasangan") : "Daftar Pemain"}
              </CardTitle>
              <CardDescription>
                {isFixed
                  ? "Nama pendaftaran diambil dari sini — mis. “Dimas / Ammar”."
                  : "Tambah, ubah, atau hapus pemain di roster."}
              </CardDescription>
              {players.length === 0 && (
                <p className="mt-1 text-sm text-[var(--warning)]">
                  Roster masih kosong — lengkapi sebelum turnamen dimulai.
                </p>
              )}
            </div>
          </CardHeader>
          <CardContent>
            <RosterEditor
              players={isFixed ? fixedRoster(players, rosterSize) : players}
              onChange={setPlayers}
              sport={team.event?.sport_type}
              disabled={!editable}
              size={rosterSize}
              schema={schema}
              onBusyChange={setUploading}
            />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="inline-flex items-center gap-2">
              <UserCog className="h-4 w-4" /> Pelatih &amp; Ofisial
            </CardTitle>
            <CardDescription>
              Pelatih, manajer, dan ofisial tim. Opsional.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <OfficialEditor
              officials={officials}
              onChange={setOfficials}
              sport={team.event?.sport_type}
              disabled={!editable}
            />
          </CardContent>
        </Card>

        {/* Only what the event asked for. An event defining no team documents
            renders no card at all, rather than an empty dropzone. */}
        {schema.team_documents.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="inline-flex items-center gap-2">
                <FileText className="h-4 w-4" /> Dokumen Tim
              </CardTitle>
              <CardDescription>Berkas yang diminta penyelenggara event ini.</CardDescription>
            </CardHeader>
            <CardContent>
              <DocumentUploadFields
                slots={schema.team_documents}
                value={docs}
                onChange={setDocs}
                onBusyChange={setUploading}
                disabled={!editable}
              />
            </CardContent>
          </Card>
        )}

        {editable && teamMissing.length > 0 && (
          <p className="text-sm text-destructive">
            Lengkapi dulu: {teamMissing.join(", ")}.
          </p>
        )}

        {editable && (
          <div className="flex flex-wrap items-center justify-between gap-3">
            <Button
              type="button"
              variant="ghost"
              className="text-[var(--danger)] hover:text-[var(--danger)]"
              onClick={() =>
                void confirm({
                  title: "Tarik tim dari turnamen?",
                  description: "Tim ini keluar dari daftar peserta dan pendaftarannya dibatalkan.",
                  consequences: "Tindakan ini tidak bisa dibatalkan.",
                  confirmLabel: "Tarik tim",
                  tone: "danger",
                  icon: LogOut,
                }).then((ok) => ok && withdraw.mutate())
              }
              disabled={withdraw.isPending}
            >
              <LogOut className="h-4 w-4" />
              Tarik tim
            </Button>
            {/* A half-finished player row is rejected outright by the server, so
                blocking here is what keeps a 422 from costing the whole form. */}
            <Button
              type="submit"
              size="lg"
              disabled={
                save.isPending ||
                uploading ||
                teamMissing.length > 0 ||
                hasIncompletePlayer(schema, players)
              }
            >
              {save.isPending ? "Menyimpan…" : "Simpan perubahan"}
            </Button>
          </div>
        )}
      </form>
    </div>
  );
}
