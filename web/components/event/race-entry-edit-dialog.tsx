"use client";

import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Loader2 } from "lucide-react";

import { updateRaceEntry, type UpdateRaceEntryPayload } from "@/lib/api/race";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { nameInput } from "@/lib/name";
import { phoneInput } from "@/lib/phone";
import type { CustomFieldAnswers, RegistrationFormSchema } from "@/lib/registration-form";
import { CustomFieldEditor } from "@/components/team/custom-field-editor";
import { Button } from "@/components/ui/button";
import { DatePicker } from "@/components/ui/date-picker";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import { FieldError } from "@/components/ui/field-error";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import type { RaceEntry } from "@/types/api";

/**
 * The organizer correcting one runner's details.
 *
 * This is the valve the runner does not have: once the registration window
 * shuts, a participant's own edits are frozen, and a misspelled name on a start
 * list or a certificate is wrong forever without somebody able to fix it here.
 *
 * **What it does not offer is the point.** No category, no price, no status, no
 * QR — each is either a snapshot a wallet credit already points at or a column
 * with exactly one writer, and the API refuses them whatever this form sends.
 * The fields below are precisely the set `UpdateRaceEntryPayload` carries, so
 * there is nothing here that submits into a 422.
 *
 * Bib number is the one column this dialog is the FIRST writer of — it had no
 * writer at all before, the migration having left the numbering to the
 * organizer. Free text, because "A-014" and "5K-07" are both bib numbers.
 */
