"use client";

import { useEffect, useId, useMemo, useRef, useState } from "react";
import {
  useContact,
  useCreateCompanyMember,
  useSetCompanyMemberActive,
  useUpdateCompanyMemberPermissions,
  useCompanyMembers,
  type CompanyMemberOption,
  type CompanyMemberPermissionOption,
} from "@/hooks/use-contact";

function permissionLabel(
  key: string,
  catalog: CompanyMemberPermissionOption[],
): string {
  return catalog.find((p) => p.key === key)?.label ?? key.replaceAll("_", " ");
}

function PermissionsEditor({
  member,
  catalog,
  busy,
  onSave,
  onCancel,
}: {
  member: CompanyMemberOption;
  catalog: CompanyMemberPermissionOption[];
  busy: boolean;
  onSave: (permissions: string[]) => void;
  onCancel: () => void;
}) {
  const [selected, setSelected] = useState<string[]>(member.permissions ?? []);

  return (
    <div className="company-member-permissions-editor">
      <p className="muted" style={{ marginTop: 0 }}>
        Grant permissions for <strong>{member.name || "this partner"}</strong>.
      </p>
      <ul className="company-member-permission-list">
        {catalog.map((opt) => {
          const checked = selected.includes(opt.key);
          return (
            <li key={opt.key}>
              <label>
                <input
                  type="checkbox"
                  checked={checked}
                  disabled={busy}
                  onChange={() => {
                    setSelected((prev) =>
                      checked
                        ? prev.filter((k) => k !== opt.key)
                        : [...prev, opt.key],
                    );
                  }}
                />
                <span>
                  <strong>{opt.label}</strong>
                  <span className="muted">{opt.description}</span>
                </span>
              </label>
            </li>
          );
        })}
      </ul>
      <div className="company-request-actions">
        <button
          type="button"
          className="btn-primary"
          disabled={busy}
          onClick={() => onSave(selected)}
        >
          Save permissions
        </button>
        <button type="button" className="btn-ghost" disabled={busy} onClick={onCancel}>
          Cancel
        </button>
      </div>
    </div>
  );
}

