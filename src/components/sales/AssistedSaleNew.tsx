import React, { useCallback, useEffect, useMemo, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text, View } from "react-native";
import { FileSignature, Users } from "lucide-react-native";
import { TextField } from "@/components/ui";
import { OptionGroup } from "@/components/forms/OptionGroup";
import { SelectField } from "@/components/forms/SelectField";
import { ContractField } from "@/components/forms/ContractField";
import { NetworkTiles } from "@/components/policies/RenewalUi";
import { useVehicleReference } from "@/components/vehicles/VehiclePicker";
import { AgentButton, AgentCard, AgentEmptyState, AgentSection, AgentShell, AgentSkeleton } from "@/components/agent";
import { AgentApi, BrokerApi, CatalogueApi, saleApi, type SalePortal } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { errorMessage } from "@/lib/purchase";
import { allFields, buildFacts, clearedDependents, isFieldVisible, localRiskSchema, normalizeRiskSchema, RiskSchema, validateStep } from "@/lib/riskSchema";
import { CLEARED_VEHICLE_VALUES, selectionFromValues, withReferenceOptions } from "@/lib/riskFormValues";
import { selectionToValues } from "@/lib/vehicles";
import { isE164, Network, networkForPhone, SALE_PRODUCTS, SaleProduct, saleProductFromParam, saleRiskFacts } from "@/lib/assistedSale";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

/**
 * Assisted sale, step 1: client, product (preselected from ?product=), the client's REAL risk answers on the same
 * risk schema as the customer quote wizard (GET catalogue/lines/{line}/risk-schema, local schema as fallback), and
 * the client's payment phone/operator. "Get the real price" rates them on the server (validated like POST /quotes);
 * the price and offers are then reviewed on the sale screen before anything is sent to the client.
 * Shared by the agent (app/agent/sales/new) and the broker (app/broker/sales/new, rated on the BROKER channel)
 * portals; the client list is the caller's own book.
 */
type SaleClient = { id: string; full_name: string; phone_e164?: string | null };

