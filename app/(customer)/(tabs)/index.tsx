import React, { useCallback, useEffect, useState } from "react";
import { RefreshControl, ScrollView, StyleSheet, Text, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { router, useFocusEffect } from "expo-router";
import { WifiOff } from "lucide-react-native";
import { SearchBar } from "@/components/SearchBar";
import { CONTENT_MAX_WIDTH } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { CategoryStrip } from "@/components/customer/CategoryTiles";
import { FiltersSheet, type FilterValues } from "@/components/customer/FiltersSheet";
import { applyExploreFilters, exploreSections } from "@/components/customer/exploreFilters";
import { useCarriers } from "@/components/customer/useCarriers";
import {
  CompareCard,
  FirstQuoteCard,
  HomeGreeting,
  HomePolicies,
  InProgressSection,
  QuickActions,
} from "@/components/customer/HomeSections";
import { usePolicies } from "@/hooks/usePolicies";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { PaymentsApi, ProposalsApi, type ProposalSummary } from "@/api/client";
import { PriorityFeed, usePriorityItems } from "@/components/customer/PriorityFeed";
import { useSession } from "@/store/session";
import { Preferences } from "@/store/preferences";
import { useTranslation } from "@/i18n";
import { homeLayout, homePolicies, inProgressItems, inProgressSeeAll } from "@/lib/homeFeed";
import { payableApplications } from "@/lib/paymentRouting";
import { colors, radius, space, type } from "@/theme/tokens";

const NO_FILTERS: FilterValues = { cat: [], prov: [], sort: ["best"] };

/**
 * Customer Home (owner-approved layout, 2026-09-29): header, one-line
 * greeting, search + filter, Needs your attention, Compare card, categories,
 * Your policies, In progress, Quick actions. Feed logic: src/lib/homeFeed.ts.
 */
export default function CustomerHome() {
  const { t, language } = useTranslation();
  const user = useSession((s) => s.bootstrap?.user);
  const offline = useSession((s) => s.offline);
  const [query, setQuery] = useState("");
  const [refreshing, setRefreshing] = useState(false);

  const policies = usePolicies();
  const quotes = useLoad(() => CustomerApi.quotes());
  const claims = useLoad(() => CustomerApi.claims());
  // Bell badge. Titles/bodies come back in the app language: refetch when it changes.
  const notifications = useLoad(() => CustomerApi.notifications(), [language]);
  // HOME-002/003: server payment + KYC state feed the priority block.
  const payments = useLoad(() => PaymentsApi.list());
  const kyc = useLoad(() => CustomerApi.kyc());
  // Applications awaiting payment ("Pay now"). Optional: a failure never blocks Home.
  const proposals = useLoad(() => ProposalsApi.list().then((x) => x.items).catch((): ProposalSummary[] => []));

  // Brand-new customers are offered the short profile/KYC step once.
  useEffect(() => {
    void Preferences.takePendingOnboarding().then((pending) => {
      if (pending) router.push({ pathname: "/onboarding/kyc", params: { first: "1" } });
    });
  }, []);

  // Unread badge stays fresh when coming back from the inbox.
  const reloadNotifications = notifications.reload;
  const reloadPayments = payments.reload;
  const reloadProposals = proposals.reload;
  useFocusEffect(
    useCallback(() => {
      void reloadNotifications();
      void reloadPayments();
      void reloadProposals();
    }, [reloadNotifications, reloadPayments, reloadProposals]),
  );

  const refresh = async () => {
    setRefreshing(true);
    await Promise.all([
      policies.reload(),
      quotes.reload(),
      claims.reload(),
      notifications.reload(),
      payments.reload(),
      kyc.reload(),
      proposals.reload(),
    ]);
    setRefreshing(false);
  };

  const shown = homePolicies(policies.policies);
  const payable = payableApplications(proposals.data, payments.data?.items);
  const feed = inProgressItems({ quotes: quotes.data, claims: claims.data, policies: policies.policies, applications: payable });
  const quotesFailed = !!quotes.error && !quotes.data;
  const claimsFailed = !!claims.error && !claims.data;
  const layout = homeLayout({
    policiesReady: !policies.loading || policies.policies.length > 0,
    quotesReady: !quotes.loading || quotes.data !== undefined,
    claimsReady: !claims.loading || claims.data !== undefined,
    policiesError: !!policies.error && !policies.policies.length,
    activityError: quotesFailed || claimsFailed,
    policyCount: shown.length,
    inProgressCount: feed.total,
  });
  const retryActivity = () => {
    if (quotesFailed) void quotes.reload();
    if (claimsFailed) void claims.reload();
    if (policies.error) void policies.reload();
  };

  const unread = (notifications.data ?? []).filter((n) => !n.read).length;
  const priority = usePriorityItems({
    payments: payments.data?.items ?? [],
    claims: claims.data ?? [],
    policies: policies.policies,
    kyc: kyc.data,
    applications: payable,
  });
  const firstName = user?.full_name?.trim().split(/\s+/)[0];
  // A typed query runs the live global search (GET /search + marketplace);
  // an empty submit opens the marketplace.
  const search = () =>
    query.trim().length >= 2
      ? router.push({ pathname: "/search", params: { q: query.trim() } })
      : router.push("/(customer)/(tabs)/explore");
  const [sheet, setSheet] = useState(false);
  const insurers = useCarriers();
  const filterSections = exploreSections(insurers, t);
  const applyFilters = (f: FilterValues) =>
    router.push({
      pathname: "/(customer)/(tabs)/explore",
      params: { cat: (f.cat ?? []).join(","), prov: (f.prov ?? []).join(","), sort: f.sort?.[0] ?? "best", ...(query.trim() ? { q: query.trim() } : {}) },
    });

  return (
    <SafeAreaView edges={["top"]} style={styles.safe}>
      <ScrollView
        contentContainerStyle={styles.content}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void refresh()} />}
      >
        <BrandHeader
          back={false}
          badge={unread || undefined}
          onRight={() => router.push("/notifications")}
        />

        {offline ? (
          <View style={styles.offline} accessibilityRole="alert">
            <WifiOff size={18} color={colors.warningText} />
            <Text style={styles.offlineText}>{t("homeOfflineNotice")}</Text>
          </View>
        ) : null}

        <HomeGreeting name={firstName} />

        <SearchBar
          value={query}
          onChangeText={setQuery}
          onSubmit={search}
          label={t("searchLabel")}
          placeholder={t("homeSearchPlaceholder")}
          clearLabel={t("clearSearch")}
          onFilter={() => setSheet(true)}
          filterLabel={t("filtersTitle")}
          inset
        />
        <FiltersSheet
          visible={sheet}
          onClose={() => setSheet(false)}
          sections={filterSections}
          value={NO_FILTERS}
          onApply={applyFilters}
          count={(f) => applyExploreFilters(insurers, f).length}
        />

        {/* HOME-003: urgent exceptions above routine content. */}
        <PriorityFeed items={priority} />

        <CompareCard />

        <CategoryStrip
          ids={["motor", "health", "travel", "home", "more"]}
          onPress={(c) =>
            c.id === "more"
              ? router.push("/(customer)/(tabs)/explore")
              : router.push({ pathname: "/quote/product", params: { product: c.id } })
          }
        />

        {layout.firstQuote ? (
          <FirstQuoteCard />
        ) : (
          <>
            <HomePolicies state={layout.policies} policies={shown} onRetry={() => void policies.reload()} />
            <InProgressSection
              state={layout.inProgress}
              items={feed.items}
              seeAll={inProgressSeeAll(feed.kinds)}
              error={quotesFailed || claimsFailed}
              onRetry={retryActivity}
            />
          </>
        )}

        <QuickActions />
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.neutral50 },
  content: { paddingHorizontal: space.x5, paddingBottom: space.x16, gap: space.x5, width: "100%", maxWidth: CONTENT_MAX_WIDTH, alignSelf: "center" },
  offline: {
    flexDirection: "row",
    gap: space.x2,
    alignItems: "center",
    backgroundColor: colors.warningSoft,
    borderRadius: radius.control,
    padding: space.x3,
  },
  offlineText: { ...type.meta, color: colors.warningText, flex: 1 },
});
