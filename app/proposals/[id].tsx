import React, { useCallback, useEffect, useRef, useState } from "react";
import { Alert, Linking, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Camera, CheckCircle2, FileText, FileUp, Hourglass, Images, MessageSquareWarning, RefreshCcw, Undo2, XCircle } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, DetailRow, SectionHeading } from "@/components/design";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { allowedAction } from "@/lib/capabilities";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard, InfoRow, QuoteSteps, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProposalSummary } from "@/components/purchase/ProposalSummary";
import { Proposal, ProposalsApi, SupportContactsApi } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { humanize, localized, proposalStatusInfo } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import { ProposalChecklist, ProposalLifecycleApi } from "@/api/workflow";
import { canWithdrawProposal, requiredDocumentInfo } from "@/lib/quoteWorkflow";
import { CustomerApi } from "@/api/customer";
import { pickUpload, storeDocument, type PickSource } from "@/api/documentUpload";
import { useLoad } from "@/hooks/useLoad";
import { kycAutoAttachments } from "@/lib/proposalDocuments";
import { canChooseStart } from "@/lib/coverStart";
import { CoverStartCard } from "@/components/purchase/CoverStartCard";

/** ProposalMachine::ANSWERABLE — cover terms can only be changed while the application is being completed. */
const ANSWERABLE = ["DRAFT", "DISCLOSURES_PENDING", "DOCUMENTS_PENDING", "INFORMATION_REQUIRED"];

type Requirement = { code: string; label: string; mandatory: boolean; status?: string; notes?: string | null; form: boolean };

