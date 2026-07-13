import Link from "next/link";

export function Logo({ href = "/" }: { href?: string }) {
  return (
    <Link href={href} className="logo">
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
  );
}