export function AssistedSaleNew({ portal }: { portal: SalePortal }) {
  const { t, language } = useTranslation();
  const params = useLocalSearchParams<{ customerId?: string; product?: string }>();
  const q = useLoad<SaleClient[]>(() => (portal === "broker" ? BrokerApi.clients() : AgentApi.clients()), [portal]);
  const clients: SaleClient[] = q.data ?? [];
  const [client, setClient] = useState(params.customerId ?? "");
  const [product, setProduct] = useState<SaleProduct>(saleProductFromParam(params.product) ?? "motor");
  const [phone, setPhone] = useState("+237");
  const [network, setNetwork] = useState<Network>("mtn_momo");
  const [schema, setSchema] = useState<RiskSchema | null>(null);
  const [schemaLoading, setSchemaLoading] = useState(true);
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const { reference } = useVehicleReference();
  const line = product.toUpperCase();

  useEffect(() => {
    const p = saleProductFromParam(params.product);
    if (p) setProduct(p);
  }, [params.product]);

  const pickPhone = useCallback((v: string) => {
    setPhone(v);
    const n = networkForPhone(v);
    if (n) setNetwork(n);
  }, []);

  useEffect(() => {
    if (!client || !q.data) return;
    const found = q.data.find((v) => v.id === client);
    if (found?.phone_e164) pickPhone(found.phone_e164);
  }, [client, q.data, pickPhone]);

  const loadSchema = useCallback(async () => {
    setSchemaLoading(true);
    setValues({});
    setErrors({});
    let next: RiskSchema | null = null;
    try {
      next = normalizeRiskSchema(await CatalogueApi.riskSchema(line), line);
    } catch {
      // Endpoint unreachable: the local schema keeps the form usable; the server still validates every answer.
    }
    setSchema(next ?? localRiskSchema(line));
    setSchemaLoading(false);
  }, [line]);
  useEffect(() => {
    void loadSchema();
  }, [loadSchema]);

  const setValue = (key: string, v: string) => {
    setValues((x) => ({ ...x, [key]: v, ...(x[key] !== v && schema ? clearedDependents(allFields(schema), key) : {}) }));
    if (errors[key]) setErrors((x) => ({ ...x, [key]: "" }));
  };
  const setAny = (key: string, v: string) => setValues((x) => ({ ...x, [key]: v }));
  const stepTitle = (s: { title: string; titleFr?: string }) => (language === "fr" && s.titleFr ? s.titleFr : s.title);
  const productOptions = useMemo(() => SALE_PRODUCTS.map((id) => ({ value: id, label: t(`qtProd_${id}`) })), [t]);

  const create = async () => {
    if (busy || !schema) return;
    const lang = language === "fr" ? "fr" : "en";
    const e = schema.steps.reduce<Record<string, string>>((acc, step) => ({ ...acc, ...validateStep(step, values, lang) }), {});
    setErrors(e);
    if (Object.values(e).some(Boolean)) return setError(t("slFixErrors"));
    setBusy(true);
    setError(null);
    try {
      const sale = await saleApi(portal).createSale({
        customer_id: client,
        product: line,
        payment_phone_e164: phone.replace(/\s/g, ""),
        provider: network,
        risk_facts: saleRiskFacts(buildFacts(schema, values)),
      });
      router.replace({ pathname: `/${portal}/sales/[id]` as never, params: { id: sale.id } });
    } catch (err) {
      setError(errorMessage(err, t("agSaleFailed"), language));
    } finally {
      setBusy(false);
    }
  };

  return (
    <AgentShell
      portal={portal}
      variant="drilldown"
      title={t("agAssistedSale")}
      hideNav
      footer={<AgentButton icon={FileSignature} label={t("slGetPrice")} disabled={!client || !isE164(phone.replace(/\s/g, "")) || !schema || schemaLoading} loading={busy} onPress={() => void create()} />}
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
          <SelectField label={t("agClient")} value={client} options={clients.map((x) => ({ value: x.id, label: x.full_name, subtitle: x.phone_e164 ?? undefined }))} onChange={setClient} />
        ) : null}
        <OptionGroup label={t("cfProduct")} value={product} options={productOptions} onChange={(v) => setProduct(saleProductFromParam(v) ?? "motor")} />
      </AgentCard>

      <AgentSection title={t("slRiskDetails")}>
        <Text style={s.note}>{t("slRiskDetailsHint")}</Text>
        {schemaLoading ? (
          <AgentSkeleton rows={4} height={56} />
        ) : !schema ? (
          <AgentEmptyState icon={FileSignature} title={t("loadErrorTitle")} body={t("slSchemaUnavailable")} actionLabel={t("retry")} onAction={() => void loadSchema()} />
        ) : (
          schema.steps.map((step) => (
            <AgentCard key={step.key} style={s.form}>
              <Text style={s.stepTitle}>{stepTitle(step)}</Text>
              {step.fields.map((f) =>
                isFieldVisible(f, values) ? (
                  <ContractField
                    key={f.key}
                    field={withReferenceOptions(f, reference)}
                    value={values[f.key]}
                    values={values}
                    error={(f.type === "vehicle_make" ? errors[f.key] || errors.model_code : errors[f.key]) || undefined}
                    vehicle={selectionFromValues(values)}
                    onChange={(v) => setValue(f.key, v)}
                    setAny={setAny}
                    lineCode={line}
                    screen="agent.sale"
                    onVehicle={(sel) => {
                      setValues((x) => ({ ...x, ...CLEARED_VEHICLE_VALUES, ...(sel ? selectionToValues(sel) : {}) }));
                      setErrors((x) => ({ ...x, make_code: "", model_code: "" }));
                    }}
                  />
                ) : null,
              )}
            </AgentCard>
          ))
        )}
      </AgentSection>

      <AgentCard style={s.form}>
        <TextField label={t("agClientPaymentPhone")} keyboardType="phone-pad" value={phone} onChangeText={pickPhone} />
        <View style={s.form}>
          <Text style={s.label}>{t("slClientNetwork")}</Text>
          <NetworkTiles value={network} onChange={setNetwork} disabled={busy} />
        </View>
      </AgentCard>
      <Text style={s.note}>{t(portal === "broker" ? "bkNeverPin" : "agNeverPin")}</Text>
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
  stepTitle: { ...T.cardTitle, color: c.heading },
  label: { ...T.secondary, color: c.text },
  note: { ...T.secondary, color: c.secondary },
  error: { ...T.body, color: c.danger },
});
