import { CarrierGate } from "@/components/carrier/CarrierGate";
import React, { useState } from "react";
import { Linking, StyleSheet, Text, View } from "react-native";
import { Paperclip } from "lucide-react-native";
import { DetailSection, UnavailableSection } from "@/components/detail";
import { OperationsList } from "@/components/OperationsList";
import { useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip, TextField } from "@/components/ui";
import { ChoiceChips, errorMessage, Notice } from "@/components/portal/Workspace";
import { CarrierClaimDetail, CarrierWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { parseAmountMinor } from "@/lib/purchase";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

type Decision = "APPROVE" | "PARTIAL" | "DECLINE";

export default function CarrierClaimScreen() {
  return (
    <CarrierGate module="claims">
      <CarrierClaimScreenBody />
    </CarrierGate>
  );
}

function CarrierClaimScreenBody() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => CarrierWorkspaceApi.claim(String(id)), [id]);
  return (
    <Screen>
      <AppHeader title={t("welcomeClaim")} subtitle={t("caClaimSubtitle")} back />
      <StatePanel {...q} onRetry={q.reload} loadingLabel={t("caLoadingClaim")}>
        {(c) => <ClaimBody claim={c} onChange={(n) => q.setData(n)} />}
      </StatePanel>
    </Screen>
  );
}

function ClaimBody({ claim: c, onChange }: { claim: CarrierClaimDetail; onChange: (c: CarrierClaimDetail) => void }) {
  const { t } = useTranslation();
  const [note, setNote] = useState("");
  const [decision, setDecision] = useState<Decision | null>(null);
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [rationale, setRationale] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  const can = (a: CarrierClaimDetail["actions"][number]) => c.actions.includes(a);
  const run = async (key: string, fn: () => Promise<CarrierClaimDetail>, ok: string) => {
    setBusy(key);
    setMsg(null);
    try {
      onChange(await fn());
      setMsg({ text: ok, tone: "ok" });
    } catch (e) {
      setMsg({ text: errorMessage(e), tone: "error" });
    } finally {
      setBusy(null);
    }
  };
  // Locale-aware ("1 000,50" in FR); null when not a valid number.
  const amountMinor = parseAmountMinor(amount) ?? 0;
  const decisionReady =
    decision !== null &&
    reason.trim().length > 1 &&
    rationale.trim().length >= 5 &&
    (decision === "DECLINE" || amountMinor > 0);

  return (
    <>
      <Card>
        <View style={s.row}>
          <Text style={s.title}>{c.claim_number}</Text>
          <StatusChip label={humanize(c.status)} tone="info" />
        </View>
        <Text style={s.meta}>
          {c.customer_name} · policy {c.policy_number ?? "—"}
        </Text>
        <Text style={s.meta}>
          Loss {shortDate(c.loss_occurred_at)}
          {c.loss_location ? ` · ${c.loss_location}` : ""}
        </Text>
        {c.description ? <Text style={s.body}>{c.description}</Text> : null}
        {c.estimated_loss_minor !== null ? <Text style={s.meta}>Estimated {money(c.estimated_loss_minor)}</Text> : null}
        {c.approved_amount_minor !== null ? <Text style={s.meta}>Approved {money(c.approved_amount_minor)}</Text> : null}
      </Card>

      {can("acknowledge") ? (
        <Button
          label={t("caAcknowledgeClaim")}
          loading={busy === "ack"}
          onPress={() => run("ack", () => CarrierWorkspaceApi.acknowledgeClaim(c.id), t("caClaimAcknowledged"))}
        />
      ) : null}

      {can("request_information") ? (
        <>
          <SectionTitle title={t("caRequestInformation")} />
          <Card>
            <TextField label={t("caWhatDoYouNeed")} value={note} onChangeText={setNote} multiline />
            <Button
              label={t("caSendRequest")}
              variant="secondary"
              loading={busy === "info"}
              disabled={note.trim().length < 5}
              onPress={() =>
                run("info", () => CarrierWorkspaceApi.requestClaimInformation(c.id, note.trim()), t("caInformationRequested"))
              }
            />
          </Card>
        </>
      ) : null}

      {can("propose_decision") ? (
        <>
          <SectionTitle title={t("caProposeDecision")} />
          <Card>
            <ChoiceChips<Decision>
              label={t("trackDecision")}
              value={decision}
              onChange={setDecision}
              options={[
                { value: "APPROVE", label: t("refDecision_APPROVE") },
                { value: "PARTIAL", label: t("caPartial") },
                { value: "DECLINE", label: t("refDecision_DECLINE") },
              ]}
            />
            {decision && decision !== "DECLINE" ? (
              <TextField label={t("caAmountToPay")} keyboardType="number-pad" value={amount} onChangeText={setAmount} />
            ) : null}
            <TextField label={t("caReasonCode")} value={reason} onChangeText={setReason} autoCapitalize="characters" />
            <TextField label={t("caRationale")} value={rationale} onChangeText={setRationale} multiline />
            <Text style={s.meta}>{t("caSecondApprover")}</Text>
            <Button
              label={t("caSubmitForApproval")}
              loading={busy === "propose"}
              disabled={!decisionReady}
              onPress={() =>
                run(
                  "propose",
                  () =>
                    CarrierWorkspaceApi.proposeClaimDecision(c.id, {
                      decision: decision as Decision,
                      approved_amount_minor: decision === "DECLINE" ? 0 : amountMinor,
                      reason_code: reason.trim().toUpperCase().replace(/\s+/g, "_"),
                      rationale: rationale.trim(),
                    }),
                  t("caDecisionSubmitted"),
                )
              }
            />
          </Card>
        </>
      ) : null}

      {c.pending_decision ? (
        <>
          <SectionTitle title={t("caDecisionAwaiting")} />
          <Card>
            <Text style={s.title}>
              {humanize(c.pending_decision.decision)}
              {c.pending_decision.decision !== "DECLINE" ? ` · ${money(c.pending_decision.approved_amount_minor)}` : ""}
            </Text>
            <Text style={s.meta}>{c.pending_decision.reason_code}</Text>
            <Text style={s.body}>{c.pending_decision.rationale}</Text>
            {can("approve_decision") ? (
              <Button
                label={t("caApproveDecision")}
                loading={busy === "approve"}
                onPress={() =>
                  run(
                    "approve",
                    () => CarrierWorkspaceApi.approveClaimDecision(c.id, c.pending_decision!.id),
                    t("caDecisionApproved"),
                  )
                }
              />
            ) : (
              <Text style={s.meta}>
                {c.pending_decision.proposed_by_me
                  ? t("caYouProposed")
                  : t("caAdminMustApprove")}
              </Text>
            )}
          </Card>
        </>
      ) : null}

      <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />

      {/* CAR-007: claim file — coverage snapshot and evidence, alongside the preserved maker-checker decision flow. */}
      <DetailSection
        title={t("cdCoverageSnapshot")}
        rows={[
          [t("cdDeductible"), c.deductible_minor != null ? money(c.deductible_minor) : null],
          ...((c.deductibles ?? []).map((d) => [
            d.name ?? humanize(d.code),
            [d.deductible_minor != null ? `${t("cdDeductible")} ${money(d.deductible_minor)}` : null, d.limit_minor != null ? `${t("cdLimit")} ${money(d.limit_minor)}` : null]
              .filter(Boolean)
              .join(" · ") || null,
          ]) as [string, string | null][]),
        ]}
      />
      <ClaimEvidence claimId={c.id} />
      <UnavailableSection title={t("cdClaimFileMore")} />

      {c.timeline.length > 0 ? (
        <>
          <SectionTitle title={t("claimTimeline")} />
          <Card>
            {c.timeline.map((e, i) => (
              <Text key={`${e.occurred_at}-${i}`} style={s.meta}>
                {shortDate(e.occurred_at)} · {humanize(e.to_status)}
              </Text>
            ))}
          </Card>
        </>
      ) : null}
    </>
  );
}

