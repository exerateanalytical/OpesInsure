import React, { useMemo } from "react";
import { Pressable, RefreshControl, SectionList, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ArrowRight, CheckCircle2, Clock3, FilePlus2, FileText, LucideIcon, Siren } from "lucide-react-native";
import { Button, Card, ripple, Screen, SectionTitle } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { ClaimCard } from "@/components/claims/ClaimCard";
import { claimPolicy, insuredLabel, policyLine, policyTitle, productCategory, providerName } from "@/components/claims/claimProduct";
import { byDate, FilterToolbar, periodMatcher, periodSection, runList, sortSection, useListFilters, type FilterSection, type FilterValues, type Matchers, type Sorters } from "@/components/filters";
import { carrierMark, useCarriers } from "@/components/customer/useCarriers";
import { CATEGORIES } from "@/components/customer/categories";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { CustomerApi } from "@/api/customer";
import type { Claim } from "@/api/client";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { ClaimSegment, claimSegment, claimStatusKey, isActiveClaim } from "@/lib/claimStatus";
import { colors, radius, space, type } from "@/theme/tokens";

const SEGMENTS: { key: ClaimSegment; label: CopyKey; icon: LucideIcon }[] = [
  { key: "all", label: "claimsAll", icon: FileText },
  { key: "progress", label: "claimsInProgress", icon: Clock3 },
  { key: "completed", label: "claimsCompleted", icon: CheckCircle2 },
];

