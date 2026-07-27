"use client";

import { QueryClient, QueryClientProvider, useQueryClient } from "@tanstack/react-query";
import { ReactQueryDevtools } from "@tanstack/react-query-devtools";
import { ReactNode, useEffect, useState } from "react";

function UnauthorizedCacheClear() {
  const queryClient = useQueryClient();

  useEffect(() => {
    const onUnauthorized = () => {
      void queryClient.cancelQueries().finally(() => {
        queryClient.clear();
      });
    };
    window.addEventListener("vas:unauthorized", onUnauthorized);
    return () => window.removeEventListener("vas:unauthorized", onUnauthorized);
  }, [queryClient]);

  return null;
}

export function QueryProvider({ children }: { children: ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: false,
            retry: 1,
          },
        },
      })
  );

  return (
    <QueryClientProvider client={client}>
      <UnauthorizedCacheClear />
      {children}
      {process.env.NODE_ENV === "development" ? (
        <ReactQueryDevtools initialIsOpen={false} />
      ) : null}
    </QueryClientProvider>
  );
}
