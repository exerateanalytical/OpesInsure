import React, { useEffect, useState } from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Ambulance, Phone, ShieldAlert, ShieldCheck, Truck } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { RadioCard, SectionHeading } from "@/components/design";
import { ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { ClaimsCompletionApi } from "@/api/client";
import { usePolicies } from "@/hooks/usePolicies";
import { useSession } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

const options = [
  ["MEDICAL", Ambulance, "emMedical"],
  ["POLICE", ShieldAlert, "emPolice"],
  ["TOWING", Truck, "emTowing"],
] as const;

/**
 * Emergency assistance on one of the customer's active policies (it used to send a hard-coded
 * policy id). One active policy is used directly; with several the customer picks one.
 */
export default function Emergency() {
  const { t } = useTranslation();
  const { policies, loading } = usePolicies();
  const active = policies.filter((p) => ["ACTIVE", "EXPIRING"].includes(String(p.status).toUpperCase()));
  const [policyId, setPolicyId] = useState<string | null>(null);
  useEffect(() => {
    if (!policyId && active.length === 1) setPolicyId(active[0]!.id);
  }, [active, policyId]);
  const [service, setService] = useState<"MEDICAL" | "POLICE" | "TOWING">("TOWING");
  const [location, setLocation] = useState("");
  const [phone, setPhone] = useState(useSession.getState().bootstrap?.user.phone_e164 ?? "+237");
  // The created ticket: once set, the form is locked so the same emergency is never sent twice.
  const [created, setCreated] = useState<{ id: string; reference: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Short confirm summary before the request goes out (the 112 call stays one tap away above).
  const [confirming, setConfirming] = useState(false);
  const valid = !!policyId && location.trim().length >= 4 && phone.length >= 8;
  const chosen = options.find(([key]) => key === service)!;

  const request = async () => {
    if (!policyId || busy || created) return;
    setBusy(true);
    setError(null);
    try {
      const ticket = await ClaimsCompletionApi.requestEmergencyAssistance({ policy_id: policyId, service, location, callback_phone: phone });
      setCreated({ id: ticket.id, reference: ticket.reference });
      setConfirming(false);
      // The operations desk works the request as a support case: follow it there.
      router.replace({ pathname: "/support/[id]", params: { id: ticket.id } });
    } catch (e) {
      setError(e instanceof Error && e.message ? e.message : t("actionFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title={t("emTitle")} subtitle={t("emSubtitle")} back />
      <Card feature>
        <StatusChip label={t("emChip")} tone="danger" />
        <Text style={s.body}>{t("emBody")}</Text>
        <Button label={t("emCall")} icon={Phone} variant="danger" onPress={() => Linking.openURL("tel:112")} />
      </Card>
      {active.length > 1 && !confirming && !created ? (
        <Card>
          <SectionHeading icon={ShieldCheck} title={t("svcWhichPolicy")} />
          <View style={s.list} accessibilityRole="radiogroup">
            {active.map((p) => (
              <RadioCard key={p.id} selected={p.id === policyId} onPress={() => setPolicyId(p.id)} icon={ShieldCheck} tint="blue" title={p.policy_number} />
            ))}
          </View>
        </Card>
      ) : null}
      {!loading && !active.length ? <Text style={s.body}>{t("emNoPolicy")}</Text> : null}
      {created ? (
        <Card>
          <StatusChip label={t("emSent")} tone="success" />
          <Text accessibilityLiveRegion="polite" style={s.ok}>{t("emReference", { reference: created.reference })}</Text>
          <Button label={t("emViewRequest")} onPress={() => router.replace({ pathname: "/support/[id]", params: { id: created.id } })} />
        </Card>
      ) : confirming ? (
        <>
          <ReviewSection icon={chosen[1]} tint="red" title={t("emConfirmTitle")} onEdit={() => setConfirming(false)}>
            <ReviewRow first label={t("emService")} value={t(chosen[2])} />
            <ReviewRow label={t("svcWhichPolicy")} value={active.find((p) => p.id === policyId)?.policy_number} />
            <ReviewRow label={t("emLocation")} value={location.trim()} />
            <ReviewRow label={t("emCallback")} value={phone} />
          </ReviewSection>
          <Button label={t("emConfirmRequest")} variant="danger" loading={busy} disabled={!valid || busy} onPress={() => void request()} />
          <Button label={t("reviewBackToForm")} variant="tertiary" disabled={busy} onPress={() => setConfirming(false)} />
          {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
        </>
      ) : (
      <Card>
        {options.map(([key, Icon, label]) => (
          <Pressable
            key={key}
            accessibilityRole="radio"
            accessibilityState={{ selected: service === key }}
            style={[s.option, service === key && s.selected]}
            onPress={() => setService(key)}
          >
            <Icon size={22} color={colors.navy800} />
            <Text style={s.label}>{t(label)}</Text>
          </Pressable>
        ))}
        <TextField label={t("emLocation")} value={location} onChangeText={setLocation} />
        <TextField label={t("emCallback")} keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
        <Button label={t("emRequest")} disabled={!valid || busy} onPress={() => { setError(null); setConfirming(true); }} />
        {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
      </Card>
      )}
    </Screen>
  );
}

const s = StyleSheet.create({
  body: { ...type.body, color: colors.neutral700 },
  label: { ...type.label, color: colors.navy950 },
  list: { gap: space.x2 },
  option: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    padding: space.x3,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
  },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  error: { ...type.meta, color: colors.dangerText },
  ok: { ...type.meta, color: colors.successText },
});