/** My Claims (design 29 / 11): header with New Claim, search + filter sheet (status, line, insurer, period, sort), claim cards with the progress rail. */
export default function Claims() {
  const { t, td } = useTranslation();
  const q = useLoad(() => CustomerApi.claims());
  const { policies } = usePolicies();
  const claims = q.data ?? [];
  const byPolicy = useMemo(() => new Map(policies.map((p) => [p.id, p])), [policies]);
  const carriers = useCarriers();
  const meta = (c: Claim) => {
    const p = byPolicy.get(c.policy_id) ?? claimPolicy(c);
    const m = carrierMark(carriers, p?.carrier_id ?? p?.carrier?.id, { name: providerName(p) });
    return { p, provider: { id: p?.carrier_id ?? m.name ?? "", ...m }, line: productCategory(policyTitle(p, ""), policyLine(p))?.id ?? "" };
  };
  // Shared list standard (FLT-001..006): product line, insurer, incident period, sort.
  const filterSections = useMemo<FilterSection[]>(() => {
    const rows = claims.map(meta);
    const provs = new Map<string, (typeof rows)[number]["provider"]>();
    rows.forEach((r) => r.provider.id && !provs.has(r.provider.id) && provs.set(r.provider.id, r.provider));
    return [
      // Former inline segmented tabs (All / In progress / Completed): now the status section of the sheet.
      { key: "segment", title: t("filterStatus"), options: SEGMENTS.filter((g) => g.key !== "all").map((g) => ({ value: g.key, label: t(g.label, { count: claims.filter((c) => claimSegment(c.status) === g.key).length }), icon: g.icon })) },
      { key: "line", title: t("filterCategory"), subtitle: t("filterCategoryBody"), options: CATEGORIES.filter((c) => rows.some((r) => r.line === c.id)).map((c) => ({ value: c.id, label: t(c.label), icon: c.icon })) },
      { key: "provider", title: t("filterProvider"), subtitle: t("filterProviderBody"), options: [...provs.values()].map((m) => ({ value: m.id, label: m.name ?? t("licensedCarrier"), logoUrl: m.logoUrl, initials: m.initials })) },
      periodSection(t, "incident", t("fltIncidentDate")),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "oldest", label: t("fltSortOldest") },
      ]),
    ];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [claims, byPolicy, carriers, t]);
  const f = useListFilters("customer.claims", filterSections);
  const segSel = f.values.segment ?? [];
  const segment: ClaimSegment = segSel.length === 1 ? (segSel[0] as ClaimSegment) : "all";
  const matchers: Matchers<Claim> = {
    segment: (c, v) => claimSegment(c.status) === v,
    line: (c, v) => meta(c).line === v,
    provider: (c, v) => meta(c).provider.id === v,
    incident: periodMatcher((c) => c.incident_at ?? c.created_at),
  };
  const sorters: Sorters<Claim> = { recent: byDate((c) => c.incident_at ?? c.created_at), oldest: byDate((c) => c.incident_at ?? c.created_at, "asc") };
  const haystack = (c: Claim) => {
    const p = byPolicy.get(c.policy_id) ?? claimPolicy(c);
    return [c.claim_number, c.incident_location, c.description, p?.policy_number, policyTitle(p, ""), insuredLabel(p), providerName(p), td(claimStatusKey(c.status), c.status)];
  };
  const run = (v: FilterValues) => runList(claims, { values: v, text: f.query, matchers, haystack, sorters });
  const visible = run(f.values);
  const open = visible.filter((c) => isActiveClaim(c.status));
  const past = visible.filter((c) => !isActiveClaim(c.status));

  const row = (claim: Claim) => (
    <ClaimCard
      key={claim.id}
      claim={claim}
      policy={byPolicy.get(claim.policy_id)}
      onPress={() => router.push({ pathname: "/claim/[id]", params: { id: claim.id } })}
    />
  );
  const emergency = (
    <Card>
      <View style={styles.row}>
        <Siren size={22} color={colors.dangerText} />
        <Text style={[styles.title, styles.flex]}>{t("emergencyTitle")}</Text>
      </View>
      <Text style={styles.body}>{t("emergencyBody")}</Text>
      <Button label={t("emergencyAssistance")} variant="danger" onPress={() => router.push("/claim/emergency")} />
    </Card>
  );
  // Primary action (design: "File a New Claim" banner under the title).
  const newClaim = (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${t("newClaim")}. ${t("claimsFileNewBody")}`}
      onPress={() => router.push("/claim/new")}
      android_ripple={ripple(true)}
      style={({ pressed }) => [styles.newClaim, pressed && styles.pressed]}
    >
      <View style={styles.newClaimIcon}>
        <FilePlus2 size={30} color={colors.white} />
      </View>
      <View style={styles.flex}>
        <Text style={styles.newClaimText}>{t("claimsFileNew")}</Text>
        <Text style={styles.newClaimBody}>{t("claimsFileNewBody")}</Text>
      </View>
      <View style={styles.newClaimArrow}>
        <ArrowRight size={22} color={colors.white} />
      </View>
    </Pressable>
  );
  const header = (
    <View style={styles.header}>
      <BrandHeader title={t("myClaims")} subtitle={t("myClaimsSubtitle")} back={false} />
      {newClaim}
      <FilterToolbar
        filters={f}
        sections={filterSections}
        filled
        placeholder={t("claimsSearchPlaceholder")}
        subtitle={t("filtersClaimsSubtitle")}
        count={(v) => run(v).length}
        resultCount={f.active ? visible.length : undefined}
      />
    </View>
  );

  if (!visible.length)
    return (
      <Screen>
        {header}
        {q.loading && !q.data ? (
          <LoadingState label={t("claimsLoading")} />
        ) : q.error && !q.data ? (
          <ErrorState error={q.error} onRetry={() => void q.reload()} />
        ) : claims.length ? (
          <EmptyState title={t("claimsNoMatch")} message={t("claimsNoMatchBody")} action={t("fltClearAll")} onPress={f.clear} />
        ) : (
          <EmptyState title={t("claimsEmpty")} message={t("claimsEmptyBody")} />
        )}
        {emergency}
      </Screen>
    );
  const sections =
    segment === "all"
      ? [
          { key: "open", title: t("claimsOpen"), data: open },
          { key: "past", title: t("claimsPast"), data: past },
        ].filter((x) => x.data.length)
      : [{ key: segment, title: "", data: visible }];
  // Virtualized (SectionList): long claim histories stay smooth.
  return (
    <Screen scroll={false}>
      <SectionList
        sections={sections}
        keyExtractor={(c) => c.id}
        stickySectionHeadersEnabled={false}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.content}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={q.loading} onRefresh={() => void q.reload()} />}
        ListHeaderComponent={header}
        renderSectionHeader={({ section }) => (section.title ? <SectionTitle title={section.title} /> : null)}
        renderItem={({ item }) => row(item)}
        ItemSeparatorComponent={Separator}
        SectionSeparatorComponent={Separator}
        ListFooterComponent={<View style={styles.footer}>{emergency}</View>}
      />
    </Screen>
  );
}
const Separator = () => <View style={styles.sep} />;
const styles = StyleSheet.create({
  content: { paddingBottom: space.x16 },
  header: { gap: space.x4, marginBottom: space.x4 },
  sep: { height: space.x3 },
  footer: { marginTop: space.x6 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  newClaim: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 96, padding: space.x4, borderRadius: radius.feature, backgroundColor: colors.blue700, overflow: "hidden" },
  newClaimIcon: { width: 52, height: 52, borderRadius: radius.card, backgroundColor: "rgba(255,255,255,0.16)", alignItems: "center", justifyContent: "center" },
  newClaimText: { ...type.cardTitle, fontSize: 19, lineHeight: 24, color: colors.white },
  newClaimBody: { ...type.meta, color: colors.blue50 },
  newClaimArrow: { width: 44, height: 44, borderRadius: 22, borderWidth: 1.5, borderColor: "rgba(255,255,255,0.6)", alignItems: "center", justifyContent: "center" },
});
