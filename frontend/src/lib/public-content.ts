import { getApiBaseUrl } from "@/lib/site";
import type { BlogPost, FaqItem } from "@/lib/api";

async function publicGet<T>(path: string): Promise<T | null> {
  try {
    const res = await fetch(`${getApiBaseUrl()}${path}`, {
      headers: { Accept: "application/json" },
      next: { revalidate: 300 },
    });
    if (!res.ok) return null;
    return (await res.json()) as T;
  } catch {
    return null;
  }
}

export async function fetchPublishedBlogPosts(): Promise<BlogPost[]> {
  const json = await publicGet<{ data: BlogPost[] }>("/blog-posts");
  return json?.data ?? [];
}

export async function fetchPublishedBlogPost(slug: string): Promise<BlogPost | null> {
  const json = await publicGet<{ data: BlogPost }>(
    `/blog-posts/${encodeURIComponent(slug)}`
  );
  return json?.data ?? null;
}

export async function fetchPublishedFaqs(): Promise<FaqItem[]> {
  const json = await publicGet<{ data: FaqItem[] }>("/faqs");
  return json?.data ?? [];
}
