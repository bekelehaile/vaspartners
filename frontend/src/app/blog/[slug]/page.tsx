"use client";

import Link from "next/link";
import { use } from "react";
import { SiteShell } from "@/components/SiteShell";
import { useBlogPost, useCustomer, useLogout } from "@/hooks/use-customer";

export default function BlogPostPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = use(params);
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
          <article>
            <p className="brand-kicker">Blog</p>
            <h1>{post.title}</h1>
            {post.published_at && (
              <p className="muted">
                {new Date(post.published_at).toLocaleDateString(undefined, {
                  day: "numeric",
                  month: "long",
                  year: "numeric",
                })}
              </p>
            )}
            {post.cover_image_url && (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={post.cover_image_url} alt="" className="blog-article-cover" />
            )}
            <div className="blog-article-body">
              {(post.body || "")
                .split(/\n{2,}/)
                .map((para, i) => (
                  <p key={i}>{para}</p>
                ))}
            </div>
          </article>
        )}
      </div>
    </SiteShell>
  );
}
