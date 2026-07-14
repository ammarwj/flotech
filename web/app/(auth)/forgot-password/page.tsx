"use client";

import { useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { forgotPassword } from "@/lib/api/auth";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const schema = z.object({ email: z.string().email("Email tidak valid") });
type FormValues = z.infer<typeof schema>;

export default function ForgotPasswordPage() {
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError: setFieldError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: FormValues) => {
    setError(null);
    setMessage(null);
    try {
      const msg = await forgotPassword(values.email);
      setMessage(msg);
    } catch (err) {
      const { message, fieldErrors } = parseApiError(err, "Gagal mengirim tautan reset");

      if (fieldErrors.email) setFieldError("email", { message: fieldErrors.email });
      else setError(message);
    }
  };

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        Lupa password
      </h1>
      <p className="mt-2 text-sm text-muted-foreground">
        Masukkan emailmu, kami kirimkan tautan reset.
      </p>

      {message ? (
        <p className="mt-6 rounded-lg border border-border bg-accent px-4 py-3.5 text-sm leading-relaxed text-accent-foreground">
          {message}
        </p>
      ) : (
        <form onSubmit={handleSubmit(onSubmit)} className="mt-6 grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" type="email" autoComplete="email" {...register("email")} />
            {errors.email && <p className="text-xs text-destructive">{errors.email.message}</p>}
          </div>
          {error && <p className="text-sm text-destructive">{error}</p>}
          <Button type="submit" size="lg" disabled={isSubmitting}>
            {isSubmitting ? "Mengirim…" : "Kirim tautan reset"}
          </Button>
        </form>
      )}

      <p className="mt-8 border-t border-border pt-6 text-center text-sm text-muted-foreground">
        <Link href="/login" className="text-primary font-medium hover:underline">
          Kembali ke login
        </Link>
      </p>
    </div>
  );
}
