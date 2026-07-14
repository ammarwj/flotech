"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";

import { resetPassword } from "@/lib/api/auth";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import { Label } from "@/components/ui/label";

// Mirrors ResetPasswordRequest: Password::min(8)->letters()->numbers().
const fields = z.object({
  password: z
    .string()
    .min(8, "Minimal 8 karakter")
    .regex(/\p{L}/u, "Harus mengandung minimal satu huruf")
    .regex(/\d/, "Harus mengandung minimal satu angka"),
  password_confirmation: z.string(),
});

const schema = fields.refine((d) => d.password === d.password_confirmation, {
  message: "Konfirmasi password tidak cocok",
  path: ["password_confirmation"],
});

type FormValues = z.infer<typeof schema>;

function ResetForm() {
  const router = useRouter();
  const params = useSearchParams();
  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";
  const [error, setError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError: setFieldError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: FormValues) => {
    setError(null);
    try {
      await resetPassword({ token, email, ...values });
      toast.success("Password berhasil direset. Silakan masuk dengan password barumu.");
      router.push("/login");
    } catch (err) {
      const { message, fieldErrors } = parseApiError(err, "Gagal mereset password");
      const entries = Object.entries(fieldErrors);

      for (const [field, msg] of entries) {
        if (field in fields.shape) {
          setFieldError(field as keyof FormValues, { message: msg });
        }
      }

      // An expired or already-used token comes back on `token`/`email`, neither of
      // which is a field on this form — surface it as the form-level error.
      if (entries.length === 0 || !entries.some(([f]) => f in fields.shape)) {
        setError(message);
      }
    }
  };

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        Reset password
      </h1>
      <p className="mt-2 text-sm text-muted-foreground">Buat password baru untuk {email || "akunmu"}.</p>

      <form onSubmit={handleSubmit(onSubmit)} className="mt-6 grid gap-4">
        <div className="grid gap-2">
          <Label htmlFor="password">Password baru</Label>
          <PasswordInput id="password" autoComplete="new-password" {...register("password")} />
          {errors.password && <p className="text-xs text-destructive">{errors.password.message}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="password_confirmation">Konfirmasi password</Label>
          <PasswordInput
            id="password_confirmation"
            autoComplete="new-password"
            {...register("password_confirmation")}
          />
          {errors.password_confirmation && (
            <p className="text-xs text-destructive">{errors.password_confirmation.message}</p>
          )}
        </div>
        {error && <p className="text-sm text-destructive">{error}</p>}
        <Button type="submit" size="lg" disabled={isSubmitting || !token}>
          {isSubmitting ? "Menyimpan…" : "Simpan password baru"}
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-muted-foreground">
        <Link href="/login" className="text-primary font-medium hover:underline">
          Kembali ke login
        </Link>
      </p>
    </div>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={null}>
      <ResetForm />
    </Suspense>
  );
}
