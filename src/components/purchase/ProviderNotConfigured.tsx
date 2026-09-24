import React from "react";
import { Text, View } from "react-native";
import { router } from "expo-router";
import { PlugZap } from "lucide-react-native";
import { Button, Card } from "@/components/ui";
import { purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { colors } from "@/theme/tokens";
import { providerErrorMessage } from "@/lib/purchase";

/** Shown when the backend answers 422/503 "payment provider not configured". */
export function ProviderNotConfigured({ error }: { error?: unknown }) {
  const serverMessage = error ? providerErrorMessage(error) : null;
  return (
    <Card>
      <View style={ps.row}>
        <PlugZap size={22} color={colors.warningText} />
        <Text style={ps.title}>Online payment is not available yet</Text>
      </View>
      <Text style={ps.body}>
        The Mobile Money connection for this insurer is not switched on yet, so no payment request was sent and nothing was charged. Your application is
        saved — you can pay as soon as payments open, or contact support for another way to pay.
      </Text>
      {serverMessage ? <Text style={ps.meta}>{serverMessage}</Text> : null}
      <Button label="Contact support" variant="secondary" onPress={() => router.push("/support/new")} />
      <Button label="My applications" variant="tertiary" onPress={() => router.replace("/proposals")} />
    </Card>
  );
}
