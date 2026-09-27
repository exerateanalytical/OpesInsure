import React, { useCallback, useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Calendar, Car, ChevronRight, FileText, Shield } from "lucide-react-native";
import { Screen, StatusChip, ripple } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { BrandArt } from "@/components/design/BrandArt";
import { InstitutionMark } from "@/components/InstitutionMark";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { PolicyDocumentsSection } from "@/components/policies/PolicyDocumentsSection";
import { WalletApi, WalletPolicy } from "@/api/client";
import { Institution, InstitutionsApi } from "@/api/extra";
import { policyStatusInfo } from "@/lib/purchase";
import { insuredObjectLabel } from "@/lib/renewal";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * "Policy Documents & Certificates": the policy summary card, then the full
 * PolicyDocumentsSection (groups, history, pack download, View / Download /
 * Share / Verify QR through the in-app viewer).
 */
export default function PolicyDocuments() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  const f = useFormatters();
  const [p, setP] = useState<WalletPolicy | null>(null);
  const [insurer, setInsurer] = useState<Institution | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);

  const load = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    setError(null);
    try {
      const policy = await WalletApi.policy(id);
      setP(policy);
      if (policy.carrier_id) InstitutionsApi.show(policy.carrier_id).then(setInsurer).catch(() => setInsurer(null));
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  }, [id]);
  useEffect(() => {
    void load();
  }, [load]);

  const header = <BrandHeader title={t("pdocTitle")} subtitle={t("pdocSubtitle")} right={null} />;
  if (!id) return <Screen>{header}</Screen>;

  const info = p ? policyStatusInfo(p.status, f.language) : null;
  const provider = p ? p.carrier_name ?? p.carrier?.party?.display_name ?? t("licensedCarrier") : "";
  const insured = p ? insuredObjectLabel(p, p.terms_snapshot?.risk_facts ?? p.proposal?.offer?.quote?.risk_facts) : null;
  const isMotor = p ? /motor|auto|vehic|moto/i.test(p.product_name ?? "") || !!p.risk_asset : false;
  const Icon = isMotor ? Car : Shield;

  return (
    <Screen>
      {header}
      {loading && !p ? <LoadingState label={t("pdLoading")} /> : null}
      {error ? <ErrorCard error={error} fallback={t("pdLoadFailed")} onRetry={() => void load()} /> : null}
      {p && info ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={`${p.product_name ?? t("pdTitle")}. ${provider}. ${info.label}`}
          onPress={() => router.push({ pathname: "/policy/[id]", params: { id: p.id } })}
          android_ripple={ripple()}
          style={({ pressed }) => [s.card, pressed && s.pressed]}
        >
          <View style={s.top}>
            <View style={s.flex}>
              <View style={s.titleRow}>
                <Text style={[s.title, s.flex]}>{p.product_name ?? t("pdTitle")}</Text>
                <StatusChip label={info.label} tone={info.tone} />
                <ChevronRight size={20} color={colors.navy900} />
              </View>
              <View style={s.providerRow}>
                <InstitutionMark logoUrl={p?.carrier_logo_url ?? insurer?.logo_url ?? null} initials={provider.slice(0, 2).toUpperCase()} size={24} />
                <Text style={s.body}>{provider}</Text>
              </View>
            </View>
          </View>
          <View style={s.meta}>
            <View style={s.metaCell}>
              <Icon size={20} color={colors.navy900} />
              <Text style={s.metaText}>{insured ?? "—"}</Text>
            </View>
            <View style={s.metaCell}>
              <Calendar size={20} color={colors.navy900} />
              <View style={s.flex}>
                <Text style={s.metaLabel}>{t("pdocPeriod")}</Text>
                <Text style={s.metaText}>{`${f.date(p.coverage_starts_at)} – ${f.date(p.coverage_ends_at)}`}</Text>
              </View>
            </View>
            <View style={s.metaCell}>
              <FileText size={20} color={colors.navy900} />
              <View style={s.flex}>
                <Text style={s.metaLabel}>{t("pdPolicyNumber")}</Text>
                <Text style={s.metaText}>{p.policy_number}</Text>
              </View>
            </View>
          </View>
        </Pressable>
      ) : null}
      <PolicyDocumentsSection policyId={id} />
      <BrandArt name="tribal_border_gold" width={240} opacity={0.6} />
    </Screen>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3, overflow: "hidden" },
  top: { flexDirection: "row", gap: space.x3 },
  titleRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  title: { ...type.cardTitle, color: colors.navy950 },
  providerRow: { flexDirection: "row", alignItems: "center", gap: space.x2, marginTop: 6 },
  body: { ...type.body, color: colors.neutral700, flexShrink: 1 },
  meta: { flexDirection: "row", flexWrap: "wrap", gap: space.x3, borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  metaCell: { flexDirection: "row", alignItems: "flex-start", gap: space.x2, flexGrow: 1, flexBasis: 140 },
  metaLabel: { ...type.meta, color: colors.neutral600 },
  metaText: { ...type.meta, color: colors.navy950, flexShrink: 1 },
});
