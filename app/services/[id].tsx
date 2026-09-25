import React, { useState } from "react";
import { useLocalSearchParams } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { Step } from "@/components/FlowPrimitives";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { PolicyServicesApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function ServiceDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data: x, setData: setX, loading, error, reload } = useLoad(() => PolicyServicesApi.show(id), [id]);
  const [m, setM] = useState("");
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<unknown>(null);
  const send = async () => {
    setSending(true);
    setSendError(null);
    try {
      setX(await PolicyServicesApi.addMessage(id, m));
      setM("");
    } catch (e) {
      setSendError(e);
    } finally {
      setSending(false);
    }
  };
  return (
    <Screen>
      <AppHeader title={x ? td(`serviceType_${x.type}`, x.type) : t("svcDetailTitle")} back />
      <StatePanel loading={loading} error={error} data={x} onRetry={() => void reload()} isEmpty={() => false}>
        {(x) => (
          <>
            <Card>
              <StatusChip label={td(`status_${x.status}`, x.status)} tone="info" />
              <Text style={s.body}>{x.reason}</Text>
              {x.timeline.map((e) => (
                <Step key={e.id} label={`${e.label}${e.description ? ` — ${e.description}` : ""}`} complete />
              ))}
            </Card>
            <Card>
              <TextField label={t("svcAddInfo")} multiline value={m} onChangeText={setM} />
              <Button label={t("svcSendMessage")} disabled={!m.trim()} loading={sending} onPress={() => void send()} />
            </Card>
            {sendError ? <ErrorCard error={sendError} fallback={t("errGeneric")} /> : null}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({ body: { ...type.body, color: colors.neutral700 } });
