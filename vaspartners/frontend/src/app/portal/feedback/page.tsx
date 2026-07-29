"use client";

import { FormEvent, useEffect, useState } from "react";
import Link from "next/link";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import { Button } from "@/components/ui/button";
import { useContact, useFeedback, useSubmitFeedback } from "@/hooks/use-contact";

function StarPicker({
  value,
  onChange,
  disabled,
}: {
  value: number;
  onChange: (n: number) => void;
  disabled?: boolean;
}) {
  return (
    <div className="feedback-stars" role="radiogroup" aria-label="Rating">
      {[1, 2, 3, 4, 5].map((n) => {
        const active = n <= value;
        return (
          <button
            key={n}
            type="button"
            role="radio"
            aria-checked={value === n}
            aria-label={`${n} star${n === 1 ? "" : "s"}`}
            className={active ? "is-active" : undefined}
            disabled={disabled}
            onClick={() => onChange(n)}
          >
            ★
          </button>
        );
      })}
    </div>
  );
}

export default function FeedbackPage() {
  const { data: me } = useContact();
  const inbox = useFeedback();
  const submit = useSubmitFeedback();
  const current = inbox.data?.current;
  const existing = current?.feedback ?? null;
  const tinReady = !!me?.company?.tin_validated;
  const canSubmit = current?.can_submit !== false && tinReady;

  const [rating, setRating] = useState(existing?.rating ?? 0);
  const [description, setDescription] = useState(existing?.description ?? "");
  const [message, setMessage] = useState<string | null>(null);
  const [messageTone, setMessageTone] = useState<"ok" | "err">("ok");

  useEffect(() => {
    setRating(existing?.rating ?? 0);
    setDescription(existing?.description ?? "");
  }, [existing?.public_id, existing?.rating, existing?.description]);

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setMessage(null);
    if (!canSubmit) {
      setMessageTone("err");
      setMessage(
        "Feedback is locked until Ethio telecom validates this company's TIN.",
      );
      return;
    }
    if (rating < 1 || rating > 5) {
      setMessageTone("err");
      setMessage("Please choose a rating from 1 to 5.");
      return;
    }
    void submit
      .mutateAsync({ rating, description: description.trim() })
      .then((res) => {
        setMessageTone("ok");
        setMessage(res.message ?? "Feedback saved.");
      })
      .catch((err: unknown) => {
        setMessageTone("err");
        setMessage(err instanceof Error ? err.message : "Could not save feedback");
      });
  };

  return (
    <>
      <PortalPageHeader
        title="Feedback"
        description={`Share your experience with Ethio telecom VAS Partners for the current quarter${
          current ? ` (${current.label})` : ""
        }. One submission per quarter — you can update it until the quarter ends.`}
      />

      <div className="section section-flush">
        <div className="portal-stack">
          <div className="panel">
            <div className="panel-section-head">
              <h2>
                {existing
                  ? `Update ${current?.label ?? "this quarter"}`
                  : `Submit ${current?.label ?? "this quarter"}`}
              </h2>
              <p className="muted">
                Rate the portal and VAS partnership support, then add a short description.
              </p>
            </div>

            {inbox.isLoading && <p className="muted">Loading feedback…</p>}
            {inbox.isError && (
              <div className="alert">
                {inbox.error instanceof Error
                  ? inbox.error.message
                  : "Could not load feedback"}
              </div>
            )}

            {!inbox.isLoading && !inbox.isError && !canSubmit && (
              <div className="alert" role="status">
                Feedback is locked until this company&apos;s TIN is validated by Ethio telecom.
                Switch to a company with a validated TIN, or wait for validation.{" "}
                <Link href="/portal/company">Open company settings</Link>
              </div>
            )}

            {!inbox.isLoading && !inbox.isError && (
              <form className="portal-stack-sm" onSubmit={onSubmit}>
                <div className="field">
                  <label>Rating</label>
                  <StarPicker
                    value={rating}
                    onChange={setRating}
                    disabled={!canSubmit}
                  />
                </div>
                <div className="field">
                  <label htmlFor="feedback-description">Description</label>
                  <textarea
                    id="feedback-description"
                    rows={5}
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder="What went well? What should we improve?"
                    required
                    minLength={10}
                    maxLength={5000}
                    disabled={!canSubmit}
                  />
                </div>
                {message && (
                  <div
                    className={messageTone === "err" ? "alert" : "alert alert-info"}
                    role="status"
                  >
                    {message}
                  </div>
                )}
                <Button
                  type="submit"
                  disabled={!canSubmit || submit.isPending || rating < 1}
                >
                  {submit.isPending
                    ? "Saving…"
                    : existing
                      ? "Update feedback"
                      : "Submit feedback"}
                </Button>
              </form>
            )}
          </div>

          {(inbox.data?.items?.length ?? 0) > 0 && (
            <div className="panel">
              <div className="panel-section-head">
                <h2>Previous quarters</h2>
                <p className="muted">Your submitted feedback history.</p>
              </div>
              <ul className="feedback-history">
                {inbox.data!.items.map((row) => (
                  <li key={row.public_id}>
                    <div className="feedback-history-head">
                      <strong>{row.label}</strong>
                      <span className="feedback-history-rating">{row.rating}/5</span>
                    </div>
                    <p>{row.description}</p>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
