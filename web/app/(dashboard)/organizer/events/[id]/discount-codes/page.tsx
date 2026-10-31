"use client";

import { useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Pencil, Trash2, X } from "lucide-react";

import { getEvent } from "@/lib/api/events";
import {
  getEventDiscountCodes,
  createEventDiscountCode,
  updateEventDiscountCode,
  deleteEventDiscountCode,
  type EventDiscountCodeInput,
} from "@/lib/api/event-discount-codes";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { DateTimePicker } from "@/components/ui/date-time-picker";
import { FieldError } from "@/components/ui/field-error";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import type { EventDiscountCode } from "@/types/api";

const EMPTY: EventDiscountCodeInput = {
  code: "",
  description: "",
  discount_type: "percent",
  discount_value: 10,
  max_discount: null,
  is_active: true,
  starts_at: null,
  ends_at: null,
  max_redemptions: null,
  once_per_user: true,
};

/**
 * Fields that render their own refusal below themselves. Anything else the
 * server names falls through to the catch-all above the submit button, so a
 * message is never dropped on the floor just because this list is behind the
 * API.
 */
const BOUND_FIELDS = [
  "code",
  "description",
  "discount_type",
  "discount_value",
  "max_discount",
  "max_redemptions",
  "starts_at",
  "ends_at",
];

