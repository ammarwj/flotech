"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { BadgeCheck, KeyRound, ShieldAlert, UserRound } from "lucide-react";

import { changePassword } from "@/lib/api/auth";
import { parseApiError } from "@/lib/api/errors";
import { useAuthStore } from "@/stores/auth-store";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Label } from "@/components/ui/label";
import { PasswordInput } from "@/components/ui/password-input";
import { PageHeader } from "@/components/shared/page-header";
import { SectionHeader } from "@/components/event/section-header";

// Mirrors ChangePasswordRequest: Password::min(8)->letters()->numbers().
const fields = z.object({
  current_password: z.string().min(1, "Password saat ini wajib diisi"),
  password: z
    .string()
    .min(8, "Minimal 8 karakter")
    .regex(/\p{L}/u, "Harus mengandung minimal satu huruf")
    .regex(/\d/, "Harus mengandung minimal satu angka"),
  password_confirmation: z.string(),
});

const schema = fields
  .refine((d) => d.password === d.password_confirmation, {
    message: "Konfirmasi password tidak cocok",
    path: ["password_confirmation"],
  })
  .refine((d) => d.password !== d.current_password, {
    message: "Password baru harus berbeda dari password saat ini",
    path: ["password"],
  });

type FormValues = z.infer<typeof schema>;

export default function AccountPage() {
  const user = useAuthStore((s) => s.user);
  // Under impersonation the token belongs to someone else, so this form would
  // change *their* password — and the admin doesn't know the current one anyway.
  const impersonating = useAuthStore((s) => s.impersonating);

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: FormValues) => {
    try {
      const { message } = await changePassword(values);
      toast.success(message);
      reset();
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
    <>
      <PageHeader
        title="Akun Saya"
        description="Data akun dan keamanan. Berlaku untuk semua mode — organizer maupun peserta."
      />

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <Card>
          <SectionHeader
            icon={KeyRound}
            title="Ubah password"
            description="Password saat ini diminta untuk memastikan ini benar-benar kamu."
          />
          <CardContent>
            {impersonating ? (
              <div className="flex items-start gap-3 rounded-lg border border-border bg-[var(--bg-soft)] p-4">
                <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">
                  Kamu sedang login sebagai pengguna lain. Kembali ke akun admin dulu untuk
                  mengubah password sendiri — atau reset password pengguna ini dari{" "}
                  <span className="font-medium text-foreground">Manajemen User</span>.
                </p>
              </div>
            ) : (
              <form onSubmit={handleSubmit(onSubmit)} className="grid max-w-md gap-4">
                {/* Hidden username field: without it password managers can't tell
                    which account the new password belongs to. */}
                <input
                  type="text"
                  name="username"
                  autoComplete="username"
                  value={user?.email ?? ""}
                  readOnly
                  hidden
                />

                <div className="grid gap-2">
                  <Label htmlFor="current_password">Password saat ini</Label>
                  <PasswordInput
                    id="current_password"
                    autoComplete="current-password"
                    {...register("current_password")}
                  />
                  {errors.current_password && (
                    <p className="text-xs text-destructive">{errors.current_password.message}</p>
                  )}
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="password">Password baru</Label>
                  <PasswordInput
                    id="password"
                    autoComplete="new-password"
                    {...register("password")}
                  />
                  {errors.password ? (
                    <p className="text-xs text-destructive">{errors.password.message}</p>
                  ) : (
                    <p className="text-xs text-muted-foreground">
                      Minimal 8 karakter, mengandung huruf dan angka.
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

                <p className="text-xs text-muted-foreground">
                  Setelah diubah, sesi di perangkat lain akan dikeluarkan. Perangkat ini tetap
                  masuk.
                </p>

                <div>
                  <Button type="submit" disabled={isSubmitting}>
                    {isSubmitting ? "Menyimpan…" : "Simpan password baru"}
                  </Button>
                </div>
              </form>
            )}
          </CardContent>
        </Card>

        <Card className="h-fit">
          <SectionHeader
            icon={UserRound}
            title="Profil"
            description="Identitas akun yang dipakai untuk masuk."
          />
          <CardContent>
            <dl className="grid gap-3 text-sm">
              <div>
                <dt className="text-xs text-muted-foreground">Nama</dt>
                <dd className="font-medium">{user?.full_name || "—"}</dd>
              </div>
              <div className="min-w-0">
                <dt className="text-xs text-muted-foreground">Email</dt>
                <dd className="truncate font-medium">{user?.email}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Status</dt>
                <dd className="mt-1">
                  {user?.is_verified ? (
                    <Badge variant="success">
                      <BadgeCheck className="h-3 w-3" />
                      Terverifikasi
                    </Badge>
                  ) : (
                    <Badge variant="warning">Belum verifikasi</Badge>
                  )}
                </dd>
              </div>
            </dl>
          </CardContent>
        </Card>
      </div>
    </>
  );
}
