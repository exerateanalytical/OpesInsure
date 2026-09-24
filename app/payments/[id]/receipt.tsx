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

export default function Receipt() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
  const { data, loading, error, reload } = useLoad(() => PaymentsApi.receipt(id), [id]);
  const [openError, setOpenError] = useState<string | null>(null);
  const r = data ? receiptView(data as unknown as Record<string, unknown>) : null;

  const openPdf = async () => {
    setOpenError(null);
    if (!r?.downloadUrl) return;
    try {
      await Linking.openURL(r.downloadUrl);
    } catch {
      setOpenError("The receipt PDF could not be opened on this device.");
    }
  };

  return (
    <Screen>
      <AppHeader title="Official receipt" back />
      {loading && !data ? <LoadingState label="Loading receipt…" /> : null}
      {error && !data ? <ErrorCard error={error} fallback="The receipt could not be loaded." onRetry={() => void reload()} /> : null}
      {r ? (
        <>
          <Card feature>
            <StatusChip label={r.status && r.status !== "SUCCEEDED" ? humanize(r.status) : "Paid"} tone={r.status && r.status !== "SUCCEEDED" ? "warning" : "success"} />
            <Text style={ps.title}>{f.xaf(r.amountMinor)}</Text>
            <Rule />
            <InfoRow label="Receipt number" value={r.number} />
            <InfoRow label="Issued" value={f.dateTime(r.issuedAt)} />
            {r.provider ? <InfoRow label="Network" value={humanize(r.provider)} /> : null}
            {r.payer ? <InfoRow label="Paid from" value={r.payer} /> : null}
            <Text style={ps.meta}>This receipt proves payment, not insurance cover. Your policy certificate is the proof of cover.</Text>
          </Card>
          {r.downloadUrl ? (
            <Button label="Open PDF receipt" icon={Download} onPress={() => void openPdf()} />
          ) : (
            <Text style={ps.meta}>A PDF copy is not available for this receipt yet. The details above are the official record.</Text>
          )}
          {openError ? <Text style={ps.error}>{openError}</Text> : null}
          <Button
            label="Share receipt details"
            icon={Share2}
            variant="secondary"
            onPress={() => void Share.share({ message: `OpesInsure receipt ${r.number}\nAmount: ${f.xaf(r.amountMinor)}\nIssued: ${f.dateTime(r.issuedAt)}` })}
          />
        </>
      ) : null}
    </Screen>
  );
}
