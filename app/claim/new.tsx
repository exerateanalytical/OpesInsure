import React, { useState } from "react";
import { Pressable, StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Siren } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { DateTimeField, toCameroonIso } from "@/components/DateTimeField";
import { ClaimsApi } from "@/api/client";
import { usePolicies } from "@/hooks/usePolicies";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export default function NewClaim() {
  const { t, date, language } = useTranslation();
  const { policies, loading, error: policyError, reload } = usePolicies();
  const active = policies.filter((p) => p.status === "ACTIVE");
  const { policyId: prefill } = useLocalSearchParams<{ policyId?: string }>();
  const [policyId, setPolicyId] = useState(typeof prefill === "string" ? prefill : "");
  const [incidentAt, setIncidentAt] = useState(() => Date.now() - 3_600_000);
  const [location, setLocation] = useState("");
  const [description, setDescription] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const claim = await ClaimsApi.create({
        policy_id: policyId,
        incident_at: toCameroonIso(incidentAt),
        incident_location: location.trim(),
        description: description.trim(),
      });
      router.replace({ pathname: "/claim/[id]", params: { id: claim.id } });
    } catch (e) {
      // Form data is kept so the customer can retry after a failure.
      setError(e instanceof Error ? e.message : t("claimSubmitFailed"));
    } finally {
      setBusy(false);
    }
  };
  const valid = !!policyId && location.trim().length >= 3 && description.trim().length >= 20;

  return (
    <Screen>
      <AppHeader title={t("reportIncident")} subtitle={t("claimNewSubtitle")} back />
      <Button label={t("emergencyAssistance")} icon={Siren} variant="danger" onPress={() => router.push("/claim/emergency")} />
      {loading ? (
        <LoadingState label={t("claimLoadingPolicies")} />
      ) : policyError ? (
        <ErrorState onRetry={() => void reload()} />
      ) : active.length === 0 ? (
        <Card>
          <StatusChip label={t("claimNoActivePolicy")} tone="warning" />
          <Text style={styles.body}>{t("claimNoActivePolicyBody")}</Text>
        </Card>
      ) : (
        <Card>
          <Text style={styles.title}>{t("claimSelectPolicy")}</Text>
          {active.map((policy) => (
            <Pressable
              accessibilityRole="radio"
              accessibilityState={{ selected: policyId === policy.id }}
              key={policy.id}
              style={[styles.choice, policyId === policy.id && styles.selected]}
              onPress={() => setPolicyId(policy.id)}
            >
              <Text style={styles.choiceTitle}>{policy.policy_number}</Text>
              <Text style={styles.body}>
                {date(policy.coverage_starts_at)} — {date(policy.coverage_ends_at)}
              </Text>
            </Pressable>
          ))}
        </Card>
      )}
      <DateTimeField
        label={t("claimWhen")}
        value={incidentAt}
        onChange={setIncidentAt}
        language={language}
        labels={{
          date: t("dateLabel"),
          time: t("timeLabel"),
          today: t("today"),
          yesterday: t("yesterday"),
          previousDay: t("previousDay"),
          nextDay: t("nextDay"),
          earlier: t("earlier"),
          later: t("later"),
          hour: t("hourUnit"),
          minutes: t("minutesUnit"),
        }}
      />
      <TextField
        label={t("claimWhere")}
        value={location}
        onChangeText={setLocation}
        placeholder={t("claimWherePlaceholder")}
        hint={t("claimWhereHint")}
      />
      <TextField
        label={t("claimWhat")}
        value={description}
        onChangeText={setDescription}
        placeholder={t("claimWhatPlaceholder")}
        hint={t("claimWhatHint", { count: Math.max(0, 20 - description.trim().length) })}
        multiline
        style={styles.description}
      />
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      <Text style={styles.note}>{t("claimNewNote")}</Text>
      <Button label={t("claimSubmit")} loading={busy} disabled={!valid} onPress={() => void submit()} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  choice: { borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.control, padding: space.x3, gap: space.x1, minHeight: 44 },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  choiceTitle: { ...type.label, color: colors.navy950 },
  description: { minHeight: 120, textAlignVertical: "top", paddingTop: 12 },
  note: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
