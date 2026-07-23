import { Suspense } from "react";
import NewRequestWizard from "./wizard";

export default function Page() {
  return (
    <Suspense
      fallback={
        <main className="auth-wait">
          <div className="spinner" />
        </main>
      }
    >
      <NewRequestWizard />
    </Suspense>
  );
}
