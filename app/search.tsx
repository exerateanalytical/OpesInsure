import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Search as SearchIcon } from "lucide-react-native";
import { AppHeader, Card, Screen, SectionTitle } from "@/components/ui";
import { SearchBar } from "@/components/SearchBar";
import { ChoiceChips } from "@/components/portal/Workspace";
import { FlowRow } from "@/components/FlowPrimitives";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { SearchApi } from "@/api/crm";
import { groupSearch, SEARCH_TYPES, SearchResponse, searchHitRoute, SearchRole, SearchType } from "@/lib/crm";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

const ROLES: SearchRole[] = ["customer", "agent", "broker", "carrier"];

/** REQ-SRC-001 global search (GET /search?q=&types[]=). ?role= picks the portal's detail screens. */
export default function GlobalSearch() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<{ role?: string }>();
  const role: SearchRole = ROLES.includes(params.role as SearchRole) ? (params.role as SearchRole) : "customer";
  const [text, setText] = useState("");
  const [only, setOnly] = useState<"all" | SearchType>("all");
  const [state, setState] = useState<{ loading: boolean; error: unknown; data: SearchResponse | null }>({ loading: false, error: null, data: null });
  const [tooShort, setTooShort] = useState(false);

  const run = async (scope = only) => {
    const q = text.trim();
    setTooShort(q.length < 2);
    if (q.length < 2) return;
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const data = await SearchApi.search(q, scope === "all" ? [] : [scope], scope === "all" ? 5 : 20);
      setState({ loading: false, error: null, data });
    } catch (e) {
      setState({ loading: false, error: e, data: null });
    }
  };
  const groups = groupSearch(state.data);

  return (
    <Screen>
      <AppHeader title={t("searchTitle")} subtitle={t("searchSubtitle")} back />
      <SearchBar value={text} onChangeText={setText} onSubmit={() => void run()} placeholder={t("globalSearchPlaceholder")} label={t("searchTitle")} clearLabel={t("clearSearch")} autoFocus />
      <ChoiceChips<"all" | SearchType>
        label={t("searchTitle")}
        value={only}
        onChange={(v) => {
          setOnly(v);
          if (text.trim().length >= 2) void run(v);
        }}
        options={[{ value: "all" as const, label: t("searchAll") }, ...SEARCH_TYPES.map((x) => ({ value: x, label: td(`searchType_${x}`, x) }))]}
      />
      {tooShort ? <Text style={s.meta}>{t("searchMinChars")}</Text> : null}
      {state.loading ? <LoadingState /> : null}
      {state.error ? <ErrorState error={state.error} onRetry={() => void run()} /> : null}
      {state.data && !state.loading && !groups.length ? (
        <EmptyState title={t("searchTitle")} message={t("searchNoResults", { q: state.data.query ?? text })} />
      ) : null}
      {!state.loading
        ? groups.map((g) => (
            <React.Fragment key={g.type}>
              <SectionTitle title={`${td(`searchType_${g.type}`, g.type)} (${state.data?.counts?.[g.type] ?? g.hits.length})`} />
              <Card>
                {g.hits.map((h) => {
                  const href = searchHitRoute(h, role);
                  return (
                    <FlowRow
                      key={`${h.type}-${h.id}`}
                      icon={SearchIcon}
                      title={h.title}
                      subtitle={h.subtitle ?? undefined}
                      status={h.status ? td(`status_${h.status}`, h.status) : undefined}
                      onPress={href ? () => router.push(href as never) : undefined}
                    />
                  );
                })}
              </Card>
            </React.Fragment>
          ))
        : null}
    </Screen>
  );
}

const s = StyleSheet.create({ meta: { ...type.meta, color: colors.neutral600 } });
