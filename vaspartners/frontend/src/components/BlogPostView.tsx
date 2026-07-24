"use client";

import Link from "next/link";
import { SiteShell } from "@/components/SiteShell";
import { useBlogPost, useCustomer, useLogout } from "@/hooks/use-customer";

export function BlogPostView({ slug }: { slug: string }) {
  const { data: me = null } = useCustomer();
  const logout = useLogout();
  const { data: post, isLoading, isError } = useBlogPost(slug);

  return (
    <SiteShell me={me} onLogout={() => void logout()}>
      <div className="section blog-article">
        <Link href="/blog" className="linkish">
          ← All posts
        </Link>

        {isLoading && <p className="muted">Loading…</p>}
        {isError && <p className="alert">Post not found.</p>}

        {post && (
          <article itemScope itemType="https://schema.org/BlogPosting">
            <p className="brand-kicker">Blog</p>
            <h1 itemProp="headline">{post.title}</h1>
            {post.published_at && (
              <p className="muted">
                <time dateTime={post.published_at} itemProp="datePublished">
                  {new Date(post.published_at).toLocaleDateString(undefined, {
                    day: "numeric",
                    month: "long",
                    year: "numeric",
                  })}
                </time>
              </p>
            )}
            {post.cover_image_url && (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={post.cover_image_url}
                alt={post.title}
                className="blog-article-cover"
                itemProp="image"
              />
            )}
            <div className="blog-article-body" itemProp="articleBody">
              {(post.body || "").split(/\n{2,}/).map((para, i) => (
                <p key={i}>{para}</p>
              ))}
            </div>
          </article>
        )}
      </div>
    </SiteShell>
  );
}
