import { Logo } from "@/components/shared/logo";

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen grid place-items-center bg-[var(--bg-alt)] px-4">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <Logo className="logo justify-center" />
        </div>
        <div className="rounded-xl border border-border bg-card p-8 shadow-sm">{children}</div>
      </div>
    </div>
  );
}
