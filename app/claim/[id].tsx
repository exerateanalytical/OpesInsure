import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect, useLocalSearchParams } from "expo-router";
import {
  AlertTriangle,
  Camera,
  ChevronRight,
  ClipboardList,
  FileCheck2,
  Gavel,
  MessageSquare,
  Paperclip,
  Search,
  Wallet,
  Wrench,
} from "lucide-react-native";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { ClaimTracker } from "@/components/claims/ClaimTracker";
import { useLoad } from "@/hooks/useLoad";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { CustomerApi } from "@/api/customer";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { ClaimAction, claimActionAllowed, claimStatusKey, claimTone } from "@/lib/claimStatus";
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

export default function ClaimDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, date } = useTranslation();
  const q = useLoad(() => ClaimsApi.show(id), [id]);
  const timeline = useLoad(() => ClaimRecordsApi.timeline(id), [id]);
  const evidence = useLoad(() => ClaimRecordsApi.evidence(id), [id]);
  const requirements = useLoad(() => CustomerApi.evidenceRequirements(id), [id]);
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

  return (
    <Screen>
      <AppHeader title={t("claimDetails")} subtitle={q.data?.claim_number} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(claim) => {
          const canUpload = claimActionAllowed("evidence", claim.status);
          return (
            <>
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

              <Card>
                <StatusChip label={td(claimStatusKey(claim.status), claim.status)} tone={claimTone(claim.status)} />
                <Text style={styles.title}>{claim.claim_number}</Text>
                <Text style={styles.body}>{t("claimIncidentAt", { date: date(claim.incident_at, true) })}</Text>
                {claim.incident_location ? (
                  <Text style={styles.body}>{t("claimLocation", { place: claim.incident_location })}</Text>
                ) : null}
              </Card>

              <Card>
                <Text accessibilityRole="header" style={styles.title}>{t("trackTitle")}</Text>
                <ClaimTracker status={claim.status} />
              </Card>

              <Card>
                <Text style={styles.title}>{t("claimYourReport")}</Text>
                <Text style={styles.body}>{claim.description}</Text>
              </Card>

              <SectionTitle title={t("claimActions")} />
              {ACTIONS.filter((a) => claimActionAllowed(a.action, claim.status)).map((a) => (
                <Button key={a.action} label={t(a.label)} icon={a.icon} variant="secondary" onPress={() => go(a.path)} />
              ))}
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

      <SectionTitle title={t("claimEvidenceSubmitted")} />
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
                <Paperclip size={19} color={colors.blue600} />
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
    </Screen>
  );
}
const styles = StyleSheet.create({
  row: { flexDirection: "row", gap: space.x3, alignItems: "center", paddingVertical: space.x2 },
  flex: { flex: 1 },
  pressed: { opacity: 0.82 },
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
  title: { ...type.cardTitle, color: colors.navy950 },
  event: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  danger: { ...type.meta, color: colors.dangerText },
  link: { ...type.label, color: colors.blue600 },
});
