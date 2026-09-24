import React, { useCallback, useEffect, useState } from "react";
import { Linking, Text, View } from "react-native";
import { router } from "expo-router";
import { CreditCard, Download, FileText, Phone, RefreshCcw, Settings2, ShieldAlert, Truck } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { FlowRow } from "@/components/FlowPrimitives";
import { ErrorCard, InfoRow, Rule, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { Claim, ClaimsApi, Payment, PaymentsApi, PolicyApi, SupportContactsApi, WalletApi, WalletPolicy } from "@/api/client";
import { InstitutionsApi } from "@/api/extra";
import { humanize, normalizeCoverage, openableUrl, paymentStatusInfo, policyStatusInfo, unwrapPage } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors } from "@/theme/tokens";

function insuredLabel(p: WalletPolicy): string | null {
  if (typeof p.insured_object === "string") return p.insured_object;
  if (p.insured_object && typeof p.insured_object === "object") {
    const o = p.insured_object as Record<string, unknown>;
    return [o.label, o.registration_number, o.make, o.model].filter((x) => typeof x === "string" && x).join(" · ") || null;
  }
  if (p.risk_asset) return [p.risk_asset.label, p.risk_asset.registration_number].filter(Boolean).join(" · ") || null;
  return null;
}

async function paymentsFor(policy: WalletPolicy): Promise<Payment[]> {
  const out: Payment[] = [];
  for (let page = 1; page <= 3; page++) {
    const r = await PaymentsApi.list(page);
    out.push(...r.items);
    if (!r.info.hasMore) break;
  }
  return out.filter((p) => (policy.proposal_id && p.proposal_id === policy.proposal_id) || p.policy_id === policy.id);
}

/**
 * Policy wallet detail, from the owned endpoint /mobile/wallet/policies/{id}
 * (the staff /policies/{id} route is tenant-wide). Payments and claims are
 * filtered to this policy; everything degrades to a message, never a crash.
 */
