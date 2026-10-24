"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { AxiosError } from "axios";
import { AlertCircle, ChevronLeft, Flag, Loader2, LogIn } from "lucide-react";

import { nameInput } from "@/lib/name";
import { phoneInput } from "@/lib/phone";
import { useOptionalSession } from "@/components/auth/use-optional-session";
import { getPublicRace, registerRaceEntry } from "@/lib/api/race";
import { getPublicEvent } from "@/lib/api/events";
import {
  allowedDomainList,
  blockedReason,
  currentPrice,
  emailDomainBlocked,
  isEnterable,
} from "@/lib/race";
import {
  missingFor,
  schemaOf,
  type CustomFieldAnswers,
  type DocumentRow,
} from "@/lib/registration-form";
import { CustomFieldEditor } from "@/components/team/custom-field-editor";
import { DocumentUploadFields } from "@/components/team/document-upload-field";
import { ChannelPicker } from "@/components/payment/channel-picker";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { DatePicker } from "@/components/ui/date-picker";
import { InfoHint } from "@/components/ui/info-hint";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * The runner's entry form.
 *
 * Deliberately NOT a branch inside the team registration page: that form is
 * built around a roster, a crest and a squad name, and none of the three exist
 * here. What they do share is the dynamic registration schema and the payment
 * handoff, both of which are imported rather than reimplemented.
 *
 * The price is never computed here. `current_price` is what the server resolved
 * from its own clock, and the form prints exactly that — a client that picked
 * its own window would be the `effective_payment_method` bug again, showing
 * early bird above a last-call charge.
 */
