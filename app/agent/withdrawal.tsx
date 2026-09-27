import React, { useEffect, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ArrowUpRight, CircleCheck } from "lucide-react-native";
import { TextField } from "@/components/ui";
import { SelectField } from "@/components/forms/SelectField";
import { AgentButton, AgentCard, AgentShell, HeritageAccent } from "@/components/agent";
import { AgentApi, AgentWithdrawal } from "@/api/client";
import { AgentWorkspaceApi, money } from "@/api/partner";
import { commissionTotals } from "@/components/partner/commissionFilters";
import { maskPhone, providerLabel } from "@/components/partner/agentEarnings";
import { KV, WithdrawalChip } from "@/components/partner/AgentEarningsUi";
import { useLoad } from "@/hooks/useLoad";
import { handleStepUpRequired } from "@/security/step-up";
import { useSession } from "@/store/session";
import { formatDisplayDate, useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

type Provider = "mtn_momo" | "orange_money";

/**
 * Request Withdrawal + Withdrawal Confirmation (spec v2 secondary screens).
 * The "available" figure is a display sum of the server's own rows; the
 * server re-checks the balance, the registered payout number and the step-up
 * (COMMISSION_WITHDRAWAL) before accepting anything.
 */
export default function AgentWithdrawalScreen() {
  const { t } = useTranslation();
  const ledger = useLoad(() => AgentWorkspaceApi.commissionLedger(), []);
  const available = ledger.data ? commissionTotals(ledger.data).available : null;
  const [amount, setAmount] = useState("");
  const [provider, setProvider] = useState<Provider>("mtn_momo");
  // Prefilled from the agent's registered mobile-money number (server
  // profile), falling back to the signed-in user's own phone. Never a
  // hard-coded default: a wrong prefill would pay someone else.
  const userPhone = useSession((st) => st.bootstrap?.user?.phone_e164) ?? "";
  const [phone, setPhone] = useState(userPhone);
  const [touched, setTouched] = useState(false);
  useEffect(() => {
    let alive = true;
    AgentApi.profile()
      .then((p) => {
        if (alive && !touched && p.momo_phone_e164) setPhone(p.momo_phone_e164);
      })
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, [touched]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<AgentWithdrawal>();

  if (result) {
    const x = result as AgentWithdrawal & { payout_number?: string | null; reference?: string | null };
    const ref = x.payout_number ?? x.reference ?? result.id.slice(0, 8).toUpperCase();
    return (
      <AgentShell variant="drilldown" title={t("ernWdSubmitted")} hideNav>
        <View style={s.hero}>
          <HeritageAccent variant="pattern" size={180} opacity={0.06} style={s.art} />
          <CircleCheck size={28} color={c.success} strokeWidth={1.9} />
          <Text style={s.heroTitle}>{t("ernWdSubmitted")}</Text>
          <Text style={s.amount} numberOfLines={1} adjustsFontSizeToFit>{money(result.amount_minor)}</Text>
          <WithdrawalChip status={result.status} />
        </View>
        <AgentCard>
          <KV first label={t("ernWdId")} value={ref} />
          <KV label={t("ernWdMethod")} value={providerLabel(result.provider)} />
          <KV label={t("ernWdAccount")} value={maskPhone(result.destination_phone)} />
          <KV label={t("ernWdRequested")} value={formatDisplayDate(result.requested_at, true)} />
        </AgentCard>
        <Text style={s.body}>{`${t("ernWdSubmittedBody", { ref })} ${t("wdNoFeeNotice")}`}</Text>
        <AgentButton label={t("ernWdView")} onPress={() => router.replace({ pathname: "/agent/withdrawals/[id]", params: { id: result.id } })} />
        <AgentButton variant="secondary" label={t("ernWdBack")} onPress={() => router.replace("/agent/wallet")} />
      </AgentShell>
    );
  }

  const n = Number(amount);
  const submit = async () => {
    setError(null);
    setBusy(true);
    try {
      setResult(await AgentApi.requestWithdrawal({ amount_minor: Math.round(n * 100), provider, destination_phone: phone }));
    } catch (e) {
      if (!handleStepUpRequired(e, "COMMISSION_WITHDRAWAL", "/agent/withdrawal")) setError(e instanceof Error ? e.message : t("errGeneric"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <AgentShell
      variant="drilldown"
      title={t("ernRequestWithdrawal")}
      hideNav
      footer={<AgentButton icon={ArrowUpRight} label={t("ernRequestWithdrawal")} loading={busy} disabled={!(n > 0) || phone.length < 8} onPress={() => void submit()} />}
    >
      <View style={s.hero}>
        <HeritageAccent variant="pattern" size={180} opacity={0.06} style={s.art} />
        <Text style={s.label}>{t("ernWdAvailable").toUpperCase()}</Text>
        <Text style={s.amount} numberOfLines={1} adjustsFontSizeToFit>{available === null ? "—" : money(available)}</Text>
        <Text style={s.body}>{t("ernWdAmountHint")}</Text>
      </View>
      <AgentCard style={s.form}>
        <TextField label={t("mdAmountFcfa")} keyboardType="number-pad" value={amount} onChangeText={(v) => setAmount(v.replace(/[^\d]/g, ""))} />
        <SelectField
          label={t("ernWdProvider")}
          value={provider}
          options={[
            { value: "mtn_momo", label: providerLabel("mtn_momo") },
            { value: "orange_money", label: providerLabel("orange_money") },
          ]}
          onChange={(v) => setProvider(v as Provider)}
        />
        <TextField
          label={t("ernWdPhone")}
          hint={t("agDestinationChanges")}
          keyboardType="phone-pad"
          value={phone}
          onChangeText={(v) => {
            setTouched(true);
            setPhone(v);
          }}
        />
      </AgentCard>
      {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
      <Text style={s.notice}>{t("wdNoFeeNotice")}</Text>
    </AgentShell>
  );
}

const s = StyleSheet.create({
  hero: { backgroundColor: c.surface, borderWidth: 1, borderColor: c.border, borderRadius: L.cardRadius, padding: 20, gap: 8, alignItems: "flex-start", overflow: "hidden" },
  art: { position: "absolute", right: -30, top: -30 },
  label: { ...T.caption, color: c.secondary, letterSpacing: 0.6 },
  heroTitle: { ...T.sectionTitle, color: c.heading },
  amount: { ...T.heroAmount, color: c.heading },
  body: { ...T.secondary, color: c.secondary },
  form: { gap: L.subsectionGap },
  error: { ...T.secondary, color: c.danger },
  notice: { ...T.caption, color: c.muted },
});
