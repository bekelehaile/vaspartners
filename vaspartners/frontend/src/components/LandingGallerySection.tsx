"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useGallery } from "@/hooks/use-customer";

export function LandingGallerySection({
  showIntro = true,
}: {
  showIntro?: boolean;
}) {
  const { data: items = [], isLoading } = useGallery();
  const [album, setAlbum] = useState<string>("all");

  const albums = useMemo(() => {
    const set = new Set<string>();
    items.forEach((i) => {
      if (i.album) set.add(i.album);
    });
    return Array.from(set).sort();
  }, [items]);

  const visible = useMemo(
    () => (album === "all" ? items : items.filter((i) => i.album === album)),
    [items, album]
  );

  if (!isLoading && !items.length) {
    return showIntro ? null : <p className="muted">No gallery images yet.</p>;
  }

  return (
    <section className="section" id={showIntro ? "gallery" : undefined}>
      {showIntro && (
        <header className="section-head">
          <span className="section-label">Gallery</span>
          <h2>Partner programmes</h2>
          <p className="section-lead">
            Selected moments from Ethio telecom VAS partner activities.
          </p>
        </header>
      )}

      {albums.length > 0 && (
        <div className="gallery-filters">
          <button
            type="button"
            className={album === "all" ? "is-active" : undefined}
            onClick={() => setAlbum("all")}
          >
            All
          </button>
          {albums.map((a) => (
            <button
              key={a}
              type="button"
              className={album === a ? "is-active" : undefined}
              onClick={() => setAlbum(a)}
            >
              {a}
            </button>
          ))}
        </div>
      )}

      {isLoading ? (
        <p className="muted">Loading gallery…</p>
      ) : (
        <div className="gallery-grid">
          {(showIntro ? visible.slice(0, 8) : visible).map((item) => (
            <figure key={item.id} className="gallery-card">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={item.image_url || ""}
                alt={item.alt_text || item.title}
                loading="lazy"
              />
              <figcaption>
                <strong>{item.title}</strong>
                {item.caption && <span>{item.caption}</span>}
              </figcaption>
            </figure>
          ))}
        </div>
      )}

      {showIntro && items.length > 0 && (
        <div className="section-more">
          <Link href="/gallery" className="linkish">
            View full gallery →
          </Link>
        </div>
      )}
    </section>
  );
}
