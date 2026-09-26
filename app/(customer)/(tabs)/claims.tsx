import React, { useMemo, useState } from "react";
import { Pressable, RefreshControl, SectionList, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { CheckCircle2, Clock3, FileText, LucideIcon, Plus, Siren } from "lucide-react-native";
import { Button, Card, ripple, Screen, SectionTitle } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { SearchBar } from "@/components/SearchBar";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { ClaimCard } from "@/components/claims/ClaimCard";
import { claimPolicy, insuredLabel, policyTitle } from "@/components/claims/claimProduct";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { CustomerApi } from "@/api/customer";
import type { Claim } from "@/api/client";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { ClaimSegment, claimSegment, claimStatusKey, isActiveClaim } from "@/lib/claimStatus";
import { matchesQuery } from "@/lib/customerLogic";
import { colors, radius, space, type } from "@/theme/tokens";

const SEGMENTS: { key: ClaimSegment; label: CopyKey; icon: LucideIcon }[] = [
  { key: "all", label: "claimsAll", icon: FileText },
  { key: "progress", label: "claimsInProgress", icon: Clock3 },
  { key: "completed", label: "claimsCompleted", icon: CheckCircle2 },
];

/** My Claims (design 29 / 11): header with New Claim, segmented filter, search, claim cards with the progress rail. */
export default function Claims() {
  const { t, td } = useTranslation();
  const q = useLoad(() => CustomerApi.claims());
  const { policies } = usePolicies();
  const [segment, setSegment] = useState<ClaimSegment>("all");
  const [query, setQuery] = useState("");
  const claims = q.data ?? [];
  const byPolicy = useMemo(() => new Map(policies.map((p) => [p.id, p])), [policies]);
  const counts = {
    all: claims.length,
    progress: claims.filter((c) => claimSegment(c.status) === "progress").length,
    completed: claims.filter((c) => claimSegment(c.status) === "completed").length,
  };
  const visible = claims.filter((c) => {
    if (segment !== "all" && claimSegment(c.status) !== segment) return false;
    const p = byPolicy.get(c.policy_id) ?? claimPolicy(c);
    return matchesQuery(
      query,
      c.claim_number,
      c.incident_location,
      c.description,
      p?.policy_number,
      policyTitle(p, ""),
      insuredLabel(p),
      td(claimStatusKey(c.status), c.status),
    );
  });
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
  const newClaim = (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={t("newClaim")}
      onPress={() => router.push("/claim/new")}
      android_ripple={ripple()}
      style={({ pressed }) => [styles.newClaim, pressed && styles.pressed]}
    >
      <Plus size={20} color={colors.navy950} strokeWidth={2.5} />
      <Text style={styles.newClaimText}>{t("newClaim")}</Text>
    </Pressable>
  );
  const header = (
    <View style={styles.header}>
      <BrandHeader title={t("myClaims")} subtitle={t("myClaimsSubtitle")} back={false} titleRow={newClaim} />
      <View style={styles.segments} accessibilityRole="tablist">
        {SEGMENTS.map(({ key, label, icon: Icon }) => {
          const on = segment === key;
          return (
            <Pressable
              key={key}
              accessibilityRole="tab"
              accessibilityState={{ selected: on }}
              onPress={() => setSegment(key)}
              android_ripple={ripple(on)}
              style={({ pressed }) => [styles.segment, on && styles.segmentOn, pressed && styles.pressed]}
            >
              <Icon size={16} color={on ? colors.white : colors.navy900} />
              <Text style={[styles.segmentText, on && styles.segmentTextOn]} numberOfLines={1} maxFontSizeMultiplier={1.4}>
                {t(label, { count: counts[key] })}
              </Text>
            </Pressable>
          );
        })}
      </View>
      <SearchBar value={query} onChangeText={setQuery} placeholder={t("claimsSearchPlaceholder")} label={t("claimsSearchLabel")} clearLabel={t("clearSearch")} />
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
          <EmptyState title={t("claimsNoMatch")} message={t("claimsNoMatchBody")} />
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
  newClaim: { flexDirection: "row", alignItems: "center", gap: 6, minHeight: 48, paddingHorizontal: space.x4, borderRadius: radius.card, backgroundColor: colors.gold500, overflow: "hidden", marginTop: 4 },
  newClaimText: { ...type.label, color: colors.navy950 },
  segments: { flexDirection: "row", backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, padding: 4, gap: 4 },
  segment: { flex: 1, minHeight: 44, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 6, borderRadius: radius.control, paddingHorizontal: 6, overflow: "hidden" },
  segmentOn: { backgroundColor: colors.navy900 },
  segmentText: { ...type.label, fontSize: 13, lineHeight: 17, color: colors.navy900, flexShrink: 1 },
  segmentTextOn: { color: colors.white },
});
