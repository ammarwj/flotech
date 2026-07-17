"use client";

import { useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { observeReveals } from "@/components/landing/reveal-init";
import { Skeleton } from "@/components/ui/skeleton";
import { getPublicFaqs } from "@/lib/api/landing";
import { ChevronDown } from "./icons";

export function Faq() {
  const [open, setOpen] = useState(0);
  const listRef = useRef<HTMLDivElement>(null);

  const query = useQuery({ queryKey: ["public-faqs"], queryFn: getPublicFaqs });
  const faqs = query.data;

  // The list doesn't exist when RevealInit sweeps the page.
  useEffect(() => {
    if (!faqs || !listRef.current) return;
    return observeReveals(listRef.current);
  }, [faqs]);

  // Nothing to say beats a heading over an empty list.
  if (query.isError || faqs?.length === 0) return null;

  return (
    <section
      className="section section-sm"
      style={{ background: "var(--bg-alt)", borderBlock: "1px solid var(--border)" }}
    >
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">FAQ</span>
          <h2 className="section-title">Pertanyaan yang sering ditanyakan</h2>
        </div>
        <div className="faq-list" ref={listRef}>
          {query.isPending &&
            Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="faq-item" aria-hidden>
                <Skeleton className="h-12 w-full" />
              </div>
            ))}
          {faqs?.map((item, i) => (
            <div key={item.id} className={`faq-item${open === i ? " open" : ""}`}>
              <button className="faq-q" onClick={() => setOpen(open === i ? -1 : i)}>
                {item.question}
                <span className="chev">
                  <ChevronDown />
                </span>
              </button>
              <div className="faq-a">
                <p>{item.answer}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
