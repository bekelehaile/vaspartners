import { Suspense } from "react";
import FaydaRedirectBridge from "./callback-inner";

export default function FaydaCallbackPage() {
  return (
    <Suspense
      fallback={
        <main className="auth-wait">
          <div className="spinner" aria-hidden />
        </main>
      }
    >
      <FaydaRedirectBridge />
    </Suspense>
  );
}
