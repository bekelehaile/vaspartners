import type { Contact } from "@/lib/api";

function isOwner(me?: Contact | null): boolean {
  return me?.company_role === "owner";
}

function hasPermission(me: Contact | null | undefined, key: string): boolean {
  if (!me) return false;
  if (isOwner(me)) return true;
  const perms = me.company_permissions ?? [];
  // Legacy grant (pre split) counts as both journeys.
  if (perms.includes("create_service_requests")) return true;
  return perms.includes(key);
}

/** Start new VAS subscriptions. */
export function contactCanCreateSubscriptions(me?: Contact | null): boolean {
  return hasPermission(me, "create_subscriptions");
}

/** Manage / renew / terminate services and one-off requests. */
export function contactCanManageServices(me?: Contact | null): boolean {
  return hasPermission(me, "manage_services");
}

/** Either journey is allowed. */
export function contactCanCreateServiceRequests(me?: Contact | null): boolean {
  return contactCanCreateSubscriptions(me) || contactCanManageServices(me);
}

const ALIVE_SUBSCRIPTION_STATUSES = new Set(["active", "pending_renewal", "grace"]);

export function isAliveSubscriptionStatus(status?: string | null): boolean {
  return ALIVE_SUBSCRIPTION_STATUSES.has(String(status || "").toLowerCase());
}

export function subscriptionStatusLabel(status?: string | null): string {
  const key = String(status || "").toLowerCase();
  switch (key) {
    case "active":
      return "Active";
    case "pending_renewal":
      return "Pending renewal";
    case "grace":
      return "Grace period";
    case "expired":
      return "Expired";
    case "terminated":
      return "Terminated";
    default:
      return status ? String(status) : "—";
  }
}
