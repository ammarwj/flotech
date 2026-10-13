"use client";

import { useCallback, useEffect, useRef } from "react";
import { EditorContent, useEditor, type Editor } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import Link from "@tiptap/extension-link";
import Image from "@tiptap/extension-image";
import { Placeholder } from "@tiptap/extensions";
import {
  Bold,
  Code,
  Heading2,
  Heading3,
  ImagePlus,
  Italic,
  Link2,
  Link2Off,
  List,
  ListOrdered,
  Loader2,
  Minus,
  Quote,
  Redo2,
  Strikethrough,
  Undo2,
} from "lucide-react";
import { toast } from "sonner";

import { uploadImage } from "@/lib/api/events";
import { compressToWebp } from "@/lib/image";
import { cn } from "@/lib/utils";

/**
 * The article body editor behind /admin/blog.
 *
 * > **The toolbar and the server's allow-list are a pair.** Everything this
 * > can produce is in `HTML.Allowed` in App\Support\HtmlSanitizer; everything
 * > it cannot is not. Adding a button without widening that list produces a
 * > control that appears to work and whose markup silently vanishes on save —
 * > the admin's formatting disappears with a green toast. Widen both, or
 * > neither.
 *
 * Sanitising stays on the server. Doing it here instead would guard only the
 * one client that happens to run this file.
 */
