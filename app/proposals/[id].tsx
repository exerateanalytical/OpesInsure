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

/** Reads a picked file as base64 without extra native modules. */
async function readAsBase64(uri: string): Promise<string> {
  const blob = await (await fetch(uri)).blob();
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("The file could not be read."));
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

  const info = proposalStatusInfo(p?.status);
  // Waiting on an underwriter: refresh quietly every 30 s.
  useEffect(() => {
    if (info.stage !== "review") return;
    const t = setInterval(() => void loadProposal(id!).then(setP).catch(() => undefined), 30000);
    return () => clearInterval(t);
  }, [id, info.stage, loadProposal]);

  const upload = async (code: string) => {
    if (!p || uploading) return;
    setUploadError(null);
    const picked = await DocumentPicker.getDocumentAsync({ type: ["image/jpeg", "image/png", "application/pdf"], copyToCacheDirectory: true });
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
      <AppHeader title="My application" subtitle={p?.proposal_number} back />
      {loading && !p ? <LoadingState label="Loading application…" /> : null}
      {error && !p ? <ErrorCard error={error} fallback="This application could not be loaded." onRetry={() => void load()} /> : null}
      {p ? (
        <>
          <Card feature>
            <StatusChip label={info.label} tone={info.tone} />
            <Text style={ps.body}>{info.message}</Text>
            {info.stage === "review" ? (
              <View style={ps.row}>
                <Hourglass size={16} color={colors.blue600} />
                <Text style={ps.meta}>Checked automatically every 30 seconds. Usual decision time: 2 business days.</Text>
              </View>
            ) : null}
          </Card>

          {info.stage === "counteroffer" ? (
            <Card>
              <Text style={ps.title}>Revised terms from the insurer</Text>
              {p.counteroffer?.total_minor ? <InfoRow label="Revised total" value={f.xaf(p.counteroffer.total_minor)} strong /> : null}
              <InfoRow label="Original total" value={f.xaf(p.terms_snapshot?.total_minor)} />
              {decision?.notes || p.counteroffer?.notes ? <Text style={ps.body}>{p.counteroffer?.notes ?? decision?.notes}</Text> : null}
              <Text style={ps.meta}>Accepting revised terms is done with our support team so the change is recorded against your application.</Text>
              <Button label="Contact support to accept" onPress={() => void contactSupport()} />
              {quote ? <Button label="Compare other offers" variant="secondary" onPress={() => router.replace("/quote/offers")} /> : null}
            </Card>
          ) : null}

          {info.stage === "declined" ? (
            <Card>
              <XCircle size={28} color={colors.dangerText} />
              <Text style={ps.title}>Application declined</Text>
              {decision?.notes ? <Text style={ps.body}>{decision.notes}</Text> : null}
              {quote ? <Button label="Compare other offers" onPress={() => router.replace("/quote/offers")} /> : null}
              <Button label="Start a new quote" variant="secondary" onPress={() => router.replace("/quote/product")} />
            </Card>
          ) : null}

          <ProposalSummary proposal={p} offer={selectedOffer} />

          {info.stage === "documents" || reqs.length ? (
            <Card>
              <Text style={ps.title}>Required documents</Text>
              {!reqs.length ? <Text style={ps.meta}>The insurer has not requested specific documents. Upload any supporting document if asked by support.</Text> : null}
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
                      <StatusChip label={ok ? "Verified" : rejected ? "Rejected" : r.status ? "Received" : "Missing"} tone={ok ? "success" : rejected ? "danger" : r.status ? "info" : "warning"} />
                    </View>
                    {r.notes ? <Text style={ps.meta}>{r.notes}</Text> : null}
                    {!ok && info.stage === "documents" ? (
                      <Button label={r.status && !rejected ? "Replace file" : "Upload"} icon={FileUp} variant="secondary" loading={uploading === r.code} disabled={!!uploading} onPress={() => void upload(r.code)} />
                    ) : null}
                  </View>
                );
              })}
              {uploadError ? <Text style={ps.error}>{uploadError instanceof Error ? uploadError.message : "Upload failed."}</Text> : null}
            </Card>
          ) : null}

          {info.stage === "disclosures" ? (
            <Button label="Answer the insurer’s questions" onPress={() => router.push({ pathname: "/quote/questions", params: { proposalId: p.id } })} />
          ) : null}
          {info.stage === "payable" ? (
            <Button label="Review terms and pay" icon={CheckCircle2} onPress={() => router.push({ pathname: "/quote/terms", params: { proposalId: p.id } })} />
          ) : null}
          {info.stage === "paid" ? (
            <Button label="Track policy issuance" onPress={() => router.push({ pathname: "/confirmation", params: { proposalId: p.id } })} />
          ) : null}
          {info.stage === "review" || info.stage === "documents" ? (
            <Button label="Refresh status" icon={RefreshCcw} variant="secondary" loading={loading} onPress={() => void load()} />
          ) : null}
          <Button label="All my applications" variant="tertiary" onPress={() => router.push("/proposals")} />
        </>
      ) : null}
    </Screen>
  );
}
