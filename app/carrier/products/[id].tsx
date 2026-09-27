import React, { useState } from "react";
import { useLocalSearchParams } from "expo-router";
import { Pause, Play } from "lucide-react-native";
import { DetailActions, DetailHistory, DetailScreen, DetailSection, UnavailableSection, useListRecord } from "@/components/detail";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import { Card, TextField } from "@/components/ui";
import { CarrierWorkspaceApi, humanize, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

/**
 * Product Detail (CAR-004): version, effective dates and tariff version
 * history. Pause/resume only shows when the server says `can_toggle`; the
 * server audits the transition (product status history).
 */
export default function CarrierProductDetail() {
  return (
    <CarrierGate module="products">
      <Body />
    </CarrierGate>
  );
}

function Body() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useListRecord(() => CarrierWorkspaceApi.products(), id);
  const [reason, setReason] = useState("");
  return (
    <DetailScreen
      title={t("cdProductTitle")}
      subtitle={(p) => p?.name}
      query={q}
      isMissing={(p) => p === null}
    >
      {(p) => {
        const active = p!.status === "ACTIVE";
        return (
          <>
            <DetailSection
              title={t("cdVersion")}
              rows={[
                [t("cdName"), p!.name],
                [t("cdCode"), p!.code],
                [t("cdVersion"), `v${p!.version}`],
                [t("cdStatus"), humanize(p!.status)],
                [t("cdLine"), p!.line_code],
                [t("cdInsurer"), p!.carrier_name],
                [t("cdEffectiveFrom"), p!.effective_from ? shortDate(p!.effective_from) : null],
                [t("cdEffectiveUntil"), p!.effective_until ? shortDate(p!.effective_until) : null],
                [t("cdPoliciesInForce"), String(p!.policies_in_force)],
              ]}
            />
            <DetailHistory
              title={t("cdTariffHistory")}
              items={p!.tariffs.map((tv) => ({
                key: tv.id,
                when: tv.effective_from,
                text: `v${tv.version} · ${humanize(tv.status)}`,
              }))}
            />
            <UnavailableSection title={t("cdProductConfig")} />
            {p!.can_toggle ? (
              <>
                <Card>
                  <TextField label={active ? t("caReasonPause") : t("caReasonResume")} value={reason} onChangeText={setReason} />
                </Card>
                <DetailActions
                  actions={[
                    {
                      key: "toggle",
                      label: active ? t("caPauseSales") : t("caResumeSales"),
                      icon: active ? Pause : Play,
                      variant: active ? "danger" : "primary",
                      allowed: p!.can_toggle,
                      disabled: reason.trim().length < 3,
                      confirm: active ? t("cdConfirmPause") : t("cdConfirmResume"),
                      run: async () => {
                        const next = await CarrierWorkspaceApi.setProductActive(p!.id, !active, reason.trim());
                        q.setData(next);
                        setReason("");
                      },
                    },
                  ]}
                />
              </>
            ) : null}
          </>
        );
      }}
    </DetailScreen>
  );
}
