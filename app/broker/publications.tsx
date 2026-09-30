import React, { useState } from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { Store } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrokerApi } from "@/api/client";
import { StyleSheet, Text } from "react-native";
import { useTranslation } from "@/i18n";
import { usePermission } from "@/components/carrier/CarrierGate";
import { errorMessage } from "@/lib/purchase";
import { colors, type } from "@/theme/tokens";

/** Publication states that are live or on their way to going live (the toggle pauses them). */
const ACTIVE = ["PUBLISHED", "APPROVED", "SUBMITTED", "ACTIVE"];

export default function Publications() {
  const { t, td, date } = useTranslation();
  // PATCH marketplace-publications/{id} needs broker.marketplace.manage (broker admins); others read only.
  const canManage = usePermission("broker.marketplace.manage");
  const q = useLoad(() => BrokerApi.publications(), []);
  const x = q.data ?? [];
  const [busy, setBusy] = useState<string | null>(null);
  const [failed, setFailed] = useState<{ id: string; message: string } | null>(null);
  const toggle = async (id: string, enable: boolean) => {
    setBusy(id);
    setFailed(null);
    try {
      const v = await BrokerApi.togglePublication(id, enable);
      q.setData(x.map((a) => (a.id === v.id ? v : a)));
    } catch (e) {
      setFailed({ id, message: errorMessage(e, t("errGeneric")) });
    } finally {
      setBusy(null);
    }
  };
  return (
    <Screen>
      <AppHeader
        title={t("brMarketplacePublications")}
        subtitle={t("brPublicationApproval")}
        back
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          {x.map((p) => {
            const active = ACTIVE.includes(p.status);
            return (
              <Card key={p.id}>
                <Store size={22} color={colors.navy800} />
                <Text style={s.title}>{p.product_name}</Text>
                <StatusChip
                  label={td(`pubStatus_${p.status}`, p.status)}
                  tone={p.status === "PUBLISHED" || p.status === "APPROVED" ? "success" : "warning"}
                />
                <Text style={s.meta}>
                  {t("brPublicationSubmitted", { channel: td(`pubChannel_${p.channel}`, p.channel), date: date(p.submitted_at, false) })}
                </Text>
                {canManage ? (
                  <Button
                    label={active ? t("brUnpublish") : t("brSubmitPublication")}
                    variant="secondary"
                    loading={busy === p.id}
                    disabled={busy !== null && busy !== p.id}
                    onPress={() => void toggle(p.id, !active)}
                  />
                ) : null}
                {failed?.id === p.id ? (
                  <Text accessibilityRole="alert" style={s.error}>
                    {failed.message}
                  </Text>
                ) : null}
              </Card>
            );
          })}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

const s = StyleSheet.create({
  title: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
