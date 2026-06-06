"use client";

import { useEffect } from "react";

/**
 * Mirrors the reference scroll-reveal: reveals `.reveal` elements as they
 * enter the viewport, honoring per-element `data-delay`.
 */
export function RevealInit() {
  useEffect(() => {
    const reveals = Array.from(document.querySelectorAll<HTMLElement>(".reveal"));

    if (!("IntersectionObserver" in window)) {
      reveals.forEach((el) => el.classList.add("in"));
      return;
    }

    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((e) => {
          if (e.isIntersecting) {
            e.target.classList.add("in");
            io.unobserve(e.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
    );

    reveals.forEach((el) => {
      const d = el.getAttribute("data-delay");
      if (d) el.style.transitionDelay = `${d}ms`;
      io.observe(el);
    });

    return () => io.disconnect();
  }, []);

  return null;
}
