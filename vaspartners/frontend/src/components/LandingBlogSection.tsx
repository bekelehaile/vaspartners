"use client";

import Link from "next/link";
import { useBlogPosts } from "@/hooks/use-customer";

export function LandingBlogSection() {
  const { data: posts = [], isLoading } = useBlogPosts();

  if (!isLoading && !posts.length) return null;

  return (
    <section className="section" id="blog" aria-labelledby="blog-heading">
      <header className="section-head">
        <span className="section-label">Updates</span>
        <h2 id="blog-heading">News &amp; guidance</h2>
        <p className="section-lead">
          Official updates for VAS partners from Ethio telecom.
        </p>
      </header>

      {isLoading ? (
        <p className="muted">Loading posts…</p>
      ) : (
        <div className="blog-grid">
          {posts.slice(0, 6).map((post) => (
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
                {post.is_featured && <span className="blog-featured">Featured</span>}
                <h3>
                  <Link href={`/blog/${post.slug}`}>{post.title}</Link>
                </h3>
                {post.excerpt && <p>{post.excerpt}</p>}
                {post.published_at && (
                  <time dateTime={post.published_at}>
                    {new Date(post.published_at).toLocaleDateString(undefined, {
                      day: "numeric",
                      month: "short",
                      year: "numeric",
                    })}
                  </time>
                )}
              </div>
            </article>
          ))}
        </div>
      )}

      <div className="section-more">
        <Link href="/blog" className="linkish">
          View all posts
        </Link>
      </div>
    </section>
  );
}
