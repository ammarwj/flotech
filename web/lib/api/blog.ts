import { apiClient } from "./client";
import type {
  ApiEnvelope,
  BlogArticle,
  BlogCategory,
  BlogPost,
  BlogPostSummary,
  Paginated,
} from "@/types/api";

// ---- Public ----

export interface BlogQuery {
  search?: string;
  category?: string;
  page?: number;
  per_page?: number;
}

export async function getPublicBlogPosts(
  params: BlogQuery = {}
): Promise<Paginated<BlogPostSummary>> {
  const { data } = await apiClient.get<ApiEnvelope<Paginated<BlogPostSummary>>>("/blog", {
    params,
  });
  return data.data;
}

export async function getPublicBlogCategories(): Promise<BlogCategory[]> {
  const { data } = await apiClient.get<ApiEnvelope<BlogCategory[]>>("/blog/categories");
  return data.data;
}

export async function getPublicBlogPost(slug: string): Promise<BlogArticle> {
  const { data } = await apiClient.get<ApiEnvelope<BlogArticle>>(`/blog/${slug}`);
  return data.data;
}

// ---- SaaS admin ----

export interface AdminBlogQuery extends BlogQuery {
  status?: "draft" | "published" | "scheduled";
}

export async function getAdminBlogPosts(
  params: AdminBlogQuery = {}
): Promise<Paginated<BlogPostSummary>> {
  const { data } = await apiClient.get<ApiEnvelope<Paginated<BlogPostSummary>>>(
    "/admin/blog-posts",
    { params }
  );
  return data.data;
}

/**
 * One article by id, drafts included — which is why the editor reads this and
 * not the public `/blog/{slug}` route: that one serves published rows only, so
 * a draft could never be reopened through it.
 */
export async function getAdminBlogPost(id: string): Promise<BlogPost> {
  const { data } = await apiClient.get<ApiEnvelope<BlogPost>>(`/admin/blog-posts/${id}`);
  return data.data;
}

export interface BlogPostInput {
  title: string;
  body: string;
  /** Blank = derive from the title (create) or keep the stored one (update). */
  slug?: string | null;
  /** Blank = derive from the body's first words. */
  excerpt?: string | null;
  blog_category_id?: string | null;
  cover_url?: string | null;
  author_name?: string | null;
  /** Null = draft. A future ISO date schedules it. */
  published_at?: string | null;
  is_featured?: boolean;
}

export async function createBlogPost(payload: BlogPostInput): Promise<BlogPost> {
  const { data } = await apiClient.post<ApiEnvelope<BlogPost>>("/admin/blog-posts", payload);
  return data.data;
}

export async function updateBlogPost(
  id: string,
  payload: Partial<BlogPostInput>
): Promise<BlogPost> {
  const { data } = await apiClient.put<ApiEnvelope<BlogPost>>(`/admin/blog-posts/${id}`, payload);
  return data.data;
}

export async function deleteBlogPost(id: string): Promise<void> {
  await apiClient.delete(`/admin/blog-posts/${id}`);
}

export async function getAdminBlogCategories(): Promise<BlogCategory[]> {
  const { data } = await apiClient.get<ApiEnvelope<BlogCategory[]>>("/admin/blog-categories");
  return data.data;
}

export interface BlogCategoryInput {
  name: string;
  slug?: string | null;
  description?: string | null;
  sort_order?: number;
}

export async function createBlogCategory(payload: BlogCategoryInput): Promise<BlogCategory> {
  const { data } = await apiClient.post<ApiEnvelope<BlogCategory>>(
    "/admin/blog-categories",
    payload
  );
  return data.data;
}

export async function updateBlogCategory(
  id: string,
  payload: BlogCategoryInput
): Promise<BlogCategory> {
  const { data } = await apiClient.put<ApiEnvelope<BlogCategory>>(
    `/admin/blog-categories/${id}`,
    payload
  );
  return data.data;
}

export async function deleteBlogCategory(id: string): Promise<void> {
  await apiClient.delete(`/admin/blog-categories/${id}`);
}
