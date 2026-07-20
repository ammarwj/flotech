"use client";

import * as React from "react";
import { AlertTriangle, HelpCircle } from "lucide-react";
import type { LucideIcon } from "lucide-react";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogBody,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogFooter,
  AlertDialogHeader,
} from "@/components/ui/alert-dialog";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  dialogConsequences,
  type DialogTone,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { cn } from "@/lib/utils";

/**
 * App-wide replacements for `window.confirm` and `window.prompt`.
 *
 * Both return a promise, so a call site reads almost exactly like the native
 * call it replaces:
 *
 *     if (await confirm({ title: "Hapus?" })) mutation.mutate();
 *
 * That shape was chosen over a declarative `<ConfirmDialog open={…}>` on
 * purpose. Half the call sites confirm an action on a *row* — a ticket
 * category, a user, a match — so the declarative form would need a second piece
 * of state holding which row is pending, and stale-pending-id is a bug class
 * this version cannot have. There is exactly one dialog instance, so dialogs
 * also can't stack.
 */

export interface ConfirmOptions {
  title: string;
  description?: string;
  /**
   * What the action costs. Rendered as a tinted strip, not as more prose —
   * keeping it out of `description` is what stops these from turning back into
   * the run-on `\n\n` strings the native dialogs forced.
   */
  consequences?: React.ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: DialogTone;
  icon?: LucideIcon;
}

export interface PromptOptions extends Omit<ConfirmOptions, "confirmLabel"> {
  /** Field label, e.g. "Alasan refund". */
  label: string;
  placeholder?: string;
  defaultValue?: string;
  multiline?: boolean;
  /** Defaults to true — both current call sites already demand a reason. */
  required?: boolean;
  requiredMessage?: string;
  confirmLabel?: string;
}

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;
type PromptFn = (options: PromptOptions) => Promise<string | null>;

const ConfirmContext = React.createContext<{ confirm: ConfirmFn; prompt: PromptFn } | null>(null);

function useDialogContext(hook: string) {
  const ctx = React.useContext(ConfirmContext);
  if (!ctx) throw new Error(`${hook} harus dipakai di dalam <ConfirmProvider>.`);
  return ctx;
}

export function useConfirm(): ConfirmFn {
  return useDialogContext("useConfirm").confirm;
}

export function usePrompt(): PromptFn {
  return useDialogContext("usePrompt").prompt;
}

/** The strip that spells out what is at stake. Absent when there's nothing to say. */
function Consequences({ tone, children }: { tone: DialogTone; children: React.ReactNode }) {
  return <div className={dialogConsequences[tone]}>{children}</div>;
}

interface ConfirmState {
  options: ConfirmOptions;
  resolve: (value: boolean) => void;
}

interface PromptState {
  options: PromptOptions;
  resolve: (value: string | null) => void;
}

