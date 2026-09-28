import React, { ReactNode } from "react";
import { StyleSheet, Text, View } from "react-native";
import { CloudOff, type LucideIcon } from "lucide-react-native";
import { AgentEmptyState, AgentSkeleton } from "@/components/agent";
import { ApiError } from "@/api/client";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

/**
 * Commercial Agent book screens (customers / policies / renewals / proposals,
 * AGENT_UI_SPEC_V2): operational title and load states. Rows use
 * AgentListRow / AgentRawChip from ./AgentListUi.
 */

/** Screen title (28/34) + secondary subtitle for operational agent screens. */
export function BookTitle({ title, subtitle, right }: { title: string; subtitle?: string | null; right?: ReactNode }) {
  return (
    <View style={s.head}>
      <View style={s.headText}>
        <Text accessibilityRole="header" style={s.title}>{title}</Text>
        {subtitle ? <Text style={s.sub}>{subtitle}</Text> : null}
      </View>
      {right}
    </View>
  );
}

/** Inline note inside a row card (e.g. "No claims on this policy"). */
export function BookNote({ children }: { children: ReactNode }) {
  return <Text style={s.note}>{children}</Text>;
}

type Loaded<D> = { data: D | undefined; loading: boolean; error: unknown; reload: () => unknown };

/**
 * Spec §11 states: skeleton while loading, offline / error + retry, empty with
 * icon + title + body (+ CTA), else children. Rows already loaded stay visible.
 */
export function BookLoad<D>({
  q,
  icon,
  emptyTitle,
  emptyBody,
  emptyAction,
  onEmptyAction,
  isEmpty = (d) => Array.isArray(d) && d.length === 0,
  rows = 5,
  children,
}: {
  q: Loaded<D>;
  icon: LucideIcon;
  emptyTitle?: string;
  emptyBody?: string;
  emptyAction?: string;
  onEmptyAction?: () => void;
  isEmpty?: (d: D) => boolean;
  rows?: number;
  children: (d: D) => ReactNode;
}) {
  const { t } = useTranslation();
  if (q.loading && q.data === undefined) return <AgentSkeleton rows={rows} height={L.rowMinHeight + 10} />;
  if (q.error && q.data === undefined) {
    const offline = q.error instanceof ApiError && q.error.status === 0;
    return (
      <AgentEmptyState
        icon={offline ? CloudOff : icon}
        title={offline ? t("dtOfflineTitle") : t("loadErrorTitle")}
        body={offline ? t("dtOfflineBody") : t("loadErrorBody")}
        actionLabel={t("retry")}
        onAction={() => void q.reload()}
      />
    );
  }
  const d = q.data as D;
  if (emptyTitle && isEmpty(d)) return <AgentEmptyState icon={icon} title={emptyTitle} body={emptyBody ?? ""} actionLabel={emptyAction} onAction={onEmptyAction} />;
  return <>{children(d)}</>;
}

export const bookStyles = StyleSheet.create({
  meta: { ...T.caption, color: c.secondary },
  body: { ...T.body, color: c.secondary },
});

const s = StyleSheet.create({
  head: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: 12 },
  headText: { flex: 1, gap: 4 },
  title: { ...T.screenTitle, color: c.heading },
  sub: { ...T.secondary, color: c.secondary },
  note: { ...T.secondary, color: c.secondary, paddingHorizontal: L.cardPadding, paddingVertical: 14 },
});
