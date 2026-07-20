"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronLeft, X, Loader2, CreditCard, LogOut, Users, FileText, Upload } from "lucide-react";
import { toast } from "sonner";

import {
  getMyTeam,
  signUpload,
  updateMyTeam,
  withdrawMyTeam,
  payRegistration,
  type UpdateMyTeamPayload,
} from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { nameInput } from "@/lib/name";
import { phoneInput } from "@/lib/phone";
import { useConfirm } from "@/components/shared/confirm-provider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { TeamStatusBadge } from "@/components/shared/status-badge";
import { RosterEditor, type PlayerRow } from "@/components/team/roster-editor";

type DocRow = { id?: string; file_name: string; file_url: string };

const LOCKED = ["rejected", "disqualified", "withdrawn"];

export default function ManageTeamPage() {
  const confirm = useConfirm();
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const qc = useQueryClient();

  const query = useQuery({ queryKey: ["my-team", params.id], queryFn: () => getMyTeam(params.id) });
  const team = query.data;
  const editable = team ? !LOCKED.includes(team.status) : false;

  const [info, setInfo] = useState({ name: "", contact_name: "", contact_phone: "" });
  const [players, setPlayers] = useState<PlayerRow[]>([]);
  const [docs, setDocs] = useState<DocRow[]>([]);
  const [uploading, setUploading] = useState(false);

  // Seed the form once the team loads.
  useEffect(() => {
    if (!team) return;
    setInfo({
      name: team.name,
      contact_name: team.contact_name ?? "",
      contact_phone: team.contact_phone ?? "",
    });
    setPlayers(
      (team.players ?? []).map((p) => ({
        id: p.id,
        full_name: p.full_name,
        jersey_number: p.jersey_number ?? "",
        position: p.position ?? "",
        photo_url: p.photo_url ?? null,
      }))
    );
    setDocs(
      (team.documents ?? []).map((d) => ({
        id: d.id,
        file_name: d.file_name ?? "Dokumen",
        file_url: d.file_url,
      }))
    );
  }, [team]);

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["my-team", params.id] });
    qc.invalidateQueries({ queryKey: ["my-teams"] });
  };

  // Files land in storage as they're picked; only their metadata is saved with
  // the rest of the form, so an upload that is never saved leaves no row behind.
  const handleFiles = async (files: FileList | null) => {
    if (!files?.length) return;
    setUploading(true);
    try {
      for (const file of Array.from(files)) {
        const signed = await signUpload(file.name, file.type);
        if (signed.upload_url) {
          await fetch(signed.upload_url, { method: "PUT", body: file, headers: { "Content-Type": file.type } });
        }
        setDocs((d) => [...d, { file_name: file.name, file_url: signed.file_url }]);
      }
    } catch {
      toast.error("Gagal mengunggah dokumen. Coba lagi.");
    } finally {
      setUploading(false);
    }
  };

  const save = useMutation({
    mutationFn: () => {
      const payload: UpdateMyTeamPayload = {
        ...info,
        players: players
          .filter((p) => p.full_name.trim())
          .map((p) => ({
            id: p.id,
            full_name: p.full_name,
            jersey_number: p.jersey_number,
            position: p.position,
            photo_url: p.photo_url,
          })),
        documents: docs.map((d) => ({ id: d.id, file_url: d.file_url, file_name: d.file_name })),
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
            <div className="grid gap-2">
              <Label>Nama tim</Label>
              <Input value={info.name} onChange={(e) => setInfo({ ...info, name: nameInput(e.target.value) })} disabled={!editable} required />
            </div>
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
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex-row items-center justify-between">
            <div>
              <CardTitle className="inline-flex items-center gap-2">
                <Users className="h-4 w-4" /> Daftar Pemain
              </CardTitle>
              <CardDescription>Tambah, ubah, atau hapus pemain di roster.</CardDescription>
              {players.length === 0 && (
                <p className="mt-1 text-sm text-[var(--warning)]">
                  Roster masih kosong — lengkapi sebelum turnamen dimulai.
                </p>
              )}
            </div>
          </CardHeader>
          <CardContent>
            <RosterEditor
              players={players}
              onChange={setPlayers}
              sport={team.event?.sport_type}
              disabled={!editable}
            />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="inline-flex items-center gap-2">
              <FileText className="h-4 w-4" /> Dokumen
            </CardTitle>
            <CardDescription>
              Berkas pendukung (KTP, surat, dll) yang bisa dilewati saat mendaftar.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {editable && (
              <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border bg-[var(--bg-alt)] px-6 py-8 text-center transition-colors hover:border-[var(--brand-500)]">
                {uploading ? (
                  <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                ) : (
                  <Upload className="h-6 w-6 text-muted-foreground" />
                )}
                <span className="text-sm font-medium">
                  {uploading ? "Mengunggah…" : "Klik untuk mengunggah berkas"}
                </span>
                <span className="text-xs text-muted-foreground">PDF, JPG, atau PNG</span>
                <input
                  type="file"
                  multiple
                  className="hidden"
                  disabled={uploading}
                  onChange={(e) => handleFiles(e.target.files)}
                />
              </label>
            )}

            {docs.length > 0 ? (
              <ul className="mt-3 grid gap-2">
                {docs.map((d, i) => (
                  <li
                    key={d.id ?? `new-${i}`}
                    className="flex items-center gap-2 rounded-md border border-border bg-[var(--surface)] px-3 py-2 text-sm"
                  >
                    <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <a
                      href={d.file_url}
                      target="_blank"
                      rel="noreferrer"
                      className="truncate hover:underline"
                    >
                      {d.file_name}
                    </a>
                    {editable && (
                      <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="ml-auto shrink-0 text-muted-foreground"
                        aria-label={`Hapus dokumen ${d.file_name}`}
                        onClick={() => setDocs(docs.filter((_, j) => j !== i))}
                      >
                        <X className="h-4 w-4" />
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              !editable && <p className="text-sm text-muted-foreground">Tidak ada dokumen.</p>
            )}
          </CardContent>
        </Card>

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
            <Button type="submit" size="lg" disabled={save.isPending}>
              {save.isPending ? "Menyimpan…" : "Simpan perubahan"}
            </Button>
          </div>
        )}
      </form>
    </div>
  );
}
