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

export default function DocumentPreview() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
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
      else setOpenError(new Error("This document has no downloadable file yet."));
    } catch (e) {
      setOpenError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title="Secure document" subtitle="Time-limited access is logged" back />
      {loading && !d ? <LoadingState label="Loading document…" /> : null}
      {error && !d ? <ErrorCard error={error} fallback="This document could not be loaded." onRetry={() => void reload()} /> : null}
      {d ? (
        <>
          <Card feature>
            <FileCheck2 size={36} />
            <StatusChip label={humanize(d.status)} tone="success" />
            <Text style={ps.title}>{d.label}</Text>
            <InfoRow label="Reference" value={d.share_reference} />
            <InfoRow label="Issued" value={f.date(d.issued_at)} />
            {d.expires_at ? <InfoRow label="Expires" value={f.date(d.expires_at)} /> : null}
          </Card>
          {openError ? <ErrorCard error={openError} fallback="The document could not be opened." /> : null}
          <Button label="Open protected document" loading={busy} onPress={() => void open()} />
          <Button
            label="Share verification reference"
            variant="secondary"
            onPress={() => void Share.share({ message: `OpesInsure document: ${d.label}\nVerification reference: ${d.share_reference}` })}
          />
          <Text style={ps.meta}>Do not forward downloaded identity or claims documents to unknown recipients.</Text>
        </>
      ) : null}
    </Screen>
  );
}
