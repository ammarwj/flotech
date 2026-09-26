import * as React from "react";
import { ChevronDown } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * Lightweight native <select> styled to match the shadcn input.
 * Keeps zero extra Radix deps while staying consistent with the design system.
 */
const Select = React.forwardRef<HTMLSelectElement, React.ComponentProps<"select">>(
  ({ className, children, ...props }, ref) => (
    // `min-w-0` di pembungkusnya, bukan di `className` pemanggil: yang jadi item
    // flex/grid adalah div ini, dan lebar min-content sebuah <select> adalah
    // opsi terpanjangnya — "Semua jenis akun" meluber keluar track-nya di lebar
    // ponsel walau `w-full` sudah dipasang, karena min-width menang atas width.
    // Di konteks blok `min-width: auto` memang sudah 0, jadi ini no-op di mana
    // pun kecuali tempat yang membutuhkannya.
    <div className="relative min-w-0">
      <select
        ref={ref}
        className={cn(
          "flex h-10 w-full appearance-none rounded-md border border-input bg-background px-3 py-2 pr-9 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50",
          className
        )}
        {...props}
      >
        {children}
      </select>
      <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
    </div>
  )
);
Select.displayName = "Select";

export { Select };
