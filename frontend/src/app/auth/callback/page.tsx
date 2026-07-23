import { Suspense } from 'react';
import AuthCallbackInner from './callback-inner';

export default function AuthCallbackPage() {
  return (
    <Suspense fallback={<main className="grid min-h-screen place-items-center">Loading…</main>}>
      <AuthCallbackInner />
    </Suspense>
  );
}
