import React, { ReactNode, useCallback, useEffect, useState } from "react";
import {
  ActivityIndicator,
  Linking,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { router, useFocusEffect } from "expo-router";
import {
  ArrowRight,
  Bell,
  ChevronRight,
  CircleHelp,
  Clock3,
  FileText,
  LifeBuoy,
  LucideIcon,
  Mail,
  MessageCircle,
  Phone,
  RefreshCw,
  ShieldCheck,
  ShieldAlert,
  WifiOff,
} from "lucide-react-native";
import { SearchBar } from "@/components/SearchBar";
import { CONTENT_MAX_WIDTH, ripple, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, IconTile, SectionHeading } from "@/components/design";
import { BrandArt } from "@/components/design/BrandArt";
import { CategoryStrip } from "@/components/customer/CategoryTiles";
import { PolicyListCard } from "@/components/policies/PolicyListCard";
import { FiltersSheet, type FilterValues } from "@/components/customer/FiltersSheet";
import { applyExploreFilters, exploreSections } from "@/components/customer/exploreFilters";
import { useCarriers } from "@/components/customer/useCarriers";
import { CATEGORIES } from "@/components/customer/categories";
import { useColumns } from "@/components/responsive";
import { usePolicies } from "@/hooks/usePolicies";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { SupportContactsApi } from "@/api/client";
import { useSession } from "@/store/session";
import { Preferences } from "@/store/preferences";
import { useTranslation } from "@/i18n";
import { claimStatusKey, claimTone, isActiveClaim } from "@/lib/claimStatus";
import { daysUntil, isRenewalDue } from "@/lib/customerLogic";
import { HeritagePattern } from "@/components/HeritagePattern";
import { colors, radius, space, type } from "@/theme/tokens";

const NO_FILTERS: FilterValues = { cat: [], prov: [], sort: ["best"] };
const OPEN_QUOTE = /^(DRAFT|QUOTING|RATED|OFFERED|REFERRED|PENDING)/;
/** i18n key for the time-of-day greeting. */
const greetingKey = (h = new Date().getHours()): "greetingMorning" | "greetingAfternoon" | "greetingEvening" => (h < 12 ? "greetingMorning" : h < 18 ? "greetingAfternoon" : "greetingEvening");

export default function CustomerHome() {
  const { t, td, date } = useTranslation();
  const user = useSession((s) => s.bootstrap?.user);
  const offline = useSession((s) => s.offline);
  const [query, setQuery] = useState("");
  const [refreshing, setRefreshing] = useState(false);
  const grid = useColumns({ max: 4, minItem: 72, gap: space.x2 });

  const policies = usePolicies();
  const quotes = useLoad(() => CustomerApi.quotes());
  const claims = useLoad(() => CustomerApi.claims());
  const notifications = useLoad(() => CustomerApi.notifications());
  const contacts = useLoad(() => SupportContactsApi.get());

  // Brand-new customers are offered the short profile/KYC step once.
  useEffect(() => {
    void Preferences.takePendingOnboarding().then((pending) => {
      if (pending) router.push({ pathname: "/onboarding/kyc", params: { first: "1" } });
    });
  }, []);

  // Unread badge stays fresh when coming back from the inbox.
  const reloadNotifications = notifications.reload;
  useFocusEffect(
    useCallback(() => {
      void reloadNotifications();
    }, [reloadNotifications]),
  );

  const refresh = async () => {
    setRefreshing(true);
    await Promise.all([
      policies.reload(),
      quotes.reload(),
      claims.reload(),
      notifications.reload(),
    ]);
    setRefreshing(false);
  };

  const active = policies.policies.filter((p) => p.status === "ACTIVE");
  const renewals = policies.policies.filter((p) => isRenewalDue(p));
  const openQuotes = (quotes.data ?? []).filter(
    (q) => q.can_resume || OPEN_QUOTE.test((q.status ?? "").toUpperCase()),
  );
  const openClaims = (claims.data ?? []).filter((c) => isActiveClaim(c.status));
  const unread = (notifications.data ?? []).filter((n) => !n.read).length;
  const firstName = user?.full_name?.split(" ")[0];
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

        <View style={styles.heroBlock}>
          <BrandArt name="map_gold_network" width={150} opacity={0.55} style={styles.heroArt} />
          <Text style={styles.greeting}>{t("homeGreetingTime", { part: t(greetingKey()) })}</Text>
          <Text style={styles.greetingName}>{firstName ? `${firstName} \u{1F44B}` : t("homeGreeting")}</Text>
          <Text style={styles.heroTagline}>{t("homeTagline")}</Text>
        </View>

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

        <CategoryStrip
          ids={["motor", "health", "travel", "home", "more"]}
          onPress={(c) =>
            c.id === "more"
              ? router.push("/(customer)/(tabs)/explore")
              : router.push({ pathname: "/quote/product", params: { product: c.id } })
          }
        />

        {/* Primary action: same size as the policy cards below (PolicyListCard metrics). */}
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={`${t("compareInsurance")}. ${t("compareInsuranceBody")}`}
          onPress={() => router.push("/quote/product")}
          android_ripple={ripple(true)}
          style={({ pressed }) => [styles.cta, pressed && styles.pressed]}
        >
          <HeritagePattern variant="ndop" opacity={0.08} />
          <View style={styles.ctaThumb}>
            <ShieldCheck size={28} color={colors.gold500} />
          </View>
          <View style={styles.ctaCopy}>
            <Text style={styles.ctaTitle} numberOfLines={2}>{t("homeCtaTitle")}</Text>
            <Text style={styles.ctaBody} numberOfLines={2}>{t("homeCtaBody")}</Text>
            <View style={styles.ctaButton}>
              <Text style={styles.ctaButtonText}>{t("propGetQuote")}</Text>
            </View>
          </View>
          <View style={styles.ctaChevron}>
            <ArrowRight size={18} color={colors.white} />
          </View>
        </Pressable>

        <SectionHeading title={t("myPoliciesTitle")} action={t("seeAll")} onAction={() => router.push("/(customer)/(tabs)/policies")} />
        {policies.loading && !policies.policies.length ? (
          <View style={styles.inline} accessibilityRole="progressbar" accessibilityLabel={t("loading")}>
            <ActivityIndicator color={colors.blue600} />
          </View>
        ) : active.length ? (
          active.slice(0, 2).map((p) => (
            <PolicyListCard key={p.id} policy={p} onPress={() => router.push({ pathname: "/policy/[id]", params: { id: p.id } })} />
          ))
        ) : (
          <Banner icon={FileText} tint="blue" title={t("homeNoPolicies")} body={t("compareInsuranceBody")} onPress={() => router.push("/quote/product")} />
        )}

        <SectionHeading title={t("homeQuickActions")} />
        <View style={styles.quickRow}>
          <IconTile icon={FileText} label={t("pdFileClaim")} onPress={() => router.push("/claim/new")} />
          <IconTile icon={RefreshCw} label={t("pdRenew")} onPress={() => (renewals[0] ? router.push({ pathname: "/policy/[id]/renew", params: { id: renewals[0].id } }) : router.push("/(customer)/(tabs)/policies"))} />
          <IconTile icon={LifeBuoy} label={t("homeGetSupport")} onPress={() => router.push("/support")} />
        </View>

        <Text accessibilityRole="header" style={styles.section}>{t("protect")}</Text>
        <View style={grid.row}>
          {CATEGORIES.map((c) => {
            const Icon = c.icon;
            return (
              <Pressable
                key={c.id}
                accessibilityRole="button"
                accessibilityLabel={`${t(c.label)}. ${t(c.caption)}`}
                onPress={() =>
                  c.id === "more"
                    ? router.push("/(customer)/(tabs)/explore")
                    : router.push({ pathname: "/quote/product", params: { product: c.id } })
                }
                android_ripple={ripple()}
                style={({ pressed }) => [styles.category, grid.item, pressed && styles.pressed]}
              >
                <Icon size={28} color={colors.navy800} />
                <Text style={styles.categoryLabel} numberOfLines={2}>{t(c.label)}</Text>
              </Pressable>
            );
          })}
        </View>

        <HomeCard
          icon={FileText}
          title={t("homeActivePolicies")}
          count={policies.loading ? undefined : active.length}
          loading={policies.loading}
          error={!!policies.error}
          onRetry={() => void policies.reload()}
          empty={t("homeNoPolicies")}
          emptyAction={t("compareInsurance")}
          onEmptyAction={() => router.push("/quote/product")}
          onSeeAll={() => router.push("/(customer)/(tabs)/policies")}
          seeAllLabel={t("seeAll")}
          retryLabel={t("retry")}
          errorLabel={t("loadErrorShort")}
          loadingLabel={t("loading")}
        >
          {active.slice(0, 2).map((p) => (
            <Row
              key={p.id}
              title={p.policy_number}
              meta={t("coverEnds", { date: date(p.coverage_ends_at) })}
              chip={<StatusChip label={td(`policyStatus_${p.status}`, p.status)} tone="success" />}
              onPress={() => router.push({ pathname: "/policy/[id]", params: { id: p.id } })}
            />
          ))}
        </HomeCard>

        <HomeCard
          icon={RefreshCw}
          title={t("homeRenewals")}
          count={policies.loading ? undefined : renewals.length}
          loading={policies.loading}
          error={!!policies.error}
          onRetry={() => void policies.reload()}
          empty={t("homeNoRenewals")}
          retryLabel={t("retry")}
          errorLabel={t("loadErrorShort")}
          loadingLabel={t("loading")}
        >
          {renewals.map((p) => (
            <Row
              key={p.id}
              title={p.policy_number}
              meta={t("renewalDueIn", { days: daysUntil(p.coverage_ends_at) ?? 0 })}
              chip={<StatusChip label={t("renew")} tone="warning" />}
              onPress={() => router.push({ pathname: "/policy/[id]/renew", params: { id: p.id } })}
            />
          ))}
        </HomeCard>

        <HomeCard
          icon={Clock3}
          title={t("homeQuotesInProgress")}
          count={quotes.loading && !quotes.data ? undefined : openQuotes.length}
          loading={quotes.loading && !quotes.data}
          error={!!quotes.error && !quotes.data}
          onRetry={() => void quotes.reload()}
          empty={t("homeNoQuotes")}
          onSeeAll={() => router.push("/quotes")}
          seeAllLabel={t("seeAll")}
          retryLabel={t("retry")}
          errorLabel={t("loadErrorShort")}
          loadingLabel={t("loading")}
        >
          {openQuotes.slice(0, 3).map((q) => (
            <Row
              key={q.id}
              title={q.product_name ?? q.vehicle_label ?? t("quote")}
              meta={
                q.offer_count
                  ? t("offersCount", { count: q.offer_count })
                  : q.created_at
                    ? date(q.created_at)
                    : ""
              }
              chip={<StatusChip label={td(`quoteStatus_${q.status}`, q.status)} tone="info" />}
              onPress={() => router.push({ pathname: "/quotes/[id]", params: { id: q.id } })}
            />
          ))}
        </HomeCard>

        <HomeCard
          icon={ShieldAlert}
          title={t("homeActiveClaims")}
          count={claims.loading && !claims.data ? undefined : openClaims.length}
          loading={claims.loading && !claims.data}
          error={!!claims.error && !claims.data}
          onRetry={() => void claims.reload()}
          empty={t("homeNoClaims")}
          onSeeAll={() => router.push("/(customer)/(tabs)/claims")}
          seeAllLabel={t("seeAll")}
          retryLabel={t("retry")}
          errorLabel={t("loadErrorShort")}
          loadingLabel={t("loading")}
        >
          {openClaims.slice(0, 3).map((c) => (
            <Row
              key={c.id}
              title={c.claim_number}
              meta={date(c.incident_at)}
              chip={<StatusChip label={td(claimStatusKey(c.status), c.status)} tone={claimTone(c.status)} />}
              onPress={() => router.push({ pathname: "/claim/[id]", params: { id: c.id } })}
            />
          ))}
        </HomeCard>

        <HomeCard
          icon={Bell}
          title={t("notifications")}
          count={notifications.loading && !notifications.data ? undefined : unread}
          countLabel={t("unread")}
          loading={notifications.loading && !notifications.data}
          error={!!notifications.error && !notifications.data}
          onRetry={() => void notifications.reload()}
          empty={t("homeNoUnread")}
          onSeeAll={() => router.push("/notifications")}
          seeAllLabel={t("seeAll")}
          retryLabel={t("retry")}
          errorLabel={t("loadErrorShort")}
          loadingLabel={t("loading")}
        >
          {(notifications.data ?? [])
            .filter((n) => !n.read)
            .slice(0, 2)
            .map((n) => (
              <Row
                key={n.id}
                title={n.title}
                meta={n.body}
                onPress={() => router.push({ pathname: "/notifications/[id]", params: { id: n.id } })}
              />
            ))}
        </HomeCard>

        <View style={styles.card}>
          <CardHeader icon={LifeBuoy} title={t("homeHelp")} />
          <Text style={styles.meta}>{t("homeHelpBody")}</Text>
          <Row title={t("faqTitle")} icon={CircleHelp} onPress={() => router.push("/support/faq")} />
          <Row title={t("supportNewTicket")} icon={MessageCircle} onPress={() => router.push("/support/new")} />
          {contacts.data?.phone ? (
            <Row
              title={contacts.data.phone}
              icon={Phone}
              onPress={() => void Linking.openURL(`tel:${contacts.data?.phone}`)}
            />
          ) : null}
          {contacts.data?.whatsapp_url ? (
            <Row
              title={t("whatsapp")}
              icon={MessageCircle}
              onPress={() => void Linking.openURL(contacts.data!.whatsapp_url!)}
            />
          ) : null}
          {contacts.data?.email ? (
            <Row
              title={contacts.data.email}
              icon={Mail}
              onPress={() => void Linking.openURL(`mailto:${contacts.data?.email}`)}
            />
          ) : null}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

function CardHeader({ icon: Icon, title, count, countLabel }: { icon: LucideIcon; title: string; count?: number; countLabel?: string }) {
  return (
    <View style={styles.cardHeader}>
      <Icon size={22} color={colors.navy900} />
      <Text accessibilityRole="header" style={styles.cardTitle}>{title}</Text>
      {count !== undefined ? (
        <View style={styles.count} accessibilityLabel={`${count} ${countLabel ?? ""}`.trim()}>
          <Text style={styles.countText}>{count}</Text>
        </View>
      ) : null}
    </View>
  );
}

function HomeCard(props: {
  icon: LucideIcon;
  title: string;
  count?: number;
  countLabel?: string;
  loading: boolean;
  error: boolean;
  onRetry: () => void;
  empty: string;
  emptyAction?: string;
  onEmptyAction?: () => void;
  onSeeAll?: () => void;
  seeAllLabel?: string;
  retryLabel: string;
  errorLabel: string;
  loadingLabel: string;
  children: ReactNode;
}) {
  const items = React.Children.toArray(props.children);
  return (
    <View style={styles.card}>
      <CardHeader icon={props.icon} title={props.title} count={props.count} countLabel={props.countLabel} />
      {props.loading ? (
        <View style={styles.inline} accessibilityRole="progressbar" accessibilityLabel={props.loadingLabel}>
          <ActivityIndicator color={colors.blue600} />
        </View>
      ) : props.error ? (
        <View style={styles.inlineRow}>
          <Text style={[styles.meta, styles.flex]} accessibilityRole="alert">{props.errorLabel}</Text>
          <Pressable accessibilityRole="button" onPress={props.onRetry} hitSlop={8} style={styles.link}>
            <Text style={styles.linkText}>{props.retryLabel}</Text>
          </Pressable>
        </View>
      ) : items.length ? (
        <>
          {items}
          {props.onSeeAll ? (
            <Pressable accessibilityRole="button" onPress={props.onSeeAll} style={styles.link} hitSlop={8}>
              <Text style={styles.linkText}>{props.seeAllLabel}</Text>
            </Pressable>
          ) : null}
        </>
      ) : (
        <View style={styles.inlineRow}>
          <Text style={[styles.meta, styles.flex]}>{props.empty}</Text>
          {props.emptyAction ? (
            <Pressable accessibilityRole="button" onPress={props.onEmptyAction} style={styles.link} hitSlop={8}>
              <Text style={styles.linkText}>{props.emptyAction}</Text>
            </Pressable>
          ) : null}
        </View>
      )}
    </View>
  );
}

function Row({
  title,
  meta,
  chip,
  icon: Icon,
  onPress,
}: {
  title: string;
  meta?: string;
  chip?: ReactNode;
  icon?: LucideIcon;
  onPress: () => void;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={[title, meta].filter(Boolean).join(". ")}
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
    >
      {Icon ? <Icon size={19} color={colors.navy800} /> : null}
      <View style={styles.flex}>
        <Text style={styles.rowTitle} numberOfLines={2}>{title}</Text>
        {meta ? <Text style={styles.meta} numberOfLines={2}>{meta}</Text> : null}
        {chip ? <View style={styles.rowChip}>{chip}</View> : null}
      </View>
      <ChevronRight size={18} color={colors.neutral500} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  rowChip: { flexDirection: "row", marginTop: 4 },
  heroBlock: { gap: 2, marginTop: -space.x2, minHeight: 130, justifyContent: "center" },
  heroArt: { position: "absolute", right: -space.x3, top: -space.x2 },
  greeting: { fontFamily: "Inter_400Regular", fontSize: 26, lineHeight: 32, color: colors.navy950 },
  greetingName: { fontFamily: "Inter_700Bold", fontSize: 34, lineHeight: 40, color: colors.navy950, letterSpacing: -0.5 },
  heroTagline: { ...type.bodyLarge, color: colors.navy800, marginTop: 4 },
  // Same metrics as PolicyListCard (the other cards on this screen): 12dp padding,
  // 56dp tile with a 28dp icon, 17dp title, 13/12dp meta, 36dp chevron.
  cta: {
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    borderRadius: radius.feature,
    // Design promo navy (sampled #00255C), darker than navy900.
    backgroundColor: "#00255C",
    borderWidth: 1,
    borderColor: "#00255C",
    overflow: "hidden",
    padding: space.x3,
  },
  ctaThumb: { width: 56, height: 56, borderRadius: radius.card, backgroundColor: "rgba(255,255,255,0.1)", alignItems: "center", justifyContent: "center" },
  ctaCopy: { flex: 1, gap: 3, minWidth: 0 },
  ctaTitle: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.white },
  ctaBody: { ...type.meta, color: colors.blue100 },
  ctaButton: { alignSelf: "flex-start", backgroundColor: colors.gold500, borderRadius: radius.pill, paddingHorizontal: space.x3, paddingVertical: 4, marginTop: 4 },
  ctaButtonText: { ...type.meta, fontSize: 12, lineHeight: 16, fontFamily: "Inter_600SemiBold", color: colors.navy950 },
  ctaChevron: { width: 36, height: 36, borderRadius: 18, borderWidth: 1, borderColor: "rgba(255,255,255,0.3)", alignItems: "center", justifyContent: "center" },
  quickRow: { flexDirection: "row", gap: space.x3 },
  section: { ...type.cardTitle, color: colors.navy950, marginBottom: -space.x2 },
  category: {
    minHeight: 92,
    overflow: "hidden",
    alignItems: "center",
    justifyContent: "center",
    gap: space.x2,
    paddingVertical: space.x3,
    paddingHorizontal: space.x1,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
  },
  categoryLabel: { ...type.caption, color: colors.navy950, textAlign: "center" },
  safe: { flex: 1, backgroundColor: colors.neutral50 },
  content: { paddingHorizontal: space.x5, paddingBottom: space.x16, gap: space.x5, width: "100%", maxWidth: CONTENT_MAX_WIDTH, alignSelf: "center" },
  flex: { flex: 1 },
  pressed: { opacity: 0.82 },
  offline: {
    flexDirection: "row",
    gap: space.x2,
    alignItems: "center",
    backgroundColor: colors.warningSoft,
    borderRadius: radius.control,
    padding: space.x3,
  },
  offlineText: { ...type.meta, color: colors.warningText, flex: 1 },
  card: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
    padding: space.x4,
    gap: space.x2,
  },
  // Icons sit directly on the card background (no tinted box); the row keeps
  // the old 36dp height so headers align with the count pill.
  cardHeader: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 36 },
  cardTitle: { ...type.label, fontSize: 16, color: colors.navy950, flex: 1 },
  count: {
    minWidth: 28,
    height: 28,
    paddingHorizontal: space.x2,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.neutral300,
    alignItems: "center",
    justifyContent: "center",
  },
  countText: { ...type.label, color: colors.navy950 },
  inline: { paddingVertical: space.x3, alignItems: "center" },
  inlineRow: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 44 },
  row: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    borderTopWidth: 1,
    borderTopColor: colors.neutral100,
    paddingVertical: space.x2,
  },
  rowTitle: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  link: { minHeight: 44, justifyContent: "center", alignSelf: "flex-start" },
  linkText: { ...type.label, color: colors.blue600 },
});
