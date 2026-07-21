"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { toast } from "sonner";
import { KeyRound } from "lucide-react";

import { resetAdminUserPassword } from "@/lib/api/admin";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { PasswordInput } from "@/components/ui/password-input";
import { Dialog, DialogContent, DialogHeader, DialogBody, DialogFooter } from "@/components/ui/dialog";
import type { AdminUser } from "@/types/api";

/** Mirrors ResetUserPasswordRequest: Password::min(8)->letters()->numbers(). */
function validate(password: string, confirmation: string): string | null {
  if (password.length < 8) return "Minimal 8 karakter";
  if (!/\p{L}/u.test(password)) return "Harus mengandung minimal satu huruf";
  if (!/\d/.test(password)) return "Harus mengandung minimal satu angka";
  if (password !== confirmation) return "Konfirmasi password tidak cocok";
  return null;
}

/**
 * Super admin sets a user's password directly — the support path for someone
 * who can't receive the reset email. Deliberately a separate dialog from the
 * inline row actions: unlike verifying or role-changing, this one is not
 * reversible by clicking again.
 */
export function ResetPasswordDialog({
  user,
  open,
  onOpenChange,
}: {
  user: AdminUser;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);

  const reset = useMutation({
    mutationFn: () => resetAdminUserPassword(user.id, password, confirmation),
    onSuccess: (message) => {
      toast.success(message);
      close();
    },
    onError: (err) => setError(parseApiError(err, "Gagal mereset password.").message),
  });

  function close() {
    setPassword("");
    setConfirmation("");
    setError(null);
    onOpenChange(false);
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    const problem = validate(password, confirmation);
    setError(problem);
    if (!problem) reset.mutate();
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
      <DialogContent>
        <DialogHeader
          icon={KeyRound}
          title="Reset password pengguna"
          description={`Password baru untuk ${user.full_name || user.email}.`}
        />
        <form onSubmit={submit}>
          <DialogBody>
            <div className="grid gap-2">
              <Label htmlFor="admin-new-password">Password baru</Label>
              <PasswordInput
                id="admin-new-password"
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Minimal 8 karakter, mengandung huruf dan angka.
              </p>
            </div>

            <div className="grid gap-2">
              <Label htmlFor="admin-new-password-confirm">Konfirmasi password</Label>
              <PasswordInput
                id="admin-new-password-confirm"
                autoComplete="new-password"
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
              />
            </div>

            <p className="rounded-md border border-border bg-[var(--bg-soft)] px-3 py-2 text-xs text-muted-foreground">
              Semua sesi pengguna ini akan dikeluarkan. Sampaikan password barunya lewat kanal
              yang aman, dan minta mereka menggantinya sendiri setelah masuk.
            </p>

            {error && <p className="text-sm text-destructive">{error}</p>}
          </DialogBody>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={close} disabled={reset.isPending}>
              Batal
            </Button>
            <Button type="submit" disabled={reset.isPending}>
              {reset.isPending ? "Menyimpan…" : "Reset password"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
