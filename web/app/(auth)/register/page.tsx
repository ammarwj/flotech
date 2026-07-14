"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { register as registerUser } from "@/lib/api/auth";
import { parseApiError } from "@/lib/api/errors";
import { safeNext } from "@/lib/next-param";
import { useAuthStore } from "@/stores/auth-store";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { Label } from "@/components/ui/label";

const fields = z.object({
  full_name: z.string().min(2, "Nama minimal 2 karakter"),
  email: z.string().email("Email tidak valid"),
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

function RegisterForm() {
  const router = useRouter();
  const setAuth = useAuthStore((s) => s.setAuth);
  const [serverError, setServerError] = useState<string | null>(null);

  // Sent here mid-flow (e.g. from team registration) — go back there after.
  const next = safeNext(useSearchParams().get("next"));

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: FormValues) => {
    setServerError(null);
    try {
      const res = await registerUser(values);
      setAuth(res.access_token, res.user);

      // Onboarding builds an *organization*; someone who came to sign a team up
      // for someone else's tournament doesn't need one.
      router.push(next ?? "/onboarding");
    } catch (err) {
      const { message, fieldErrors } = parseApiError(err, "Registrasi gagal");
      const entries = Object.entries(fieldErrors);

      for (const [field, msg] of entries) {
        if (field in fields.shape) {
          setError(field as keyof FormValues, { message: msg });
        }
      }

      if (entries.length === 0) setServerError(message);
    }
  };

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        Buat akun gratis
      </h1>
      <p className="mt-2 text-sm text-muted-foreground">
        Sudah punya akun?{" "}
        <Link href={next ? `/login?next=${encodeURIComponent(next)}` : "/login"} className="text-primary font-medium hover:underline">
          Masuk
        </Link>
      </p>

      <form onSubmit={handleSubmit(onSubmit)} className="mt-6 grid gap-4">
        <div className="grid gap-2">
          <Label htmlFor="full_name">Nama lengkap</Label>
          <Input id="full_name" {...register("full_name")} />
          {errors.full_name && <p className="text-xs text-destructive">{errors.full_name.message}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="email">Email</Label>
          <Input id="email" type="email" autoComplete="email" {...register("email")} />
          {errors.email && <p className="text-xs text-destructive">{errors.email.message}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="password">Password</Label>
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

        {serverError && <p className="text-sm text-destructive">{serverError}</p>}

        <Button type="submit" size="lg" disabled={isSubmitting}>
          {isSubmitting ? "Memproses…" : "Daftar gratis"}
        </Button>
      </form>
    </div>
  );
}

// useSearchParams needs a Suspense boundary to keep the page statically
// renderable (same shape as /login).
export default function RegisterPage() {
  return (
    <Suspense fallback={null}>
      <RegisterForm />
    </Suspense>
  );
}
