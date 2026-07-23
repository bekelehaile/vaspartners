import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { BlogPostView } from "@/components/BlogPostView";
import { JsonLd } from "@/components/JsonLd";
import { fetchPublishedBlogPost } from "@/lib/public-content";
import { buildPageMetadata } from "@/lib/seo";
import { absoluteUrl, siteConfig } from "@/lib/site";

type PageProps = {
  params: Promise<{ slug: string }>;
};

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const post = await fetchPublishedBlogPost(slug);
  if (!post) {
    return buildPageMetadata({
      title: "Post not found",
      description: "This blog post is unavailable.",
      path: `/blog/${slug}`,
      noIndex: true,
    });
  }

  return buildPageMetadata({
    title: post.title,
    description: post.excerpt || post.title,
    path: `/blog/${post.slug}`,
    image: post.cover_image_url,
    type: "article",
    publishedTime: post.published_at,
  });
}

export default async function BlogPostPage({ params }: PageProps) {
  const { slug } = await params;
  const post = await fetchPublishedBlogPost(slug);
  if (!post) notFound();

  const articleLd = {
    "@context": "https://schema.org",
    "@type": "BlogPosting",
    headline: post.title,
    description: post.excerpt || post.title,
    datePublished: post.published_at || undefined,
    image: post.cover_image_url || undefined,
    author: {
      "@type": "Organization",
      name: siteConfig.orgName,
    },
    publisher: {
      "@type": "Organization",
      name: siteConfig.orgName,
      logo: {
        "@type": "ImageObject",
        url: absoluteUrl("/brand/ethio_logo_full.png"),
      },
    },
    mainEntityOfPage: absoluteUrl(`/blog/${post.slug}`),
  };

  return (
    <>
      <JsonLd data={articleLd} />
      <BlogPostView slug={slug} />
    </>
  );
}
