"use client";

import { useState } from "react";
import { UserPlus } from "lucide-react";

import {
  OPERATOR_CATEGORIES,
  OPERATOR_CATEGORY_LABELS,
  isAllAccess,
  permissionsPayload,
  type OperatorCategory,
} from "@/lib/event-operator-permissions";
import type { FieldErrors } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FieldError } from "@/components/ui/field-error";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import type { EventOperatorPermissions } from "@/types/api";

/**
 * Invite someone to operate this event with a slice of the organizer surface.
 *
 * "Semua Akses" is deliberately NOT a fifth checkbox in state — it's
 * `isAllAccess(checked)` read back from the four category booleans, and
 * toggling it writes all four at once. A separate boolean here would be a
 * second fact that could disagree with what actually gets sent, the same
 * mistake `effective_payment_method` was written to avoid.
 */
export function InviteOperatorDialog({
  open,
  pending,
  fieldErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  pending?: boolean;
  fieldErrors?: FieldErrors;
  onClose: () => void;
  onSubmit: (payload: { email: string } & EventOperatorPermissions) => void;
}) {
  const [email, setEmail] = useState("");
  const [checked, setChecked] = useState<Record<OperatorCategory, boolean>>({
    ticketing: false,
    scheduling: false,
    registration: false,
    documents: false,
  });

  const permissions = permissionsPayload(checked);
  const allAccess = isAllAccess(permissions);
  const noneChecked = !Object.values(checked).some(Boolean);

  const toggleCategory = (category: OperatorCategory) =>
    setChecked((c) => ({ ...c, [category]: !c[category] }));

  const toggleAllAccess = () => {
    const next = !allAccess;
    setChecked({
      ticketing: next,
      scheduling: next,
      registration: next,
      documents: next,
    });
  };

  const handleClose = () => {
    setEmail("");
    setChecked({ ticketing: false, scheduling: false, registration: false, documents: false });
    onClose();
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !o && handleClose()}>
      <DialogContent>
        <DialogHeader
          icon={UserPlus}
          title="Undang Operator"
          description="Beri akses sebagian dashboard organizer untuk event ini ke orang lain."
        />
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (noneChecked) return;
            onSubmit({ email: email.trim(), ...permissions });
          }}
        >
          <DialogBody>
            <div className="grid gap-1.5">
              <Label htmlFor="operator-email">Email</Label>
              <Input
                id="operator-email"
                type="email"
                placeholder="nama@email.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                aria-invalid={!!fieldErrors?.email}
                required
              />
              <FieldError message={fieldErrors?.email} />
            </div>

            <div className="grid gap-2">
              <Label>Akses</Label>
              <label className="flex items-center gap-2 rounded-md border border-border bg-[var(--bg-soft)] px-3 py-2 text-sm font-medium">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-border"
                  checked={allAccess}
                  onChange={toggleAllAccess}
                />
                Semua Akses
              </label>
              <div className="grid gap-1.5 pl-1">
                {OPERATOR_CATEGORIES.map((category) => (
                  <label key={category} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-border"
                      checked={checked[category]}
                      onChange={() => toggleCategory(category)}
                    />
                    {OPERATOR_CATEGORY_LABELS[category]}
                  </label>
                ))}
              </div>
              {noneChecked && (
                <p className="text-xs text-muted-foreground">
                  Pilih minimal satu kategori akses.
                </p>
              )}
              <FieldError message={fieldErrors?.can_ticketing} />
            </div>

            {/* Anything the server refused that isn't bound to a field above. */}
            {fieldErrors &&
              Object.entries(fieldErrors)
                .filter(([field]) => field !== "email" && field !== "can_ticketing")
                .map(([field, message]) => <FieldError key={field} message={message} />)}
          </DialogBody>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={handleClose}>
              Batal
            </Button>
            <Button type="submit" disabled={pending || noneChecked || !email.trim()}>
              {pending ? "Mengundang…" : "Kirim Undangan"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
