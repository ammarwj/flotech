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
