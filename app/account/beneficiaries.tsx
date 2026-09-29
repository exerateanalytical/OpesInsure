import React, { useEffect, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Info, ShieldCheck, UsersRound } from "lucide-react-native";
import { AccountApi, WalletApi } from "@/api/client";
import { Screen } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading } from "@/components/design";
import { BeneficiariesSection } from "@/components/policies/BeneficiariesSection";
import { EditableSchemaSection } from "@/components/forms/SchemaSummary";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { Preferences } from "@/store/preferences";
import { useLoad } from "@/hooks/useLoad";
import { allowedAction } from "@/lib/capabilities";
import { PROFILE_BENEFICIARY_FIELDS, profileToValues } from "@/lib/inputForms";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/**
 * The one beneficiaries page:
 *  1. the customer's own list on their profile (server form customer_profile,
 *     field beneficiaries -> PATCH /mobile/account/customer-profile; shares total 100 %),
 *  2. the designations on each policy the customer owns (GET /mobile/wallet),
 *     each edited through GET/PUT /policies/{id}/beneficiaries (REQ-CRM-004).
 *     Policies whose product has no beneficiaries answer 403/404 and the
 *     section hides itself.
 * Personal information links here instead of carrying a second editor.
 */
export default function Beneficiaries() {
  const { t } = useTranslation();
  const profile = useLoad(() => AccountApi.customerProfile(), []);
  const q = useLoad(() => WalletApi.all(), []);
  const [legacy, setLegacy] = useState<Record<string, string> | null>(null);
  useEffect(() => {
    let live = true;
    void Preferences.profileExtras().then((local) => {
      const b = local.beneficiaries.map((x) => ({ name: x.full_name, relationship: x.relationship, share_percent: x.share_percent }));
      if (live && b.length) setLegacy({ beneficiaries: JSON.stringify(b) });
    });
    return () => {
      live = false;
    };
  }, []);
  const policies = (q.data ?? []).filter((p) => !["CANCELLED", "EXPIRED", "LAPSED"].includes(String(p.status).toUpperCase()));
  const values = { beneficiaries: profileToValues(profile.data).beneficiaries ?? "" };
  return (
    <Screen>
      <BrandHeader title={t("benPageTitle")} subtitle={t("benPageSubtitle")} back right={null} />

      <SectionHeading title={t("benProfileHeading")} icon={UsersRound} />
      {profile.loading && !profile.data ? <LoadingState /> : null}
      {profile.error && !profile.data ? <ErrorState error={profile.error} onRetry={profile.reload} /> : null}
      {profile.data ? (
        <EditableSchemaSection
          form="customer_profile"
          only={PROFILE_BENEFICIARY_FIELDS}
          values={values}
          seed={legacy}
          icon={UsersRound}
          title={t("benTitle")}
          submitLabel={t("benProfileSave")}
          disabled={!allowedAction(profile.data, "update_profile", true)}
          review={{ intro: t("benReviewIntro"), confirmLabel: t("benConfirmSave") }}
          onSubmit={async (payload) => {
            // An emptied list is sent as [] and clears the beneficiaries.
            profile.setData(await AccountApi.updateCustomerProfile(payload));
            await Preferences.forgetProfileExtrasPart("beneficiaries");
            setLegacy(null);
          }}
        />
      ) : null}
      <Text style={styles.meta}>{t("benProfileBody")}</Text>

      <SectionHeading title={t("benPolicyHeading")} icon={ShieldCheck} />
      {q.loading && !q.data ? <LoadingState /> : null}
      {q.error && !q.data ? <ErrorState error={q.error} onRetry={q.reload} /> : null}
      {q.data && !policies.length ? (
        <EmptyState title={t("benPageEmpty")} message={t("benPageEmptyBody")} action={t("explore")} onPress={() => router.push("/(customer)/(tabs)/explore" as never)} />
      ) : null}
      {policies.map((p) => (
        <View key={p.id} style={styles.block}>
          <Text style={styles.policy}>{p.product_name ?? p.policy_number}</Text>
          <Text style={styles.meta}>
            {p.policy_number}
            {p.carrier_name ? ` · ${p.carrier_name}` : ""}
          </Text>
          <BeneficiariesSection policyId={p.id} hideTitle />
        </View>
      ))}
      {policies.length ? <Banner icon={Info} tint="gold" title={t("benAllocationTitle")} body={t("benAllocationBody")} /> : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  block: { gap: space.x2 },
  policy: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
});
