import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { errorMessage, Notice } from "@/components/portal/Workspace";
import { CarrierApi, CarrierQueueItem } from "@/api/client";
import { CarrierWorkspaceApi, humanize, shortDate } from "@/api/partner";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

const PENDING = ["REQUESTED", "CARRIER_REVIEW", "PENDING"];

export default function Issuance() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierApi.issuance(), []);
  return (
    <Screen>
      <AppHeader
        title={t("caIssuanceQueue")}
        subtitle={t("caIssuanceSubtitle")}
        back
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("caLoadingIssuance")}
        emptyTitle={t("caNothingToIssue")}
        emptyMessage={t("caNothingToIssueBody")}
      >
        {(rows) => (
          <>
            {rows.map((r) => (
              <IssuanceCard
                key={r.id}
                item={r}
                onDone={(status) => q.setData(rows.map((x) => (x.id === r.id ? { ...x, status } : x)))}
              />
            ))}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

function IssuanceCard({ item, onDone }: { item: CarrierQueueItem; onDone: (status: string) => void }) {
  const { t } = useTranslation();
  const [carrierReference, setCarrierReference] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState<"approve" | "reject" | null>(null);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  const pending = PENDING.includes(item.status);
  return (
    <Card>
      <View style={s.row}>
        <Text style={s.title}>{item.reference}</Text>
        <StatusChip label={humanize(item.status)} tone={pending ? "warning" : item.status === "APPROVED" ? "success" : "neutral"} />
      </View>
      <Text style={s.meta}>
        {item.subject} · {shortDate(item.submitted_at)}
      </Text>
      {pending ? (
        <>
          {/* Policy numbers are always allocated by the server; the carrier may add its own reference. */}
          <Text style={s.meta}>{t("caPolicyNumberByServer")}</Text>
          <TextField
            label={t("caCarrierReferenceOptional")}
            value={carrierReference}
            onChangeText={setCarrierReference}
            autoCapitalize="characters"
            maxLength={64}
          />
          <Button
            label={t("caApproveIssue")}
            loading={busy === "approve"}
            onPress={async () => {
              setBusy("approve");
              setMsg(null);
              try {
                const r = await CarrierWorkspaceApi.approveIssuance(item.id, carrierReference.trim() ? { carrier_reference: carrierReference.trim() } : {});
                setMsg({ text: t("caPolicyIssued", { number: r.policy_number }), tone: "ok" });
                onDone(r.status);
              } catch (e) {
                setMsg({ text: errorMessage(e), tone: "error" });
              } finally {
                setBusy(null);
              }
            }}
          />
          <TextField label={t("caReasonRejection")} value={reason} onChangeText={setReason} multiline />
          <Button
            label={t("settleReject")}
            variant="danger"
            loading={busy === "reject"}
            disabled={reason.trim().length < 5}
            onPress={async () => {
              setBusy("reject");
              setMsg(null);
              try {
                const r = await CarrierWorkspaceApi.rejectIssuance(item.id, reason.trim());
                setMsg({ text: t("caIssuanceRejected"), tone: "ok" });
                onDone(r.status);
              } catch (e) {
                setMsg({ text: errorMessage(e), tone: "error" });
              } finally {
                setBusy(null);
              }
            }}
          />
        </>
      ) : null}
      <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />
    </Card>
  );
}

const s = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x2 },
  title: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  meta: { ...type.meta, color: colors.neutral600 },
});
