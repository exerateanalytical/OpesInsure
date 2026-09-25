import React, { useCallback, useEffect, useState } from "react";
import { Linking, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as DocumentPicker from "expo-document-picker";
import { CheckCircle2, FileUp, Hourglass, RefreshCcw, XCircle } from "lucide-react-native";
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

function requirementsOf(p: Proposal, language: string): Requirement[] {
  const listed: Requirement[] = (p.required_documents ?? []).map((r) => ({
    code: r.code,
    label: r.label ?? (localized(r.name, language) || humanize(r.code)),
    mandatory: r.mandatory !== false,
  }));
  for (const d of p.documents ?? []) {
    const hit = listed.find((r) => r.code === d.requirement_code);
    if (hit) Object.assign(hit, { status: d.status, notes: d.review_notes });
    else listed.push({ code: d.requirement_code, label: humanize(d.requirement_code), mandatory: true, status: d.status, notes: d.review_notes });
  }
  return listed;
}

/** "My application" hub: review, documents, underwriting states, and the way to pay. */
export default function ProposalDetail() {
  const { t } = useTranslation();
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

  const load = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    setError(null);
    try {
      setP(await loadProposal(id));
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

  const decision = p?.underwriting_case?.decisions?.[p.underwriting_case.decisions.length - 1];
  const reqs = p ? requirementsOf(p, f.language) : [];

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

          {info.stage === "documents" || reqs.length ? (
            <Card>
              <Text style={ps.title}>{t("prRequiredDocs")}</Text>
              {!reqs.length ? <Text style={ps.meta}>{t("prNoDocs")}</Text> : null}
              {reqs.map((r) => {
                const ok = r.status === "VERIFIED";
                const rejected = r.status === "REJECTED";
                return (
                  <View key={r.code} style={{ gap: 4 }}>
                    <View style={ps.between}>
                      <Text style={[ps.body, { flex: 1 }]}>
                        {r.label}
                        {r.mandatory ? "" : " (optional)"}
                      </Text>
                      <StatusChip label={ok ? t("prDocVerified") : rejected ? t("prDocRejected") : r.status ? t("prDocReceived") : t("prDocMissing")} tone={ok ? "success" : rejected ? "danger" : r.status ? "info" : "warning"} />
                    </View>
                    {r.notes ? <Text style={ps.meta}>{r.notes}</Text> : null}
                    {!ok && info.stage === "documents" ? (
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
          <Button label={t("prAll")} variant="tertiary" onPress={() => router.push("/proposals")} />
        </>
      ) : null}
    </Screen>
  );
}
