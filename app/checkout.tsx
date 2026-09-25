import React, { useCallback, useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Smartphone } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProposalSummary } from "@/components/purchase/ProposalSummary";
import { ProviderNotConfigured } from "@/components/purchase/ProviderNotConfigured";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { isProviderNotConfigured, proposalStatusInfo } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

export default function Checkout() {
  const { proposalId } = useLocalSearchParams<{ proposalId?: string }>();
  const proposal = useInsurance((s) => s.proposal);
  const selectedOffer = useInsurance((s) => s.selectedOffer);
  const loadProposal = useInsurance((s) => s.loadProposal);
  const request = useInsurance((s) => s.requestPayment);
  const busy = useInsurance((s) => s.busy);
  const defaultPhone = useSession((s) => s.bootstrap?.user.phone_e164 ?? "");
  const f = useFormatters();
  const { t } = useTranslation();
  const [phone, setPhone] = useState(defaultPhone);
  const [provider, setProvider] = useState<"mtn_momo" | "orange_money">("mtn_momo");
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [payError, setPayError] = useState<unknown>(null);
  const id = proposalId ?? proposal?.id;

  // Always re-read the proposal: the price and status on screen must be the server's current ones.
  const load = useCallback(async () => {
    if (!id) return setLoading(false);
    setLoading(true);
    setLoadError(null);
    try {
      await loadProposal(id);
    } catch (e) {
      setLoadError(e);
    } finally {
      setLoading(false);
    }
  }, [id, loadProposal]);
  useEffect(() => {
    void load();
  }, [load]);

  if (!id)
    return (
      <Screen>
        <AppHeader title={t("coTitle")} back />
        <Card>
          <Text style={ps.title}>{t("coNoApplication")}</Text>
          <Button label={t("myApplications")} variant="secondary" onPress={() => router.replace("/proposals")} />
        </Card>
      </Screen>
    );
  if (loading && !proposal) return <Screen><AppHeader title={t("coTitle")} back /><LoadingState label={t("coLoading")} /></Screen>;
  if (!proposal) return <Screen><AppHeader title={t("coTitle")} back /><ErrorCard error={loadError} fallback={t("coLoadFailed")} onRetry={() => void load()} /></Screen>;

  const info = proposalStatusInfo(proposal.status, f.language);
  const payable = info.stage === "payable";
  const phoneValid = /^\+237[26]\d{8}$/.test(phone);
  const pay = async () => {
    if (busy) return;
    setPayError(null);
    try {
      await request(provider, phone);
      router.replace({ pathname: "/payment", params: { proposalId: proposal.id } });
    } catch (e) {
      setPayError(e);
    }
  };

  return (
    <Screen>
      <AppHeader title={t("coTitle")} subtitle={t("coSubtitle")} back />
      {loadError ? <ErrorCard error={loadError} fallback={t("coStaleTerms")} onRetry={() => void load()} /> : null}
      <ProposalSummary proposal={proposal} offer={selectedOffer} />
      {!payable ? (
        <Card>
          <StatusChip label={info.label} tone={info.tone} />
          <Text style={ps.body}>{info.message}</Text>
          <Button label={t("coOpenApplication")} onPress={() => router.replace({ pathname: "/proposals/[id]", params: { id: proposal.id } })} />
        </Card>
      ) : (
        <>
          <Card>
            <Text style={ps.title}>{t("coNetwork")}</Text>
            <View style={st.networks}>
              {(["mtn_momo", "orange_money"] as const).map((v) => (
                <Pressable accessibilityRole="radio" accessibilityState={{ selected: provider === v }} key={v} style={[st.network, provider === v && st.selected]} onPress={() => setProvider(v)} disabled={busy}>
                  <Text style={st.networkText}>{v === "mtn_momo" ? "MTN MoMo" : "Orange Money"}</Text>
                </Pressable>
              ))}
            </View>
            <TextField label={t("coPhone")} value={phone} onChangeText={setPhone} keyboardType="phone-pad" editable={!busy} error={phone && !phoneValid ? t("coPhoneInvalid") : undefined} />
            <Text style={ps.meta}>{t("coPinNote")}</Text>
          </Card>
          {payError ? isProviderNotConfigured(payError) ? <ProviderNotConfigured error={payError} /> : <ErrorCard error={payError} fallback={t("coPayFailed")} onRetry={() => void pay()} /> : null}
          <Button label={t("coRequest", { amount: f.xaf(proposal.terms_snapshot?.total_minor) })} icon={Smartphone} loading={busy} disabled={busy || !phoneValid} onPress={() => void pay()} />
        </>
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  networks: { flexDirection: "row", gap: space.x2 },
  network: { flex: 1, minHeight: 48, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control, alignItems: "center", justifyContent: "center" },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  networkText: { ...type.label, color: colors.navy950 },
});
