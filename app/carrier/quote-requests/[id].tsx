import React, { useState } from "react";
import { Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { Play, Plus, Send, Trash2, XCircle } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { DateField, ErrorCard, InfoRow, PickerField, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { CarrierQuoteRequest, CarrierQuoteRequestsApi } from "@/api/workflow";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { buildCarrierOffer, CARRIER_DECLINE_REASONS, caseKindParts, caseWaitingState, isOpenCarrierRequest, slaChipText, slaState, slaTone, toMinor } from "@/lib/quoteWorkflow";

type Line = { code: string; label: string; amount: string };

/** One manual quote request: SLA, risk, then start / offer (premium, tax, fees, breakdown, conditions, validity) / decline. */
export default function CarrierQuoteRequestDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const f = useFormatters();
  const q = useLoad(() => CarrierQuoteRequestsApi.show(id), [id]);
  const x: CarrierQuoteRequest | undefined = q.data;
  const [mode, setMode] = useState<"offer" | "decline" | null>(null);
  const [premium, setPremium] = useState("");
  const [tax, setTax] = useState("");
  const [fee, setFee] = useState("");
  const [validUntil, setValidUntil] = useState("");
  const [lines, setLines] = useState<Line[]>([]);
  const [conditions, setConditions] = useState("");
  const [reason, setReason] = useState<string | undefined>(undefined);
  const [notes, setNotes] = useState("");
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [apiError, setApiError] = useState<unknown>(null);

  const act = async (fn: () => Promise<CarrierQuoteRequest>) => {
    setBusy(true);
    setApiError(null);
    try {
      const updated = await fn();
      q.setData({ ...x, ...updated } as CarrierQuoteRequest);
      setMode(null);
      void q.reload();
    } catch (e) {
      setApiError(e);
    } finally {
      setBusy(false);
    }
  };

  const draft = buildCarrierOffer({ premium, tax, fee, validUntil, lines, conditions: conditions.split("\n") });
  const linesSum = lines.reduce((a, l) => a + (toMinor(l.amount) ?? 0), 0);
  const total = draft.total ?? null;

  const sendOffer = () => {
    setFormError(null);
    if (!draft.ok) {
      setFormError(t(`cqrErr_${draft.error}`, { sum: f.xaf(draft.linesSum ?? linesSum), total: f.xaf(draft.total ?? 0) }));
      return;
    }
    void act(() => CarrierQuoteRequestsApi.offer(id, draft.body));
  };
  const setLine = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));

  const sla = x ? slaState(x) : null;
  const waiting = x ? caseWaitingState(x) : null;
  const kind = x ? caseKindParts(x) : null;
  const open = x ? isOpenCarrierRequest(x) : false;
  const risk = Object.entries((x?.risk_snapshot ?? {}) as Record<string, unknown>).filter(([, v]) => v !== null && typeof v !== "object");

  return (
    <Screen>
      <AppHeader title={t("cqrDetailTitle")} subtitle={x?.request_number} back />
      {!x ? (
        <StatePanel {...q} onRetry={q.reload} loadingLabel={t("cqrLoading")}>
          {() => null}
        </StatePanel>
      ) : (
        <>
          <Card feature>
            <View style={ps.between}>
              <StatusChip label={td(`cqrStatus_${x.status}`, x.status)} tone={open ? "warning" : x.status === "OFFERED" ? "success" : "neutral"} />
              {waiting ? <StatusChip label={td(`cqrWait_${waiting}`, waiting)} tone="warning" /> : null}
              {sla ? <StatusChip label={slaChipText(x, td)} tone={slaTone(sla.state)} /> : null}
            </View>
            {kind ? (
              <InfoRow
                label={t("cqrCaseKind")}
                value={[kind.family ? td(`cqrFamily_${kind.family}`, kind.family) : null, kind.subtype ? td(`cqrSubtype_${kind.subtype}`, kind.subtype) : null].filter(Boolean).join(" · ")}
              />
            ) : null}
            <InfoRow label={t("cqrRequested")} value={f.dateTime(x.requested_at)} />
            <InfoRow label={t("cqrResponseDue")} value={f.dateTime(x.response_due_at)} />
            {x.notes ? <InfoRow label={t("cqrNotes")} value={x.notes} /> : null}
          </Card>

          {risk.length ? (
            <Card>
              <Text style={ps.title}>{t("cqrRisk")}</Text>
              {risk.map(([k, v]) => (
                <InfoRow key={k} label={td(`risk_${k}`, k)} value={String(v)} />
              ))}
            </Card>
          ) : null}

          {(x.responses ?? []).length ? (
            <Card>
              <Text style={ps.title}>{t("cqrResponses")}</Text>
              {(x.responses ?? []).map((r) => (
                <View key={r.id} style={{ gap: 4 }}>
                  <InfoRow label={td(`cqrStatus_${r.response_type}`, r.response_type)} value={r.total_minor !== null ? f.xaf(r.total_minor) : td(`cqrDeclineReason_${r.decline_reason_code}`, r.decline_reason_code ?? "")} strong />
                  {(r.premium_breakdown ?? []).map((l) => (
                    <InfoRow key={l.code} label={l.label ?? l.code} value={f.xaf(l.amount_minor)} />
                  ))}
                  {(r.conditions ?? []).map((c, i) => (
                    <Text key={i} style={ps.meta}>• {c.text}</Text>
                  ))}
                  {r.valid_until ? <InfoRow label={t("cqrValidUntil")} value={f.date(r.valid_until)} /> : null}
                </View>
              ))}
            </Card>
          ) : null}

          {apiError ? <ErrorCard error={apiError} fallback={t("qwActionFailed")} /> : null}

          {x.status === "REQUESTED" ? <Button label={t("cqrStart")} icon={Play} loading={busy && !mode} disabled={busy} onPress={() => void act(() => CarrierQuoteRequestsApi.start(id))} /> : null}

          {open && mode === "offer" ? (
            <Card>
              <Text style={ps.title}>{t("cqrOfferTitle")}</Text>
              <TextField label={t("cqrPremium")} keyboardType="numeric" value={premium} onChangeText={setPremium} />
              <TextField label={t("cqrTax")} keyboardType="numeric" value={tax} onChangeText={setTax} />
              <TextField label={t("cqrFee")} keyboardType="numeric" value={fee} onChangeText={setFee} />
              <InfoRow label={t("cqrTotal")} value={total !== null ? f.xaf(total) : "—"} strong />
              <Text style={ps.title}>{t("cqrBreakdown")}</Text>
              {lines.map((l, i) => (
                <View key={i} style={{ gap: 4 }}>
                  <TextField label={t("cqrLineLabel")} value={l.label} onChangeText={(v) => setLine(i, { label: v })} />
                  <TextField label={t("cqrLineAmount")} keyboardType="numeric" value={l.amount} onChangeText={(v) => setLine(i, { amount: v })} />
                  <Button label={t("cqrRemoveLine")} icon={Trash2} variant="tertiary" onPress={() => setLines((ls) => ls.filter((_, j) => j !== i))} />
                </View>
              ))}
              {lines.length ? (
                <Text style={linesSum === total ? ps.meta : ps.error}>{t("cqrBreakdownHint", { sum: f.xaf(linesSum), total: f.xaf(total ?? 0) })}</Text>
              ) : null}
              <Button label={t("cqrAddLine")} icon={Plus} variant="secondary" onPress={() => setLines((ls) => [...ls, { code: "", label: "", amount: "" }])} />
              <TextField label={t("cqrConditions")} multiline value={conditions} onChangeText={setConditions} />
              <DateField label={t("cqrValidUntil")} value={validUntil} onChange={setValidUntil} minYear={new Date().getFullYear()} maxYear={new Date().getFullYear() + 1} />
              {formError ? <Text accessibilityRole="alert" style={ps.error}>{formError}</Text> : null}
              <Button label={t("cqrSendOffer")} icon={Send} loading={busy} disabled={busy} onPress={sendOffer} />
              <Button label={t("cancel")} variant="tertiary" disabled={busy} onPress={() => setMode(null)} />
            </Card>
          ) : null}

          {open && mode === "decline" ? (
            <Card>
              <Text style={ps.title}>{t("cqrDeclineTitle")}</Text>
              <PickerField label={t("cqrDeclineReason")} value={reason} options={CARRIER_DECLINE_REASONS.map((r) => ({ value: r, label: t(`cqrDeclineReason_${r}`) }))} onChange={setReason} />
              <TextField label={t("cqrDeclineNotes")} multiline value={notes} onChangeText={setNotes} />
              <Button label={t("cqrDeclineSend")} icon={XCircle} variant="danger" loading={busy} disabled={!reason || busy} onPress={() => reason && void act(() => CarrierQuoteRequestsApi.decline(id, reason, notes))} />
              <Button label={t("cancel")} variant="tertiary" disabled={busy} onPress={() => setMode(null)} />
            </Card>
          ) : null}

          {open && !mode ? (
            <>
              <Button label={t("cqrOfferTitle")} icon={Send} variant="secondary" disabled={busy} onPress={() => setMode("offer")} />
              <Button label={t("cqrDeclineTitle")} icon={XCircle} variant="danger" disabled={busy} onPress={() => setMode("decline")} />
            </>
          ) : null}
        </>
      )}
    </Screen>
  );
}
