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
  Scale,
  ShieldAlert,
  WifiOff,
} from "lucide-react-native";
import { BrandMark } from "@/components/BrandMark";
import { SearchBar } from "@/components/SearchBar";
import { HeritageAccent, StatusChip } from "@/components/ui";
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
import { HeritagePattern, KenteBand } from "@/components/HeritagePattern";
import { colors, radius, space, type } from "@/theme/tokens";

const OPEN_QUOTE = /^(DRAFT|QUOTING|RATED|OFFERED|REFERRED|PENDING)/;

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
  const search = () =>
    router.push({ pathname: "/(customer)/(tabs)/explore", params: query.trim() ? { q: query.trim() } : {} });

  return (
    <SafeAreaView edges={["top"]} style={styles.safe}>
      <ScrollView
        contentContainerStyle={styles.content}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void refresh()} />}
      >
        <View style={styles.top}>
          <BrandMark size={34} />
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={unread ? t("notificationsUnread", { count: unread }) : t("notifications")}
            hitSlop={6}
            onPress={() => router.push("/notifications")}
            style={({ pressed }) => [styles.bell, pressed && styles.pressed]}
          >
            <Bell size={21} color={colors.navy950} />
            {unread ? (
              <View style={styles.badge}>
                <Text style={styles.badgeText}>{unread > 99 ? "99+" : unread}</Text>
              </View>
            ) : null}
          </Pressable>
        </View>

        {offline ? (
          <View style={styles.offline} accessibilityRole="alert">
            <WifiOff size={18} color={colors.warningText} />
            <Text style={styles.offlineText}>{t("homeOfflineNotice")}</Text>
          </View>
        ) : null}

        <View>
          <Text style={styles.greeting}>
            {firstName ? t("homeGreetingName", { name: firstName }) : t("homeGreeting")}
          </Text>
          <Text style={styles.title}>{t("homeTitle")}</Text>
          <HeritageAccent />
        </View>

        {/* Primary action: the largest control on the screen. */}
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={`${t("compareInsurance")}. ${t("compareInsuranceBody")}`}
          onPress={() => router.push("/quote/product")}
          style={({ pressed }) => [styles.cta, pressed && styles.pressed]}
        >
          <HeritagePattern variant="ndop" opacity={0.1} />
          <KenteBand height={5} style={styles.ctaBand} />
          <View style={styles.ctaIcon}>
            <Scale size={28} color={colors.navy950} />
          </View>
          <View style={styles.flex}>
            <Text style={styles.ctaTitle}>{t("compareInsurance")}</Text>
            <Text style={styles.ctaBody}>{t("compareInsuranceBody")}</Text>
          </View>
          <ChevronRight size={24} color={colors.white} />
        </Pressable>

        <SearchBar
          value={query}
          onChangeText={setQuery}
          onSubmit={search}
          label={t("searchLabel")}
          placeholder={t("searchPlaceholder")}
          clearLabel={t("clearSearch")}
        />

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
                style={({ pressed }) => [styles.category, grid.item, pressed && styles.pressed]}
              >
                <View style={styles.categoryIcon}>
                  <Icon size={22} color={colors.blue600} />
                </View>
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
      <View style={styles.cardIcon}>
        <Icon size={20} color={colors.blue600} />
      </View>
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
        <Text style={styles.rowTitle} numberOfLines={1}>{title}</Text>
        {meta ? <Text style={styles.meta} numberOfLines={2}>{meta}</Text> : null}
      </View>
      {chip}
      <ChevronRight size={18} color={colors.neutral500} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.neutral50 },
  content: { paddingHorizontal: space.x5, paddingBottom: space.x16, gap: space.x5 },
  flex: { flex: 1 },
  pressed: { opacity: 0.82 },
  top: { height: 56, flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  bell: {
    width: 44,
    height: 44,
    borderRadius: radius.control,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
  },
  badge: {
    position: "absolute",
    top: -4,
    right: -4,
    minWidth: 20,
    height: 20,
    paddingHorizontal: 5,
    borderRadius: 10,
    backgroundColor: colors.danger,
    alignItems: "center",
    justifyContent: "center",
  },
  badgeText: { ...type.caption, fontSize: 11, lineHeight: 14, color: colors.white },
  offline: {
    flexDirection: "row",
    gap: space.x2,
    alignItems: "center",
    backgroundColor: colors.warningSoft,
    borderRadius: radius.control,
    padding: space.x3,
  },
  offlineText: { ...type.meta, color: colors.warningText, flex: 1 },
  greeting: { ...type.label, color: colors.neutral600 },
  title: { ...type.pageTitle, color: colors.navy950, marginTop: 4 },
  cta: {
    minHeight: 96,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x4,
    padding: space.x5,
    borderRadius: radius.feature,
    backgroundColor: colors.navy950,
    overflow: "hidden",
  },
  ctaBand: { position: "absolute", left: 0, right: 0, bottom: 0 },
  ctaIcon: {
    width: 52,
    height: 52,
    borderRadius: radius.card,
    backgroundColor: colors.gold500,
    alignItems: "center",
    justifyContent: "center",
  },
  ctaTitle: { ...type.sectionTitle, color: colors.white },
  ctaBody: { ...type.meta, color: colors.gold100, marginTop: 2 },
  section: { ...type.cardTitle, color: colors.navy950, marginBottom: -space.x2 },
  category: {
    minHeight: 92,
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
  categoryIcon: {
    width: 42,
    height: 42,
    borderRadius: radius.control,
    backgroundColor: colors.gold50,
    alignItems: "center",
    justifyContent: "center",
  },
  categoryLabel: { ...type.caption, color: colors.navy950, textAlign: "center" },
  card: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
    padding: space.x4,
    gap: space.x2,
  },
  cardHeader: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  cardIcon: {
    width: 36,
    height: 36,
    borderRadius: radius.control,
    backgroundColor: colors.blue50,
    alignItems: "center",
    justifyContent: "center",
  },
  cardTitle: { ...type.label, fontSize: 16, color: colors.navy950, flex: 1 },
  count: {
    minWidth: 28,
    height: 28,
    paddingHorizontal: space.x2,
    borderRadius: 14,
    backgroundColor: colors.neutral100,
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
