"use client";

import { ReactNode } from "react";
import { cn } from "@/lib/utils";

export type VerticalTabItem<T extends string = string> = {
  id: T;
  label: string;
  description?: string;
  count?: number | string;
  icon?: ReactNode;
  disabled?: boolean;
};

export function VerticalTabs<T extends string>({
  items,
  value,
  onChange,
  label,
  className,
}: {
  items: VerticalTabItem<T>[];
  value: T;
  onChange: (id: T) => void;
  label: string;
  className?: string;
}) {
  return (
    <div
      className={cn("portal-vtabs", className)}
      role="tablist"
      aria-label={label}
      aria-orientation="vertical"
    >
      {items.map((item) => {
        const active = item.id === value;
        return (
          <button
            key={item.id}
            type="button"
            role="tab"
            id={`vtab-${item.id}`}
            aria-selected={active}
            aria-controls={`vpanel-${item.id}`}
            disabled={item.disabled}
            className={cn("portal-vtab", active && "is-active")}
            onClick={() => onChange(item.id)}
          >
            {item.icon && <span className="portal-vtab-icon">{item.icon}</span>}
            <span className="portal-vtab-copy">
              <span className="portal-vtab-label">{item.label}</span>
              {item.description && (
                <span className="portal-vtab-desc">{item.description}</span>
              )}
            </span>
            {item.count != null && item.count !== "" && (
              <span className="portal-vtab-count">{item.count}</span>
            )}
          </button>
        );
      })}
    </div>
  );
}

export function VerticalTabPanels({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return <div className={cn("portal-vpanels", className)}>{children}</div>;
}

export function VerticalTabPanel({
  id,
  active,
  labelledBy,
  children,
  className,
}: {
  id: string;
  active: boolean;
  labelledBy: string;
  children: ReactNode;
  className?: string;
}) {
  if (!active) return null;
  return (
    <div
      role="tabpanel"
      id={`vpanel-${id}`}
      aria-labelledby={labelledBy}
      className={cn("portal-vpanel", className)}
    >
      {children}
    </div>
  );
}

export function AdminWorkspace({
  sidebar,
  children,
  className,
}: {
  sidebar: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("admin-workspace", className)}>
      <aside className="admin-workspace-rail">{sidebar}</aside>
      <div className="admin-workspace-main">{children}</div>
    </div>
  );
}
