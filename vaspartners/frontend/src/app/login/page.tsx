import type { Metadata } from "next";
import { LoginPageView } from "@/components/LoginPageView";

export const metadata: Metadata = {
  title: "Sign in | VAS Partners",
  description:
    "Sign in to the Ethio telecom VAS Partners portal with Fayda National ID.",
};

export default function LoginPage() {
  return <LoginPageView />;
}
