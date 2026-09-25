import React, { useState } from "react";
import { Linking, Share, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { Download, Share2 } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard, InfoRow, Rule, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { PaymentsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize, receiptView } from "@/lib/purchase";
import { useTranslation } from "@/i18n";

export default function Receipt() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => PaymentsApi.receipt(id), [id]);
  const [openError, setOpenError] = useState<string | null>(null);
  const r = data ? receiptView(data as unknown as Record<string, unknown>) : null;

  const openPdf = async () => {
    setOpenError(null);
    if (!r?.downloadUrl) return;
    try {
      await Linking.openURL(r.downloadUrl);
    } catch {
      setOpenError(t("rcPdfFailed"));
    }
  };

  return (
    <Screen>
      <AppHeader title={t("rcTitle")} back />
      {loading && !data ? <LoadingState label={t("rcLoading")} /> : null}
      {error && !data ? <ErrorCard error={error} fallback={t("rcLoadFailed")} onRetry={() => void reload()} /> : null}
      {r ? (
        <>
          <Card feature>
            <StatusChip label={r.status && r.status !== "SUCCEEDED" ? td(`status_${r.status}`, humanize(r.status)) : t("rcPaid")} tone={r.status && r.status !== "SUCCEEDED" ? "warning" : "success"} />
            <Text style={ps.title}>{f.xaf(r.amountMinor)}</Text>
            <Rule />
            <InfoRow label={t("rcNumber")} value={r.number} />
            <InfoRow label={t("rcIssued")} value={f.dateTime(r.issuedAt)} />
            {r.provider ? <InfoRow label={t("pmNetwork")} value={humanize(r.provider)} /> : null}
            {r.payer ? <InfoRow label={t("rcPaidFrom")} value={r.payer} /> : null}
            <Text style={ps.meta}>{t("rcNotCover")}</Text>
          </Card>
          {r.downloadUrl ? (
            <Button label={t("rcOpenPdf")} icon={Download} onPress={() => void openPdf()} />
          ) : (
            <Text style={ps.meta}>{t("rcNoPdf")}</Text>
          )}
          {openError ? <Text style={ps.error}>{openError}</Text> : null}
          <Button
            label={t("rcShare")}
            icon={Share2}
            variant="secondary"
            onPress={() => void Share.share({ message: t("rcShareMessage", { number: r.number, amount: f.xaf(r.amountMinor), date: f.dateTime(r.issuedAt) }) })}
          />
        </>
      ) : null}
    </Screen>
  );
}
