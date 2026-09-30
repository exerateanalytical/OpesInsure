import React, { useState } from "react";
import { useLocalSearchParams } from "expo-router";
import { StyleSheet, Text, View } from "react-native";
import { FileCog, MessageSquare, Send } from "lucide-react-native";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { Step } from "@/components/FlowPrimitives";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { PolicyServicesApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

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
      <BrandHeader title={x ? td(`svcType_${x.type}`, x.type) : t("svcDetailTitle")} back right="help" />
      <StatePanel loading={loading} error={error} data={x} onRetry={() => void reload()} isEmpty={() => false}>
        {(x) => (
          <>
            <Card style={s.card}>
              <View style={s.headRow}>
                <TintedIcon icon={FileCog} tint="blue" size={56} />
                <View style={s.flex}>
                  <StatusChip label={td(`status_${x.status}`, x.status)} tone="info" />
                  <Text style={s.title}>{td(`svcType_${x.type}`, x.type)}</Text>
                </View>
              </View>
              <Text style={s.body}>{x.reason}</Text>
              <View style={s.timeline}>
                {x.timeline.map((e) => (
                  <Step key={e.id} label={`${e.label}${e.description ? ` — ${e.description}` : ""}`} complete />
                ))}
              </View>
            </Card>
            <Card style={s.card}>
              <SectionHeading title={t("svcAddInfo")} icon={MessageSquare} />
              <TextField label={t("svcAddInfo")} multiline value={m} onChangeText={setM} style={s.area} />
              <Button label={t("svcSendMessage")} icon={Send} disabled={!m.trim()} loading={sending} onPress={() => void send()} />
              {sendError ? <ErrorCard error={sendError} fallback={t("errGeneric")} /> : null}
            </Card>
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1, gap: space.x2 },
  card: { borderRadius: radius.feature },
  headRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  timeline: { borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x2 },
  area: { minHeight: 96, textAlignVertical: "top", paddingTop: 12 },
});
