import type { Metadata } from "next";
import { Nav } from "@/components/landing/nav";
import { Pricing } from "@/components/landing/pricing";
import { Footer } from "@/components/landing/footer";
import { RevealInit } from "@/components/landing/reveal-init";

export const metadata: Metadata = {
  title: "Harga — flo-event",
  description: "Pilih paket flo-event yang sesuai dengan skala turnamenmu.",
};

export default function PricingPage() {
  return (
    <>
      <Nav />
      <main>
        <Pricing />
      </main>
      <Footer />
      <RevealInit />
    </>
  );
}
