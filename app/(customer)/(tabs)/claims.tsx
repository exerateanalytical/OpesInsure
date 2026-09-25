import React from "react";
import { Pressable, RefreshControl, SectionList, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { AlertTriangle, ChevronRight, Siren } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { useTranslation } from "@/i18n";
import { claimStatusKey, claimTone, isActiveClaim, normalizeClaimStatus } from "@/lib/claimStatus";
import { colors, space, type } from "@/theme/tokens";

export default function Claims() {
  const { t, td, date } = useTranslation();
  const q = useLoad(() => CustomerApi.claims());
  const claims = q.data ?? [];
  const open = claims.filter((c) => isActiveClaim(c.status));
  const past = claims.filter((c) => !isActiveClaim(c.status));
  const row = (claim: (typeof claims)[number]) => {
    const needsAction = normalizeClaimStatus(claim.status) === "EVIDENCE_PENDING";
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`${claim.claim_number}. ${td(claimStatusKey(claim.status), claim.status)}`}
        key={claim.id}
        onPress={() => router.push({ pathname: "/claim/[id]", params: { id: claim.id } })}
      >
        <Card>
          <View style={styles.row}>
            <View style={styles.flex}>
              <Text style={styles.title}>{claim.claim_number}</Text>
              <Text style={styles.body}>
                {date(claim.incident_at)}
                {claim.incident_location ? ` · ${claim.incident_location}` : ""}
              </Text>
            </View>
            <ChevronRight size={20} color={colors.neutral500} />
          </View>
          <View style={styles.chips}>
            <StatusChip label={td(claimStatusKey(claim.status), claim.status)} tone={claimTone(claim.status)} />
            {needsAction ? <StatusChip label={t("claimActionNeeded")} tone="warning" /> : null}
          </View>
        </Card>
      </Pressable>
    );
  };
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
  const header = (
    <View style={styles.header}>
      <AppHeader title={t("claims")} subtitle={t("claimsSubtitle")} />
      <Button label={t("reportIncident")} icon={AlertTriangle} onPress={() => router.push("/claim/new")} />
    </View>
  );
  if (!claims.length)
    return (
      <Screen>
        {header}
        {q.loading && !q.data ? (
          <LoadingState label={t("claimsLoading")} />
        ) : q.error && !q.data ? (
          <ErrorState error={q.error} onRetry={() => void q.reload()} />
        ) : (
          <EmptyState title={t("claimsEmpty")} message={t("claimsEmptyBody")} />
        )}
        {emergency}
      </Screen>
    );
  const sections = [
    { key: "open", title: t("claimsOpen"), data: open },
    { key: "past", title: t("claimsPast"), data: past },
  ].filter((x) => x.data.length);
  // Virtualized (SectionList): long claim histories stay smooth.
  return (
    <Screen scroll={false}>
      <SectionList
        sections={sections}
        keyExtractor={(c) => c.id}
        stickySectionHeadersEnabled={false}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={q.loading} onRefresh={() => void q.reload()} />}
        ListHeaderComponent={header}
        renderSectionHeader={({ section }) => <SectionTitle title={section.title} />}
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
  chips: { flexDirection: "row", gap: space.x2, flexWrap: "wrap" },
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});
