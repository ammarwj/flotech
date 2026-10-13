"use client";

import { use } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";

import { getAdminBlogPost } from "@/lib/api/blog";
import { BlogPostForm } from "@/components/admin/blog-post-form";
import { Button } from "@/components/ui/button";

export default function EditBlogPostPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);

  // The admin endpoint, not the public one: that serves published rows only,
  // so a draft could never be reopened through it.
  const query = useQuery({
    queryKey: ["admin-blog-post", id],
    queryFn: () => getAdminBlogPost(id),
  });

  if (query.isLoading) {
    return (
      <div className="grid min-h-[50vh] place-items-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" aria-label="Memuat" />
      </div>
    );
  }

  if (query.isError || !query.data) {
    return (
      <div className="grid min-h-[50vh] place-items-center gap-3 text-center">
        <p className="text-sm text-destructive">Artikel tidak ditemukan.</p>
        <Button variant="outline" asChild>
          <Link href="/admin/blog">Kembali ke daftar</Link>
        </Button>
      </div>
    );
  }

  // Keyed on the id so switching articles remounts the form rather than
  // leaving the previous one's text in the editor's local state.
  return <BlogPostForm key={query.data.id} post={query.data} />;
}
