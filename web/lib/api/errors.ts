import { AxiosError } from "axios";

/** Flat map of field name → first validation message. */
export type FieldErrors = Record<string, string>;

export interface ParsedApiError {
  /** Top-level human message for a banner. */
  message: string;
  /** Per-field validation messages (Laravel 422 `errors` bag), if any. */
  fieldErrors: FieldErrors;
}

interface LaravelErrorBody {
  message?: string;
  errors?: Record<string, string[]>;
}

/**
 * Normalizes an API/network error into a banner message plus per-field messages.
 * Laravel returns `{ message, errors: { field: [msg, ...] } }` on 422; we keep
 * the first message per field for inline display.
 */
export function parseApiError(
  err: unknown,
  fallback = "Terjadi kesalahan. Silakan coba lagi."
): ParsedApiError {
  if (err instanceof AxiosError && err.response?.data) {
    const body = err.response.data as LaravelErrorBody;
    const fieldErrors: FieldErrors = {};

    for (const [field, messages] of Object.entries(body.errors ?? {})) {
      if (Array.isArray(messages) && messages.length > 0) {
        fieldErrors[field] = messages[0];
      }
    }

    return { message: body.message ?? fallback, fieldErrors };
  }

  return { message: fallback, fieldErrors: {} };
}

/**
 * The message for one field of a 422, or `fallback` for anything else.
 *
 * For uploads, where the interesting failure is a size the browser already
 * checked against its own copy of the cap: PHP's limits sit under ours and can
 * be smaller, so the server is the only one that knows the real number. Showing
 * a generic "coba lagi" there hides a message that says exactly what to do.
 */
export function fieldErrorMessage(err: unknown, field: string, fallback: string): string {
  return parseApiError(err, fallback).fieldErrors[field] ?? fallback;
}

/**
 * True when a request was rejected because a plan limit/feature gate was hit
 * (HTTP 403 with a `feature` marker in the error bag).
 */
export function isPlanLimitError(err: unknown): boolean {
  if (!(err instanceof AxiosError) || err.response?.status !== 403) return false;
  const body = err.response?.data as { errors?: { feature?: unknown } } | undefined;
  return typeof body?.errors?.feature === "string";
}
