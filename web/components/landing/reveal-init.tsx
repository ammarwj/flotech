"use client";

import { useEffect } from "react";

/**
 * Reveals the `.reveal` elements inside `root` as they enter the viewport,
 * honoring per-element `data-delay`. Returns a teardown.
 *
 * Exported because RevealInit only ever sees the elements present when the page
 * mounts: a section that renders cards from fetched data has to observe them
 * itself once they exist, or they stay stuck at opacity 0.
 */
export function observeReveals(root: ParentNode): () => void {
  const reveals = Array.from(root.querySelectorAll<HTMLElement>(".reveal"));

  if (!("IntersectionObserver" in window)) {
    reveals.forEach((el) => el.classList.add("in"));
    return () => {};
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
}

/** Mirrors the reference scroll-reveal for everything already in the document. */
export function RevealInit() {
  useEffect(() => observeReveals(document), []);

  return null;
}
