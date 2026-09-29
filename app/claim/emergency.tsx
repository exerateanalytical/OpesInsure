import React, { useEffect, useState } from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { Ambulance, Phone, ShieldAlert, ShieldCheck, Truck } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { RadioCard, SectionHeading } from "@/components/design";
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
  const [reference, setReference] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const request = async () => {
    if (!policyId || busy) return;
    setBusy(true);
    setError(null);
    try {
      setReference((await ClaimsCompletionApi.requestEmergencyAssistance({ policy_id: policyId, service, location, callback_phone: phone })).reference);
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
      {active.length > 1 ? (
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
        <Button label={t("emRequest")} loading={busy} disabled={!policyId || location.trim().length < 4 || phone.length < 8 || busy} onPress={() => void request()} />
        {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
        {reference ? <Text accessibilityLiveRegion="polite" style={s.ok}>{t("emReference", { reference })}</Text> : null}
      </Card>
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
