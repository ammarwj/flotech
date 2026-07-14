"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/lib/api/client";
import { getOrganizations } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { safeNext } from "@/lib/next-param";
import { useAuthStore, type AuthUser } from "@/stores/auth-store";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { Label } from "@/components/ui/label";

const schema = z.object({
  email: z.string().email("Email tidak valid"),
  password: z.string().min(8, "Minimal 8 karakter"),
});

type FormValues = z.infer<typeof schema>;

function LoginForm() {
  const router = useRouter();
  const qc = useQueryClient();
  const setAuth = useAuthStore((s) => s.setAuth);
  const [serverError, setServerError] = useState<string | null>(null);

  // Set when the visitor was sent here mid-flow (e.g. from team registration).
  const next = safeNext(useSearchParams().get("next"));

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: FormValues) => {
    setServerError(null);
    try {
      const { data } = await apiClient.post("/auth/login", values);
      const user = data.data.user as AuthUser;
      setAuth(data.data.access_token, user);

      // Came here to finish something else — go back to it. A participant
      // registering a team has no organization and no business in onboarding.
      if (next) {
        router.push(next);
        return;
      }

      if (user.role === "super_admin") {
        router.push("/admin");
        return;
      }

      // A user who never finished onboarding owns no organization; the organizer
      // dashboard is useless to them, so route them back into the flow.
      const orgs = await getOrganizations();
      qc.setQueryData(["organizations"], orgs);
      router.push(orgs.length === 0 ? "/onboarding" : "/organizer");
    } catch (err) {
      setServerError(parseApiError(err, "Login gagal").message);
    }
  };

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        Masuk ke akunmu
      </h1>
      <p className="mt-2 text-sm text-muted-foreground">
        Belum punya akun?{" "}
        <Link href={next ? `/register?next=${encodeURIComponent(next)}` : "/register"} className="text-primary font-medium hover:underline">
          Daftar gratis
        </Link>
      </p>

      <form onSubmit={handleSubmit(onSubmit)} className="mt-6 grid gap-4">
        <div className="grid gap-2">
          <Label htmlFor="email">Email</Label>
          <Input id="email" type="email" autoComplete="email" {...register("email")} />
          {errors.email && <p className="text-xs text-destructive">{errors.email.message}</p>}
        </div>

        <div className="grid gap-2">
          <div className="flex items-center justify-between">
            <Label htmlFor="password">Password</Label>
            <Link
              href="/forgot-password"
              className="text-xs font-medium text-muted-foreground hover:text-primary hover:underline"
            >
              Lupa password?
            </Link>
          </div>
          <PasswordInput
            id="password"
            autoComplete="current-password"
            {...register("password")}
          />
          {errors.password && (
            <p className="text-xs text-destructive">{errors.password.message}</p>
          )}
        </div>

        {serverError && <p className="text-sm text-destructive">{serverError}</p>}

        <Button type="submit" size="lg" disabled={isSubmitting}>
          {isSubmitting ? "Memproses…" : "Masuk"}
        </Button>
      </form>
    </div>
  );
}

// useSearchParams needs a Suspense boundary to keep the page statically
// renderable (same shape as /reset-password).
export default function LoginPage() {
  return (
    <Suspense fallback={null}>
      <LoginForm />
    </Suspense>
  );
}