function AddMemberForm({
  busy,
  onCreated,
}: {
  busy: boolean;
  onCreated: () => void;
}) {
  const create = useCreateCompanyMember();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [isActive, setIsActive] = useState(true);

  if (!open) {
    return (
      <div className="company-members-toolbar">
        <button
          type="button"
          className="btn-primary"
          disabled={busy}
          onClick={() => setOpen(true)}
        >
          Add member
        </button>
      </div>
    );
  }

  return (
    <div className="company-add-member panel" style={{ margin: "0 0 1rem", borderRadius: 0 }}>
      <h3 style={{ marginTop: 0 }}>Add member</h3>
      <p className="muted">
        Create a member with their phone number. When they sign in with Fayda using that
        phone, their identity syncs here. If access is disabled, Fayda still updates their
        profile but they cannot use this company until you enable them.
      </p>
      <div className="field">
        <label htmlFor="add-member-name">
          Full name <span aria-hidden="true">*</span>
        </label>
        <input
          id="add-member-name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          disabled={create.isPending}
        />
      </div>
      <div className="field">
        <label htmlFor="add-member-phone">
          Phone (Fayda) <span aria-hidden="true">*</span>
        </label>
        <input
          id="add-member-phone"
          value={phone}
          onChange={(e) => setPhone(e.target.value)}
          placeholder="09xxxxxxxx"
          required
          disabled={create.isPending}
        />
      </div>
      <div className="field">
        <label htmlFor="add-member-email">Email (optional)</label>
        <input
          id="add-member-email"
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          disabled={create.isPending}
        />
      </div>
      <label className="company-add-member-active">
        <input
          type="checkbox"
          checked={isActive}
          disabled={create.isPending}
          onChange={(e) => setIsActive(e.target.checked)}
        />
        <span>Enable access now (required for Fayda sync into this company)</span>
      </label>
      {create.isError && (
        <div className="alert">
          {create.error instanceof Error
            ? create.error.message
            : "Could not add member"}
        </div>
      )}
      <div className="company-request-actions">
        <button
          type="button"
          className="btn-primary"
          disabled={create.isPending || name.trim().length < 2 || phone.trim().length < 9}
          onClick={() => {
            void create
              .mutateAsync({
                name: name.trim(),
                phone_number: phone.trim(),
                email: email.trim() || undefined,
                is_active: isActive,
              })
              .then(() => {
                setName("");
                setPhone("");
                setEmail("");
                setIsActive(true);
                setOpen(false);
                onCreated();
              });
          }}
        >
          {create.isPending ? "Adding…" : "Save member"}
        </button>
        <button
          type="button"
          className="btn-ghost"
          disabled={create.isPending}
          onClick={() => setOpen(false)}
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

type MemberAction = {
  key: string;
  label: string;
  danger?: boolean;
  onSelect: () => void;
};

function MemberActionsMenu({
  actions,
  busy,
}: {
  actions: MemberAction[];
  busy: boolean;
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const menuId = useId();

  useEffect(() => {
    if (!open) return;
    const onPointerDown = (e: MouseEvent) => {
      if (!rootRef.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("mousedown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  if (actions.length === 0) {
    return <span className="muted">—</span>;
  }

  return (
    <div className="member-actions-menu" ref={rootRef}>
      <button
        type="button"
        className="member-actions-trigger"
        aria-expanded={open}
        aria-haspopup="menu"
        aria-controls={menuId}
        disabled={busy}
        onClick={() => setOpen((v) => !v)}
      >
        Actions
        <span aria-hidden>▾</span>
      </button>
      {open && (
        <ul className="member-actions-list" id={menuId} role="menu">
          {actions.map((action) => (
            <li key={action.key} role="none">
              <button
                type="button"
                role="menuitem"
                className={action.danger ? "is-danger" : undefined}
                disabled={busy}
                onClick={() => {
                  setOpen(false);
                  action.onSelect();
                }}
              >
                {action.label}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function CompanyMembersTable({ enabled }: { enabled: boolean }) {
  const { data: me } = useContact();
  const membersQuery = useCompanyMembers(enabled);
  const setActive = useSetCompanyMemberActive();
  const updatePermissions = useUpdateCompanyMemberPermissions();
  const [editingId, setEditingId] = useState<string | null>(null);
  const [detailsId, setDetailsId] = useState<string | null>(null);

  const members = membersQuery.data?.members ?? [];
  const catalog =
    membersQuery.data?.permissionCatalog?.length
      ? membersQuery.data.permissionCatalog
      : (me?.company_permission_catalog ?? []);
  const canManage = !!me?.company_can_manage_members;
  const busy = setActive.isPending || updatePermissions.isPending;
  const error =
    (setActive.error instanceof Error && setActive.error.message) ||
    (updatePermissions.error instanceof Error && updatePermissions.error.message) ||
    null;

  const rows = useMemo(() => members, [members]);

  if (!enabled) {
    return null;
  }

  return (
    <div className="company-members-table-wrap">
      {canManage && (
        <AddMemberForm
          busy={busy}
          onCreated={() => {
            void membersQuery.refetch();
          }}
        />
      )}
      <div className="data-table-wrap">
        <table className="data-table company-members-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Phone</th>
              <th>Email</th>
              <th>Role</th>
              <th>Access</th>
              <th>Fayda</th>
              <th>Permissions</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {membersQuery.isLoading ? (
              <tr>
                <td colSpan={8} className="data-table-empty">
                  Loading members…
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="data-table-empty">
                  No members found for this company yet.
                </td>
              </tr>
            ) : (
              rows.flatMap((member, index) => {
                const id = member.public_id || `member-${index}`;
                const isYou =
                  !!member.public_id && me?.public_id === member.public_id;
                const isOwnerRow = member.role === "owner" || member.is_owner;
                const active = member.is_active !== false;
                const editing = editingId === member.public_id;
                const showDetails = detailsId === member.public_id;

                const actions: MemberAction[] = [
                  {
                    key: "details",
                    label: showDetails ? "Hide details" : "View details",
                    onSelect: () =>
                      setDetailsId(showDetails ? null : member.public_id || null),
                  },
                ];

                if (canManage && !isYou && !isOwnerRow && member.public_id) {
                  actions.push({
                    key: "permissions",
                    label: editing ? "Close permissions" : "Grant permissions",
                    onSelect: () =>
                      setEditingId(editing ? null : member.public_id || null),
                  });
                  if (active) {
                    actions.push({
                      key: "disable",
                      label: "Disable access",
                      danger: true,
                      onSelect: () => {
                        if (
                          !window.confirm(
                            `Disable access for ${member.name || "this partner"}? They will not sync into this company on Fayda sign-in until re-enabled.`,
                          )
                        ) {
                          return;
                        }
                        void setActive.mutateAsync({
                          public_id: member.public_id!,
                          active: false,
                        });
                      },
                    });
                  } else {
                    actions.push({
                      key: "enable",
                      label: "Enable access",
                      onSelect: () => {
                        void setActive.mutateAsync({
                          public_id: member.public_id!,
                          active: true,
                        });
                      },
                    });
                  }
                }

                const mainRow = (
                  <tr key={id} className={editing ? "is-editing" : undefined}>
                    <td>
                      <strong>{member.name || "—"}</strong>
                      {isYou ? <span className="service-meta"> You</span> : null}
                    </td>
                    <td>{member.phone_number || "—"}</td>
                    <td>{member.email || "—"}</td>
                    <td>{isOwnerRow ? "Owner" : "Member"}</td>
                    <td>
                      <span
                        className={`company-request-status ${
                          active ? "is-approved" : "is-rejected"
                        }`}
                      >
                        {active ? "Enabled" : "Disabled"}
                      </span>
                    </td>
                    <td>
                      {member.awaiting_fayda ? (
                        <span className="company-request-status is-pending">
                          Awaiting sign-in
                        </span>
                      ) : (
                        <span className="company-request-status is-approved">
                          Linked
                        </span>
                      )}
                    </td>
                    <td>
                      {isOwnerRow ? (
                        <span className="muted">Full access</span>
                      ) : (member.permissions?.length ?? 0) === 0 ? (
                        <span className="muted">None</span>
                      ) : (
                        <ul className="company-member-perm-chips">
                          {(member.permissions ?? []).map((key) => (
                            <li key={key}>{permissionLabel(key, catalog)}</li>
                          ))}
                        </ul>
                      )}
                    </td>
                    <td>
                      <MemberActionsMenu actions={actions} busy={busy} />
                    </td>
                  </tr>
                );

                const extraRows = [];

                if (showDetails) {
                  extraRows.push(
                    <tr key={`${id}-details`} className="company-member-expand-row">
                      <td colSpan={8}>
                        <dl className="fayda-dl company-member-details-dl">
                          <div>
                            <dt>Gender</dt>
                            <dd>{member.gender || "—"}</dd>
                          </div>
                          <div>
                            <dt>Nationality</dt>
                            <dd>{member.nationality || "—"}</dd>
                          </div>
                          <div>
                            <dt>Birthdate</dt>
                            <dd>{member.birthdate || "—"}</dd>
                          </div>
                          <div>
                            <dt>ID type</dt>
                            <dd>{member.identification_type || "—"}</dd>
                          </div>
                          <div>
                            <dt>ID number</dt>
                            <dd>{member.identification_number || "—"}</dd>
                          </div>
                        </dl>
                      </td>
                    </tr>,
                  );
                }

                if (editing && member.public_id) {
                  extraRows.push(
                    <tr key={`${id}-perms`} className="company-member-expand-row">
                      <td colSpan={8}>
                        <PermissionsEditor
                          member={member}
                          catalog={catalog}
                          busy={busy}
                          onCancel={() => setEditingId(null)}
                          onSave={(permissions) => {
                            void updatePermissions
                              .mutateAsync({
                                public_id: member.public_id!,
                                permissions,
                              })
                              .then(() => setEditingId(null));
                          }}
                        />
                      </td>
                    </tr>,
                  );
                }

                return [mainRow, ...extraRows];
              })
            )}
          </tbody>
        </table>
      </div>

      {membersQuery.isError && (
        <div className="alert" style={{ margin: "1rem" }}>
          {membersQuery.error instanceof Error
            ? membersQuery.error.message
            : "Could not load members"}
        </div>
      )}
      {error && (
        <div className="alert" style={{ margin: "1rem" }}>
          {error}
        </div>
      )}
      {canManage ? (
        <p className="muted company-members-hint">
          Add members by phone. Fayda sign-in syncs their identity; only Enabled members
          get company access. Owner company claim stays separate and never overrides a
          disabled membership.
        </p>
      ) : (
        <p className="muted company-members-hint">
          Member roster for this company. Only the company owner can add members or change
          access.
        </p>
      )}
    </div>
  );
}
