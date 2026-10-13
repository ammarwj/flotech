"use client";

import { BlogPostForm } from "@/components/admin/blog-post-form";

export default function NewBlogPostPage() {
  // No `post` prop: the form starts empty and redirects to /admin/blog/{id}
  // once the first save gives the article an id.
  return <BlogPostForm />;
}
