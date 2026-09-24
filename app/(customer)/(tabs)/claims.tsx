import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
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
  return (
    <Screen>
      <AppHeader title={t("claims")} subtitle={t("claimsSubtitle")} />
      <Button label={t("reportIncident")} icon={AlertTriangle} onPress={() => router.push("/claim/new")} />
      {q.loading && !q.data ? (
        <LoadingState label={t("claimsLoading")} />
      ) : q.error && !q.data ? (
        <ErrorState onRetry={() => void q.reload()} />
      ) : claims.length ? (
        <>
          {open.length ? <SectionTitle title={t("claimsOpen")} /> : null}
          {open.map(row)}
          {past.length ? <SectionTitle title={t("claimsPast")} /> : null}
          {past.map(row)}
        </>
      ) : (
        <EmptyState title={t("claimsEmpty")} message={t("claimsEmptyBody")} />
      )}
      <Card>
        <View style={styles.row}>
          <Siren size={22} color={colors.dangerText} />
          <Text style={[styles.title, styles.flex]}>{t("emergencyTitle")}</Text>
        </View>
        <Text style={styles.body}>{t("emergencyBody")}</Text>
        <Button label={t("emergencyAssistance")} variant="danger" onPress={() => router.push("/claim/emergency")} />
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  chips: { flexDirection: "row", gap: space.x2, flexWrap: "wrap" },
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});
