import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { FileSignature, Users } from "lucide-react-native";
import { TextField } from "@/components/ui";
import { OptionGroup } from "@/components/forms/OptionGroup";
import { SelectField } from "@/components/forms/SelectField";
import { AgentButton, AgentCard, AgentEmptyState, AgentShell, AgentSkeleton } from "@/components/agent";
import { AgentApi, AgentClient } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";
// The product name is sent as-is to the server; only its label is translated.
const products = [
  { value: "Motor Third Party", key: "agMotorThirdParty" },
  { value: "Motor Comprehensive", key: "agMotorComprehensive" },
  { value: "Travel", key: "catTravel" },
  { value: "Health", key: "catHealth" },
] as const;
/** Assisted sale (spec v2 form): client, product, payment phone; sticky primary action. */
export default function AgentSaleNew() {
  const { t } = useTranslation();
  const { customerId } = useLocalSearchParams<{ customerId?: string }>();
  const q = useLoad(() => AgentApi.clients(), []);
  const clients: AgentClient[] = q.data ?? [];
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [client, setClient] = useState(customerId ?? "");
  const [product, setProduct] = useState<string>(products[0].value);
  const [phone, setPhone] = useState("+237");
  useEffect(() => {
    if (!customerId || !q.data) return;
    const found = q.data.find((v) => v.id === customerId);
    if (found) setPhone(found.phone_e164);
  }, [customerId, q.data]);
  const create = async () => {
    setBusy(true);
    setError(null);
    try {
      const x = await AgentApi.createSale({
        customer_id: client,
        product,
        payment_phone_e164: phone,
      });
      router.replace(`/agent/sales/${x.id}`);
    } catch {
      setError(t("agSaleFailed"));
    } finally {
      setBusy(false);
    }
  };
  return (
    <AgentShell
      variant="drilldown"
      title={t("agAssistedSale")}
      hideNav
      footer={<AgentButton icon={FileSignature} label={t("agCreateQuoteReview")} disabled={!client || phone.length < 8} loading={busy} onPress={() => void create()} />}
    >
      <Text style={s.sub}>{t("agClientAuthorizes")}</Text>
      {q.loading && !q.data ? (
        <AgentSkeleton rows={3} height={56} />
      ) : q.error ? (
        <AgentEmptyState icon={Users} title={t("loadErrorTitle")} body={t("loadErrorBody")} actionLabel={t("retry")} onAction={q.reload} />
      ) : clients.length === 0 ? (
        <AgentEmptyState icon={Users} title={t("agNoClients")} body={t("agNoClientsBody")} />
      ) : null}
      <AgentCard style={s.form}>
        {clients.length ? (
          <SelectField
            label={t("agClient")}
            value={client}
            options={clients.map((x) => ({ value: x.id, label: x.full_name, subtitle: x.phone_e164 }))}
            onChange={(id) => {
              setClient(id);
              const x = clients.find((v) => v.id === id);
              if (x) setPhone(x.phone_e164);
            }}
          />
        ) : null}
        <OptionGroup label={t("cfProduct")} value={product} options={products.map(({ value, key }) => ({ value, label: t(key) }))} onChange={setProduct} />
        <TextField label={t("agClientPaymentPhone")} keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
      </AgentCard>
      <Text style={s.note}>{t("agNeverPin")}</Text>
      {error ? (
        <Text accessibilityRole="alert" style={s.error}>
          {error}
        </Text>
      ) : null}
    </AgentShell>
  );
}
const s = StyleSheet.create({
  sub: { ...T.secondary, color: c.secondary },
  form: { gap: L.subsectionGap },
  note: { ...T.secondary, color: c.secondary },
  error: { ...T.body, color: c.danger },
});