export function ConfirmProvider({ children }: { children: React.ReactNode }) {
  const [confirmState, setConfirmState] = React.useState<ConfirmState | null>(null);
  const [confirmOpen, setConfirmOpen] = React.useState(false);
  const [promptState, setPromptState] = React.useState<PromptState | null>(null);
  const [promptOpen, setPromptOpen] = React.useState(false);
  const [value, setValue] = React.useState("");
  const [error, setError] = React.useState<string>();

  // A pending promise that never settles would hang its caller's `await`
  // forever, so unmounting resolves whatever is open with the cancel answer.
  // The ref is synced from an effect, not during render — reading state in the
  // unmount cleanup would otherwise capture the value from first mount.
  const pending = React.useRef<{ confirm?: ConfirmState; prompt?: PromptState }>({});
  React.useEffect(() => {
    pending.current = { confirm: confirmState ?? undefined, prompt: promptState ?? undefined };
  }, [confirmState, promptState]);
  React.useEffect(
    () => () => {
      pending.current.confirm?.resolve(false);
      pending.current.prompt?.resolve(null);
    },
    []
  );

  const confirm = React.useCallback<ConfirmFn>(
    (options) =>
      new Promise((resolve) => {
        setConfirmState({ options, resolve });
        setConfirmOpen(true);
      }),
    []
  );

  const prompt = React.useCallback<PromptFn>(
    (options) =>
      new Promise((resolve) => {
        setValue(options.defaultValue ?? "");
        setError(undefined);
        setPromptState({ options, resolve });
        setPromptOpen(true);
      }),
    []
  );

  // State is left populated on close so the text doesn't blank out mid-exit;
  // Radix unmounts the node once the animation ends, so it's never seen.
  const settleConfirm = (answer: boolean) => {
    confirmState?.resolve(answer);
    setConfirmState((s) => (s ? { ...s, resolve: () => {} } : null));
    setConfirmOpen(false);
  };

  const settlePrompt = (answer: string | null) => {
    promptState?.resolve(answer);
    setPromptState((s) => (s ? { ...s, resolve: () => {} } : null));
    setPromptOpen(false);
  };

  const submitPrompt = () => {
    const o = promptState?.options;
    if (!o) return;
    const trimmed = value.trim();

    // Field-level validation belongs beside the field, never in a toast.
    if (o.required !== false && trimmed === "") {
      setError(o.requiredMessage ?? "Alasan wajib diisi.");
      return;
    }
    settlePrompt(trimmed);
  };

  const c = confirmState?.options;
  const cTone = c?.tone ?? "default";
  const cIcon = c?.icon ?? (cTone === "danger" ? AlertTriangle : HelpCircle);

  const p = promptState?.options;
  const pTone = p?.tone ?? "default";
  const pIcon = p?.icon ?? (pTone === "danger" ? AlertTriangle : HelpCircle);
  const Field = p?.multiline ? Textarea : Input;

  return (
    <ConfirmContext.Provider value={{ confirm, prompt }}>
      {children}

      <AlertDialog
        open={confirmOpen}
        onOpenChange={(next) => {
          if (!next) settleConfirm(false);
        }}
      >
        {c && (
          <AlertDialogContent placement="sheet">
            <AlertDialogHeader
              icon={cIcon}
              title={c.title}
              description={c.description}
              tone={cTone}
            />
            {c.consequences && (
              <AlertDialogBody>
                <Consequences tone={cTone}>{c.consequences}</Consequences>
              </AlertDialogBody>
            )}
            <AlertDialogFooter>
              <AlertDialogCancel asChild>
                <Button variant="ghost" className="w-full sm:w-auto">
                  {c.cancelLabel ?? "Batal"}
                </Button>
              </AlertDialogCancel>
              <AlertDialogAction asChild>
                <Button
                  variant={cTone === "danger" ? "destructive" : "default"}
                  className="w-full sm:w-auto"
                  onClick={() => settleConfirm(true)}
                >
                  {c.confirmLabel ?? "Lanjutkan"}
                </Button>
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        )}
      </AlertDialog>

      <Dialog
        open={promptOpen}
        onOpenChange={(next) => {
          if (!next) settlePrompt(null);
        }}
      >
        {p && (
          <DialogContent
            placement="sheet"
            // Radix would otherwise land on the close button; the field is the
            // only reason this dialog exists.
            onOpenAutoFocus={(e) => {
              e.preventDefault();
              const el = document.getElementById("prompt-field");
              (el as HTMLInputElement | HTMLTextAreaElement | null)?.focus();
            }}
          >
            <DialogHeader icon={pIcon} title={p.title} description={p.description} tone={pTone} />
            <DialogBody>
              {p.consequences && <Consequences tone={pTone}>{p.consequences}</Consequences>}
              <div className="grid gap-1.5">
                <Label htmlFor="prompt-field" className="font-semibold">
                  {p.label}
                </Label>
                <Field
                  id="prompt-field"
                  value={value}
                  placeholder={p.placeholder}
                  aria-invalid={!!error}
                  aria-describedby={error ? "prompt-error" : undefined}
                  onChange={(e: React.ChangeEvent<HTMLInputElement & HTMLTextAreaElement>) => {
                    setValue(e.target.value);
                    if (error) setError(undefined);
                  }}
                  onKeyDown={(e: React.KeyboardEvent) => {
                    // Enter submits a single-line field; a textarea keeps it.
                    if (e.key === "Enter" && !p.multiline) {
                      e.preventDefault();
                      submitPrompt();
                    }
                  }}
                  className={cn(error && "border-destructive focus-visible:ring-destructive")}
                />
                {error && (
                  <p id="prompt-error" className="text-xs text-[var(--danger)]">
                    {error}
                  </p>
                )}
              </div>
            </DialogBody>
            <DialogFooter>
              <Button
                variant="ghost"
                className="w-full sm:w-auto"
                onClick={() => settlePrompt(null)}
              >
                {p.cancelLabel ?? "Batal"}
              </Button>
              <Button
                variant={pTone === "danger" ? "destructive" : "default"}
                className="w-full sm:w-auto"
                onClick={submitPrompt}
              >
                {p.confirmLabel ?? "Lanjutkan"}
              </Button>
            </DialogFooter>
          </DialogContent>
        )}
      </Dialog>
    </ConfirmContext.Provider>
  );
}
