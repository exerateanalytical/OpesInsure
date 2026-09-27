import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect, useLocalSearchParams } from "expo-router";
import { Calendar, Clock3, FileCheck2, FileText, Headphones, Info, Upload } from "lucide-react-native";
import { Card, Screen, StatusChip } from "@/components/ui";
import { ActionTile, Banner, BrandHeader, HeroCard, SectionHeading, type HeroMeta } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ClaimTracker } from "@/components/claims/ClaimTracker";
import { claimPolicy, insuredLabel, policyLine, policyTitle, productIcon, providerName } from "@/components/claims/claimProduct";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { claimNextStepKeys, claimStatusMessageKey } from "@/components/claims/nextSteps";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { useTranslation } from "@/i18n";
import { claimActionAllowed, claimStatusKey, claimTone, trackerStepDates } from "@/lib/claimStatus";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Full claim timeline (opesinsure_claim_timeline_dashboard): claim summary
 * with insurer logo, the seven-step tracker dated from the status-change
 * events (GET /mobile/claims/{id}/timeline), latest update, next step, and
 * actions to add information (when evidence is still open) or contact
 * support. The raw status history follows below.
 */
export default function ClaimTimeline() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, date } = useTranslation();
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const timeline = useLoad(() => ClaimRecordsApi.timeline(id), [id]);
  const evidence = useLoad(() => ClaimRecordsApi.evidence(id), [id]);
  const { policies } = usePolicies();
  const logoFor = useInsurerLogo();
  useFocusEffect(
    React.useCallback(() => {
      void claim.reload();
      void timeline.reload();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]),
  );
  const events = timeline.data ?? [];
  const firstEvidence = (evidence.data ?? [])
    .map((e) => e.submitted_at)
    .filter((d): d is string => !!d)
    .sort()[0];

  return (
    <Screen>
      <BrandHeader title={t("claimTimelineTitle")} subtitle={t("claimTimelineSubtitle")} />
      <StatePanel {...claim} onRetry={claim.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(c) => {
          const policy = policies.find((p) => p.id === c.policy_id) ?? claimPolicy(c);
          const title = policyTitle(policy, t("claimPolicyLabel"));
          const provider = providerName(policy);
          const asset = insuredLabel(policy);
          const meta: HeroMeta[] = [
            ...(policy?.policy_number ? [{ icon: FileText, label: t("claimPolicyNumberLabel"), value: policy.policy_number }] : []),
            ...(asset ? [{ icon: productIcon(title, policyLine(policy)), label: t("claimInsuredItem"), value: asset }] : []),
            { icon: Calendar, label: t("claimIncidentDate"), value: date(c.incident_at) },
          ];
          const latest = [...events].sort((a, b) => b.occurred_at.localeCompare(a.occurred_at))[0];
          const next = claimNextStepKeys(c.status)[0];
          const canAdd = claimActionAllowed("information", c.status);
          return (
            <>
              <HeroCard compact
                icon={productIcon(title, policyLine(policy))}
                title={title}
                lines={[c.claim_number]}
                provider={provider}
                providerLogo={logoFor(c, policy)}
                chip={<StatusChip label={td(claimStatusKey(c.status), c.status)} tone={claimTone(c.status)} />}
                meta={meta}
              />
              <Card>
                <ClaimTracker status={c.status} dates={trackerStepDates(events, firstEvidence)} describe />
              </Card>
              <Banner
                icon={Info}
                tint="blue"
                title={latest ? `${t("claimLatestUpdate")} · ${date(latest.occurred_at, true)}` : t("claimLatestUpdate")}
                body={td(claimStatusMessageKey(c.status), t("claimStatusMsg_UNKNOWN"))}
              />
              {next ? (
                <View style={s.next}>
                  <Clock3 size={26} color={colors.gold600} />
                  <View style={s.flex}>
                    <Text style={s.nextLabel}>{t("claimNextStepLabel")}</Text>
                    <Text style={s.nextBody}>{t(next)}</Text>
                  </View>
                </View>
              ) : null}
              <View style={s.tiles}>
                {canAdd ? (
                  <ActionTile icon={Upload} label={t("claimAddInformation")} onPress={() => router.push({ pathname: "/claim/[id]/information" as never, params: { id } })} style={s.tile} />
                ) : null}
                {claimActionAllowed("message", c.status) ? (
                  <ActionTile
                    icon={Headphones}
                    label={t("claimContactClaimsSupport")}
                    onPress={() => router.push({ pathname: "/support/new", params: { claimId: c.id, reference: c.claim_number, category: "CLAIM" } })}
                    style={s.tile}
                  />
                ) : null}
              </View>
            </>
          );
        }}
      </StatePanel>

      <SectionHeading title={t("claimTimeline")} />
      <StatePanel {...timeline} onRetry={timeline.reload} emptyTitle={t("claimNoUpdates")} emptyMessage={t("claimNoUpdatesBody")} loadingLabel={t("loading")}>
        {(rows) => (
          <Card>
            {rows.map((event) => (
              <View key={event.id} style={s.row}>
                <FileCheck2 size={19} color={colors.blue600} />
                <View style={s.flex}>
                  <Text style={s.event}>{event.to_status ? td(claimStatusKey(event.to_status), event.to_status) : td(`claimEvent_${event.type}`, event.type)}</Text>
                  <Text style={s.meta}>{date(event.occurred_at, true)}</Text>
                </View>
              </View>
            ))}
          </Card>
        )}
      </StatePanel>
    </Screen>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: "row", gap: space.x3, alignItems: "center", paddingVertical: space.x2 },
  event: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  next: { flexDirection: "row", gap: space.x3, padding: space.x4, borderRadius: radius.card, backgroundColor: colors.warningSoft, borderWidth: 1, borderColor: colors.gold500 },
  nextLabel: { ...type.label, color: colors.gold600 },
  nextBody: { ...type.body, color: colors.navy950 },
  tiles: { flexDirection: "row", flexWrap: "wrap", gap: space.x3 },
  tile: { flexBasis: 140, flexGrow: 1 },
});
