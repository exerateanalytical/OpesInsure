/**
 * Role code / permissions -> mobile portal. Pure (no imports) so it is
 * node-tested; src/store/session.ts re-exports it and owns the routes.
 */
export type Portal =
  | "customer"
  | "agent"
  | "broker_admin"
  | "broker_staff"
  | "carrier"
  | "platform_admin"
  | "compliance"
  | "finance"
  | "claims"
  | "operations";

/** Maps the backend's tenant_memberships.role_code values (the one role
 * catalogue: app/Application/Identity/RoleCatalogue.php) to a mobile portal.
 * Unknown codes return null; workspacePortal() then falls back on the
 * workspace's permissions before the app shows "Access not available". */
export const roleToPortal = (role: string | null | undefined): Portal | null => {
  switch ((role ?? "").toUpperCase()) {
    case "CUSTOMER":
      return "customer";
    case "AGENT":
    case "FREELANCE_AGENT":
      return "agent";
    case "BROKER_ADMIN":
    case "BROKER":
      return "broker_admin";
    case "BROKER_STAFF":
    // Supervisor = broker staff permissions + team oversight; not the company admin.
    case "BROKER_SUPERVISOR":
      return "broker_staff";
    case "CARRIER_SUPER_ADMIN":
    case "CARRIER_ADMIN":
    case "CARRIER_STAFF":
    case "CARRIER":
    // Underwriters work the insurer's referrals and quote requests (carrier.* reads).
    case "UNDERWRITER":
    case "SENIOR_UNDERWRITER":
      return "carrier";
    // Loss adjusters work claims; the till operator works cashier sessions.
    case "ADJUSTER":
      return "claims";
    case "CASHIER":
      return "finance";
    // Tenant operations roles without a dedicated portal: the permission-aware workspace.
    case "CUSTOMER_SERVICE":
    case "REINSURANCE_OFFICER":
    case "BRANCH_MANAGER":
      return "operations";
    case "PLATFORM_ADMIN":
    case "SYSTEM_ADMIN":
      return "platform_admin";
    case "COMPLIANCE_ADMIN":
    // Legacy codes (not in RoleCatalogue) still named by backend policies; kept so old memberships keep working.
    case "COMPLIANCE_OFFICER":
      return "compliance";
    case "FINANCE_ADMIN":
    case "FINANCE_MANAGER":
    case "FINANCE_OPERATOR":
    case "FINANCE_OFFICER":
      return "finance";
    case "CLAIMS_MANAGER":
    case "CLAIMS_OFFICER":
      return "claims";
    default:
      return null;
  }
};

export const WORKSPACE_PORTALS: Portal[] = [
  "platform_admin",
  "compliance",
  "finance",
  "claims",
  "operations",
];

/** Carrier-shell read permissions (mirror of src/lib/carrierAccess.ts
 * CARRIER_MODULE_PERMISSION; kept local so this store has no UI imports). */
const CARRIER_READS = [
  "carrier.dashboard.read",
  "carrier.referrals.read",
  "carrier.quote_requests.view",
  "carrier.issuance.read",
  "carrier.claims.read",
  "carrier.finance.read",
];

/** Portal for a role code the app does not know, from the workspace's
 * permissions: a carrier read opens the carrier shell, the broker portal
 * permission the broker portal, and workspace.read (or "*") the operations
 * workspace. Anything else stays null → "Access not available". */
export const portalFromPermissions = (
  permissions: readonly string[] | null | undefined,
): Portal | null => {
  const perms = permissions ?? [];
  if (perms.includes("*")) return "operations";
  if (CARRIER_READS.some((p) => perms.includes(p))) return "carrier";
  if (perms.includes("broker.portal.read")) return "broker_staff";
  if (perms.includes("workspace.read")) return "operations";
  return null;
};

/** The portal a workspace opens: its role code first, then its permissions. */
export const workspacePortal = (
  workspace: { role_code: string; permissions?: readonly string[] | null } | null | undefined,
): Portal | null => {
  if (!workspace) return null;
  return roleToPortal(workspace.role_code) ?? portalFromPermissions(workspace.permissions);
};
