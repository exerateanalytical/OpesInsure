import React, { useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text, View } from "react-native";
import { CarFront, FileCheck, FilePen, FileX, LucideIcon, MapPin, ShieldCheck, Users } from "lucide-react-native";
import { Button, Card, Screen, TextField } from "@/components/ui";
import { BrandHeader, CtaBar, RadioCard, SectionHeading } from "@/components/design";
import { PolicyServicesApi } from "@/api/client";
import { usePolicies } from "@/hooks/usePolicies";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/** ServiceRequestIntake::TYPES — the only request types the server accepts. */
const TYPES = ["ADDRESS_CHANGE", "VEHICLE_CHANGE", "BENEFICIARY_CHANGE", "ENDORSEMENT", "DOCUMENT_REISSUE", "CANCELLATION_REVIEW"] as const;
type ServiceType = (typeof TYPES)[number];
const TYPE_ICON: Record<ServiceType, LucideIcon> = {
  ADDRESS_CHANGE: MapPin,
  VEHICLE_CHANGE: CarFront,
  BENEFICIARY_CHANGE: Users,
  ENDORSEMENT: FilePen,
  DOCUMENT_REISSUE: FileCheck,
  CANCELLATION_REVIEW: FileX,
};
const MIN_REASON = 10;

/**
 * Policy service request (endorsement, change, document reissue, cancellation review) on one policy:
 * POST /policies/{id}/service-requests. Opened from the policy (?policyId=) or from Services, where the
 * customer first picks one of their policies. Tracked at /services/{id}.
 */
export default function NewService() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<{ policyId?: string; type?: string }>();
  const { policies, loading } = usePolicies();
  const [policyId, setPolicyId] = useState<string | null>(params.policyId ?? null);
  const [typeValue, setType] = useState<ServiceType>((TYPES as readonly string[]).includes(params.type ?? "") ? (params.type as ServiceType) : "ADDRESS_CHANGE");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const choosable = policies.filter((p) => ["ACTIVE", "EXPIRING"].includes(String(p.status).toUpperCase()));

  const submit = async () => {
    if (!policyId || busy) return;
    setBusy(true);
    setError(null);
    try {
      const x = await PolicyServicesApi.create({ policy_id: policyId, type: typeValue, reason: reason.trim() });
      router.replace({ pathname: "/services/[id]", params: { id: x.id } });
    } catch (e) {
      setError(e instanceof Error && e.message ? e.message : t("svcSubmitFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen
      footer={
        <CtaBar>
          {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
          <Button label={t("svcSubmit")} loading={busy} disabled={!policyId || reason.trim().length < MIN_REASON || busy} onPress={() => void submit()} />
        </CtaBar>
      }
    >
      <BrandHeader title={t("svcNewTitle")} subtitle={t("svcNewSubtitle")} back right="help" />
      {!params.policyId ? (
        <Card style={s.card}>
          <SectionHeading icon={ShieldCheck} title={t("svcWhichPolicy")} />
          {loading && !policies.length ? <Text style={s.meta}>{t("loading")}</Text> : null}
          {!loading && !choosable.length ? <Text style={s.meta}>{t("svcNoActivePolicy")}</Text> : null}
          <View style={s.wrap} accessibilityRole="radiogroup">
            {choosable.map((p) => (
              <RadioCard key={p.id} selected={p.id === policyId} onPress={() => setPolicyId(p.id)} icon={ShieldCheck} tint="blue" title={p.policy_number} style={s.option} />
            ))}
          </View>
        </Card>
      ) : null}
      <Card style={s.card}>
        <SectionHeading title={t("svcType")} />
        <View style={s.wrap} accessibilityRole="radiogroup">
          {TYPES.map((x) => (
            <RadioCard key={x} selected={x === typeValue} onPress={() => setType(x)} icon={TYPE_ICON[x]} tint={x === "CANCELLATION_REVIEW" ? "red" : "blue"} title={td(`svcType_${x}`, x)} style={s.option} />
          ))}
        </View>
      </Card>
      <Card style={s.card}>
        <TextField label={t("svcReason")} multiline value={reason} onChangeText={setReason} style={s.area} hint={t(`svcHint_${typeValue}`)} />
        <Text style={s.meta}>{t("minChars", { count: MIN_REASON })}</Text>
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({
  card: { borderRadius: radius.feature },
  wrap: { gap: space.x2 },
  option: { padding: space.x3 },
  area: { minHeight: 120, textAlignVertical: "top", paddingTop: 12 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
