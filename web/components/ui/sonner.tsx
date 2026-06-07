"use client";

import { useSyncExternalStore } from "react";
import { Toaster as Sonner, type ToasterProps } from "sonner";

type Theme = "light" | "dark";

function subscribe(onChange: () => void) {
  const observer = new MutationObserver(onChange);
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ["data-theme"],
  });
  return () => observer.disconnect();
}

const getSnapshot = (): Theme =>
  document.documentElement.getAttribute("data-theme") === "dark" ? "dark" : "light";

const getServerSnapshot = (): Theme => "light";

/** App-themed Sonner toaster; mirrors the `data-theme` attribute on <html>. */
export function Toaster(props: ToasterProps) {
  const theme = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  return <Sonner theme={theme} richColors closeButton position="top-right" {...props} />;
}
