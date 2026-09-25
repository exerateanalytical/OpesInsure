import React, { useState } from "react";
import { Linking, Share, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { FileCheck2 } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard, InfoRow, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { DocumentsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize, openableUrl } from "@/lib/purchase";

import { useTranslation } from "@/i18n";
export default function DocumentPreview() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
  const { t } = useTranslation();
  const { data: d, setData, loading, error, reload } = useLoad(() => DocumentsApi.show(id), [id]);
  const [busy, setBusy] = useState(false);
  const [openError, setOpenError] = useState<unknown>(null);

  const open = async () => {
    if (busy) return;
    setBusy(true);
    setOpenError(null);
    try {
      const x = await DocumentsApi.access(id);
      setData(x);
      const url = openableUrl(x.signed_url);
      if (url) await Linking.openURL(url);
      else setOpenError(new Error(t("docNoFile")));
    } catch (e) {
      setOpenError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title={t("docTitle")} subtitle={t("docSubtitle")} back />
      {loading && !d ? <LoadingState label={t("docLoading")} /> : null}
      {error && !d ? <ErrorCard error={error} fallback={t("docLoadFailed")} onRetry={() => void reload()} /> : null}
      {d ? (
        <>
          <Card feature>
            <FileCheck2 size={36} />
            <StatusChip label={humanize(d.status)} tone="success" />
            <Text style={ps.title}>{d.label}</Text>
            <InfoRow label={t("docReference")} value={d.share_reference} />
            <InfoRow label={t("docIssued")} value={f.date(d.issued_at)} />
            {d.expires_at ? <InfoRow label={t("docExpires")} value={f.date(d.expires_at)} /> : null}
          </Card>
          {openError ? <ErrorCard error={openError} fallback={t("docOpenFailed")} /> : null}
          <Button label={t("docOpen")} loading={busy} onPress={() => void open()} />
          <Button
            label={t("docShare")}
            variant="secondary"
            onPress={() => void Share.share({ message: t("docShareMessage", { label: d.label, reference: d.share_reference }) })}
          />
          <Text style={ps.meta}>{t("docWarning")}</Text>
        </>
      ) : null}
    </Screen>
  );
}
