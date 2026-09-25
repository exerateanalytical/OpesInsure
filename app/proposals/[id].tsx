import React, { useCallback, useEffect, useState } from "react";
import { Alert, Linking, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as DocumentPicker from "expo-document-picker";
import { CheckCircle2, FileUp, Hourglass, MessageSquareWarning, RefreshCcw, Undo2, XCircle } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard, InfoRow, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProposalSummary } from "@/components/purchase/ProposalSummary";
import { Proposal, ProposalsApi, SupportContactsApi } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { humanize, localized, proposalStatusInfo } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors } from "@/theme/tokens";
import { translateNow, useTranslation } from "@/i18n";
import { withoutRelock } from "@/lib/appLock";
import { ProposalChecklist, ProposalLifecycleApi } from "@/api/workflow";
import { canWithdrawProposal, requiredDocumentInfo } from "@/lib/quoteWorkflow";

/** Reads a picked file as base64 without extra native modules. */
async function readAsBase64(uri: string): Promise<string> {
  const blob = await (await fetch(uri)).blob();
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error(translateNow("prFileUnreadable")));
    reader.onloadend = () => resolve(String(reader.result ?? "").replace(/^data:[^,]*,/, ""));
    reader.readAsDataURL(blob);
  });
}

type Requirement = { code: string; label: string; mandatory: boolean; status?: string; notes?: string | null };

function requirementsOf(p: Proposal, language: string, checklist?: ProposalChecklist | null): Requirement[] {
  // The Batch 6 checklist carries each requirement's own status; older payloads only list them.
  const listed: Requirement[] = (checklist?.required_documents?.length ? checklist.required_documents : p.required_documents ?? []).map((r) => ({
    code: r.code,
    label: r.label ?? (localized(r.name, language) || humanize(r.code)),
    mandatory: r.mandatory !== false,
    status: r.status && !["MISSING", "REQUIRED"].includes(String(r.status).toUpperCase()) ? String(r.status) : undefined,
  }));
  for (const d of p.documents ?? []) {
    const hit = listed.find((r) => r.code === d.requirement_code);
    if (hit) Object.assign(hit, { status: hit.status ?? d.status, notes: d.review_notes });
    else listed.push({ code: d.requirement_code, label: humanize(d.requirement_code), mandatory: true, status: d.status, notes: d.review_notes });
  }
  return listed;
}

