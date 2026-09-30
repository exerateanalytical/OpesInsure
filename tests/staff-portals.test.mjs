// Launch fixes 2026-09-29 (staff portals): role routing, shared screens, carrier
// decisions, portal notifications and the insurer tab bar.
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { portalFromPermissions, roleToPortal, workspacePortal, WORKSPACE_PORTALS } from "../src/lib/portalRouting.ts";
import { markedRead, portalNotificationTarget } from "../src/lib/portalNotifications.ts";
import { bordereauDecidable, bordereauDecisionReady, issuanceActions, referralDecisions } from "../src/lib/carrierDecisions.ts";
import { carrierHrefAllowed } from "../src/lib/carrierAccess.ts";

const root = new URL("../", import.meta.url);
const read = (path) => readFileSync(new URL(path, root), "utf8");

test("every staff role in RoleCatalogue opens a portal instead of Access denied", () => {
  const expected = {
    UNDERWRITER: "carrier",
    SENIOR_UNDERWRITER: "carrier",
    CARRIER_SUPER_ADMIN: "carrier",
    CARRIER_ADMIN: "carrier",
    CARRIER_STAFF: "carrier",
    BROKER_SUPERVISOR: "broker_staff",
    BROKER_STAFF: "broker_staff",
    BROKER_ADMIN: "broker_admin",
    CUSTOMER_SERVICE: "operations",
    REINSURANCE_OFFICER: "operations",
    BRANCH_MANAGER: "operations",
    CASHIER: "finance",
    ADJUSTER: "claims",
    FINANCE_OFFICER: "finance",
    CLAIMS_OFFICER: "claims",
    AGENT: "agent",
    CUSTOMER: "customer",
  };
  for (const [role, portal] of Object.entries(expected)) assert.equal(roleToPortal(role), portal, role);
  assert.ok(WORKSPACE_PORTALS.includes("operations"));
  // Every mapped code exists in the backend catalogue (legacy aliases aside).
  const catalogue = read("../app/Application/Identity/RoleCatalogue.php");
  for (const role of Object.keys(expected)) assert.match(catalogue, new RegExp(`'${role}' =>`), role);
});

test("an unknown role falls back on its permissions", () => {
  assert.equal(portalFromPermissions(["carrier.referrals.read"]), "carrier");
  assert.equal(portalFromPermissions(["broker.portal.read"]), "broker_staff");
  assert.equal(portalFromPermissions(["workspace.read"]), "operations");
  assert.equal(portalFromPermissions(["*"]), "operations");
  assert.equal(portalFromPermissions(["quotes.rate"]), null);
  assert.equal(portalFromPermissions(null), null);
  assert.equal(workspacePortal({ role_code: "NEW_ROLE", permissions: ["carrier.finance.read"] }), "carrier");
  assert.equal(workspacePortal({ role_code: "NEW_ROLE", permissions: [] }), null);
  assert.equal(workspacePortal({ role_code: "AGENT", permissions: ["*"] }), "agent", "a known role code wins");
  assert.equal(workspacePortal(null), null);
});

test("layout, role picker and workspace route through workspacePortal", () => {
  const layout = read("app/_layout.tsx");
  assert.match(layout, /workspacePortal\(workspace\)/);
  assert.match(read("app/(auth)/role.tsx"), /workspacePortal\(workspace\)/);
  assert.match(read("app/workspace/[role].tsx"), /workspacePortal\(workspace\)/);
  assert.match(read("src/store/session.ts"), /const portal = workspacePortal\(workspace\)/);
});

test("documents are declared for every signed-in role and agent/pipeline inside the agent guard", () => {
  const layout = read("app/_layout.tsx");
  const block = (guard) => {
    const start = layout.indexOf(`<Stack.Protected guard={${guard}}>`);
    return layout.slice(start, layout.indexOf("</Stack.Protected>", start));
  };
  for (const screen of ["documents/[id]", "documents/view"]) {
    assert.ok(block("authenticated").includes(`name="${screen}"`), screen);
    assert.ok(!block("customer").includes(`name="${screen}"`), `${screen} must not be customer-only`);
  }
  assert.ok(block("agent").includes('name="agent/pipeline"'));
});

