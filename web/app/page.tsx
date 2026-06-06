import { Nav } from "@/components/landing/nav";
import { Hero, Proof } from "@/components/landing/hero";
import { Features } from "@/components/landing/features";
import { Sports } from "@/components/landing/sports";
import { HowItWorks } from "@/components/landing/how-it-works";
import { Certificate } from "@/components/landing/certificate";
import { Tickets } from "@/components/landing/tickets";
import { Pricing } from "@/components/landing/pricing";
import { Testimonials } from "@/components/landing/testimonials";
import { Faq } from "@/components/landing/faq";
import { Cta } from "@/components/landing/cta";
import { Footer } from "@/components/landing/footer";
import { RevealInit } from "@/components/landing/reveal-init";

export default function Home() {
  return (
    <>
      <Nav />
      <main>
        <Hero />
        <Proof />
        <Features />
        <Sports />
        <HowItWorks />
        <Certificate />
        <Tickets />
        <Pricing />
        <Testimonials />
        <Faq />
        <Cta />
      </main>
      <Footer />
      <RevealInit />
    </>
  );
}
