"use client";

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
        <>
          <span className="section-label">Gallery</span>
          <h2>In pictures</h2>
          <p className="section-lead">
            Moments from Ethio telecom VAS partner programmes and events.
          </p>
        </>
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
          {visible.map((item) => (
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
    </section>
  );
}
