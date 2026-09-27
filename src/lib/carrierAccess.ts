/**
 * Carrier-shell module visibility (CAR-013 / CAR-014).
 *
 * Mirrors the `permission:` middleware on each backend route the module
 * calls (routes/wave12_brokercarrier.php, wave14_mobile.php,
 * wave16_partner.php, carrier_quote_requests.php). This ONLY decides what
 * the menu shows: the server re-checks every request, so a hidden entry is
 * never the security boundary. No imports (node-tested).
 */
export type CarrierModule =
  | "products"
  | "proposals"
  | "quote_requests"
  | "referrals"
  | "issuance"
  | "policies"
  | "claims"
  | "payments"
  | "partners"
  | "settlements"
  | "bordereaux";

export const CARRIER_MODULE_PERMISSION: Record<CarrierModule, string> = {
  products: "carrier.dashboard.read",
  proposals: "carrier.referrals.read",
  quote_requests: "carrier.quote_requests.view",
  referrals: "carrier.referrals.read",
  issuance: "carrier.issuance.read",
  policies: "carrier.dashboard.read",
  claims: "carrier.claims.read",
  payments: "carrier.finance.read",
  partners: "carrier.dashboard.read",
  settlements: "carrier.finance.read",
  bordereaux: "carrier.finance.read",
};

/** Route (under /carrier) -> module, for the direct-route gate. */
export const CARRIER_ROUTE_MODULE: Record<string, CarrierModule> = {
  products: "products",
  proposals: "proposals",
  "quote-requests": "quote_requests",
  referrals: "referrals",
  issuance: "issuance",
  policies: "policies",
  claims: "claims",
  payments: "payments",
  partners: "partners",
  settlements: "settlements",
  settlement: "settlements",
  bordereaux: "bordereaux",
};

export const hasPermission = (perms: readonly string[] | null | undefined, permission: string) =>
  !!perms && (perms.includes("*") || perms.includes(permission));

export const canUseCarrierModule = (perms: readonly string[] | null | undefined, module: CarrierModule) =>
  hasPermission(perms, CARRIER_MODULE_PERMISSION[module]);

export const allowedCarrierModules = (perms: readonly string[] | null | undefined): CarrierModule[] =>
  (Object.keys(CARRIER_MODULE_PERMISSION) as CarrierModule[]).filter((m) => canUseCarrierModule(perms, m));

/** Specialised operational roles (finance / claims / compliance) whose
 * transactional work lives in the carrier shell (CAR-014), scoped by the
 * permissions the server granted them. */
export const SPECIALIST_CARRIER_MODULES: Record<string, CarrierModule[]> = {
  finance: ["payments", "settlements", "bordereaux", "issuance", "policies"],
  claims: ["claims", "policies"],
  compliance: ["partners", "policies", "bordereaux", "issuance"],
};

export const specialistCarrierModules = (
  portal: string | null | undefined,
  perms: readonly string[] | null | undefined,
): CarrierModule[] => (SPECIALIST_CARRIER_MODULES[portal ?? ""] ?? []).filter((m) => canUseCarrierModule(perms, m));

/** Whether a signed-in workspace may enter the /carrier stack at all. */
export const carrierShellAllowed = (portal: string | null | undefined, perms: readonly string[] | null | undefined) =>
  portal === "carrier" || specialistCarrierModules(portal, perms).length > 0;
