import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { ClaimsApi } from "@/api/client";
import { useTranslation } from "@/i18n";
import { claimActionAllowed, claimStatusKey } from "@/lib/claimStatus";
import { colors, type } from "@/theme/tokens";

export default function Appeal() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const submit = async () => {
    if (!id) return;
    setBusy(true);
    setError(null);
    try {
      await ClaimsApi.appeal(id, reason.trim());
      router.replace({ pathname: "/claim/[id]", params: { id } });
    } catch (e) {
      setError(e instanceof Error ? e.message : t("appealFailed"));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen>
      <AppHeader title={t("appealTitle")} subtitle={claim.data?.claim_number} back />
      <StatePanel {...claim} onRetry={claim.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(c) =>
          claimActionAllowed("appeal", c.status) ? (
            <>
              <Card>
                <Text style={styles.title}>{t("appealExplain")}</Text>
                <TextField
                  label={t("appealReason")}
                  value={reason}
                  onChangeText={setReason}
                  multiline
                  style={styles.input}
                  placeholder={t("appealPlaceholder")}
                  hint={t("claimWhatHint", { count: Math.max(0, 30 - reason.trim().length) })}
                />
                <Text style={styles.body}>{t("appealAudit")}</Text>
              </Card>
              {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
              <Button label={t("appealSubmit")} loading={busy} disabled={reason.trim().length < 30} onPress={() => void submit()} />
            </>
          ) : (
            <Card>
              <StatusChip label={td(claimStatusKey(c.status), c.status)} tone="neutral" />
              <Text style={styles.body}>{t("appealNotAvailable")}</Text>
            </Card>
          )
        }
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  input: { minHeight: 140, textAlignVertical: "top", paddingTop: 12 },
  error: { ...type.meta, color: colors.dangerText },
});