function ClaimEvidence({ claimId }: { claimId: string }) {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierWorkspaceApi.claimEvidence(claimId), [claimId]);
  const [err, setErr] = useState<string | null>(null);
  const open = async (documentId: string) => {
    setErr(null);
    try {
      const r = await CarrierWorkspaceApi.claimEvidenceAccess(claimId, documentId);
      await Linking.openURL(r.url);
    } catch (e) {
      setErr(errorMessage(e));
    }
  };
  return (
    <>
      <SectionTitle title={t("cdEvidence")} />
      <StatePanel {...q} onRetry={q.reload} emptyTitle={t("cdNoEvidence")} emptyMessage={t("cdNoEvidenceBody")}>
        {(rows) => (
          <OperationsList
            icon={Paperclip}
            onPress={(docId) => {
              const r = rows.find((x) => x.document_id === docId);
              if (r?.downloadable) void open(docId);
              else setErr(t("cdEvidenceNotReady"));
            }}
            rows={rows.map((r) => ({
              id: r.document_id,
              title: humanize(r.evidence_type),
              subtitle: [r.category ? humanize(r.category) : null, r.submitted_at ? shortDate(r.submitted_at) : null, r.downloadable ? null : t("cdEvidenceNotReady")]
                .filter(Boolean)
                .join(" · "),
              status: humanize(r.status),
            }))}
          />
        )}
      </StatePanel>
      <Notice text={err} tone="error" />
    </>
  );
}

const s = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x2 },
  title: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  meta: { ...type.meta, color: colors.neutral600 },
  body: { ...type.body, color: colors.neutral700 },
});
