import { z } from "zod";

/**
 * Mirrors ChangePasswordRequest: Password::min(8)->letters()->numbers(), plus
 * `confirmed` and `different:current_password`.
 *
 * Extracted because two screens now post to the same endpoint — the account
 * page and the forced rotation the officiating invite triggers. Two copies of a
 * strength rule drift in one direction only: the copy nobody looked at gets
 * looser, and the 422 it earns lands on a screen the user cannot leave.
 */
export const passwordFields = z.object({
  current_password: z.string().min(1, "Password saat ini wajib diisi"),
  password: z
    .string()
    .min(8, "Minimal 8 karakter")
    .regex(/\p{L}/u, "Harus mengandung minimal satu huruf")
    .regex(/\d/, "Harus mengandung minimal satu angka"),
  password_confirmation: z.string(),
});

export const passwordSchema = passwordFields
  .refine((d) => d.password === d.password_confirmation, {
    message: "Konfirmasi password tidak cocok",
    path: ["password_confirmation"],
  })
  .refine((d) => d.password !== d.current_password, {
    message: "Password baru harus berbeda dari password saat ini",
    path: ["password"],
  });

export type PasswordFormValues = z.infer<typeof passwordSchema>;
