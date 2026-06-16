"use client";

import { Suspense, useRef, useState } from "react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useMutation } from "@tanstack/react-query";
import { AxiosError } from "axios";
import {
  ChevronLeft,
  Plus,
  X,
  Upload,
  FileText,
  CheckCircle2,
  AlertCircle,
  Loader2,
  ImagePlus,
} from "lucide-react";

import { registerTeam, signUpload, uploadImage, type RegisterTeamPayload } from "@/lib/api/events";
import { compressToWebp } from "@/lib/image";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";

type PlayerRow = { full_name: string; jersey_number: string; position: string };
type DocRow = { file_name: string; file_url: string };

function RegisterTeamPage() {
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  const searchParams = useSearchParams();

  // Returned from Midtrans after a successful payment (finish redirect URL).
  const paidStatus = searchParams.get("transaction_status");
  const paidReturn =
    !!searchParams.get("order_id") &&
    (paidStatus === "settlement" || paidStatus === "capture" || searchParams.get("status") === "success");

  const [team, setTeam] = useState({ name: "", city: "", jersey_color: "", logo_url: "", contact_name: "", contact_phone: "" });
  const [players, setPlayers] = useState<PlayerRow[]>([{ full_name: "", jersey_number: "", position: "" }]);
  const [docs, setDocs] = useState<DocRow[]>([]);
  const [uploading, setUploading] = useState(false);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [logoUploading, setLogoUploading] = useState(false);
  const logoInputRef = useRef<HTMLInputElement>(null);
  const [error, setError] = useState<string | null>(null);

  // Local blob for instant preview (dev R2 returns a non-renderable mock:// URL).
  const logoShown = logoPreview ?? (team.logo_url && /^https?:\/\//.test(team.logo_url) ? team.logo_url : null);

  const handleLogo = async (file?: File | null) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      setError("Logo harus berupa gambar.");
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      setError("Ukuran logo maksimal 2 MB.");
      return;
    }
    setLogoUploading(true);
    setError(null);
    try {
      const webp = await compressToWebp(file, { maxDim: 512, quality: 0.85 });
      setLogoPreview(URL.createObjectURL(webp));
      const url = await uploadImage(webp, "teams");
      setTeam((t) => ({ ...t, logo_url: url }));
    } catch {
      setError("Gagal mengunggah logo.");
      setLogoPreview(null);
    } finally {
      setLogoUploading(false);
    }
  };

  const clearLogo = () => {
    setLogoPreview(null);
    setTeam((t) => ({ ...t, logo_url: "" }));
    if (logoInputRef.current) logoInputRef.current.value = "";
  };

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
    onSuccess: (res) => {
      // Paid registration fee → hand off to Midtrans before showing success.
      if (!res.mock && res.redirect_url) {
        window.location.href = res.redirect_url;
      }
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

  // Paid registration: the order was created and we're handing off to Midtrans.
  // Show a "redirecting" state instead of a misleading success card.
  const redirectingToPay = mutation.isSuccess && !mutation.data?.mock && !!mutation.data?.redirect_url;

  if (redirectingToPay) {
    return (
      <div className="container" style={{ paddingBlock: 80, maxWidth: 560 }}>
        <Card className="p-8 text-center sm:p-10">
          <div className="mx-auto mb-5 grid h-14 w-14 place-items-center text-[var(--primary)]">
            <Loader2 className="h-8 w-8 animate-spin" />
          </div>
          <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Mengalihkan ke pembayaran…
          </h1>
          <p className="mt-2 text-muted-foreground">
            Mohon tunggu, kamu sedang diarahkan ke halaman pembayaran. Jangan tutup halaman ini.
          </p>
        </Card>
      </div>
    );
  }

  if (paidReturn || mutation.isSuccess) {
    return (
      <div className="container" style={{ paddingBlock: 80, maxWidth: 560 }}>
        <Card className="p-8 text-center sm:p-10">
          <div className="mx-auto mb-5 grid h-14 w-14 place-items-center rounded-full bg-[color-mix(in_srgb,var(--success)_14%,transparent)] text-[var(--success)]">
            <CheckCircle2 className="h-7 w-7" />
          </div>
          <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {paidReturn ? "Pembayaran berhasil 🎉" : "Pendaftaran terkirim 🎉"}
          </h1>
          <p className="mt-2 text-muted-foreground">
            {paidReturn ? (
              <>
                Pembayaran biaya pendaftaran diterima. Pendaftaran tim sedang menunggu persetujuan penyelenggara.
              </>
            ) : (
              <>
                Tim <b className="text-foreground">{team.name}</b> berhasil didaftarkan dan menunggu persetujuan
                penyelenggara.
              </>
            )}
          </p>
          <Button asChild size="lg" className="mt-6">
            <Link href={`/${params.orgSlug}/${params.eventSlug}`}>Kembali ke halaman event</Link>
          </Button>
        </Card>
      </div>
    );
  }

  return (
    <div className="container" style={{ paddingBlock: 48, maxWidth: 720 }}>
      <Link
        href={`/${params.orgSlug}/${params.eventSlug}`}
        className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <ChevronLeft className="h-4 w-4" />
        Kembali ke halaman event
      </Link>
      <h1 className="mb-1 mt-3 text-2xl font-bold sm:text-3xl" style={{ fontFamily: "var(--font-display)" }}>
        Daftar Tim
      </h1>
      <p className="mb-6 text-muted-foreground">Lengkapi data tim untuk mendaftar ke turnamen.</p>

      {error && (
        <div className="mb-5 flex items-center gap-2 rounded-md border border-[color-mix(in_srgb,var(--danger)_40%,transparent)] bg-[color-mix(in_srgb,var(--danger)_10%,transparent)] px-4 py-3 text-sm text-[var(--danger)]">
          <AlertCircle className="h-4 w-4 shrink-0" />
          {error}
        </div>
      )}

      <form
        onSubmit={(e) => {
          e.preventDefault();
          setError(null);
          mutation.mutate();
        }}
        className="grid gap-5"
      >
        <Card>
          <CardHeader>
            <CardTitle>Informasi Tim</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            <div className="flex items-center gap-4 sm:col-span-2">
              <input
                ref={logoInputRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => handleLogo(e.target.files?.[0])}
              />
              {logoShown ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={logoShown}
                  alt="Logo tim"
                  className="h-16 w-16 shrink-0 rounded-lg border border-border object-cover"
                />
              ) : (
                <div className="grid h-16 w-16 shrink-0 place-items-center rounded-lg border border-dashed border-border bg-[var(--bg-soft)] text-muted-foreground">
                  {logoUploading ? <Loader2 className="h-5 w-5 animate-spin" /> : <ImagePlus className="h-6 w-6" />}
                </div>
              )}
              <div className="grid gap-1.5">
                <span className="text-sm font-semibold">
                  Logo tim <span className="font-normal text-muted-foreground">(opsional)</span>
                </span>
                <div className="flex items-center gap-2">
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={logoUploading}
                    onClick={() => logoInputRef.current?.click()}
                  >
                    {logoUploading ? "Mengunggah…" : logoShown ? "Ganti" : "Unggah logo"}
                  </Button>
                  {logoShown && (
                    <Button type="button" size="sm" variant="ghost" className="text-muted-foreground" onClick={clearLogo}>
                      Hapus
                    </Button>
                  )}
                </div>
                <span className="text-xs text-muted-foreground">PNG / JPG, maks 2 MB.</span>
              </div>
            </div>
            <Field label="Nama tim" required value={team.name} onChange={(v) => setTeam({ ...team, name: v })} />
            <Field label="Kota" value={team.city} onChange={(v) => setTeam({ ...team, city: v })} />
            <Field label="Nama kontak" required value={team.contact_name} onChange={(v) => setTeam({ ...team, contact_name: v })} />
            <Field label="No. HP kontak" required value={team.contact_phone} onChange={(v) => setTeam({ ...team, contact_phone: v })} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex-row items-center justify-between">
            <div>
              <CardTitle>Daftar Pemain</CardTitle>
              <CardDescription>Tambahkan minimal satu pemain.</CardDescription>
            </div>
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => setPlayers([...players, { full_name: "", jersey_number: "", position: "" }])}
            >
              <Plus className="h-4 w-4" />
              Pemain
            </Button>
          </CardHeader>
          <CardContent className="grid gap-2">
            {players.map((p, i) => (
              <div key={i} className="flex items-center gap-2">
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-[var(--bg-soft)] text-xs font-semibold text-muted-foreground">
                  {i + 1}
                </span>
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

        <Card>
          <CardHeader>
            <CardTitle>Dokumen</CardTitle>
            <CardDescription>Opsional — unggah berkas pendukung (KTP, surat, dll).</CardDescription>
          </CardHeader>
          <CardContent>
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
              <input type="file" multiple className="hidden" onChange={(e) => handleFiles(e.target.files)} />
            </label>
            {docs.length > 0 && (
              <ul className="mt-3 grid gap-2">
                {docs.map((d, i) => (
                  <li
                    key={i}
                    className="flex items-center gap-2 rounded-md border border-border bg-[var(--surface)] px-3 py-2 text-sm"
                  >
                    <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className="truncate">{d.file_name}</span>
                    <button
                      type="button"
                      className="ml-auto text-muted-foreground hover:text-[var(--danger)]"
                      onClick={() => setDocs(docs.filter((_, j) => j !== i))}
                      aria-label="Hapus dokumen"
                    >
                      <X className="h-4 w-4" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <div className="flex justify-end">
          <Button type="submit" size="lg" disabled={mutation.isPending || uploading || logoUploading}>
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
      <Label className="font-semibold">
        {label}
        {required && <span className="text-[var(--danger)]"> *</span>}
      </Label>
      <Input value={value} onChange={(e) => onChange(e.target.value)} required={required} />
    </div>
  );
}

export default function RegisterTeamPageWrapper() {
  return (
    <Suspense fallback={null}>
      <RegisterTeamPage />
    </Suspense>
  );
}