/** `DateTimePicker` wants `YYYY-MM-DDTHH:mm`, not an ISO string. */
function toLocalInput(value: string | null): string {
  if (!value) return "";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * Discount codes a participant may type while registering for this event.
 *
 * Shaped on /admin/discount-codes, minus the plan multi-select: a code here has
 * no scope axis at all — it applies to every category of this event, and both
 * rails read it (registration fees and fun run entries).
 *
 * Deleting or renaming one is safe, unlike a feature key: every registration
 * snapshots the code it used, so an issued invoice keeps reading what was
 * charged and nothing reads these rows back afterwards. Only future
 * registrations change.
 *
 * **The discount comes off the organizer's own revenue.** The net price is what
 * their wallet is credited — that is stated on the page rather than left for
 * them to discover from a smaller payout.
 */
export default function EventDiscountCodesPage() {
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();
  const qc = useQueryClient();

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const query = useQuery({
    queryKey: ["event-discount-codes", orgId, eventId],
    queryFn: () => getEventDiscountCodes(orgId!, eventId),
    enabled: !!orgId,
  });

  const [form, setForm] = useState<EventDiscountCodeInput>(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);
  /**
   * The server's per-field refusals, rendered under the fields they name.
   *
   * A toast alone was all this form had, and the messages that matter here are
   * all field-shaped — "kode itu sudah dipakai di event ini", "diskon
   * persentase tidak boleh lebih dari 100%", "tanggal berakhir harus setelah
   * tanggal mulai". None of them says which input to go back to, and a toast
   * is gone by the time the organizer starts looking.
   */
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const invalidate = () =>
    qc.invalidateQueries({ queryKey: ["event-discount-codes", orgId, eventId] });

  const reset = () => {
    setForm(EMPTY);
    setEditingId(null);
    setFieldErrors({});
  };

  const save = useMutation({
    mutationFn: () =>
      editingId
        ? updateEventDiscountCode(orgId!, editingId, form)
        : createEventDiscountCode(orgId!, eventId, form),
    onSuccess: () => {
      toast.success(editingId ? "Kode diskon diperbarui." : "Kode diskon ditambahkan.");
      reset();
      invalidate();
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan kode diskon.");
      setFieldErrors(parsed.fieldErrors);
      // The banner stays even when the fields carry the detail: a refusal on a
      // field scrolled out of view would otherwise be a submit that does
      // nothing at all.
      toast.error(parsed.message);
    },
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteEventDiscountCode(orgId!, id),
    onSuccess: () => {
      toast.success("Kode diskon dihapus.");
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus kode diskon.").message),
  });

  const startEdit = (c: EventDiscountCode) => {
    setEditingId(c.id);
    // Last submit's refusals named the row that was in the form before this
    // one; keeping them would mark fields this row has not been judged on.
    setFieldErrors({});
    setForm({
      code: c.code,
      description: c.description ?? "",
      discount_type: c.discount_type,
      discount_value: c.discount_value,
      max_discount: c.max_discount,
      is_active: c.is_active,
      starts_at: c.starts_at,
      ends_at: c.ends_at,
      max_redemptions: c.max_redemptions,
      once_per_user: c.once_per_user,
    });
  };

  return (
    <div>
      <PageHeader
        title="Kode Diskon"
        description={
          eventQuery.data?.name ??
          "Potongan biaya pendaftaran yang bisa diketik peserta saat mendaftar."
        }
        backHref={`/organizer/events/${eventId}/edit`}
        backLabel="Kelola event"
      />

      <p className="mb-6 max-w-2xl text-sm text-muted-foreground">
        Kode berlaku untuk semua kategori event ini dan bersifat opsional — peserta yang tidak
        memakainya tetap membayar harga penuh. Potongannya mengurangi pendapatanmu: saldo yang
        masuk ke dompet adalah harga setelah diskon.
      </p>

      {query.isError && (
        <p className="mb-4 text-sm text-destructive">Gagal memuat kode diskon.</p>
      )}

      {/* Create / edit form */}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
        className="grid items-end gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-2 lg:grid-cols-3"
      >
        <div className="grid gap-1.5">
          <Label htmlFor="code">Kode</Label>
          <Input
            id="code"
            placeholder="mis. EARLYBIRD"
            value={form.code}
            onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
            aria-invalid={!!fieldErrors.code}
            required
          />
          <FieldError message={fieldErrors.code} />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="description">Catatan</Label>
          <Input
            id="description"
            placeholder="mis. Promo pendaftaran awal"
            value={form.description ?? ""}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
            aria-invalid={!!fieldErrors.description}
          />
          <FieldError message={fieldErrors.description} />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="discount_type">Jenis</Label>
          <Select
            id="discount_type"
            value={form.discount_type}
            onChange={(e) =>
              setForm({
                ...form,
                discount_type: e.target.value as EventDiscountCode["discount_type"],
                // The ceiling has no meaning for a fixed code: its value already
                // *is* the ceiling.
                max_discount: e.target.value === "percent" ? form.max_discount : null,
              })
            }
          >
            <option value="percent">Persentase (%)</option>
            <option value="fixed">Nominal (Rp)</option>
          </Select>
          <FieldError message={fieldErrors.discount_type} />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="discount_value">
            {form.discount_type === "percent" ? "Besar diskon (%)" : "Besar diskon (Rp)"}
          </Label>
          <Input
            id="discount_value"
            type="number"
            min={0}
            max={form.discount_type === "percent" ? 100 : undefined}
            step={form.discount_type === "percent" ? 1 : 1000}
            value={form.discount_value}
            onChange={(e) => setForm({ ...form, discount_value: Number(e.target.value) })}
            aria-invalid={!!fieldErrors.discount_value}
            required
          />
          <FieldError message={fieldErrors.discount_value} />
        </div>
        {form.discount_type === "percent" && (
          <div className="grid gap-1.5">
            <Label htmlFor="max_discount">Maks. potongan (Rp)</Label>
            <Input
              id="max_discount"
              type="number"
              min={0}
              step={1000}
              placeholder="Kosong = tanpa batas"
              value={form.max_discount ?? ""}
              onChange={(e) =>
                setForm({
                  ...form,
                  max_discount: e.target.value === "" ? null : Number(e.target.value),
                })
              }
              aria-invalid={!!fieldErrors.max_discount}
            />
            <FieldError message={fieldErrors.max_discount} />
          </div>
        )}
        <div className="grid gap-1.5">
          <Label htmlFor="max_redemptions">Kuota pemakaian</Label>
          <Input
            id="max_redemptions"
            type="number"
            min={1}
            placeholder="Kosong = tanpa kuota"
            value={form.max_redemptions ?? ""}
            onChange={(e) =>
              setForm({
                ...form,
                max_redemptions: e.target.value === "" ? null : Number(e.target.value),
              })
            }
            aria-invalid={!!fieldErrors.max_redemptions}
          />
          <FieldError message={fieldErrors.max_redemptions} />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="starts_at">Mulai berlaku</Label>
          <DateTimePicker
            id="starts_at"
            value={toLocalInput(form.starts_at)}
            onChange={(val) => setForm({ ...form, starts_at: val || null })}
            datePlaceholder="Kosong = langsung berlaku"
            clearable
            aria-invalid={!!fieldErrors.starts_at}
          />
          <FieldError message={fieldErrors.starts_at} />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="ends_at">Berakhir</Label>
          <DateTimePicker
            id="ends_at"
            value={toLocalInput(form.ends_at)}
            min={toLocalInput(form.starts_at).slice(0, 10) || undefined}
            onChange={(val) => setForm({ ...form, ends_at: val || null })}
            datePlaceholder="Kosong = tanpa batas"
            clearable
            aria-invalid={!!fieldErrors.ends_at}
          />
          <FieldError message={fieldErrors.ends_at} />
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-border"
            checked={form.once_per_user}
            onChange={(e) => setForm({ ...form, once_per_user: e.target.checked })}
          />
          Maks. sekali per akun peserta
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-border"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
          />
          Aktif
        </label>

        <div className="grid gap-2 lg:col-span-3">
          {/* Anything the server refused that no field above renders — the two
              checkboxes, or a key added to the API before this form knows about
              it. Without this a refusal on one of them is a submit that looks
              ignored. */}
          {Object.entries(fieldErrors)
            .filter(([field]) => !BOUND_FIELDS.includes(field))
            .map(([field, message]) => (
              <FieldError key={field} message={message} />
            ))}
          <div className="flex gap-2">
            <Button type="submit" disabled={save.isPending || !orgId}>
              {save.isPending ? "Menyimpan…" : editingId ? "Simpan perubahan" : "Tambah kode"}
            </Button>
            {editingId && (
              <Button type="button" variant="ghost" onClick={reset}>
                <X className="h-4 w-4" />
                Batal
              </Button>
            )}
          </div>
        </div>
      </form>

      {/* List */}
      <div className="mt-6 grid gap-2">
        {query.isLoading && <Skeleton className="h-24 w-full rounded-lg" />}
        {query.data?.length === 0 && (
          <p className="text-sm text-muted-foreground">
            Belum ada kode diskon untuk event ini.
          </p>
        )}
        {query.data?.map((c) => (
          <div
            key={c.id}
            className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border bg-card p-4"
          >
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono font-semibold">{c.code}</span>
                <span className="text-sm text-muted-foreground">
                  {c.discount_type === "percent"
                    ? `${c.discount_value}%${c.max_discount ? ` (maks ${rupiah(c.max_discount)})` : ""}`
                    : rupiah(c.discount_value)}
                </span>
                {!c.is_active && (
                  <span className="rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-muted-foreground">
                    Nonaktif
                  </span>
                )}
              </div>
              <p className="mt-1 text-sm text-muted-foreground">
                Terpakai {c.redeemed_count}
                {c.max_redemptions === null ? "" : ` / ${c.max_redemptions}`}
                {/* Unpaid registrations hold a slot and release on their own,
                    which is why a quota can read as full before anyone has
                    paid. */}
                {c.reserved_count > 0 && ` · ${c.reserved_count} menunggu pembayaran`}
                {c.once_per_user && " · maks 1× per peserta"}
              </p>
              {(c.starts_at || c.ends_at) && (
                <p className="mt-1 text-xs text-muted-foreground">
                  {c.starts_at && `Mulai ${new Date(c.starts_at).toLocaleString("id-ID")}`}
                  {c.starts_at && c.ends_at && " · "}
                  {c.ends_at && `sampai ${new Date(c.ends_at).toLocaleString("id-ID")}`}
                </p>
              )}
              {c.description && <p className="mt-1 text-sm">{c.description}</p>}
            </div>
            <div className="flex shrink-0 gap-2">
              <Button size="sm" variant="outline" onClick={() => startEdit(c)}>
                <Pencil className="h-4 w-4" />
                Ubah
              </Button>
              <Button
                size="sm"
                variant="destructive"
                onClick={() => remove.mutate(c.id)}
                disabled={remove.isPending}
              >
                <Trash2 className="h-4 w-4" />
                Hapus
              </Button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
