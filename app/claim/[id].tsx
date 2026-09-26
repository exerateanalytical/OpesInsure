import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect, useLocalSearchParams } from "expo-router";
import {
  AlertTriangle,
  ArrowRight,
  Calendar,
  Camera,
  ChevronRight,
  ClipboardList,
  Clock3,
  FileCheck2,
  FileText,
  Gavel,
  MapPin,
  MessageSquare,
  Paperclip,
  Search,
  ShieldCheck,
  Wallet,
  Wrench,
} from "lucide-react-native";
import { Button, Card, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { BrandHeader, DetailRow, IconTile, SectionHeading, StepIndicator, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ClaimTracker } from "@/components/claims/ClaimTracker";
import { claimExtra, claimPolicy, insuredLabel, policyLine, policyTitle, productIcon } from "@/components/claims/claimProduct";
import { claimNextStepKeys, claimStatusMessageKey } from "@/components/claims/nextSteps";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { CustomerApi } from "@/api/customer";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { ClaimAction, claimActionAllowed, claimStage, claimStatusKey, claimTone, DETAIL_STAGES } from "@/lib/claimStatus";
import { colors, radius, space, type } from "@/theme/tokens";

const ACTIONS: { action: ClaimAction; label: CopyKey; icon: typeof Camera; path: string }[] = [
  { action: "evidence", label: "claimAddEvidence", icon: Camera, path: "/claim/[id]/evidence" },
  { action: "incident", label: "claimCompleteIncident", icon: ClipboardList, path: "/claim/[id]/incident" },
  { action: "checklist", label: "claimRequirements", icon: FileCheck2, path: "/claim/[id]/checklist" },
  { action: "inspection", label: "claimInspection", icon: Search, path: "/claim/[id]/inspection" },
  { action: "repair", label: "claimRepair", icon: Wrench, path: "/claim/[id]/repair" },
  { action: "settlement", label: "claimSettlement", icon: Wallet, path: "/claim/[id]/settlement" },
  { action: "appeal", label: "claimAppeal", icon: Gavel, path: "/claim/[id]/appeal" },
];

const STAGE_LABELS: Record<(typeof DETAIL_STAGES)[number], CopyKey> = {
  submitted: "claimStageSubmitted",
  review: "claimStageReview",
  assessment: "claimStageAssessment",
  decision: "claimStageDecision",
  settlement: "claimStageSettlement",
};

/** Claim detail (design 39): status header, five-stage indicator, claim information, current status, next steps, actions, evidence and the full timeline. */
export default function ClaimDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, date } = useTranslation();
  const q = useLoad(() => ClaimsApi.show(id), [id]);
  const timeline = useLoad(() => ClaimRecordsApi.timeline(id), [id]);
  const evidence = useLoad(() => ClaimRecordsApi.evidence(id), [id]);
  const requirements = useLoad(() => CustomerApi.evidenceRequirements(id), [id]);
  const { policies } = usePolicies();
  const [showTimeline, setShowTimeline] = useState(false);
  const reloadAll = [q.reload, evidence.reload, requirements.reload, timeline.reload];
  useFocusEffect(
    React.useCallback(() => {
      // Returning from the evidence picker refreshes the actionable list.
      reloadAll.forEach((r) => void r());
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]),
  );
  const go = (pathname: string) => router.push({ pathname: pathname as never, params: { id } });
  const outstanding = (requirements.data ?? []).filter(
    (r) => r.status === "MISSING" || r.status === "REJECTED",
  );
  const stages = DETAIL_STAGES.map((k) => t(STAGE_LABELS[k]));
  const lastEvent = (timeline.data ?? [])[0];
  // The header date is the first-notice date: the SUBMITTED event when the insurer recorded one, else the record's creation.
  const submittedEvent = (timeline.data ?? []).find((e) => /submit/i.test(`${e.type ?? ""} ${e.to_status ?? ""}`));

  return (
    <Screen>
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(claim) => {
          const canUpload = claimActionAllowed("evidence", claim.status);
          const policy = policies.find((p) => p.id === claim.policy_id) ?? claimPolicy(claim);
          const title = policyTitle(policy, t("claimPolicyLabel"));
          const ProductIcon = productIcon(title, policyLine(policy));
          const incidentType = claimExtra(claim, "incident_type");
          const status = td(claimStatusKey(claim.status), claim.status);
          const statusDate = lastEvent?.occurred_at ?? claim.created_at;
          const actions = ACTIONS.filter((a) => claimActionAllowed(a.action, claim.status));
          return (
            <>
              <BrandHeader
                title={t("claimDetails")}
                subtitle={claim.claim_number}
                titleRow={<View style={styles.chipTop}><StatusChip label={status} tone={claimTone(claim.status)} /></View>}
              />
              <Text style={styles.submitted}>{t("claimSubmittedOn", { date: date(submittedEvent?.occurred_at ?? claim.created_at ?? claim.incident_at) })}</Text>
              <StepIndicator steps={stages} current={claimStage(claim.status)} />

              {outstanding.length && canUpload ? (
                <Card style={styles.attention}>
                  <View style={styles.row}>
                    <AlertTriangle size={22} color={colors.warningText} />
                    <Text accessibilityRole="header" style={[styles.title, styles.flex]}>
                      {t("claimInsurerRequests", { count: outstanding.length })}
                    </Text>
                  </View>
                  {outstanding.map((r) => (
                    <Pressable
                      key={r.key}
                      accessibilityRole="button"
                      accessibilityLabel={`${r.label}. ${t("claimUploadNow")}`}
                      onPress={() => router.push({ pathname: "/claim/[id]/evidence", params: { id, requirement: r.key } })}
                      style={({ pressed }) => [styles.request, pressed && styles.pressed]}
                    >
                      <View style={styles.flex}>
                        <Text style={styles.event}>{r.label}</Text>
                        {r.guidance ? <Text style={styles.meta}>{r.guidance}</Text> : null}
                        {r.status === "REJECTED" ? <Text style={styles.danger}>{t("claimRequirementRejected")}</Text> : null}
                      </View>
                      <Text style={styles.link}>{t("claimUploadNow")}</Text>
                      <ChevronRight size={18} color={colors.blue600} />
                    </Pressable>
                  ))}
                </Card>
              ) : null}

              <Card style={styles.infoCard}>
                <Text accessibilityRole="header" style={styles.cardTitle}>{t("claimInformation")}</Text>
                <DetailRow
                  icon={ProductIcon}
                  label={t("claimPolicyLabel")}
                  valueNode={
                    <View style={styles.valueCol}>
                      <Text style={styles.valueStrong}>{title}</Text>
                      {policy?.policy_number ? <Text style={styles.value}>{policy.policy_number}</Text> : null}
                      {insuredLabel(policy) ? <Text style={styles.value}>{insuredLabel(policy)}</Text> : null}
                    </View>
                  }
                />
                <DetailRow icon={Calendar} label={t("claimIncidentDate")} value={date(claim.incident_at, true)} />
                {claim.incident_location ? <DetailRow icon={MapPin} label={t("claimIncidentLocation")} value={claim.incident_location} /> : null}
                {incidentType ? <DetailRow icon={ShieldCheck} label={t("claimIncidentType")} value={td(`incidentKind_${incidentType}`, incidentType)} /> : null}
              </Card>

              <Card>
                <Text accessibilityRole="header" style={styles.cardTitle}>{t("claimCurrentStatus")}</Text>
                <View style={styles.statusRow}>
                  <TintedIcon icon={Clock3} tint={claimTone(claim.status) === "danger" ? "red" : claimTone(claim.status) === "success" ? "green" : "gold"} size={48} />
                  <View style={styles.flex}>
                    <Text style={[styles.statusTitle, claimTone(claim.status) === "danger" && styles.dangerTitle]}>{status}</Text>
                    {statusDate ? <Text style={styles.meta}>{date(statusDate)}</Text> : null}
                    <Text style={styles.body}>{td(claimStatusMessageKey(claim.status), t("claimStatusMsg_UNKNOWN"))}</Text>
                  </View>
                </View>
              </Card>

              <Card>
                <Text accessibilityRole="header" style={styles.cardTitle}>{t("claimNextSteps")}</Text>
                <View accessibilityRole="list">
                  {claimNextStepKeys(claim.status).map((key, i, all) => (
                    <View key={key} style={styles.nextRow}>
                      <View style={styles.nextRail}>
                        <View style={[styles.nextDot, i === 0 && styles.nextDotOn]}>{i === 0 ? <View style={styles.nextDotInner} /> : null}</View>
                        {i < all.length - 1 ? <View style={styles.nextLine} /> : null}
                      </View>
                      <Text style={[styles.body, styles.flex, styles.nextText]}>{t(key)}</Text>
                    </View>
                  ))}
                </View>
              </Card>

              {claim.description ? (
                <Card>
                  <Text style={styles.cardTitle}>{t("claimYourReport")}</Text>
                  <Text style={styles.body}>{claim.description}</Text>
                </Card>
              ) : null}

              <Button
                label={showTimeline ? t("claimHideTimeline") : t("claimViewTimeline")}
                icon={ArrowRight}
                variant="secondary"
                onPress={() => setShowTimeline((x) => !x)}
              />
              {showTimeline ? (
                <>
                  <Card>
                    <Text accessibilityRole="header" style={styles.cardTitle}>{t("trackTitle")}</Text>
                    <ClaimTracker status={claim.status} />
                  </Card>
                  <SectionTitle title={t("claimTimeline")} />
                  <StatePanel
                    {...timeline}
                    onRetry={timeline.reload}
                    emptyTitle={t("claimNoUpdates")}
                    emptyMessage={t("claimNoUpdatesBody")}
                    loadingLabel={t("loading")}
                  >
                    {(events) => (
                      <Card>
                        {events.map((event) => (
                          <View key={event.id} style={styles.row}>
                            <FileCheck2 size={19} color={colors.blue600} />
                            <View style={styles.flex}>
                              <Text style={styles.event}>
                                {event.to_status
                                  ? td(claimStatusKey(event.to_status), event.to_status)
                                  : td(`claimEvent_${event.type}`, event.type)}
                              </Text>
                              <Text style={styles.meta}>{date(event.occurred_at)}</Text>
                            </View>
                          </View>
                        ))}
                      </Card>
                    )}
                  </StatePanel>
                </>
              ) : null}

              <SectionHeading title={t("claimActions")} />
              {actions.length ? (
                <View style={styles.tiles}>
                  {actions.map((a) => (
                    <IconTile key={a.action} icon={a.icon} label={t(a.label)} tint={a.action === "appeal" ? "gold" : "blue"} onPress={() => go(a.path)} style={styles.tile} />
                  ))}
                </View>
              ) : null}
              {claimActionAllowed("message", claim.status) ? (
                <Button
                  label={t("claimMessage")}
                  icon={MessageSquare}
                  variant="tertiary"
                  onPress={() =>
                    router.push({
                      pathname: "/support/new",
                      params: { claimId: claim.id, reference: claim.claim_number, category: "CLAIM" },
                    })
                  }
                />
              ) : (
                <Text style={styles.meta}>{t("claimClosedNoActions")}</Text>
              )}
            </>
          );
        }}
      </StatePanel>

      <SectionHeading title={t("claimEvidenceSubmitted")} icon={Paperclip} />
      <StatePanel
        {...evidence}
        onRetry={evidence.reload}
        emptyTitle={t("claimNoEvidence")}
        emptyMessage={t("claimNoEvidenceBody")}
        loadingLabel={t("loading")}
      >
        {(items) => (
          <Card>
            {items.map((e) => (
              <View key={e.id} style={styles.row}>
                <FileText size={19} color={colors.blue600} />
                <View style={styles.flex}>
                  <Text style={styles.event}>{td(`evidence_${e.evidence_type}`, e.evidence_type)}</Text>
                  {e.submitted_at ? <Text style={styles.meta}>{date(e.submitted_at)}</Text> : null}
                </View>
                <StatusChip
                  label={td(`evidenceStatus_${e.status}`, e.status)}
                  tone={e.status === "VERIFIED" ? "success" : e.status === "REJECTED" ? "danger" : "neutral"}
                />
              </View>
            ))}
          </Card>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  row: { flexDirection: "row", gap: space.x3, alignItems: "center", paddingVertical: space.x2 },
  flex: { flex: 1 },
  pressed: { opacity: 0.82 },
  chipTop: { paddingTop: 8 },
  submitted: { ...type.body, color: colors.neutral600, marginTop: -space.x4 },
  attention: { borderColor: colors.gold500, backgroundColor: colors.warningSoft },
  request: {
    minHeight: 56,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x2,
    backgroundColor: colors.white,
    borderRadius: radius.control,
    padding: space.x3,
  },
  infoCard: { gap: space.x2 },
  valueCol: { alignItems: "flex-end" },
  value: { ...type.body, color: colors.neutral700, textAlign: "right" },
  valueStrong: { ...type.label, color: colors.navy950, textAlign: "right" },
  statusRow: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  statusTitle: { ...type.cardTitle, color: colors.gold600 },
  dangerTitle: { color: colors.dangerText },
  nextRow: { flexDirection: "row", gap: space.x3, minHeight: 44 },
  nextRail: { alignItems: "center", width: 20 },
  nextDot: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: colors.neutral300, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  nextDotOn: { borderColor: colors.blue600 },
  nextDotInner: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.blue600 },
  nextLine: { flex: 1, width: 2, backgroundColor: colors.neutral200, marginVertical: 2 },
  nextText: { paddingBottom: space.x3, color: colors.navy950 },
  tiles: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  tile: { flexBasis: "30%", flexGrow: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  cardTitle: { ...type.cardTitle, color: colors.navy900 },
  event: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  danger: { ...type.meta, color: colors.dangerText },
  link: { ...type.label, color: colors.blue600 },
});
