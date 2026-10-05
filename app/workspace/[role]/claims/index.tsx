import React, { useEffect, useMemo, useRef } from "react";
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ChevronRight, ShieldAlert } from "lucide-react-native";
import { AppHeader, Card, Screen, StatusChip } from "@/components/ui";
import { EmptyState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { FilterToolbar, useListFilters, type FilterSection } from "@/components/filters";
import { usePagedList } from "@/hooks/usePagedList";
import { WorkspaceClaimsApi, type WorkspaceClaim } from "@/api/workspace";
import { money, shortDate } from "@/api/partner";
import { WORKSPACE_CLAIM_STATUSES } from "@/lib/workspaceClaims";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Phase-1 fix S (2026-09-30): the workspace claims module for claims officers / managers, adjusters and every staff
 * role holding claims.view. Rows are the caller's data scope (server-side); search + one filter sheet (status) are
 * sent to the server; the list loads the next page on scroll.
 */
export default function WorkspaceClaims() {
  const { t, td } = useTranslation();
  const { role } = useLocalSearchParams<{ role: string }>();
  const sections = useMemo<FilterSection[]>(
    () => [{ key: "status", title: t("wscStatus"), options: WORKSPACE_CLAIM_STATUSES.map((s) => ({ value: s, label: td(`claimStatus_${s}`, s.replaceAll("_", " ").toLowerCase()) })) }],
    [t, td],
  );
  const flt = useListFilters("workspace.claims", sections);
  const status = (flt.values.status ?? []).join(",");
  const q = flt.query;
  const pager = useMemo(() => WorkspaceClaimsApi.pager({ status, q }), [status, q]);
  const list = usePagedList<WorkspaceClaim>((page) => pager(page));
  // A new server-side filter restarts from the first page.
  const first = useRef(true);
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    void list.reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pager]);

  return (
    <Screen scroll={false}>
      <FlatList
        data={list.items}
        keyExtractor={(c) => c.id}
        contentContainerStyle={s.content}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={list.loading && list.items.length > 0} onRefresh={() => void list.reload()} />}
        onEndReachedThreshold={0.4}
        onEndReached={() => {
          if (!list.moreError) void list.loadMore();
        }}
        ListHeaderComponent={
          <View style={s.header}>
            <AppHeader title={t("wscTitle")} subtitle={t("wscSubtitle")} back />
            <FilterToolbar filters={flt} sections={sections} placeholder={t("wscSearch")} count={() => list.items.length} />
            {list.error ? <ErrorCard error={list.error} fallback={t("wsLoadFailed")} onRetry={() => void list.reload()} /> : null}
          </View>
        }
        ListEmptyComponent={!list.loading && !list.error ? <EmptyState title={t("wscEmpty")} message={t("wscEmptyBody")} /> : null}
        ListFooterComponent={<LoadMore hasMore={list.hasMore} loading={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />}
        renderItem={({ item: c }) => (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={[c.claim_number, c.claimant_name, c.status_label].filter(Boolean).join(", ")}
            onPress={() => router.push({ pathname: "/workspace/[role]/claims/[id]", params: { role: String(role), id: c.id } } as never)}
          >
            <Card>
              <View style={s.row}>
                <View style={s.icon}>
                  <ShieldAlert size={20} color={colors.blue600} />
                </View>
                <View style={s.flex}>
                  <Text style={s.title}>{c.claim_number}</Text>
                  <Text style={s.meta}>{[c.claimant_name, c.policy_number].filter(Boolean).join(" · ")}</Text>
                  <Text style={s.meta}>
                    {[shortDate(c.submitted_at), c.estimated_loss_minor !== null ? money(c.estimated_loss_minor) : null, c.assigned_to_me ? t("wscAssignedToMe") : c.assignee_name ? t("wscHandler", { name: c.assignee_name }) : t("wscUnassigned")]
                      .filter(Boolean)
                      .join(" · ")}
                  </Text>
                </View>
                <View style={s.side}>
                  <StatusChip label={c.status_label} />
                  <ChevronRight size={18} color={colors.neutral500} />
                </View>
              </View>
            </Card>
          </Pressable>
        )}
      />
    </Screen>
  );
}

const s = StyleSheet.create({
  content: { gap: space.x3, paddingBottom: space.x8 },
  header: { gap: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  icon: { width: 40, height: 40, borderRadius: radius.control, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  flex: { flex: 1, gap: 2 },
  side: { alignItems: "flex-end", gap: space.x2 },
  title: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
});
