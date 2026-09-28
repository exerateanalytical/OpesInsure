/**
 * Commercial Agent UI kit (spec: docs/AGENT_UI_SPEC_V2.md, tokens: src/theme/agent.ts).
 * Import from "@/components/agent". APIs are stable — extend with optional props only.
 *
 *  AgentShell       Screen frame. variant "operational" (OPESINSURE wordmark + "Commercial Agent
 *                   Portal", bell with unread badge -> /agent/notifications, avatar -> /agent/account)
 *                   or "drilldown" (back arrow + centred `title`, optional `headerRight`, `onBack`).
 *                   Locked bottom nav unless `hideNav`; `footer` pins a sticky action above it;
 *                   `scroll={false}` for FlatList bodies; `onRefresh`/`refreshing` for pull-to-refresh.
 *  AgentSection     Uppercase caption label + children (`title`, optional `action`).
 *  AgentCard        White, 1px #E9EAEB, radius 18, no shadow. `padded={false}` for row lists,
 *                   `tone="danger"` for danger zones, optional `onPress`.
 *  AgentNavRow      `icon` (Lucide) · `title` · `subtitle?` · `status?` chip · `right?` · chevron.
 *                   Full row tappable, min height 62. `divider={false}` on the first row in a card,
 *                   `danger`, `busy`, `chevron={false}`.
 *  AgentStatusChip  `status` = a locked vocabulary word (agentStatus in src/theme/agent.ts), EN/FR via
 *                   i18n keys agentSt_<WordWithoutSpaces>. `tone?` overrides success|warning|danger|info|neutral.
 *  AgentButton      `variant` primary (blue, 52h, radius 14) | secondary (outline) | danger (outline);
 *                   `icon?`, `loading?`, `disabled?`.
 *  AgentActionTiles 2-column quick-action tiles (`actions`: key/title/subtitle/icon/onPress).
 *  AgentIconBadge   Pronounced icon on a platform-colour tile (`icon`, `tone` brand|navy|gold|success|warning|danger, `size`).
 *                   AgentNavRow uses it; pass `iconTone` on a row to change the tile colour.
 *  AgentCountBadge  Numeric pill for a row's `right` (`count`, `tone` warning|danger|neutral).
 *  AgentEmptyState  `icon`, `title`, `body`, optional `actionLabel` + `onAction`.
 *  AgentSkeleton    Loading placeholder rows (`rows`, `height`).
 *  HeritageAccent   The one brand-art moment per screen (`variant` africa|pattern|network, `size`,
 *                   `opacity` clamped 0.03–0.08, `style` to position). Decorative, a11y-hidden.
 *  AgentAvatar      Initials avatar (`name`, `size`). agentInitials(name) helper.
 */
export { AgentShell, AgentAvatar, AgentPageHeader, agentInitials } from "./AgentShell";
export type { PartnerPortal } from "./AgentShell";
export { PartnerHome } from "./PartnerHome";
export type { PartnerHomeLink } from "./PartnerHome";
export {
  AgentSection,
  AgentCard,
  AgentNavRow,
  AgentStatusChip,
  AgentButton,
  AgentActionTiles,
  AgentCountBadge,
  AgentIconBadge,
  AgentEmptyState,
  AgentSkeleton,
  HeritageAccent,
  agentStatusKey,
} from "./primitives";
export type { AgentAction, AgentChipTone, AgentIconTone, AgentStatusKey } from "./primitives";
