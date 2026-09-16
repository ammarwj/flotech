"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { KeyRound } from "lucide-react";

import { changePassword } from "@/lib/api/auth";
import { parseApiError } from "@/lib/api/errors";
import {
  passwordFields as fields,
  passwordSchema as schema,
  type PasswordFormValues as FormValues,
} from "@/lib/password";
import { useAuthStore } from "@/stores/auth-store";
import { useLogout } from "@/lib/hooks/use-logout";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { PasswordInput } from "@/components/ui/password-input";
import { Logo } from "@/components/shared/logo";

/**
 * Full-screen takeover for an account still holding the password it was mailed.
 *
 * A takeover, not a redirect: a redirect leaves every other dashboard URL
 * reachable by typing it, and the user who does that gets a 403 with no screen
 * explaining it. Rendered instead of the shell's children, there is no route
 * inside (dashboard) that routes around it.
 *
 * It is not the control, though — `password.rotated` on the server is. This is
 * the part that tells the person what happened and lets them fix it.
 */
export function ForcePasswordGate() {
  const user = useAuthStore((s) => s.user);
  const accessToken = useAuthStore((s) => s.accessToken);
  const setAuth = useAuthStore((s) => s.setAuth);
  const { logout, pending: loggingOut } = useLogout();

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: FormValues) => {
    try {
      await changePassword(values);
      // The token stays valid — the server spares this device — so lifting the
      // flag locally is all that is needed to drop the takeover. me() would say
      // the same thing one round trip later.
      if (accessToken && user) setAuth(accessToken, { ...user, must_change_password: false });
      toast.success("Password berhasil diganti. Selamat bertugas!");
    } catch (err) {
      const { message, fieldErrors } = parseApiError(err, "Gagal mengubah password");
      let inline = false;

      for (const [field, msg] of Object.entries(fieldErrors)) {
        if (field in fields.shape) {
          setError(field as keyof FormValues, { message: msg });
          inline = true;
        }
      }

      if (!inline) toast.error(message);
    }
  };

  return (
    <div className="grid min-h-screen place-items-center bg-[var(--bg-alt)] px-5 py-10">
      <div className="w-full max-w-md">
        <div className="mb-6 flex justify-center">
          <Logo />
        </div>

        <Card>
          <CardContent className="grid gap-5 p-6">
            <div className="grid gap-2 text-center">
              <span className="mx-auto grid h-11 w-11 place-items-center rounded-full bg-[color-mix(in_srgb,var(--brand-500)_12%,transparent)] text-[var(--brand-500)]">
                <KeyRound className="h-5 w-5" />
              </span>
              <h1 className="text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
                Ganti password dulu
              </h1>
              <p className="text-sm text-muted-foreground">
                Akun ini dibuatkan penyelenggara dengan password sementara yang dikirim lewat
                email. Ganti dulu sebelum mulai bertugas.
              </p>
            </div>

            <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4">
              {/* Password managers need to know which account this belongs to. */}
              <input
                type="text"
                name="username"
                autoComplete="username"
                value={user?.email ?? ""}
                readOnly
                hidden
              />

              <div className="grid gap-2">
                <Label htmlFor="current_password">Password sementara</Label>
                {/* Never prefilled, even though the default is a constant we
                    know: the person has to have read their own mail. */}
                <PasswordInput
                  id="current_password"
                  autoComplete="current-password"
                  {...register("current_password")}
                />
                {errors.current_password ? (
                  <p className="text-xs text-destructive">{errors.current_password.message}</p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    Yang tertulis di email undangan.
                  </p>
                )}
              </div>

              <div className="grid gap-2">
                <Label htmlFor="password">Password baru</Label>
                <PasswordInput id="password" autoComplete="new-password" {...register("password")} />
                {errors.password ? (
                  <p className="text-xs text-destructive">{errors.password.message}</p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    Minimal 8 karakter, mengandung huruf dan angka. Harus berbeda dari password
                    sementara.
                  </p>
                )}
              </div>

              <div className="grid gap-2">
                <Label htmlFor="password_confirmation">Konfirmasi password baru</Label>
                <PasswordInput
                  id="password_confirmation"
                  autoComplete="new-password"
                  {...register("password_confirmation")}
                />
                {errors.password_confirmation && (
                  <p className="text-xs text-destructive">
                    {errors.password_confirmation.message}
                  </p>
                )}
              </div>

              <Button type="submit" size="lg" disabled={isSubmitting}>
                {isSubmitting ? "Menyimpan…" : "Simpan & lanjutkan"}
              </Button>
            </form>

            {/* The only other way out of this screen. Without it a wrong
                account is a dead end — there is no header, no menu, nothing. */}
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="text-muted-foreground"
              onClick={logout}
              disabled={loggingOut}
            >
              Keluar
            </Button>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
