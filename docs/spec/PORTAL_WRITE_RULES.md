# Portal write rules (owner decision 2026-09-29, D4 lifted)

The portals /insurer and /broker (and the /account, /provider areas that reuse the same helpers) are no longer
read-only. Every portal user sees and does exactly what their RBAC permissions allow: no more, no less.

## The rule

For every navigation item, list page, record view and action in a portal:

1. **Permission** - the SAME permission string the API route uses (`RequirePermission` / `permission:` middleware),
   evaluated with `$user->hasPermission($p)` in the portal tenant (TenantContext). No role-name shortcuts.
   - Reads: `PortalAuthorization::allowsRead($user, $p)` (accepts the documented `EQUIVALENT_READS`).
   - Writes: `PortalAuthorization::allowsWritePermission($user, $p)` (accepts `EQUIVALENT_WRITES`: the permission of an
     existing API alias route on the same service, e.g. `bordereaux.confirm` <- `carrier.bordereaux.decide`).
2. **Own organisation** - a record-level action runs only on a record of the caller's own organisation:
   `PortalScope::isOwnRecord($record)`:
   - tenant: `tenant_id` / `custodian_tenant_id` = portal tenant (absolute, fails closed);
   - /insurer: a record with `carrier_id` must be the caller's carrier (`PortalScope::carrierId()`);
   - /broker: commission accruals = the caller's partner; statements / payouts = the caller's partner when book-scoped
     (`PortalScope::brokerPartnerId()`, same as the lists);
   - carrier-broker agreements: insurer = own `carrier_id`; broker = `CarrierBrokerAgreementRecord::visibleInPortal()`;
   - book tables (policies, quotes, proposals, claims, underwriting cases, health ...): the same `visibleIds()` the lists use.
   Outside a portal it returns true (the admin panel keeps its own tenant scope).
3. **Same service** - the action calls the same application service as the API (no business logic in the page).
   Generic Filament CRUD (resource create / edit / delete forms) stays refused in portal sections, because those bypass
   the services.

## Helpers to call

| Need | Call |
| --- | --- |
| Workflow action (preferred) | `WorkflowAction::make($name, $permission, $lang)` + `WorkflowAction::run(...)` - applies permission AND `isOwnRecord($action->getRecord())` on show and on execution (denial audited). Nothing else to do. |
| Custom action / button | `->authorize(fn ($record = null) => PortalScope::allowsWrite('the.api.permission', $record))` |
| Record belongs to caller? | `PortalScope::isOwnRecord($record)` |
| Read gate (nav, page, widget) | `PortalAuthorization::allowsRead($user, 'the.api.read.permission')` |
| Narrow a list query | `PortalScope::narrowTable($q, 'policies')`, `narrowToCarrier($q)`, `narrowToPartner($q)`, `narrowToBookPartner($q)` |
| Select options for a carrier | `FinanceOptions::carriers()` / `CarrierOnboardingActions::carrierOptions()` return only the own carrier in /insurer |

Do NOT hide actions with `PortalScope::panel() === null` or `PortalAccess::isPortal(...)` any more; gate them with the
helpers above. Do not grant a permission to a role to make a button appear - RBAC (RoleCatalogue) is the owner's.

## Finance sections enabled

Bordereaux, carrier settlements, commission accruals, commission rules, partner statements, partner payouts and
carrier-broker agreements show their workflow actions in every panel. /broker now also mounts PartnerStatementResource
and PartnerPayoutResource. Which buttons a user sees depends only on their permissions and on record ownership.

Tests: tests/Feature/Web/PortalWriteCoreTest.php.