export function PolicyDetailView({ id }: { id: string }) {
  const f = useFormatters();
  const [p, setP] = useState<WalletPolicy | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const [payments, setPayments] = useState<Payment[] | null>(null);
  const [claims, setClaims] = useState<Claim[] | null>(null);
  const [certBusy, setCertBusy] = useState(false);
  const [certMessage, setCertMessage] = useState<string | null>(null);
  const [contactBusy, setContactBusy] = useState(false);

  const load = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    setError(null);
    try {
      const policy = await WalletApi.policy(id);
      setP(policy);
      paymentsFor(policy).then(setPayments).catch(() => setPayments([]));
      ClaimsApi.list()
        .then((x) => setClaims(unwrapPage<Claim>(x).items.filter((c) => c.policy_id === policy.id)))
        .catch(() => setClaims([]));
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  }, [id]);
  useEffect(() => {
    void load();
  }, [load]);

  const certificate = async () => {
    setCertBusy(true);
    setCertMessage(null);
    try {
      const cert = await PolicyApi.certificate(id);
      const url = openableUrl(cert.download_url);
      if (url) await Linking.openURL(url);
      else setCertMessage(`Certificate ${cert.serial_number ?? ""} is registered, but its PDF is not ready yet. The insurer is preparing it — check again later.`.replace("  ", " "));
    } catch (e) {
      const status = (e as { status?: number }).status;
      setCertMessage(status === 404 ? "No certificate has been issued for this policy yet. It appears here as soon as the insurer issues it." : "The certificate could not be opened. Try again.");
    } finally {
      setCertBusy(false);
    }
  };

  const contactProvider = async () => {
    if (!p) return;
    setContactBusy(true);
    try {
      const insurer = await InstitutionsApi.show(p.carrier_id).catch(() => null);
      if (insurer?.phone) return void (await Linking.openURL(`tel:${insurer.phone}`));
      const website = openableUrl(insurer?.website);
      if (website) return void (await Linking.openURL(website));
      const support = await SupportContactsApi.get();
      if (support?.whatsapp_url) return void (await Linking.openURL(support.whatsapp_url));
      if (support?.phone) return void (await Linking.openURL(`tel:${support.phone}`));
      if (support?.email) return void (await Linking.openURL(`mailto:${support.email}`));
      router.push("/support/new");
    } catch {
      router.push("/support/new");
    } finally {
      setContactBusy(false);
    }
  };

  if (loading && !p) return <Screen><AppHeader title="Policy details" back /><LoadingState label="Loading policy…" /></Screen>;
  if (!p) return <Screen><AppHeader title="Policy details" back /><ErrorCard error={error} fallback="This policy could not be loaded." onRetry={() => void load()} /></Screen>;

  const info = policyStatusInfo(p.status);
  const provider = p.carrier_name ?? p.carrier?.party?.display_name ?? "Licensed insurance carrier";
  const premium = p.premium_minor ?? p.terms_snapshot?.total_minor ?? null;
  const cover = normalizeCoverage(p.terms_snapshot?.coverage_snapshot, f.language);
  const insured = insuredLabel(p);
  const canRenew = ["active", "expired"].includes(info.bucket) && p.status !== "CANCELLATION_PENDING";
  const delivery = p.delivery ?? null;

  return (
    <Screen>
      <AppHeader title="Policy details" subtitle={p.policy_number} back />
      {error ? <ErrorCard error={error} fallback="Showing the last loaded details; refresh failed." onRetry={() => void load()} /> : null}
      <Card feature>
        <StatusChip label={info.label} tone={info.tone} />
        <Text style={ps.meta}>{provider}</Text>
        <Text style={ps.title}>{p.product_name ?? "Insurance policy"}</Text>
        {info.note ? <Text style={ps.body}>{info.note}</Text> : null}
        <Rule />
        <InfoRow label="Policy number" value={p.policy_number} />
        {p.certificate_number ? <InfoRow label="Certificate" value={p.certificate_number} /> : null}
        <InfoRow label="Cover" value={f.range(p.coverage_starts_at, p.coverage_ends_at)} />
        <InfoRow label="Premium" value={premium === null ? "—" : f.xaf(premium)} />
        {p.issued_at ? <InfoRow label="Issued" value={f.date(p.issued_at)} /> : null}
        {insured ? <InfoRow label="Insured" value={insured} /> : null}
        {cover.excessMinor !== null ? <InfoRow label="Excess" value={f.xaf(cover.excessMinor)} /> : null}
      </Card>

      <Card>
        <Text style={ps.title}>Actions</Text>
        <Button label="File a claim" icon={ShieldAlert} disabled={!info.claimable} onPress={() => router.push({ pathname: "/claim/new", params: { policyId: p.id } })} />
        {!info.claimable ? <Text style={ps.meta}>Claims can be filed only while the policy is in force.</Text> : null}
        {canRenew ? <Button label="Renew" icon={RefreshCcw} variant="secondary" onPress={() => router.push({ pathname: "/policy/[id]/renew", params: { id: p.id } })} /> : null}
        <Button label="Contact provider" icon={Phone} variant="secondary" loading={contactBusy} onPress={() => void contactProvider()} />
        <Button label="Request a policy change" icon={Settings2} variant="tertiary" onPress={() => router.push({ pathname: "/policy/[id]/service", params: { id: p.id } })} />
      </Card>

      {cover.coverages.length ? (
        <Card>
          <Text style={ps.title}>Coverage</Text>
          {cover.coverages.map((c) => (
            <InfoRow key={c.code} label={`${c.name}${c.optional ? " (optional)" : ""}`} value={c.limitMinor !== null ? f.xaf(c.limitMinor) : "Included"} />
          ))}
          {cover.exclusions.length ? <Text style={ps.meta}>Excludes: {cover.exclusions.map((e) => e.name).join(", ")}</Text> : null}
        </Card>
      ) : null}

      <Card>
        <Text style={ps.title}>Documents</Text>
        <Button label="Open verified certificate" icon={Download} variant="secondary" loading={certBusy} onPress={() => void certificate()} />
        {certMessage ? <Text style={ps.meta}>{certMessage}</Text> : null}
        {(p.documents ?? []).map((d) => {
          const url = openableUrl(d.download_url);
          return (
            <FlowRow
              key={d.id}
              icon={FileText}
              title={d.label || humanize(d.type) || "Document"}
              subtitle={d.issued_at ? f.date(d.issued_at) : undefined}
              status={d.status}
              onPress={() => (url ? void Linking.openURL(url) : router.push({ pathname: "/documents/[id]", params: { id: d.id } }))}
            />
          );
        })}
        {!(p.documents ?? []).length ? <Text style={ps.meta}>Policy documents appear here once issued.</Text> : null}
      </Card>

      {delivery ? (
        <Card>
          <View style={ps.row}>
            <Truck size={18} color={colors.blue600} />
            <Text style={ps.title}>Sticker delivery</Text>
          </View>
          <StatusChip label={humanize(delivery.status)} tone="info" />
          {delivery.tracking_code ? <InfoRow label="Tracking" value={delivery.tracking_code} /> : null}
          {delivery.id ? <Button label="Track physical sticker" variant="secondary" onPress={() => router.push({ pathname: "/delivery/[id]", params: { id: delivery.id } })} /> : null}
        </Card>
      ) : null}

      <Card>
        <Text style={ps.title}>Payments</Text>
        {payments === null ? <Text style={ps.meta}>Loading payments…</Text> : null}
        {payments?.length === 0 ? <Text style={ps.meta}>No payments linked to this policy.</Text> : null}
        {payments?.map((pay) => (
          <FlowRow key={pay.id} icon={CreditCard} title={f.xaf(pay.amount_minor)} subtitle={pay.created_at ? f.date(pay.created_at) : humanize(pay.provider)} status={paymentStatusInfo(pay.status).label} onPress={() => router.push({ pathname: "/payments/[id]", params: { id: pay.id } })} />
        ))}
      </Card>

      <Card>
        <Text style={ps.title}>Claims</Text>
        {claims === null ? <Text style={ps.meta}>Loading claims…</Text> : null}
        {claims?.length === 0 ? <Text style={ps.meta}>No claims on this policy.</Text> : null}
        {claims?.map((c) => (
          <FlowRow key={c.id} icon={ShieldAlert} title={c.claim_number ?? "Claim"} subtitle={c.incident_at ? f.date(c.incident_at) : undefined} status={humanize(c.status)} onPress={() => router.push({ pathname: "/claim/[id]", params: { id: c.id } })} />
        ))}
      </Card>
    </Screen>
  );
}