export function RichTextEditor({
  value,
  onChange,
  onBusyChange,
  placeholder = "Tulis artikelmu di sini…",
  className,
}: {
  value: string;
  onChange: (html: string) => void;
  /** Lets the form disable Save while an inline image is uploading. */
  onBusyChange?: (busy: boolean) => void;
  placeholder?: string;
  className?: string;
}) {
  const fileRef = useRef<HTMLInputElement>(null);
  const uploadingRef = useRef(false);

  const editor = useEditor({
    // Tiptap hydrates from `value` on mount, so leaving this on would make the
    // server HTML and the first client render disagree.
    immediatelyRender: false,
    extensions: [
      StarterKit.configure({
        // Only the levels the sanitiser keeps: h1 belongs to the page title,
        // not to a paragraph inside the article.
        heading: { levels: [2, 3, 4] },
        link: false,
      }),
      Link.configure({
        openOnClick: false,
        autolink: true,
        // Matches the server's HTML.TargetBlank + Attr.AllowedRel; without it
        // the editor shows one thing and the saved article renders another.
        HTMLAttributes: { rel: "noopener noreferrer", target: "_blank" },
      }),
      Image.configure({ HTMLAttributes: { loading: "lazy" } }),
      // The empty document is one empty <p> with no height; without this the
      // admin is given a blank box with nothing to aim at.
      Placeholder.configure({ placeholder }),
    ],
    content: value,
    editorProps: {
      attributes: {
        class: "blog-prose min-h-[320px] px-4 py-3 focus:outline-none",
        "data-placeholder": placeholder,
      },
    },
    onUpdate: ({ editor }) => onChange(editor.getHTML()),
  });

  // Re-sync when the form loads a different article into the same editor
  // instance. Guarded on equality: feeding back the HTML the editor just
  // emitted would reset the cursor to the top on every keystroke.
  useEffect(() => {
    if (!editor) return;
    if (value !== editor.getHTML()) {
      editor.commands.setContent(value || "", { emitUpdate: false });
    }
  }, [value, editor]);

  const setBusy = useCallback(
    (busy: boolean) => {
      uploadingRef.current = busy;
      onBusyChange?.(busy);
    },
    [onBusyChange]
  );

  const pickImage = async (file?: File | null) => {
    if (!file || !editor) return;

    if (!file.type.startsWith("image/")) {
      toast.error("File harus berupa gambar.");
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast.error("Ukuran gambar maksimal 5 MB.");
      return;
    }

    setBusy(true);
    try {
      const url = await uploadImage(await compressToWebp(file, { maxDim: 1600 }), "blog");
      editor.chain().focus().setImage({ src: url, alt: "" }).run();
    } catch {
      toast.error("Gagal mengunggah gambar.");
    } finally {
      setBusy(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  };

  const toggleLink = () => {
    if (!editor) return;

    if (editor.isActive("link")) {
      editor.chain().focus().unsetLink().run();
      return;
    }

    const href = window.prompt("Alamat tautan (https://…)");
    if (!href) return;

    // http(s) and mailto only, matching URI.AllowedSchemes on the server. A
    // javascript: href typed here would be stripped on save anyway — rejecting
    // it now just explains why instead of letting it vanish.
    if (!/^(https?:\/\/|mailto:)/i.test(href)) {
      toast.error("Tautan harus diawali https:// atau mailto:");
      return;
    }

    editor.chain().focus().extendMarkRange("link").setLink({ href }).run();
  };

  if (!editor) {
    return (
      <div
        className={cn(
          "grid min-h-[380px] place-items-center rounded-lg border border-input bg-background",
          className
        )}
      >
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" aria-label="Memuat editor" />
      </div>
    );
  }

  return (
    <div className={cn("overflow-hidden rounded-lg border border-input bg-background", className)}>
      <div className="flex flex-wrap items-center gap-0.5 border-b border-border bg-muted/40 p-1.5">
        <ToolButton
          editor={editor}
          label="Tebal"
          active="bold"
          onClick={() => editor.chain().focus().toggleBold().run()}
          icon={Bold}
        />
        <ToolButton
          editor={editor}
          label="Miring"
          active="italic"
          onClick={() => editor.chain().focus().toggleItalic().run()}
          icon={Italic}
        />
        <ToolButton
          editor={editor}
          label="Coret"
          active="strike"
          onClick={() => editor.chain().focus().toggleStrike().run()}
          icon={Strikethrough}
        />
        <ToolButton
          editor={editor}
          label="Kode"
          active="code"
          onClick={() => editor.chain().focus().toggleCode().run()}
          icon={Code}
        />

        <Divider />

        <ToolButton
          editor={editor}
          label="Subjudul"
          active={{ name: "heading", attrs: { level: 2 } }}
          onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
          icon={Heading2}
        />
        <ToolButton
          editor={editor}
          label="Sub-subjudul"
          active={{ name: "heading", attrs: { level: 3 } }}
          onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
          icon={Heading3}
        />

        <Divider />

        <ToolButton
          editor={editor}
          label="Daftar butir"
          active="bulletList"
          onClick={() => editor.chain().focus().toggleBulletList().run()}
          icon={List}
        />
        <ToolButton
          editor={editor}
          label="Daftar bernomor"
          active="orderedList"
          onClick={() => editor.chain().focus().toggleOrderedList().run()}
          icon={ListOrdered}
        />
        <ToolButton
          editor={editor}
          label="Kutipan"
          active="blockquote"
          onClick={() => editor.chain().focus().toggleBlockquote().run()}
          icon={Quote}
        />
        <ToolButton
          editor={editor}
          label="Garis pemisah"
          onClick={() => editor.chain().focus().setHorizontalRule().run()}
          icon={Minus}
        />

        <Divider />

        <ToolButton
          editor={editor}
          label={editor.isActive("link") ? "Hapus tautan" : "Tautan"}
          active="link"
          onClick={toggleLink}
          icon={editor.isActive("link") ? Link2Off : Link2}
        />
        <ToolButton
          editor={editor}
          label="Sisipkan gambar"
          onClick={() => fileRef.current?.click()}
          icon={ImagePlus}
        />

        <div className="ml-auto flex items-center gap-0.5">
          <ToolButton
            editor={editor}
            label="Urungkan"
            disabled={!editor.can().undo()}
            onClick={() => editor.chain().focus().undo().run()}
            icon={Undo2}
          />
          <ToolButton
            editor={editor}
            label="Ulangi"
            disabled={!editor.can().redo()}
            onClick={() => editor.chain().focus().redo().run()}
            icon={Redo2}
          />
        </div>
      </div>

      <input
        ref={fileRef}
        type="file"
        accept="image/*"
        className="hidden"
        onChange={(e) => void pickImage(e.target.files?.[0])}
      />

      <EditorContent editor={editor} />
    </div>
  );
}

function Divider() {
  return <span aria-hidden className="mx-1 h-5 w-px bg-border" />;
}

function ToolButton({
  editor,
  label,
  icon: Icon,
  onClick,
  active,
  disabled,
}: {
  editor: Editor;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  onClick: () => void;
  /** Mark/node name (with attrs where the node has levels) that lights it up. */
  active?: string | { name: string; attrs: Record<string, unknown> };
  disabled?: boolean;
}) {
  const isActive =
    typeof active === "string"
      ? editor.isActive(active)
      : active
        ? editor.isActive(active.name, active.attrs)
        : false;

  return (
    <button
      type="button"
      // type="button" is load-bearing: the editor lives inside the article
      // <form>, and the default submit type would publish the post on every
      // bold click.
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      aria-pressed={isActive}
      title={label}
      className={cn(
        "grid h-8 w-8 place-items-center rounded-md text-muted-foreground transition-colors",
        "hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40",
        isActive && "bg-[var(--tint)] text-[var(--brand-600)]"
      )}
    >
      <Icon className="h-4 w-4" />
    </button>
  );
}
