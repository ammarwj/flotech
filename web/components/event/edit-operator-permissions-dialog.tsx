"use client";

import { useState } from "react";
import { ShieldCheck } from "lucide-react";

import {
  OPERATOR_CATEGORIES,
  OPERATOR_CATEGORY_LABELS,
  isAllAccess,
  permissionsPayload,
  type OperatorCategory,
} from "@/lib/event-operator-permissions";
import type { FieldErrors } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { FieldError } from "@/components/ui/field-error";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import type { EventOperator, EventOperatorPermissions } from "@/types/api";

/**
 * Change what an existing operator can touch. Same derived "Semua Akses"
 * checkbox as the invite dialog — see its note on why that isn't stored state.
 */
export function EditOperatorPermissionsDialog({
  operator,
  pending,
  fieldErrors,
  onClose,
  onSubmit,
}: {
  operator: EventOperator | null;
  pending?: boolean;
  fieldErrors?: FieldErrors;
  onClose: () => void;
  onSubmit: (payload: EventOperatorPermissions) => void;
}) {
  const [checked, setChecked] = useState<Record<OperatorCategory, boolean>>({
    ticketing: operator?.can_ticketing ?? false,
    scheduling: operator?.can_scheduling ?? false,
    registration: operator?.can_registration ?? false,
    documents: operator?.can_documents ?? false,
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

  return (
    <Dialog
      // `key={operator?.id}` on the mount point re-seeds `checked` per
      // operator — see InviteOperatorDialog's note on why this isn't an
      // effect.
      key={operator?.id}
      open={!!operator}
      onOpenChange={(o) => !o && onClose()}
    >
      <DialogContent>
        <DialogHeader
          icon={ShieldCheck}
          title="Ubah Akses Operator"
          description={operator?.user.full_name}
        />
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (noneChecked) return;
            onSubmit(permissions);
          }}
        >
          <DialogBody>
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
                Pilih minimal satu kategori akses, atau hapus operator ini.
              </p>
            )}
            <FieldError message={fieldErrors?.can_ticketing} />
          </DialogBody>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={onClose}>
              Batal
            </Button>
            <Button type="submit" disabled={pending || noneChecked}>
              {pending ? "Menyimpan…" : "Simpan"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
