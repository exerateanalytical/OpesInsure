/**
 * Customer bottom navigation outside the tab navigator (node-tested in
 * tests/customer-nav.test.mjs). Pure: no React Native imports.
 *
 * The five customer tabs live in app/(customer)/(tabs). Every other customer
 * screen (quote, checkout, policy, claim, payments, notifications, support,
 * account...) is a root Stack screen, so the tab navigator's bar is not
 * there. app/_layout.tsx wraps those stack screens (screenLayout) in
 * CustomerScreenFrame, which renders the same bar under the screen using
 * these rules.
 */

export type CustomerTab = "home" | "explore" | "policies" | "claims" | "profile";

/** Bar order and destinations (same as app/(customer)/(tabs)/_layout.tsx). */
export const CUSTOMER_TABS: readonly { key: CustomerTab; label: "home" | "explore" | "policies" | "claims" | "profile"; href: string }[] = [
  { key: "home", label: "home", href: "/(customer)/(tabs)" },
  { key: "explore", label: "explore", href: "/(customer)/(tabs)/explore" },
  { key: "policies", label: "policies", href: "/(customer)/(tabs)/policies" },
  { key: "claims", label: "claims", href: "/(customer)/(tabs)/claims" },
  { key: "profile", label: "profile", href: "/(customer)/(tabs)/profile" },
];

export const CUSTOMER_HOME_HREF = "/(customer)/(tabs)";

/** "quote/product/[id]" from a route name or pathname ("/quote/product/1" also works for the first segment). */
export const normalizeRouteName = (name: string | null | undefined) =>
  String(name ?? "")
    .trim()
    .replace(/^\/+/, "")
    .replace(/\/index$/, "")
    .replace(/^index$/, "");

const first = (name: string) => name.split("/")[0] ?? "";

/** Root screens that are not customer stack screens: the splash router, the
 * tab navigator group (own bar, own back behaviour), auth, onboarding,
 * partner portals and the app's own status screens. */
const NOT_STACK_EXACT = new Set(["", "welcome", "access-denied", "session-expired", "+not-found"]);
const NOT_STACK_SECTIONS = new Set(["(auth)", "(customer)", "onboarding", "agent", "broker", "carrier", "workspace"]);

export function isCustomerStackScreen(routeName: string | null | undefined): boolean {
  const name = normalizeRouteName(routeName);
  return !NOT_STACK_EXACT.has(name) && !NOT_STACK_SECTIONS.has(first(name));
}

/** Stack screens where the bar would be harmful: the payment confirmation
 * poll, full-screen viewers and scanners, and step-up verification. */
const NO_BAR = new Set(["payment", "documents/view", "assets/[id]/scan", "verify/scan", "security/step-up"]);

export function customerBarVisible(routeName: string | null | undefined): boolean {
  return isCustomerStackScreen(routeName) && !NO_BAR.has(normalizeRouteName(routeName));
}

/**
 * The tab a stack screen belongs to (highlighted in the bar), or null when it
 * belongs to none. Buying (quote, offers, comparison, proposal, checkout,
 * confirmation, insurer directory) starts from Explore's catalogue, so it
 * highlights Explore; what the customer owns afterwards (policy, documents,
 * payments, wallet, delivery, policy services) highlights Policies; claims
 * highlight Claims; account, vehicles/assets and help highlight Profile; the
 * notification inbox and search open from Home's header.
 */
export function customerTabForRoute(routeName: string | null | undefined): CustomerTab | null {
  const name = normalizeRouteName(routeName);
  const section = first(name);
  switch (section) {
    case "quote":
    case "quotes":
    case "quote-comparison":
    case "proposals":
    case "checkout":
    case "payment":
    case "confirmation":
    case "institutions":
      return "explore";
    case "policy":
    case "payments":
    case "wallet":
    case "delivery":
    case "documents":
    case "services":
    case "verify":
      return "policies";
    case "claim":
      return "claims";
    case "account":
    case "assets":
    case "support":
    case "security":
    case "system":
    case "sync":
    case "terms":
      return "profile";
    case "notifications":
    case "search":
      return "home";
    default:
      return null;
  }
}

export type BackAction = { kind: "default" } | { kind: "replace"; href: string } | { kind: "dismissTo"; href: string };

/**
 * Android hardware back on a customer stack screen.
 * - confirmation: payment is done; going back would reopen checkout/payment,
 *   so back returns Home (the stack under it is dropped).
 * - payment (confirmation poll): back behaves like "Check later": the
 *   application page, never the checkout that started the payment.
 * - no history (opened from a notification, deep link or a replace chain):
 *   Home instead of closing the app.
 * - otherwise the normal pop.
 */
export function customerBackAction(routeName: string | null | undefined, canGoBack: boolean, params: { proposalId?: unknown } = {}): BackAction {
  const name = normalizeRouteName(routeName);
  // Tabs, auth and portal screens keep their own back behaviour.
  if (!isCustomerStackScreen(name)) return { kind: "default" };
  if (name === "confirmation") return { kind: "dismissTo", href: CUSTOMER_HOME_HREF };
  if (name === "payment") {
    const id = typeof params.proposalId === "string" && params.proposalId ? params.proposalId : null;
    return { kind: "replace", href: id ? `/proposals/${encodeURIComponent(id)}` : "/(customer)/(tabs)/policies" };
  }
  if (!canGoBack) return { kind: "replace", href: CUSTOMER_HOME_HREF };
  return { kind: "default" };
}