export function RaceEntryEditDialog({
  entry,
  schema,
  orgId,
  eventId,
  onClose,
}: {
  entry: RaceEntry;
  schema: RegistrationFormSchema;
  orgId: string;
  eventId: string;
  onClose: () => void;
}) {
  const qc = useQueryClient();

  // Seeded from the row, not from a draft kept across rows: this dialog is
  // mounted per entry (see the list), so there is no previous runner's answer
  // to leak into this one.
  const [form, setForm] = useState({
    full_name: entry.full_name,
    email: entry.email,
    phone: entry.phone ?? "",
    gender: entry.gender ?? "",
    birth_date: entry.birth_date ?? "",
    emergency_contact_name: entry.emergency_contact_name ?? "",
    emergency_contact_phone: entry.emergency_contact_phone ?? "",
    emergency_contact_relation: entry.emergency_contact_relation ?? "",
    bib_number: entry.bib_number ?? "",
  });
  const [answers, setAnswers] = useState<CustomFieldAnswers>(entry.custom_fields ?? {});

  // Stored, not just toasted: a 422 names the field it is about, and the
  // server's required-answer refusal is keyed `custom_fields.<key>` — which is
  // the only way the editor below can mark the right input.
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const set = <K extends keyof typeof form>(key: K, value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const mutation = useMutation({
    mutationFn: () => {
      const payload: UpdateRaceEntryPayload = {
        full_name: form.full_name.trim(),
        email: form.email.trim(),
        // Empty string means "cleared", which the API spells as null — sending
        // `""` would store a blank where the column is nullable, and the detail
        // panel reads null to decide whether to print the block at all.
        phone: form.phone.trim() || null,
        gender: form.gender || null,
        birth_date: form.birth_date || null,
        emergency_contact_name: form.emergency_contact_name.trim() || null,
        emergency_contact_phone: form.emergency_contact_phone.trim() || null,
        emergency_contact_relation: form.emergency_contact_relation.trim() || null,
        bib_number: form.bib_number.trim() || null,
      };

      // Only when this event asks something: sending the key at all makes the
      // server validate the whole set against the form, so an event with no
      // custom fields would be answering a question nobody asked.
      if (schema.team_fields.length > 0) {
        payload.custom_fields = answers;
      }

      return updateRaceEntry(orgId, eventId, entry.id, payload);
    },
    onSuccess: () => {
      // Every page of the list, because the row may be on any of them and the
      // cards above it are counted server-side from the same query.
      qc.invalidateQueries({ queryKey: ["race-entries", orgId, eventId] });
      toast.success("Data peserta diperbarui");
      onClose();
    },
    onError: (err) => {
      const { message, fieldErrors: errors } = parseApiError(err, "Gagal menyimpan data peserta.");
      setFieldErrors(errors);
      // The banner stays alongside the inline messages: a refusal on a field
      // scrolled out of this dialog's body would otherwise read as a submit
      // that was ignored.
      toast.error(message);
    },
  });

  /**
   * The server's refusal for one custom field.
   *
   * Keyed `custom_fields.<key>` by Laravel, so a plain lookup on the key alone
   * never finds it — the same prefix matching the discount-code pages do.
   */
  const answerErrors = Object.fromEntries(
    Object.entries(fieldErrors)
      .filter(([path]) => path.startsWith("custom_fields."))
      .map(([path, message]) => [path.slice("custom_fields.".length), message]),
  );

  return (
    <Dialog open onOpenChange={(open) => !open && !mutation.isPending && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader
          title="Ubah data peserta"
          description="Kategori, harga, dan status pembayaran tidak bisa diubah dari sini."
        />
        <DialogBody>
          <div className="grid gap-4">
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="edit-name">Nama lengkap</Label>
                <Input
                  id="edit-name"
                  value={form.full_name}
                  onChange={(e) => set("full_name", nameInput(e.target.value))}
                  aria-invalid={!!fieldErrors.full_name}
                />
                <FieldError message={fieldErrors.full_name} />
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="edit-email">Email</Label>
                <Input
                  id="edit-email"
                  type="email"
                  value={form.email}
                  onChange={(e) => set("email", e.target.value)}
                  aria-invalid={!!fieldErrors.email}
                />
                <FieldError message={fieldErrors.email} />
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="edit-phone">Nomor HP</Label>
                <Input
                  id="edit-phone"
                  value={form.phone}
                  onChange={(e) => set("phone", phoneInput(e.target.value))}
                  aria-invalid={!!fieldErrors.phone}
                />
                <FieldError message={fieldErrors.phone} />
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="edit-gender">Jenis kelamin</Label>
                <Select
                  id="edit-gender"
                  value={form.gender}
                  onChange={(e) => set("gender", e.target.value)}
                >
                  <option value="">—</option>
                  <option value="L">Laki-laki</option>
                  <option value="P">Perempuan</option>
                </Select>
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="edit-birth">Tanggal lahir</Label>
                <DatePicker
                  id="edit-birth"
                  value={form.birth_date}
                  onChange={(val) => set("birth_date", val)}
                  placeholder="Pilih tanggal lahir"
                  clearable
                />
                <FieldError message={fieldErrors.birth_date} />
              </div>

              {/* Beside the runner's own details rather than in a block of its
                  own: it is one field, and it is the thing an organizer most
                  often opens this dialog to type. */}
              <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="edit-bib">Nomor BIB</Label>
                <Input
                  id="edit-bib"
                  value={form.bib_number}
                  onChange={(e) => set("bib_number", e.target.value)}
                  placeholder="mis. A-014"
                  maxLength={20}
                  aria-invalid={!!fieldErrors.bib_number}
                />
                <FieldError message={fieldErrors.bib_number} />
                <p className="text-xs text-muted-foreground">
                  Bebas formatnya. Nomor yang sama tidak ditolak — urutannya
                  milik penyelenggara.
                </p>
              </div>
            </div>

            <div className="grid gap-3 border-t border-border pt-4 sm:grid-cols-2">
              <p className="text-sm font-semibold sm:col-span-2">Kontak darurat</p>
              <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="edit-ec-name">Nama</Label>
                <Input
                  id="edit-ec-name"
                  value={form.emergency_contact_name}
                  onChange={(e) =>
                    set("emergency_contact_name", nameInput(e.target.value))
                  }
                />
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="edit-ec-phone">Nomor HP</Label>
                <Input
                  id="edit-ec-phone"
                  value={form.emergency_contact_phone}
                  onChange={(e) =>
                    set("emergency_contact_phone", phoneInput(e.target.value))
                  }
                />
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="edit-ec-relation">Status hubungan</Label>
                {/* Not run through `nameInput` like the name beside it: that
                    uppercases, which is right for a roster name and wrong for a
                    word in a sentence a marshal reads aloud — "IBU" is
                    shouting. Same call the public form makes. */}
                <Input
                  id="edit-ec-relation"
                  value={form.emergency_contact_relation}
                  maxLength={50}
                  placeholder="Ibu / Suami / Teman"
                  onChange={(e) => set("emergency_contact_relation", e.target.value)}
                />
              </div>
            </div>

            {schema.team_fields.length > 0 && (
              <div className="grid gap-3 border-t border-border pt-4 sm:grid-cols-2">
                <p className="text-sm font-semibold sm:col-span-2">Data formulir</p>
                {/* The same editor the public form renders, so a conditional
                    field hides here exactly as it did there and a required one
                    cannot be emptied without the server saying which. */}
                <CustomFieldEditor
                  fields={schema.team_fields}
                  value={answers}
                  onChange={setAnswers}
                  idPrefix={`edit-${entry.id}`}
                  disabled={mutation.isPending}
                  fieldErrors={answerErrors}
                />
              </div>
            )}

            {/* Documents are deliberately absent: the runner's uploads are
                replaced through their own form, not retyped by an organizer
                reading a scan over the phone. What is already stored stays
                stored — this endpoint never touches it. */}
          </div>
        </DialogBody>
        <DialogFooter>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Batal
          </Button>
          <Button
            onClick={() => {
              setFieldErrors({});
              mutation.mutate();
            }}
            disabled={mutation.isPending || !form.full_name.trim() || !form.email.trim()}
          >
            {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            Simpan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
