"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronLeft, Plus, X, Loader2, CreditCard, LogOut, Users } from "lucide-react";
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
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { TeamStatusBadge } from "@/components/shared/status-badge";

type PlayerRow = { id?: string; full_name: string; jersey_number: string; position: string };

const LOCKED = ["rejected", "disqualified", "withdrawn"];

export default function ManageTeamPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const qc = useQueryClient();

  const query = useQuery({ queryKey: ["my-team", params.id], queryFn: () => getMyTeam(params.id) });
  const team = query.data;
  const editable = team ? !LOCKED.includes(team.status) : false;

  const [info, setInfo] = useState({ name: "", city: "", contact_name: "", contact_phone: "" });
  const [players, setPlayers] = useState<PlayerRow[]>([]);

  // Seed the form once the team loads.
  useEffect(() => {
    if (!team) return;
    setInfo({
      name: team.name,
      city: team.city ?? "",
      contact_name: team.contact_name ?? "",
      contact_phone: team.contact_phone ?? "",
    });
    setPlayers(
      (team.players ?? []).map((p) => ({
        id: p.id,
        full_name: p.full_name,
        jersey_number: p.jersey_number ?? "",
        position: p.position ?? "",
      }))
    );
  }, [team]);

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["my-team", params.id] });
    qc.invalidateQueries({ queryKey: ["my-teams"] });
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
          })),
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
              <Input value={info.name} onChange={(e) => setInfo({ ...info, name: e.target.value })} disabled={!editable} required />
            </div>
            <div className="grid gap-2">
              <Label>Kota</Label>
              <Input value={info.city} onChange={(e) => setInfo({ ...info, city: e.target.value })} disabled={!editable} />
            </div>
            <div className="grid gap-2">
              <Label>Nama kontak</Label>
              <Input value={info.contact_name} onChange={(e) => setInfo({ ...info, contact_name: e.target.value })} disabled={!editable} />
            </div>
            <div className="grid gap-2">
              <Label>No. HP kontak</Label>
              <Input value={info.contact_phone} onChange={(e) => setInfo({ ...info, contact_phone: e.target.value })} disabled={!editable} />
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
            </div>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={!editable}
              onClick={() => setPlayers([...players, { full_name: "", jersey_number: "", position: "" }])}
            >
              <Plus className="h-4 w-4" />
              Pemain
            </Button>
          </CardHeader>
          <CardContent className="grid gap-2">
            {players.map((p, i) => (
              <div key={p.id ?? `new-${i}`} className="flex items-center gap-2">
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-[var(--bg-soft)] text-xs font-semibold text-muted-foreground">
                  {i + 1}
                </span>
                <Input
                  placeholder="Nama pemain"
                  value={p.full_name}
                  disabled={!editable}
                  onChange={(e) => setPlayers(players.map((x, j) => (j === i ? { ...x, full_name: e.target.value } : x)))}
                />
                <Input
                  className="w-20"
                  placeholder="No."
                  value={p.jersey_number}
                  disabled={!editable}
                  onChange={(e) => setPlayers(players.map((x, j) => (j === i ? { ...x, jersey_number: e.target.value } : x)))}
                />
                {editable && players.length > 1 && (
                  <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="shrink-0 text-muted-foreground"
                    onClick={() => setPlayers(players.filter((_, j) => j !== i))}
                  >
                    <X className="h-4 w-4" />
                  </Button>
                )}
              </div>
            ))}
          </CardContent>
        </Card>

        {editable && (
          <div className="flex flex-wrap items-center justify-between gap-3">
            <Button
              type="button"
              variant="ghost"
              className="text-[var(--danger)] hover:text-[var(--danger)]"
              onClick={() => {
                if (confirm("Tarik tim ini dari turnamen? Tindakan ini tidak bisa dibatalkan.")) {
                  withdraw.mutate();
                }
              }}
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
