"use client";

import { type ComponentProps, Suspense, useId, useRef, useState } from "react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { AxiosError } from "axios";
import {
  ChevronLeft,
  X,
  Upload,
  FileText,
  CheckCircle2,
  AlertCircle,
  Loader2,
  LogIn,
  ImagePlus,
} from "lucide-react";

import { phoneInput } from "@/lib/phone";
import { useOptionalSession } from "@/components/auth/use-optional-session";
import {
  getPublicEvent,
  registerTeam,
  signUpload,
  uploadImage,
  type RegisterTeamPayload,
} from "@/lib/api/events";
import { compressToWebp } from "@/lib/image";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { RosterEditor, emptyPlayer, type PlayerRow } from "@/components/team/roster-editor";

type DocRow = { file_name: string; file_url: string };

function RegisterTeamPage() {
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  const searchParams = useSearchParams();

  // Public route, so nothing has restored the session: without this the request
  // goes out unauthenticated even for a signed-in visitor, the team is stored
  // with no manager, and it never shows up under "Tim Saya".
  const { ready: sessionReady, isAuthenticated } = useOptionalSession();

  // The sport drives the position suggestions in the roster editor.
  const { data: event } = useQuery({
    queryKey: ["public-event", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicEvent(params.orgSlug, params.eventSlug),
  });

  // Returned from Midtrans after a successful payment (finish redirect URL).
  const paidStatus = searchParams.get("transaction_status");
  const paidReturn =
    !!searchParams.get("order_id") &&
    (paidStatus === "settlement" || paidStatus === "capture" || searchParams.get("status") === "success");

  const [team, setTeam] = useState({ name: "", logo_url: "", contact_name: "", contact_phone: "" });
  // Which competition category the team is entering. Preselect from ?category=slug
  // (used by the "Daftar" buttons on the event page), else the first category.
  const categories = event?.categories ?? [];
  const querySlug = searchParams.get("category");
  const [categoryId, setCategoryId] = useState<string>("");
  const resolvedCategoryId =
    categoryId ||
    categories.find((c) => c.slug === querySlug)?.id ||
    categories[0]?.id ||
    "";
  const selectedCategory = categories.find((c) => c.id === resolvedCategoryId) ?? null;

  const [players, setPlayers] = useState<PlayerRow[]>([emptyPlayer()]);
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
        category_id: resolvedCategoryId,
        ...team,
        players: players
          .filter((p) => p.full_name.trim())
          .map((p) => ({
            full_name: p.full_name,
            jersey_number: p.jersey_number,
            position: p.position,
            photo_url: p.photo_url,
          })),
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
                Lengkapi roster &amp; dokumen kapan saja di <b className="text-foreground">Tim Saya</b>.
              </>
            ) : (
              <>
                Tim <b className="text-foreground">{team.name}</b> berhasil didaftarkan dan menunggu persetujuan
                penyelenggara. Lengkapi roster &amp; dokumen kapan saja di <b className="text-foreground">Tim Saya</b>.
              </>
            )}
          </p>
          <div className="mt-6 grid gap-2 sm:grid-cols-2">
            <Button asChild size="lg">
              <Link href="/participant">Ke Tim Saya</Link>
            </Button>
            <Button asChild size="lg" variant="outline">
              <Link href={`/${params.orgSlug}/${params.eventSlug}`}>Ke halaman event</Link>
            </Button>
          </div>
        </Card>
      </div>
    );
  }

  // Registration needs an account: the team is tied to whoever files it, and
  // that link is what puts it in their "Tim Saya" afterwards — where the roster
  // gets completed, documents uploaded and the fee paid. Sending them off with
  // `next` brings them straight back to this form.
  if (sessionReady && !isAuthenticated) {
    const next = encodeURIComponent(`/${params.orgSlug}/${params.eventSlug}/register`);

    return (
      <div className="container" style={{ paddingBlock: 80, maxWidth: 520 }}>
        <Card className="p-8 text-center sm:p-10">
          <div className="mx-auto mb-5 grid h-14 w-14 place-items-center rounded-full bg-[var(--tint)] text-[var(--brand-600)]">
            <LogIn className="h-7 w-7" />
          </div>
          <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Masuk dulu untuk mendaftar
          </h1>
          <p className="mt-2 text-muted-foreground">
            Tim yang kamu daftarkan tersimpan di akunmu — dari sana kamu bisa melengkapi roster,
            mengunggah dokumen, dan memantau status persetujuan.
          </p>
          <div className="mt-6 grid gap-2 sm:grid-cols-2">
            <Button asChild size="lg">
              <Link href={`/login?next=${next}`}>Masuk</Link>
            </Button>
            <Button asChild size="lg" variant="outline">
              <Link href={`/register?next=${next}`}>Daftar akun</Link>
            </Button>
          </div>
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
            {categories.length > 0 && (
              <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="category" className="font-semibold">
                  Kategori<span className="text-[var(--danger)]"> *</span>
                </Label>
                {categories.length > 1 ? (
                  <Select
                    id="category"
                    value={resolvedCategoryId}
                    onChange={(e) => setCategoryId(e.target.value)}
                  >
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name} — {c.registration_fee > 0 ? rupiah(c.registration_fee) : "Gratis"}
                      </option>
                    ))}
                  </Select>
                ) : (
                  <p className="text-sm text-foreground">{selectedCategory?.name}</p>
                )}
                {selectedCategory && (
                  <p className="text-xs text-muted-foreground">
                    Biaya registrasi:{" "}
                    {selectedCategory.registration_fee > 0
                      ? rupiah(selectedCategory.registration_fee)
                      : "Gratis"}
                  </p>
                )}
              </div>
            )}
            <Field label="Nama tim" required value={team.name} onChange={(v) => setTeam({ ...team, name: v })} />
            <Field label="Nama kontak" required value={team.contact_name} onChange={(v) => setTeam({ ...team, contact_name: v })} />
            <Field label="No. HP kontak" required inputMode="tel" sanitize={phoneInput} value={team.contact_phone} onChange={(v) => setTeam({ ...team, contact_phone: v })} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>
              Daftar Pemain <span className="font-normal text-muted-foreground">(opsional)</span>
            </CardTitle>
            <CardDescription>
              Boleh dilewati dulu — roster bisa dilengkapi kapan saja lewat dashboard Tim Saya.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <RosterEditor players={players} onChange={setPlayers} sport={event?.sport_type} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>
              Dokumen <span className="font-normal text-muted-foreground">(opsional)</span>
            </CardTitle>
            <CardDescription>
              Berkas pendukung (KTP, surat, dll). Bisa menyusul lewat dashboard Tim Saya.
            </CardDescription>
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
          <Button
            type="submit"
            size="lg"
            disabled={!sessionReady || !resolvedCategoryId || mutation.isPending || uploading || logoUploading}
          >
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
  inputMode,
  sanitize,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  required?: boolean;
  inputMode?: ComponentProps<typeof Input>["inputMode"];
  // Runs on every keystroke to drop characters this field doesn't accept (e.g.
  // letters in a phone number) before they ever land in state.
  sanitize?: (v: string) => string;
}) {
  // Without an id the label points at nothing: clicking it doesn't focus the
  // input and assistive tech reads the field unnamed.
  const id = useId();

  return (
    <div className="grid gap-2">
      <Label htmlFor={id} className="font-semibold">
        {label}
        {required && <span className="text-[var(--danger)]"> *</span>}
      </Label>
      <Input
        id={id}
        value={value}
        inputMode={inputMode}
        onChange={(e) => onChange(sanitize ? sanitize(e.target.value) : e.target.value)}
        required={required}
      />
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
