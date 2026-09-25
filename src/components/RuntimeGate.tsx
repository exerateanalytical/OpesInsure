import React from "react";
import { Linking, StyleSheet, Text, View } from "react-native";
import { AlertTriangle, Construction, ShieldX } from "lucide-react-native";
import { Button, Card } from "@/components/ui";
import { RuntimeGate as Gate } from "@/store/runtime";
import { RuntimeBootstrap } from "@/api/client";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

export function RuntimeGateView({ gate, bootstrap, issues, retry }: { gate: Gate; bootstrap: RuntimeBootstrap | null; issues: string[]; retry: () => void }) {
  const maintenance = gate === "maintenance";
  const update = gate === "update_required";
  const { t } = useTranslation();
  const Icon = maintenance ? Construction : gate === "configuration_error" ? ShieldX : AlertTriangle;
  return (
    <View style={styles.page} accessibilityViewIsModal>
      <Card feature>
        <Icon size={34} color={gate === "configuration_error" ? colors.danger : colors.warningText} />
        <Text accessibilityRole="header" style={styles.title}>
          {maintenance ? t("gateMaintenanceTitle") : update ? t("gateUpdateTitle") : t("gateConfigTitle")}
        </Text>
        <Text style={styles.body}>
          {maintenance
            ? bootstrap?.maintenance.message ?? t("gateMaintenanceBody")
            : update
              ? t("gateUpdateBody")
              : t("gateConfigBody")}
        </Text>
        {issues.length ? <Text style={styles.code}>{issues.join(" · ")}</Text> : null}
        {update && bootstrap?.release.store_url ? (
          <Button label={t("gateOpenUpdate")} onPress={() => void Linking.openURL(bootstrap.release.store_url!)} />
        ) : null}
        {!update ? <Button label={t("gateCheckAgain")} variant="secondary" onPress={retry} /> : null}
      </Card>
    </View>
  );
}
const styles = StyleSheet.create({
  page: { flex: 1, justifyContent: "center", padding: space.x5, backgroundColor: colors.neutral50 },
  title: { ...type.pageTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  code: { ...type.meta, color: colors.dangerText },
});
