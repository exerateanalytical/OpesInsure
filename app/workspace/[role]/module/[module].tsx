import React, { useMemo } from "react";
import { FlatList, RefreshControl, ScrollView, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { MoveHorizontal } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { LoadMore } from "@/components/purchase/PurchaseUi";
import { usePagedList } from "@/hooks/usePagedList";
import { workspaceModulePager } from "@/api/workspace";
import { colors, space, type } from "@/theme/tokens";

import { useTranslation } from "@/i18n";
const cellText = (v: unknown) =>
  v === null || v === undefined || v === "" ? "" : String(v);

/**
 * Generic workspace module table. Phase-1 fix S (2026-09-30): rows are scoped by the server to the caller's data
 * scope, column headers arrive in the app language, and the list loads the next page (?cursor=) on scroll.
 */
export default function Module() {
  const { t } = useTranslation();
  const { module } = useLocalSearchParams<{ module: string }>();
  const source = useMemo(() => workspaceModulePager(String(module)), [module]);
  const list = usePagedList(source.pager);
  const meta = source.meta();
  const columns = meta?.columns ?? [];
  const { width } = useWindowDimensions();
  const narrow = width < 600;
  const onEnd = () => {
    if (!list.moreError) void list.loadMore();
  };
  const footer = <LoadMore hasMore={list.hasMore} loading={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />;
  const empty = !list.loading && !list.error ? <EmptyState title={t("wsNoRecordsTitle")} message={t("wsNoRecords")} /> : null;
  const refresh = <RefreshControl refreshing={list.loading && list.items.length > 0} onRefresh={() => void list.reload()} />;

  return (
    <Screen scroll={false}>
      <AppHeader title={meta?.label ?? meta?.title ?? t("wsModuleTitle")} back />
      <StatePanel loading={list.loading} error={list.error} data={list.loading && !list.items.length ? undefined : list.items} onRetry={() => void list.reload()}>
        {(rows) =>
          narrow ? (
            <FlatList
              data={rows}
              keyExtractor={(r) => r.id}
              contentContainerStyle={styles.content}
              refreshControl={refresh}
              onEndReachedThreshold={0.4}
              onEndReached={onEnd}
              ListEmptyComponent={empty}
              ListFooterComponent={footer}
              renderItem={({ item }) => (
                <Card>
                  {columns.map((column) => (
                    <View key={column} style={styles.pair}>
                      <Text style={styles.label}>{column}</Text>
                      <Text style={styles.value}>{cellText(item[column])}</Text>
                    </View>
                  ))}
                </Card>
              )}
            />
          ) : (
            <>
              <View style={styles.hint}>
                <MoveHorizontal size={16} color={colors.neutral600} />
                <Text style={styles.hintText}>{t("wsScrollHint")}</Text>
              </View>
              <ScrollView horizontal showsHorizontalScrollIndicator>
                <FlatList
                  data={rows}
                  keyExtractor={(r) => r.id}
                  refreshControl={refresh}
                  onEndReachedThreshold={0.4}
                  onEndReached={onEnd}
                  ListEmptyComponent={empty}
                  ListFooterComponent={footer}
                  ListHeaderComponent={
                    <View style={styles.row}>
                      {columns.map((column) => (
                        <Text key={column} style={[styles.cell, styles.header]}>
                          {column}
                        </Text>
                      ))}
                    </View>
                  }
                  renderItem={({ item }) => (
                    <View style={styles.row}>
                      {columns.map((column) => (
                        <Text key={column} style={styles.cell}>
                          {cellText(item[column])}
                        </Text>
                      ))}
                    </View>
                  )}
                />
              </ScrollView>
            </>
          )
        }
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  content: { gap: space.x3, paddingBottom: space.x6 },
  row: {
    flexDirection: "row",
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral200,
  },
  cell: { width: 150, padding: space.x3, ...type.meta, color: colors.neutral700 },
  header: {
    ...type.label,
    color: colors.navy950,
    backgroundColor: colors.neutral100,
  },
  pair: { gap: 2 },
  label: { ...type.caption, color: colors.neutral600 },
  value: { ...type.body, color: colors.navy950 },
  hint: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  hintText: { ...type.meta, color: colors.neutral600 },
});