function requirementsOf(p: Proposal, language: string, checklist?: ProposalChecklist | null): Requirement[] {
  // The Batch 6 checklist carries each requirement's own status; older payloads only list them.
  // The catalogue can list one code twice (variants): one row per code.
  const listed: Requirement[] = [];
  for (const r of checklist?.required_documents?.length ? checklist.required_documents : p.required_documents ?? []) {
    if (listed.some((x) => x.code === r.code)) continue;
    listed.push({
      code: r.code,
      label: r.label ?? (localized(r.name, language) || humanize(r.code)),
      mandatory: r.mandatory !== false,
      status: r.status && !["MISSING", "REQUIRED"].includes(String(r.status).toUpperCase()) ? String(r.status) : undefined,
      form: String(r.satisfied_by ?? "").toUpperCase() === "PROPOSAL_FORM",
    });
  }
  for (const d of p.documents ?? []) {
    const hit = listed.find((r) => r.code === d.requirement_code);
    if (hit) Object.assign(hit, { status: hit.status ?? d.status, notes: d.review_notes });
    else listed.push({ code: d.requirement_code, label: humanize(d.requirement_code), mandatory: true, status: d.status, notes: d.review_notes, form: false });
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
  const [choosing, setChoosing] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
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

  // Take a photo, pick one from the gallery, or choose a PDF/image file; then link it to the requirement.
  const upload = async (code: string, source: PickSource) => {
    if (!p || uploading) return;
    setUploadError(null);
    setNotice(null);
    setUploading(code);
    try {
      const file = await pickUpload(source);
      if (!file) return;
      const documentId = await storeDocument(`PROPOSAL_${code}`, file);
      await ProposalsApi.linkDocument(p.id, documentId, code);
      setChoosing(null);
      await load();
    } catch (e) {
      setUploadError(e);
    } finally {
      setUploading(null);
    }
  };

  // A verified ID on file is linked automatically to any identity requirement (once per screen visit).
  const kyc = useLoad(() => CustomerApi.kyc().catch(() => null), []);
  const autoTried = useRef(false);
  useEffect(() => {
    if (!p || !kyc.data || autoTried.current || !allowedAction(p, "attach_document", true)) return;
    const rows = (checklist?.required_documents?.length ? checklist.required_documents : p.required_documents ?? []).map((r) => ({ code: r.code, status: r.status, satisfied_by: r.satisfied_by }));
    const links = kycAutoAttachments(rows, kyc.data);
    if (!rows.length) return;
    autoTried.current = true;
    if (!links.length) return;
    void (async () => {
      let linked = 0;
      for (const l of links) {
        try {
          await ProposalsApi.linkDocument(p.id, l.document_id, l.requirement_code);
          linked++;
        } catch {
          // The server decides what a document may satisfy; a refused link simply leaves the upload button.
        }
      }
      if (linked) {
        setNotice(t("prIdAutoAttached"));
        await load();
      }
    })();
  }, [p, checklist, kyc.data, load, t]);

  // Counter-offer: accepting moves the application to payment on the revised terms; declining closes it.
  const [answering, setAnswering] = useState<"accept" | "decline" | null>(null);
  const [answerError, setAnswerError] = useState<unknown>(null);
  const answerCounter = async (answer: "accept" | "decline") => {
    if (!p || answering) return;
    setAnswering(answer);
    setAnswerError(null);
    try {
      await ProposalsApi.respondCounteroffer(p.id, answer);
      await load();
    } catch (e) {
      setAnswerError(e);
    } finally {
      setAnswering(null);
    }
  };
  const declineCounter = () =>
    Alert.alert(t("prCounterDeclineQ"), t("prCounterDeclineBody"), [
      { text: t("cancel"), style: "cancel" },
      { text: t("prCounterDecline"), style: "destructive", onPress: () => void answerCounter("decline") },
    ]);

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
  // allowed_actions (when the server sends it) narrows the local stage rules.
  const canUpload = allowedAction(p, "attach_document", info.stage === "documents" || info.stage === "information");

  const decision = p?.underwriting_case?.decisions?.[p.underwriting_case.decisions.length - 1];
  const reqs = p ? requirementsOf(p, f.language, checklist) : [];

  const primary = p ? (
    p?.policy_id ? (
      <Button label={t("draftsViewPolicy")} icon={CheckCircle2} onPress={() => router.push({ pathname: "/policy/[id]", params: { id: p.policy_id! } })} />
    ) : info.stage === "disclosures" && allowedAction(p, "answer_disclosures", true) ? (
      <Button label={t("prAnswer")} onPress={() => router.push({ pathname: "/quote/questions", params: { proposalId: p.id } })} />
    ) : info.stage === "payable" ? (
      <Button label={t("prReviewPay")} icon={CheckCircle2} onPress={() => router.push({ pathname: "/quote/terms", params: { proposalId: p.id } })} />
    ) : info.stage === "paid" ? (
      <Button label={t("prTrackIssuance")} onPress={() => router.push({ pathname: "/confirmation", params: { proposalId: p.id } })} />
    ) : info.stage === "review" || info.stage === "documents" ? (
      <Button label={t("pmRefresh")} icon={RefreshCcw} variant="secondary" loading={loading} onPress={() => void load()} />
    ) : null
  ) : null;

  return (
    <Screen
      footer={
        p ? (
          <CtaBar>
            {primary}
            <Button label={t("prAll")} variant="tertiary" onPress={() => router.push("/proposals")} />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("prTitle")} subtitle={p?.proposal_number} />
      <QuoteSteps current={3} />
      {loading && !p ? <LoadingState label={t("prLoading")} /> : null}
      {error && !p ? <ErrorCard error={error} fallback={t("prLoadFailed")} onRetry={() => void load()} /> : null}
      {p ? (
        <>
          <Card>
            <SectionHeading title={t("prStatus")} right={<StatusChip label={info.label} tone={info.tone} />} />
            <Text style={ps.body}>{info.message}</Text>
            {info.stage === "review" ? <Banner icon={Hourglass} tint="blue" body={t("prAutoCheck")} /> : null}
          </Card>

          {info.stage === "information" ? (
            <Card>
              <SectionHeading icon={MessageSquareWarning} title={t("prInfoTitle")} />
              {checklist?.information_request?.message ? <Text style={ps.body}>{checklist.information_request.message}</Text> : null}
              {(checklist?.information_request?.items ?? []).map((it, i) => (
                <Text key={it.code ?? i} style={ps.meta}>• {it.description}</Text>
              ))}
              <Button label={t("prInfoOpen")} onPress={() => router.push({ pathname: "/proposals/[id]/information", params: { id: p.id } })} />
            </Card>
          ) : null}

          {info.stage === "counteroffer" ? (
            <Card>
              <SectionHeading title={t("prRevised")} />
              {p.counteroffer?.total_minor ? <InfoRow label={t("prRevisedTotal")} value={f.xaf(p.counteroffer.total_minor)} strong /> : null}
              <InfoRow label={t("prOriginalTotal")} value={f.xaf(p.terms_snapshot?.total_minor)} />
              {decision?.notes || p.counteroffer?.notes ? <Text style={ps.body}>{p.counteroffer?.notes ?? decision?.notes}</Text> : null}
              <Text style={ps.meta}>{t("prCounterNote")}</Text>
              <Button label={t("prCounterAccept")} icon={CheckCircle2} loading={answering === "accept"} disabled={!!answering} onPress={() => void answerCounter("accept")} />
              <Button label={t("prCounterDecline")} variant="secondary" loading={answering === "decline"} disabled={!!answering} onPress={declineCounter} />
              {answerError ? <ErrorCard error={answerError} fallback={t("prCounterFailed")} /> : null}
              <Button label={t("prContactQuestions")} variant="tertiary" onPress={() => void contactSupport()} />
              {quote ? <Button label={t("prCompareOthers")} variant="tertiary" onPress={() => router.replace("/quote/offers")} /> : null}
            </Card>
          ) : null}

          {info.stage === "declined" ? (
            <Card>
              <Banner icon={XCircle} tint="red" title={t("prDeclined")} body={decision?.notes ?? undefined} />
              {quote ? <Button label={t("prCompareOthers")} onPress={() => router.replace("/quote/offers")} /> : null}
              <Button label={t("prNewQuote")} variant="secondary" onPress={() => router.replace("/quote/product")} />
            </Card>
          ) : null}

          {checklist?.cover_term_rule && canChooseStart(checklist.cover_term_rule.effective_date_rules) && ANSWERABLE.includes(String(p.status).toUpperCase()) ? (
            <CoverStartCard proposalId={p.id} rule={checklist.cover_term_rule} terms={checklist.cover_terms} onSaved={() => void load()} />
          ) : null}

          <ProposalSummary proposal={p} offer={selectedOffer} />

          {canUpload || reqs.length ? (
            <Card>
              <SectionHeading icon={FileText} title={t("prRequiredDocs")} />
              {!reqs.length ? <Text style={ps.meta}>{t("prNoDocs")}</Text> : null}
              {reqs.map((r) => {
                const doc = requiredDocumentInfo(r.status);
                const ok = ["VERIFIED", "ACCEPTED"].includes(String(r.status).toUpperCase());
                const rejected = doc.tone === "danger";
                return (
                  <View key={r.code} style={st.req}>
                    <DetailRow label={r.mandatory ? r.label : `${r.label} ${t("prDocOptional")}`} valueNode={<StatusChip label={td(doc.key, r.status ?? "")} tone={doc.tone} />} />
                    {r.notes ? <Text style={ps.meta}>{r.notes}</Text> : null}
                    {r.form ? (
                      !ok ? <Text style={ps.meta}>{t("prDocByForm")}</Text> : null
                    ) : !ok && canUpload ? (
                      choosing === r.code ? (
                        <View style={st.choices}>
                          <Button label={t("prTakePhoto")} icon={Camera} variant="secondary" loading={uploading === r.code} disabled={!!uploading} onPress={() => void upload(r.code, "camera")} />
                          <Button label={t("evidenceFromLibrary")} icon={Images} variant="secondary" disabled={!!uploading} onPress={() => void upload(r.code, "library")} />
                          <Button label={t("prChooseFile")} icon={FileUp} variant="tertiary" disabled={!!uploading} onPress={() => void upload(r.code, "file")} />
                          <Button label={t("cancel")} variant="tertiary" disabled={!!uploading} onPress={() => setChoosing(null)} />
                        </View>
                      ) : (
                        <Button label={r.status && !rejected ? t("prReplace") : t("prUpload")} icon={FileUp} variant="secondary" disabled={!!uploading} onPress={() => setChoosing(r.code)} />
                      )
                    ) : null}
                  </View>
                );
              })}
              {notice ? <Text accessibilityLiveRegion="polite" style={st.notice}>{notice}</Text> : null}
              {uploadError ? <Text accessibilityRole="alert" style={st.error}>{uploadError instanceof Error ? uploadError.message : t("prUploadFailed")}</Text> : null}
            </Card>
          ) : null}

          {allowedAction(p, "withdraw", canWithdrawProposal(p.status, checklist?.available_transitions)) ? (
            <Button label={t("prWithdraw")} icon={Undo2} variant="danger" loading={withdrawing} disabled={withdrawing} onPress={withdraw} />
          ) : null}
          {withdrawError ? <ErrorCard error={withdrawError} fallback={t("prWithdrawFailed")} /> : null}
        </>
      ) : null}
    </Screen>
  );
}

const st = StyleSheet.create({
  req: { gap: space.x1, paddingVertical: space.x1, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.neutral200 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
  choices: { gap: space.x2 },
});
