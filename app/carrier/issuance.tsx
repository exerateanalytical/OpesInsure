import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { errorMessage, Notice } from "@/components/portal/Workspace";
import { CarrierApi, CarrierQueueItem } from "@/api/client";
import { CarrierWorkspaceApi, humanize, shortDate } from "@/api/partner";
import { colors, space, type } from "@/theme/tokens";

const PENDING = ["REQUESTED", "CARRIER_REVIEW", "PENDING"];

export default function Issuance() {
  const q = useLoad(() => CarrierApi.issuance(), []);
  return (
    <Screen>
      <AppHeader
        title="Issuance queue"
        subtitle="Payment and underwriting must be verified before issue"
        back
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading issuance requests…"
        emptyTitle="Nothing to issue"
        emptyMessage="Paid proposals waiting for your policy number will appear here."
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
  const [policyNumber, setPolicyNumber] = useState("");
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
          <TextField
            label="Policy number (optional — generated if blank)"
            value={policyNumber}
            onChangeText={setPolicyNumber}
            autoCapitalize="characters"
          />
          <Button
            label="Approve and issue"
            loading={busy === "approve"}
            onPress={async () => {
              setBusy("approve");
              setMsg(null);
              try {
                const r = await CarrierWorkspaceApi.approveIssuance(item.id, policyNumber.trim() ? { policy_number: policyNumber.trim() } : {});
                setMsg({ text: `Policy ${r.policy_number} issued.`, tone: "ok" });
                onDone(r.status);
              } catch (e) {
                setMsg({ text: errorMessage(e), tone: "error" });
              } finally {
                setBusy(null);
              }
            }}
          />
          <TextField label="Reason for rejection" value={reason} onChangeText={setReason} multiline />
          <Button
            label="Reject"
            variant="danger"
            loading={busy === "reject"}
            disabled={reason.trim().length < 5}
            onPress={async () => {
              setBusy("reject");
              setMsg(null);
              try {
                const r = await CarrierWorkspaceApi.rejectIssuance(item.id, reason.trim());
                setMsg({ text: "Issuance rejected.", tone: "ok" });
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