/** "My application" hub: review, documents, underwriting states, and the way to pay. */
export default function ProposalDetail() {
  const { t, td } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const loadProposal = useInsurance((s) => s.loadProposal);
  const selectedOffer = useInsurance((s) => s.selectedOffer);
  const quote = useInsurance((s) => s.quote);
  const f = useFormatters();
  const [p, setP] = useState<Proposal | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const [uploading, setUploading] = useState<string | null>(null);
  const [uploadError, setUploadError] = useState<unknown>(null);
  const [checklist, setChecklist] = useState<ProposalChecklist | null>(null);
  const [withdrawing, setWithdrawing] = useState(false);
  const [withdrawError, setWithdrawError] = useState<unknown>(null);

  const load = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    setError(null);
    try {
      setP(await loadProposal(id));
      // Optional (Batch 6): document statuses, information request and allowed transitions.
      setChecklist(await ProposalLifecycleApi.checklist(id).catch(() => null));
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  }, [id, loadProposal]);
  useEffect(() => {
    void load();
  }, [load]);

  const info = proposalStatusInfo(p?.status, f.language);
  // Waiting on an underwriter: refresh quietly every 30 s.
  useEffect(() => {
    if (info.stage !== "review") return;
    const t = setInterval(() => void loadProposal(id!).then(setP).catch(() => undefined), 30000);
    return () => clearInterval(t);
  }, [id, info.stage, loadProposal]);

  const upload = async (code: string) => {
    if (!p || uploading) return;
    setUploadError(null);
    const picked = await withoutRelock(() => DocumentPicker.getDocumentAsync({ type: ["image/jpeg", "image/png", "application/pdf"], copyToCacheDirectory: true }));
    const asset = picked.canceled ? null : picked.assets?.[0];
    if (!asset) return;
    setUploading(code);
    try {
      const mime = asset.mimeType ?? (asset.name.toLowerCase().endsWith(".pdf") ? "application/pdf" : "image/jpeg");
      await ProposalsApi.uploadDocument(p.id, { requirement_code: code, mime_type: mime, file_base64: await readAsBase64(asset.uri) });
      await load();
    } catch (e) {
      setUploadError(e);
    } finally {
      setUploading(null);
    }
  };

  const contactSupport = async () => {
    const c = await SupportContactsApi.get();
    if (c?.whatsapp_url) return Linking.openURL(c.whatsapp_url);
    if (c?.phone) return Linking.openURL(`tel:${c.phone}`);
    router.push("/support/new");
  };

  const withdraw = () =>
    Alert.alert(t("prWithdrawQ"), t("prWithdrawBody"), [
      { text: t("cancel"), style: "cancel" },
      {
        text: t("prWithdraw"),
        style: "destructive",
        onPress: async () => {
          setWithdrawing(true);
          setWithdrawError(null);
          try {
            await ProposalLifecycleApi.withdraw(p!.id);
            await load();
          } catch (e) {
            setWithdrawError(e);
          } finally {
            setWithdrawing(false);
          }
        },
      },
    ]);
  const canUpload = info.stage === "documents" || info.stage === "information";

  const decision = p?.underwriting_case?.decisions?.[p.underwriting_case.decisions.length - 1];
  const reqs = p ? requirementsOf(p, f.language, checklist) : [];

  return (
    <Screen>
      <AppHeader title={t("prTitle")} subtitle={p?.proposal_number} back />
      {loading && !p ? <LoadingState label={t("prLoading")} /> : null}
      {error && !p ? <ErrorCard error={error} fallback={t("prLoadFailed")} onRetry={() => void load()} /> : null}
      {p ? (
        <>
          <Card feature>
            <StatusChip label={info.label} tone={info.tone} />
            <Text style={ps.body}>{info.message}</Text>
            {info.stage === "review" ? (
              <View style={ps.row}>
                <Hourglass size={16} color={colors.blue600} />
                <Text style={ps.meta}>{t("prAutoCheck")}</Text>
              </View>
            ) : null}
          </Card>

          {info.stage === "information" ? (
            <Card>
              <View style={ps.row}>
                <MessageSquareWarning size={20} color={colors.warningText} />
                <Text style={ps.title}>{t("prInfoTitle")}</Text>
              </View>
              {checklist?.information_request?.message ? <Text style={ps.body}>{checklist.information_request.message}</Text> : null}
              {(checklist?.information_request?.items ?? []).map((it, i) => (
                <Text key={it.code ?? i} style={ps.meta}>• {it.description}</Text>
              ))}
              <Button label={t("prInfoOpen")} onPress={() => router.push({ pathname: "/proposals/[id]/information", params: { id: p.id } })} />
            </Card>
          ) : null}

          {info.stage === "counteroffer" ? (
            <Card>
              <Text style={ps.title}>{t("prRevised")}</Text>
              {p.counteroffer?.total_minor ? <InfoRow label={t("prRevisedTotal")} value={f.xaf(p.counteroffer.total_minor)} strong /> : null}
              <InfoRow label={t("prOriginalTotal")} value={f.xaf(p.terms_snapshot?.total_minor)} />
              {decision?.notes || p.counteroffer?.notes ? <Text style={ps.body}>{p.counteroffer?.notes ?? decision?.notes}</Text> : null}
              <Text style={ps.meta}>{t("prAcceptNote")}</Text>
              <Button label={t("prContactAccept")} onPress={() => void contactSupport()} />
              {quote ? <Button label={t("prCompareOthers")} variant="secondary" onPress={() => router.replace("/quote/offers")} /> : null}
            </Card>
          ) : null}

          {info.stage === "declined" ? (
            <Card>
              <XCircle size={28} color={colors.dangerText} />
              <Text style={ps.title}>{t("prDeclined")}</Text>
              {decision?.notes ? <Text style={ps.body}>{decision.notes}</Text> : null}
              {quote ? <Button label={t("prCompareOthers")} onPress={() => router.replace("/quote/offers")} /> : null}
              <Button label={t("prNewQuote")} variant="secondary" onPress={() => router.replace("/quote/product")} />
            </Card>
          ) : null}

          <ProposalSummary proposal={p} offer={selectedOffer} />

          {canUpload || reqs.length ? (
            <Card>
              <Text style={ps.title}>{t("prRequiredDocs")}</Text>
              {!reqs.length ? <Text style={ps.meta}>{t("prNoDocs")}</Text> : null}
              {reqs.map((r) => {
                const doc = requiredDocumentInfo(r.status);
                const ok = ["VERIFIED", "ACCEPTED"].includes(String(r.status).toUpperCase());
                const rejected = doc.tone === "danger";
                return (
                  <View key={r.code} style={{ gap: 4 }}>
                    <View style={ps.between}>
                      <Text style={[ps.body, { flex: 1 }]}>
                        {r.label}
                        {r.mandatory ? "" : " (optional)"}
                      </Text>
                      <StatusChip label={td(doc.key, r.status ?? "")} tone={doc.tone} />
                    </View>
                    {r.notes ? <Text style={ps.meta}>{r.notes}</Text> : null}
                    {!ok && canUpload ? (
                      <Button label={r.status && !rejected ? t("prReplace") : t("prUpload")} icon={FileUp} variant="secondary" loading={uploading === r.code} disabled={!!uploading} onPress={() => void upload(r.code)} />
                    ) : null}
                  </View>
                );
              })}
              {uploadError ? <Text style={ps.error}>{uploadError instanceof Error ? uploadError.message : t("prUploadFailed")}</Text> : null}
            </Card>
          ) : null}

          {info.stage === "disclosures" ? (
            <Button label={t("prAnswer")} onPress={() => router.push({ pathname: "/quote/questions", params: { proposalId: p.id } })} />
          ) : null}
          {info.stage === "payable" ? (
            <Button label={t("prReviewPay")} icon={CheckCircle2} onPress={() => router.push({ pathname: "/quote/terms", params: { proposalId: p.id } })} />
          ) : null}
          {info.stage === "paid" ? (
            <Button label={t("prTrackIssuance")} onPress={() => router.push({ pathname: "/confirmation", params: { proposalId: p.id } })} />
          ) : null}
          {info.stage === "review" || info.stage === "documents" ? (
            <Button label={t("pmRefresh")} icon={RefreshCcw} variant="secondary" loading={loading} onPress={() => void load()} />
          ) : null}
          {canWithdrawProposal(p.status, checklist?.available_transitions) ? (
            <Button label={t("prWithdraw")} icon={Undo2} variant="danger" loading={withdrawing} disabled={withdrawing} onPress={withdraw} />
          ) : null}
          {withdrawError ? <ErrorCard error={withdrawError} fallback={t("prWithdrawFailed")} /> : null}
          <Button label={t("prAll")} variant="tertiary" onPress={() => router.push("/proposals")} />
        </>
      ) : null}
    </Screen>
  );
}
