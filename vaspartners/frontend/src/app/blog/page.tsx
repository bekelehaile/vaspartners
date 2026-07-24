"use client";

import Link from "next/link";
import { SiteShell } from "@/components/SiteShell";
import { useBlogPosts, useCustomer, useLogout } from "@/hooks/use-customer";

export default function BlogIndexPage() {
  const { data: me = null } = useCustomer();
  const logout = useLogout();
  const { data: posts = [], isLoading } = useBlogPosts();

  return (
    <SiteShell me={me} onLogout={() => void logout()}>
      <div className="portal-hero">
        <p className="brand-kicker">Blog</p>
        <h1>VAS partner updates</h1>
        <p className="muted">News and guidance managed from the Ethio telecom admin.</p>
      </div>

      <div className="section">
        {isLoading && <p className="muted">Loading…</p>}
        {!isLoading && !posts.length && (
          <p className="muted">No published posts yet.</p>
        )}
        <div className="blog-grid">
          {posts.map((post) => (
            <article key={post.id} className="blog-card">
              {post.cover_image_url && (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={post.cover_image_url}
                  alt={post.title}
                  className="blog-card-image"
                  loading="lazy"
                />
              )}
              <div className="blog-card-body">
                <h3>
                  <Link href={`/blog/${post.slug}`}>{post.title}</Link>
                </h3>
                {post.excerpt && <p>{post.excerpt}</p>}
              </div>
            </article>
          ))}
        </div>
      </div>
    </SiteShell>
  );
}
