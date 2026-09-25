import React from "react";
import { Text, View } from "react-native";
import { router } from "expo-router";
import { PlugZap } from "lucide-react-native";
import { Button, Card } from "@/components/ui";
import { purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { colors } from "@/theme/tokens";
import { providerErrorMessage } from "@/lib/purchase";
import { useTranslation } from "@/i18n";

/** Shown when the backend answers 422/503 "payment provider not configured". */
export function ProviderNotConfigured({ error }: { error?: unknown }) {
  const { t } = useTranslation();
  const serverMessage = error ? providerErrorMessage(error) : null;
  return (
    <Card>
      <View style={ps.row}>
        <PlugZap size={22} color={colors.warningText} />
        <Text style={ps.title}>{t("payNotAvailableTitle")}</Text>
      </View>
      <Text style={ps.body}>{t("payNotAvailableBody")}</Text>
      {serverMessage ? <Text style={ps.meta}>{serverMessage}</Text> : null}
      <Button label={t("contactSupport")} variant="secondary" onPress={() => router.push("/support/new")} />
      <Button label={t("myApplications")} variant="tertiary" onPress={() => router.replace("/proposals")} />
    </Card>
  );
}