function RaceRegisterPage() {
  const params = useParams<{ orgSlug: string; eventSlug: string }>();
  const searchParams = useSearchParams();
  const router = useRouter();

  // Public route, so nothing has restored the session yet: without this the
  // request goes out unauthenticated even for a signed-in visitor and the
  // server refuses it.
  const { ready: sessionReady, isAuthenticated } = useOptionalSession();

  const { data: event } = useQuery({
    queryKey: ["public-event", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicEvent(params.orgSlug, params.eventSlug),
  });

  const offerQuery = useQuery({
    queryKey: ["public-race", params.orgSlug, params.eventSlug],
    queryFn: () => getPublicRace(params.orgSlug, params.eventSlug),
  });

  const categories = offerQuery.data?.categories ?? [];
  const livePeriod = offerQuery.data?.current_period ?? null;

  const [categoryId, setCategoryId] = useState("");
  const querySlug = searchParams.get("category");
  const resolvedCategoryId =
    categoryId ||
    categories.find((c) => c.slug === querySlug)?.id ||
    // Preselect the first category a runner can actually enter rather than the
    // first in the list: landing on a sold-out one makes the form look broken.
    categories.find(isEnterable)?.id ||
    categories[0]?.id ||
    "";
  const selected = categories.find((c) => c.id === resolvedCategoryId) ?? null;
  const price = currentPrice(selected);
  const blocked = blockedReason(selected, !!livePeriod);

  const [runner, setRunner] = useState({
    full_name: "",
    email: "",
    phone: "",
    gender: "",
    birth_date: "",
    emergency_contact_name: "",
    emergency_contact_phone: "",
    emergency_contact_relation: "",
  });

  /**
   * The category's email condition, read proactively so the runner is told
   * before they pick a payment channel. Reactive too: the server refuses the
   * same thing with a 422 on `email`, which `setError` already surfaces — the
   * proactive-and-reactive pair the plan gates use.
   */
  const domainError = emailDomainBlocked(selected, runner.email);

  // Whatever else this event asks for. Empty lists render nothing at all — an
  // event that defines no documents shows no upload UI.
  const schema = schemaOf(event);
  const [answers, setAnswers] = useState<CustomFieldAnswers>({});
  const [docs, setDocs] = useState<DocumentRow[]>([]);
  const [uploading, setUploading] = useState(false);
  const missing = missingFor(schema.team_fields, schema.team_documents, answers, docs);

  const [agreed, setAgreed] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [channel, setChannel] = useState<string | null>(null);
  const [pickingChannel, setPickingChannel] = useState(false);

  const requiresChannel = Boolean(event?.requires_payment_channel) && (price ?? 0) > 0;

  const mutation = useMutation({
    mutationFn: () =>
      registerRaceEntry(params.orgSlug, params.eventSlug, {
        race_category_id: resolvedCategoryId,
        full_name: runner.full_name,
        email: runner.email,
        phone: runner.phone || null,
        gender: runner.gender || null,
        birth_date: runner.birth_date || null,
        emergency_contact_name: runner.emergency_contact_name || null,
        emergency_contact_phone: runner.emergency_contact_phone || null,
        emergency_contact_relation: runner.emergency_contact_relation || null,
        custom_fields: answers as Record<string, string>,
        // Only the rows that name a slot. A `DocumentRow` carries
        // `document_type` optionally — the uploader fills it from the schema —
        // and a row without one names no slot the server could validate
        // against, so it is dropped rather than sent as an empty string. No
        // `file_size_bytes` either: that is the API's own optional field and
        // the uploader does not track it.
        documents: docs
          .filter((d): d is typeof d & { document_type: string } => !!d.document_type)
          .map((d) => ({
            document_type: d.document_type,
            file_url: d.file_url,
            file_name: d.file_name ?? null,
          })),
        payment_channel: channel,
      }),
    onSuccess: (res) => {
      // A paid gateway entry hands off to Midtrans; everything else lands on
      // the proof page, which is also where a manual entry uploads its receipt
      // and where the receipt is downloaded from. One destination either way,
      // so there is no success card to maintain here.
      if (!res.mock && res.redirect_url) {
        window.location.assign(res.redirect_url);
        return;
      }

      router.push(`/race-entries/${res.entry.id}`);
    },
    onError: (err) =>
      setError(
        err instanceof AxiosError
          ? (err.response?.data?.message ?? "Gagal mendaftar")
          : "Gagal mendaftar"
      ),
    onSettled: () => setPickingChannel(false),
  });

  const incomplete =
    !runner.full_name.trim() ||
    !runner.email.trim() ||
    !!domainError ||
    !resolvedCategoryId ||
    !agreed ||
    !!blocked ||
    missing.length > 0;

  if (sessionReady && !isAuthenticated) {
    const next = encodeURIComponent(`/${params.orgSlug}/${params.eventSlug}/race`);

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
            Pendaftaranmu tersimpan di akunmu, jadi kamu bisa membuka kembali
            bukti dan kwitansinya kapan saja.
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
      {/* The event's own name, not a word for what kind of event it is: the
          `fun_run` branch covers a race, a training run and a charity walk
          alike, and "Lomba" is wrong for two of the three. Falls back to the
          neutral verb while the event is still loading. */}
      <h1 className="mb-1 mt-3 text-2xl font-bold sm:text-3xl" style={{ fontFamily: "var(--font-display)" }}>
        {event?.name ? `Pendaftaran ${event.name}` : "Pendaftaran"}
      </h1>
      <p className="mb-6 text-muted-foreground">
        Isi datamu, pilih kategori, lalu selesaikan pembayaran.
      </p>

      {error && (
        <Card className="mb-5 flex items-start gap-2.5 border-[var(--danger)] p-4 text-sm">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-[var(--danger)]" />
          <span>{error}</span>
        </Card>
      )}

      {offerQuery.isSuccess && categories.length === 0 && (
        <Card className="mb-5 flex items-start gap-2.5 p-4 text-sm">
          <Flag className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
          <span>
            Penyelenggara belum membuka kategori untuk event ini. Coba lagi nanti.
          </span>
        </Card>
      )}

      <div className="space-y-5">
        {/* ---- Category + price ---- */}
        <Card>
          <CardHeader>
            <CardTitle>Kategori</CardTitle>
            <CardDescription>
              Harga mengikuti periode pendaftaran yang sedang berjalan.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="grid gap-1.5">
              <Label htmlFor="race-category">Pilih kategori</Label>
              <Select
                id="race-category"
                value={resolvedCategoryId}
                onChange={(e) => setCategoryId(e.target.value)}
              >
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                    {currentPrice(c) !== null ? ` — ${rupiah(currentPrice(c)!)}` : " — tidak dijual"}
                  </option>
                ))}
              </Select>
              {selected?.description && (
                <p className="text-xs text-muted-foreground">{selected.description}</p>
              )}
              {/* Said here as well as under the email input, because this is
                  where the choice is made: finding out in the Data Peserta card
                  means backtracking past the price list to change it. */}
              {selected && selected.email_domains.length > 0 && (
                <p className="text-xs text-muted-foreground">
                  Khusus pendaftar dengan email{" "}
                  <strong>{allowedDomainList(selected.email_domains)}</strong>.
                </p>
              )}
            </div>

            {/* The whole price list, current row emphasised. A runner deciding
                whether to register today wants the next number, and a window
                this category is not sold in prints "—" rather than vanishing. */}
            {selected && selected.price_schedule.length > 0 && (
              <div className="rounded-xl border border-border p-3 text-sm">
                <p className="mb-2 font-semibold">Periode pendaftaran</p>
                <div className="space-y-1">
                  {selected.price_schedule.map((row) => (
                    <div
                      key={row.period_id}
                      className="flex items-center justify-between gap-3"
                      style={row.is_current ? { fontWeight: 700 } : { color: "var(--text-muted)" }}
                    >
                      <span>
                        {row.name}
                        {row.is_current && (
                          <Badge variant="success" className="ml-2">
                            Berjalan
                          </Badge>
                        )}
                      </span>
                      <span>{row.price === null ? "—" : rupiah(row.price)}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {blocked ? (
              <p className="text-sm font-medium text-[var(--danger)]">{blocked}</p>
            ) : (
              <p className="text-sm">
                Biaya pendaftaran:{" "}
                <strong>{price === 0 ? "Gratis" : rupiah(price ?? 0)}</strong>
                {selected?.remaining !== null && selected?.remaining !== undefined && (
                  <span className="text-muted-foreground">
                    {" "}
                    · sisa {selected.remaining} kuota
                  </span>
                )}
              </p>
            )}
          </CardContent>
        </Card>

        {/* ---- Runner ---- */}
        <Card>
          <CardHeader>
            <CardTitle>Data Peserta</CardTitle>
            <CardDescription>Sesuai identitas yang dibawa di hari H.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-1.5 sm:col-span-2">
              <Label htmlFor="runner-name">Nama lengkap</Label>
              <Input
                id="runner-name"
                value={runner.full_name}
                onChange={(e) => setRunner({ ...runner, full_name: nameInput(e.target.value) })}
                placeholder="AMMAR WIJAYA"
                required
              />
            </div>
            <div className="grid gap-1.5">
              {/* Behind a marker for the same reason the organizer's own fields
                  are: a line of help under this input pushed it off the
                  baseline of "Nomor HP" beside it, and the row stopped reading
                  as a row. The marker beside the Label, never inside it — a
                  <label> may not wrap interactive content. */}
              <div className="flex items-center gap-1.5">
                <Label htmlFor="runner-email">Email</Label>
                <InfoHint label="Penjelasan email">
                  Kwitansi dan bukti pendaftaran dikirim ke alamat ini, jadi
                  pastikan alamatnya aktif dan bisa kamu buka di hari H.
                </InfoHint>
              </div>
              <Input
                id="runner-email"
                type="email"
                value={runner.email}
                onChange={(e) => setRunner({ ...runner, email: e.target.value })}
                placeholder="nama@email.com"
                required
                aria-invalid={!!domainError}
              />
              {domainError && (
                <p className="text-xs text-[var(--danger)]">{domainError}</p>
              )}
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="runner-phone">Nomor HP</Label>
              <Input
                id="runner-phone"
                value={runner.phone}
                onChange={(e) => setRunner({ ...runner, phone: phoneInput(e.target.value) })}
                placeholder="08xxxxxxxxxx"
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="runner-gender">Jenis kelamin</Label>
              <Select
                id="runner-gender"
                value={runner.gender}
                onChange={(e) => setRunner({ ...runner, gender: e.target.value })}
              >
                <option value="">—</option>
                <option value="L">Laki-laki</option>
                <option value="P">Perempuan</option>
              </Select>
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="runner-birth">Tanggal lahir</Label>
              {/* The same picker the organizer's event form uses, and the one
                  CustomFieldEditor already renders for `date` fields — a native
                  <input type="date"> looked like a different control sitting
                  between them. Its year dropdown reaches 100 years back, which
                  is what makes a birth date reachable at all: the month
                  chevrons alone are ~430 clicks to 1990. */}
              <DatePicker
                id="runner-birth"
                value={runner.birth_date}
                onChange={(val) => setRunner({ ...runner, birth_date: val })}
                placeholder="Pilih tanggal lahir"
              />
            </div>
          </CardContent>
        </Card>

        {/* ---- Emergency contact ---- */}
        <Card>
          <CardHeader>
            <CardTitle>Kontak Darurat</CardTitle>
            <CardDescription>
              Dihubungi panitia kalau terjadi sesuatu saat acara berlangsung.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-3 sm:grid-cols-2">
            {/* Full width, so the phone and the relation pair off on the row
                below instead of leaving one cell of the two-column grid
                hanging — the same shape "Nama lengkap" takes in the card
                above. */}
            <div className="grid gap-1.5 sm:col-span-2">
              <Label htmlFor="ec-name">Nama</Label>
              <Input
                id="ec-name"
                value={runner.emergency_contact_name}
                onChange={(e) =>
                  setRunner({ ...runner, emergency_contact_name: nameInput(e.target.value) })
                }
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="ec-phone">Nomor HP</Label>
              <Input
                id="ec-phone"
                value={runner.emergency_contact_phone}
                onChange={(e) =>
                  setRunner({ ...runner, emergency_contact_phone: phoneInput(e.target.value) })
                }
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="ec-relation">Status hubungan</Label>
              {/* Free text, not a select, and deliberately NOT run through
                  `nameInput` like the name beside it: that uppercases, which is
                  right for a person's name on a roster and wrong for a word in
                  a sentence a marshal reads off a phone — "IBU" is shouting.
                  The placeholder carries the common answers instead of an enum
                  locking them in; see the column, which makes the same call
                  `gender` did. */}
              <Input
                id="ec-relation"
                value={runner.emergency_contact_relation}
                maxLength={50}
                placeholder="Ibu / Suami / Teman"
                onChange={(e) =>
                  setRunner({ ...runner, emergency_contact_relation: e.target.value })
                }
              />
            </div>
          </CardContent>
        </Card>

        {/* ---- What this event asks for ---- */}
        {schema.team_fields.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Data Tambahan</CardTitle>
              <CardDescription>Diminta oleh penyelenggara event ini.</CardDescription>
            </CardHeader>
            {/* Two columns and `gap-3`, the same as the two cards above, which
                is what makes this read as one form rather than a section
                bolted on. It only works because the editor puts each field's
                guidance behind a marker beside the label: in the flow, help
                text wrapped to two lines beside help text wrapped to three put
                the inputs on different baselines and the row stopped reading
                as a row.

                The grid belongs here rather than to the editor because the
                editor emits bare cells — that is what lets `long_text` span
                both columns, and without any grid at all they stack with no
                gap whatsoever. */}
            <CardContent className="grid gap-3 sm:grid-cols-2">
              <CustomFieldEditor
                fields={schema.team_fields}
                value={answers}
                onChange={setAnswers}
                idPrefix="race"
              />
            </CardContent>
          </Card>
        )}

        {schema.team_documents.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Dokumen</CardTitle>
              <CardDescription>Unggah berkas yang diminta penyelenggara.</CardDescription>
            </CardHeader>
            <CardContent>
              <DocumentUploadFields
                slots={schema.team_documents}
                value={docs}
                onChange={setDocs}
                onBusyChange={setUploading}
              />
            </CardContent>
          </Card>
        )}

        <label className="flex cursor-pointer items-start gap-2.5 text-sm">
          <input
            type="checkbox"
            className="mt-0.5 h-4 w-4 accent-[var(--brand-600)]"
            checked={agreed}
            onChange={(e) => setAgreed(e.target.checked)}
          />
          <span>
            Saya menyatakan data di atas benar dan dalam kondisi sehat untuk
            mengikuti event ini.
          </span>
        </label>

        {missing.length > 0 && (
          <p className="text-sm text-muted-foreground">
            Belum lengkap: {missing.join(", ")}.
          </p>
        )}

        <Button
          size="lg"
          className="w-full"
          disabled={
            incomplete ||
            uploading ||
            !sessionReady ||
            mutation.isPending ||
            offerQuery.isLoading
          }
          onClick={() => {
            setError(null);
            // The channel picker opens only when there is actually a gateway
            // charge: a free entry and a manual transfer both settle without
            // one, and the server refuses a channel it did not ask for.
            if (requiresChannel) {
              setPickingChannel(true);
              return;
            }
            mutation.mutate();
          }}
        >
          {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
          {price === 0 ? "Kirim Pendaftaran" : "Lanjut ke Pembayaran"}
        </Button>
      </div>

      {pickingChannel && (
        <Dialog open onOpenChange={(open) => !open && setPickingChannel(false)}>
          <DialogContent>
            <DialogHeader
              title="Pilih metode pembayaran"
              description="Biaya layanan dan biaya gateway mengikuti metode yang kamu pilih."
            />
            <DialogBody>
              <ChannelPicker
                amount={price ?? 0}
                // The fourth audience, not `registration`: that rate is priced
                // for a whole squad entering at once. See
                // PaymentFeeCalculator::AUDIENCE_RACE_ENTRY.
                audience="race_entry"
                value={channel}
                onChange={setChannel}
              />
            </DialogBody>
            <DialogFooter>
              <Button
                variant="secondary"
                onClick={() => setPickingChannel(false)}
                disabled={mutation.isPending}
              >
                Batal
              </Button>
              <Button onClick={() => mutation.mutate()} disabled={!channel || mutation.isPending}>
                {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                Bayar
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}
    </div>
  );
}

/**
 * `useSearchParams` needs a Suspense boundary, the same wrapper the team
 * registration page uses for the same reason.
 */
export default function Page() {
  return (
    <Suspense fallback={null}>
      <RaceRegisterPage />
    </Suspense>
  );
}
