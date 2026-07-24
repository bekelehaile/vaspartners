import { Suspense } from "react";
import { AuthWait } from "@/components/AuthWait";
import NewRequestWizard from "./wizard";

export default function Page() {
  return (
    <Suspense fallback={<AuthWait />}>
      <NewRequestWizard />
    </Suspense>
  );
}
