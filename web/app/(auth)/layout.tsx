import Link from "next/link";

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen grid place-items-center bg-[var(--bg-alt)] px-4">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <Link href="/" className="logo justify-center">
            <span className="logo-mark">
              <svg viewBox="0 0 24 24" fill="none">
                <path
                  d="M5 4h14l-2 6H7l1 10"
                  stroke="#fff"
                  strokeWidth="2.2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
                <circle cx="9" cy="12" r="1.4" fill="#fff" />
              </svg>
            </span>
            flo<span>-event</span>
          </Link>
        </div>
        <div className="rounded-xl border border-border bg-card p-8 shadow-sm">{children}</div>
      </div>
    </div>
  );
}