test("referral decisions follow the server's allowed actions, else the open statuses", () => {
  assert.deepEqual(referralDecisions({ status: "QUEUED", allowed_actions: ["DECLINE", "APPROVE"] }), ["APPROVE", "DECLINE"]);
  assert.deepEqual(referralDecisions({ status: "QUEUED", allowed_actions: [] }), []);
  assert.deepEqual(referralDecisions({ status: "QUEUED" }), ["APPROVE", "MORE_INFORMATION", "DECLINE"]);
  assert.deepEqual(referralDecisions({ status: "IN_REVIEW" }), ["APPROVE", "MORE_INFORMATION", "DECLINE"]);
  assert.deepEqual(referralDecisions({ status: "AWAITING_INFORMATION" }), ["APPROVE", "DECLINE"]);
  assert.deepEqual(referralDecisions({ status: "DECIDED" }), []);
  assert.deepEqual(referralDecisions({ status: "PENDING_REVIEW" }), [], "the old never-sent status grants nothing");
  const screen = read("app/carrier/referrals/[id].tsx");
  assert.doesNotMatch(screen, /PENDING_REVIEW|"LOADING"/);
  assert.match(screen, /td\(`refStatus_\$\{x\.status\}`/);
});

test("issuance maker-checker actions come from the server capabilities", () => {
  assert.deepEqual(issuanceActions({ status: "REQUESTED", capabilities: ["reject", "verify", "request_correction"] }, true), ["verify", "request_correction", "reject"]);
  assert.deepEqual(issuanceActions({ status: "REQUESTED", capabilities: ["second_approve", "reject"] }, true), ["second_approve", "reject"]);
  assert.deepEqual(issuanceActions({ status: "REQUESTED", capabilities: [] }, true), []);
  assert.deepEqual(issuanceActions({ status: "REQUESTED" }, true), ["approve", "reject"]);
  assert.deepEqual(issuanceActions({ status: "REQUESTED" }, false), []);
  const screen = read("app/carrier/issuance/[id].tsx");
  assert.match(screen, /CarrierWorkspaceApi\.issuance\(/);
  assert.doesNotMatch(screen, /CarrierApi\.issuance\(\)/, "no longer scans the whole queue");
  const api = read("src/api/partner.ts");
  for (const path of ["/verify`", "/request-correction`", "/second-approve`"]) assert.ok(api.includes(`/mobile/carrier/issuance/\${id}${path}`), path);
});

test("bordereau decision mirrors the server rules", () => {
  assert.equal(bordereauDecidable("SUBMITTED", true), true);
  assert.equal(bordereauDecidable("SUBMITTED", false), false);
  assert.equal(bordereauDecidable("ACKNOWLEDGED", true), false);
  assert.equal(bordereauDecisionReady("CR-1", "x".repeat(20)), true);
  assert.equal(bordereauDecisionReady("", "x".repeat(20)), false);
  assert.equal(bordereauDecisionReady("CR-1", "too short"), false);
  assert.match(read("src/api/extra.ts"), /`\/carrier\/bordereaux\/\$\{id\}\/decision`/);
  assert.match(read("app/carrier/bordereaux/[id].tsx"), /usePermission\("carrier\.bordereaux\.decide"\)/);
});

test("portal notification links stay inside the user's portal", () => {
  assert.equal(portalNotificationTarget("/carrier/referrals/abc", "carrier"), "/carrier/referrals/abc");
  assert.equal(portalNotificationTarget("/agent/quotes/1?x=1", "agent"), "/agent/quotes/1?x=1");
  assert.equal(portalNotificationTarget("/documents/view?id=1", "broker"), "/documents/view?id=1");
  assert.equal(portalNotificationTarget("/broker/clients/1", "agent"), null, "another portal");
  assert.equal(portalNotificationTarget("/policy/1", "agent"), null, "customer screen");
  assert.equal(portalNotificationTarget("/carrier/unknown", "carrier"), null, "no such screen");
  assert.equal(portalNotificationTarget("https://evil.example/x", "agent"), null);
  assert.equal(portalNotificationTarget("//evil.example/agent", "agent"), null);
  assert.equal(portalNotificationTarget("/agent/../policy/1", "agent"), null);
  assert.equal(portalNotificationTarget(null, "agent"), null);
  const rows = [{ id: "a", read: false }, { id: "b", read: false }];
  assert.deepEqual(markedRead(rows, ["a"]).map((n) => n.read), [true, false]);
  assert.deepEqual(markedRead(rows, "all").map((n) => n.read), [true, true]);
  for (const file of ["app/agent/notifications.tsx", "src/components/portal/PortalShell.tsx"]) {
    const src = read(file);
    assert.match(src, /NotificationsApi\.markRead\(/, file);
    assert.match(src, /NotificationsApi\.markAllRead\(/, file);
    assert.match(src, /portalNotificationTarget\(/, file);
  }
});

test("the insurer tab bar hides modules the workspace is not granted", () => {
  const finance = ["carrier.dashboard.read", "carrier.finance.read"];
  assert.equal(carrierHrefAllowed("/carrier", finance), true);
  assert.equal(carrierHrefAllowed("/carrier/payments", finance), true);
  assert.equal(carrierHrefAllowed("/carrier/referrals", finance), false);
  assert.equal(carrierHrefAllowed("/carrier/claims", finance), false);
  assert.equal(carrierHrefAllowed("/carrier/notifications", []), true);
  const shell = read("src/components/portal/PortalShell.tsx");
  assert.match(shell, /allTabs\.filter\(\(tab\) => carrierHrefAllowed\(tab\.href, perms, caps\)\)/);
});

test("publications toggle is permission-gated with busy and error states", () => {
  const src = read("app/broker/publications.tsx");
  assert.match(src, /usePermission\("broker\.marketplace\.manage"\)/);
  assert.match(src, /loading=\{busy === p\.id\}/);
  assert.match(src, /errorMessage\(e/);
  assert.doesNotMatch(src, /submitted \{p\.submitted_at\}/);
});

test("catalogue rows start an assisted sale with the product preselected", () => {
  assert.match(read("src/components/offers/SellableCatalogueScreen.tsx"), /pathname: "\/agent\/sales\/new", params: \{ product:/);
});

test("staff-portal copy has EN and FR keys", () => {
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  const keys = [
    "role_operations", "wsMobileLimited", "wsMobileLimitedBody", "wsModuleTitle", "wsNoRecordsTitle", "refStatus_QUEUED", "refRecorded",
    "portalNotifMarkAll", "portalNotifMarkFailed", "cdIssVerify", "cdIssSecondApprove", "cdIssRequestCorrection", "bdxAcknowledge", "bdxDecisionNotes",
    "pubStatus_PAUSED", "brPublicationSubmitted", "caPendingNumber", "caEffectiveFrom", "agOffersCount", "agOffersCountOne", "caItemsCount",
    "caItemsCountOne", "caPoliciesCount", "caPoliciesCountOne", "caSettlePeriod", "caSettleItemBreakdown",
  ];
  for (const key of keys) {
    assert.match(en, new RegExp(`\\n  ${key}: "`), `en ${key}`);
    assert.match(fr, new RegExp(`\\n  ${key}: "`), `fr ${key}`);
  }
  for (const [file, text] of [
    ["app/workspace/[role]/module/[module].tsx", /"Workspace module"|"No records"/],
    ["app/carrier/policies.tsx", /"Pending number"/],
    ["app/carrier/products.tsx", /· from \{/],
    ["app/carrier/settlement/[id].tsx", /Period \{|Items \(|replaceAll\("_", " "\)/],
    ["app/agent/quotes.tsx", /\} offers`/],
    ["app/carrier/bordereaux.tsx", /\} items ·/],
    ["app/carrier/partners.tsx", /\} policies ·/],
  ])
    assert.doesNotMatch(read(file), text, file);
});
