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

/** GET /mobile/capabilities module names for carrier modules (products / partners have none). */
export const CARRIER_MODULE_CAPABILITY: Partial<Record<CarrierModule, string>> = {
  proposals: "proposals",
  quote_requests: "quotes",
  referrals: "referrals",
  issuance: "issuance",
  policies: "policies",
  claims: "claims",
  payments: "payments",
  settlements: "settlements",
  bordereaux: "bordereaux",
};

/** Minimal shape of the capabilities payload (see src/lib/capabilities.ts). */
export type CarrierCapabilities = { modules: Record<string, { view: boolean }> } | null | undefined;

/**
 * Permission check, additionally narrowed by server capabilities when they
 * are known (a module the server reports as not viewable is hidden). With no
 * capabilities the permission check alone decides, as before.
 */
export const canUseCarrierModule = (
  perms: readonly string[] | null | undefined,
  module: CarrierModule,
  caps?: CarrierCapabilities,
) => {
  if (!hasPermission(perms, CARRIER_MODULE_PERMISSION[module])) return false;
  const capModule = CARRIER_MODULE_CAPABILITY[module];
  const cap = capModule ? caps?.modules[capModule] : undefined;
  return cap ? cap.view : true;
};

/** Whether a /carrier/... href may be shown (menu tile, bottom tab). Routes
 * that are no module (home, account, notifications) are always shown. */
export const carrierHrefAllowed = (
  href: string,
  perms: readonly string[] | null | undefined,
  caps?: CarrierCapabilities,
) => {
  const mod = CARRIER_ROUTE_MODULE[href.split("?")[0]!.split("/")[2] ?? ""];
  return !mod || canUseCarrierModule(perms, mod, caps);
};

export const allowedCarrierModules =(perms: readonly string[] | null | undefined, caps?: CarrierCapabilities): CarrierModule[] =>
  (Object.keys(CARRIER_MODULE_PERMISSION) as CarrierModule[]).filter((m) => canUseCarrierModule(perms, m, caps));

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
  caps?: CarrierCapabilities,
): CarrierModule[] => (SPECIALIST_CARRIER_MODULES[portal ?? ""] ?? []).filter((m) => canUseCarrierModule(perms, m, caps));

/** Whether a signed-in workspace may enter the /carrier stack at all. */
export const carrierShellAllowed = (
  portal: string | null | undefined,
  perms: readonly string[] | null | undefined,
  caps?: CarrierCapabilities,
) => portal === "carrier" || specialistCarrierModules(portal, perms, caps).length > 0;
