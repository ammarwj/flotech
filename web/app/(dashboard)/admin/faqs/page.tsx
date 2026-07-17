"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Pencil, Trash2, X } from "lucide-react";

import {
  getAdminFaqs,
  createFaq,
  updateFaq,
  deleteFaq,
  type FaqInput,
} from "@/lib/api/landing";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { Faq } from "@/types/api";

const EMPTY: FaqInput = {
  question: "",
  answer: "",
  is_active: true,
  sort_order: 0,
};

export default function AdminFaqsPage() {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ["admin-faqs"], queryFn: getAdminFaqs });

  const [form, setForm] = useState<FaqInput>(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);

  const invalidate = () => qc.invalidateQueries({ queryKey: ["admin-faqs"] });

  const reset = () => {
    setForm(EMPTY);
    setEditingId(null);
  };

  const save = useMutation({
    mutationFn: () => (editingId ? updateFaq(editingId, form) : createFaq(form)),
    onSuccess: () => {
      toast.success(editingId ? "FAQ diperbarui." : "FAQ ditambahkan.");
      reset();
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan FAQ.").message),
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteFaq(id),
    onSuccess: () => {
      toast.success("FAQ dihapus.");
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus FAQ.").message),
  });

  const startEdit = (faq: Faq) => {
    setEditingId(faq.id);
    setForm({
      question: faq.question,
      answer: faq.answer,
      is_active: faq.is_active,
      sort_order: faq.sort_order,
    });
  };

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        SaaS Admin · FAQ
      </h1>
      <p className="mt-2 text-muted-foreground">
        Section FAQ di landing page. Yang nonaktif tidak ikut tampil.
      </p>

      {query.isError && (
        <p className="mt-4 text-sm text-destructive">
          Tidak bisa memuat FAQ (butuh akses Super Admin & API berjalan).
        </p>
      )}

      {/* Create / edit form */}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
        className="mt-6 grid items-end gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-2"
      >
        <div className="grid gap-1.5 sm:col-span-2">
          <Label htmlFor="question">Pertanyaan</Label>
          <Input
            id="question"
            placeholder="mis. Paket paling murah mulai dari berapa?"
            maxLength={255}
            value={form.question}
            onChange={(e) => setForm({ ...form, question: e.target.value })}
            required
          />
        </div>
        <div className="grid gap-1.5 sm:col-span-2">
          <Label htmlFor="answer">Jawaban</Label>
          <Textarea
            id="answer"
            placeholder="Jawaban yang tampil saat item dibuka…"
            value={form.answer}
            onChange={(e) => setForm({ ...form, answer: e.target.value })}
            required
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="sort_order">Urutan</Label>
          <Input
            id="sort_order"
            type="number"
            min={0}
            value={form.sort_order}
            onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) })}
          />
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-border"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
          />
          Tampilkan di landing page
        </label>
        <div className="flex gap-2 sm:col-span-2">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? "Menyimpan…" : editingId ? "Simpan perubahan" : "Tambah FAQ"}
          </Button>
          {editingId && (
            <Button type="button" variant="ghost" onClick={reset}>
              <X className="h-4 w-4" />
              Batal
            </Button>
          )}
        </div>
      </form>

      {/* List */}
      <div className="mt-6 grid gap-2">
        {query.isLoading && <p className="text-sm text-muted-foreground">Memuat…</p>}
        {query.data?.length === 0 && <p className="text-sm text-muted-foreground">Belum ada FAQ.</p>}
        {query.data?.map((faq) => (
          <div
            key={faq.id}
            className="flex items-start justify-between gap-3 rounded-lg border border-border bg-card p-4"
          >
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                  {faq.question}
                </span>
                {!faq.is_active && (
                  <span className="rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-muted-foreground">
                    Nonaktif
                  </span>
                )}
              </div>
              <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{faq.answer}</p>
            </div>
            <div className="flex shrink-0 gap-2">
              <Button size="sm" variant="outline" onClick={() => startEdit(faq)}>
                <Pencil className="h-4 w-4" />
                Ubah
              </Button>
              <Button
                size="sm"
                variant="destructive"
                onClick={() => remove.mutate(faq.id)}
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
