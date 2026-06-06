"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation } from "@tanstack/react-query";
import { AxiosError } from "axios";

import { registerTeam, signUpload, type RegisterTeamPayload } from "@/lib/api/events";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

type PlayerRow = { full_name: string; jersey_number: string; position: string };
type DocRow = { file_name: string; file_url: string };

export default function RegisterTeamPage() {
  const params = useParams<{ orgSlug: string; eventSlug: string }>();

  const [team, setTeam] = useState({ name: "", city: "", jersey_color: "", contact_name: "", contact_phone: "" });
  const [players, setPlayers] = useState<PlayerRow[]>([{ full_name: "", jersey_number: "", position: "" }]);
  const [docs, setDocs] = useState<DocRow[]>([]);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => {
      const payload: RegisterTeamPayload = {
        ...team,
        players: players
          .filter((p) => p.full_name.trim())
          .map((p) => ({ full_name: p.full_name, jersey_number: p.jersey_number, position: p.position })),
        documents: docs.map((d) => ({ file_url: d.file_url, file_name: d.file_name })),
      };
      return registerTeam(params.orgSlug, params.eventSlug, payload);
    },
    onError: (err) =>
      setError(err instanceof AxiosError ? (err.response?.data?.message ?? "Gagal mendaftar") : "Gagal mendaftar"),
  });

  const handleFiles = async (files: FileList | null) => {
    if (!files) return;
    setUploading(true);
    setError(null);
    try {
      for (const file of Array.from(files)) {
        const signed = await signUpload(file.name, file.type);
        if (signed.upload_url) {
          await fetch(signed.upload_url, { method: "PUT", body: file, headers: { "Content-Type": file.type } });
        }
        setDocs((d) => [...d, { file_name: file.name, file_url: signed.file_url }]);
      }
    } catch {
      setError("Gagal mengunggah dokumen.");
    } finally {
      setUploading(false);
    }
  };

  if (mutation.isSuccess) {
    return (
      <div className="container" style={{ paddingBlock: 80, maxWidth: 560, textAlign: "center" }}>
        <h1 className="section-title">Pendaftaran terkirim 🎉</h1>
        <p className="section-sub">
          Tim <b>{team.name}</b> berhasil didaftarkan dan menunggu persetujuan penyelenggara.
        </p>
        <Button asChild className="mt-6">
          <Link href={`/${params.orgSlug}/${params.eventSlug}`}>Kembali ke halaman event</Link>
        </Button>
      </div>
    );
  }

  return (
    <div className="container" style={{ paddingBlock: 56, maxWidth: 720 }}>
      <Link href={`/${params.orgSlug}/${params.eventSlug}`} className="text-sm text-muted-foreground hover:text-foreground">
        ← Kembali ke halaman event
      </Link>
      <h1 className="mt-2 mb-6 section-title">Daftar Tim</h1>

      {error && <p className="mb-4 text-sm text-destructive">{error}</p>}

      <form
        onSubmit={(e) => {
          e.preventDefault();
          setError(null);
          mutation.mutate();
        }}
        className="grid gap-6"
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Nama tim" required value={team.name} onChange={(v) => setTeam({ ...team, name: v })} />
          <Field label="Kota" value={team.city} onChange={(v) => setTeam({ ...team, city: v })} />
          <Field label="Nama kontak" required value={team.contact_name} onChange={(v) => setTeam({ ...team, contact_name: v })} />
          <Field label="No. HP kontak" required value={team.contact_phone} onChange={(v) => setTeam({ ...team, contact_phone: v })} />
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <Label>Pemain</Label>
            <Button type="button" size="sm" variant="outline" onClick={() => setPlayers([...players, { full_name: "", jersey_number: "", position: "" }])}>
              + Pemain
            </Button>
          </div>
          <div className="grid gap-2">
            {players.map((p, i) => (
              <div key={i} className="flex gap-2">
                <Input
                  placeholder="Nama pemain"
                  value={p.full_name}
                  onChange={(e) => setPlayers(players.map((x, j) => (j === i ? { ...x, full_name: e.target.value } : x)))}
                />
                <Input
                  className="w-20"
                  placeholder="No."
                  value={p.jersey_number}
                  onChange={(e) => setPlayers(players.map((x, j) => (j === i ? { ...x, jersey_number: e.target.value } : x)))}
                />
                {players.length > 1 && (
                  <Button type="button" size="sm" variant="ghost" onClick={() => setPlayers(players.filter((_, j) => j !== i))}>
                    ✕
                  </Button>
                )}
              </div>
            ))}
          </div>
        </div>

        <div>
          <Label>Dokumen (opsional)</Label>
          <input
            type="file"
            multiple
            className="mt-2 block w-full text-sm text-muted-foreground"
            onChange={(e) => handleFiles(e.target.files)}
          />
          {uploading && <p className="mt-1 text-xs text-muted-foreground">Mengunggah…</p>}
          {docs.length > 0 && (
            <ul className="mt-2 text-sm text-muted-foreground">
              {docs.map((d, i) => (
                <li key={i}>📎 {d.file_name}</li>
              ))}
            </ul>
          )}
        </div>

        <div>
          <Button type="submit" size="lg" disabled={mutation.isPending || uploading}>
            {mutation.isPending ? "Mengirim…" : "Kirim Pendaftaran"}
          </Button>
        </div>
      </form>
    </div>
  );
}

function Field({
  label,
  value,
  onChange,
  required,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  required?: boolean;
}) {
  return (
    <div className="grid gap-2">
      <Label>{label}</Label>
      <Input value={value} onChange={(e) => onChange(e.target.value)} required={required} />
    </div>
  );
}
