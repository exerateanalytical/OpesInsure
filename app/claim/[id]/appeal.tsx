import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, Check, CircleCheck, FileText, Headphones, Lock } from "lucide-react-native";
import { Button, Card, Chip, ChipRow, ripple, Screen, StatusChip, TextField } from "@/components/ui";
import { ReviewIntro, ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { BrandHeader, CtaBar, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { ClaimsApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { claimActionAllowed, claimDecisionDate, claimStatusKey } from "@/lib/claimStatus";
import { colors, radius, space, type } from "@/theme/tokens";

const MAX_GROUNDS = 1000;
// Server limit on the composed appeal statement (reason max:4000).
const MAX_STATEMENT = 4000;
const REASONS: CopyKey[] = ["appealReason_amount", "appealReason_coverage", "appealReason_evidence", "appealReason_facts", "appealReason_other"];

/**
 * Appeal a claim decision (opesinsure_claim_appeal_screen), available while
 * claimActionAllowed("appeal") (DECLINED / PARTIALLY_APPROVED). Reason,
 * detailed grounds (≥ 30 characters) and requested outcome are composed into
 * the single `reason` the API takes (POST /mobile/claims/{id}/appeals, max 4000;
 * grounds capped at 1000 as designed). The backend has no appeal deadline or decision
 * reference for customers and appeals take no attachments, so those are not
 * shown.
 */
export default function Appeal() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, date } = useTranslation();
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const timeline = useLoad(() => ClaimRecordsApi.timeline(id), [id]);
  const [kind, setKind] = useState<CopyKey | null>(null);
  const [reason, setReason] = useState("");
  const [outcome, setOutcome] = useState("");
  const [agreed, setAgreed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // The appeal is shown read-only before it is sent.
  const [reviewing, setReviewing] = useState(false);
  const composed = [kind ? `${t(kind)}.` : "", reason.trim(), outcome.trim() ? `${t("appealOutcome")}: ${outcome.trim()}` : ""].filter(Boolean).join("\n\n");
  const valid = !!kind && reason.trim().length >= 30 && agreed && composed.length <= MAX_STATEMENT;
  const submit = async () => {
    if (!id || !valid) return;
    setBusy(true);
    setError(null);
    try {
      await ClaimsApi.appeal(id, composed);
      router.replace({ pathname: "/claim/[id]", params: { id } });
    } catch (e) {
      setError(e instanceof Error ? e.message : t("appealFailed"));
    } finally {
      setBusy(false);
    }
  };
  const allowed = claim.data ? claimActionAllowed("appeal", claim.data.status) : false;
  const decidedAt = claimDecisionDate(timeline.data ?? []);
  return (
    <Screen
      footer={
        claim.data ? (
          <CtaBar>
            {allowed && reviewing ? (
              <>
                <Button label={t("appealSubmit")} icon={ArrowRight} loading={busy} disabled={!valid || busy} onPress={() => void submit()} />
                <Button label={t("reviewBackToForm")} variant="tertiary" disabled={busy} onPress={() => setReviewing(false)} />
              </>
            ) : allowed ? (
              <Button label={t("reviewContinue")} icon={ArrowRight} disabled={!valid} onPress={() => { setError(null); setReviewing(true); }} />
            ) : null}
            <Button
              label={t("contactSupport")}
              icon={Headphones}
              variant="secondary"
              onPress={() => router.push({ pathname: "/support/new", params: { claimId: id, reference: claim.data?.claim_number ?? "", category: "CLAIM" } })}
            />
          </CtaBar>
        ) : undefined
      }
    >
      <BrandHeader title={t("appealTitle")} subtitle={t("appealExplain")} />
      <StatePanel {...claim} onRetry={claim.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(c) =>
          allowed && reviewing ? (
            <>
              <ReviewIntro />
              <ReviewSection icon={FileText} title={t("appealTitle")} onEdit={() => setReviewing(false)}>
                <ReviewRow first label={t("claimNumberLabel")} value={c.claim_number} />
                <ReviewRow label={t("appealReasonKind")} value={kind ? t(kind) : null} />
                <ReviewRow label={t("appealReason")} value={reason.trim()} />
                <ReviewRow label={t("appealOutcome")} value={outcome.trim()} />
              </ReviewSection>
              {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
            </>
          ) : allowed ? (
            <>
              <View style={s.eligible}>
                <View style={s.row}>
                  <TintedIcon icon={FileText} tint="blue" size={52} />
                  <View style={s.flex}>
                    <Text style={s.title}>{t("appealEligible")}</Text>
                    <Text style={s.body}>{t("appealEligibleBody")}</Text>
                  </View>
                  <CircleCheck size={22} color={colors.success} />
                </View>
                <View style={s.facts}>
                  <Fact label={t("claimNumberLabel")} value={c.claim_number} />
                  <Fact label={t("appealDecision")} value={td(claimStatusKey(c.status), c.status)} />
                  {decidedAt ? <Fact label={t("decisionDate")} value={date(decidedAt)} /> : null}
                </View>
              </View>
              <Card>
                <Text style={s.label}>{t("appealReasonKind")} *</Text>
                <ChipRow exclusive>
                  {REASONS.map((r) => (
                    <Chip key={r} label={t(r)} selected={kind === r} onPress={() => setKind(r)} />
                  ))}
                </ChipRow>
                <TextField
                  label={`${t("appealReason")} *`}
                  value={reason}
                  onChangeText={(v) => setReason(v.slice(0, MAX_GROUNDS))}
                  multiline
                  style={s.input}
                  placeholder={t("appealPlaceholder")}
                  hint={reason.trim().length < 30 ? t("claimWhatHint", { count: Math.max(0, 30 - reason.trim().length) }) : `${reason.length}/${MAX_GROUNDS}`}
                />
                <TextField
                  label={t("appealOutcome")}
                  value={outcome}
                  onChangeText={setOutcome}
                  multiline
                  style={s.inputSmall}
                  placeholder={t("appealOutcomePlaceholder")}
                />
                {composed.length > MAX_STATEMENT ? <Text accessibilityRole="alert" style={s.error}>{t("appealTooLong", { max: MAX_STATEMENT })}</Text> : null}
              </Card>
              <Pressable
                accessibilityRole="checkbox"
                accessibilityState={{ checked: agreed }}
                accessibilityLabel={t("appealConfirm")}
                onPress={() => setAgreed((x) => !x)}
                android_ripple={ripple()}
                style={s.declaration}
              >
                <View style={[s.checkbox, agreed && s.checkboxOn]}>{agreed ? <Check size={16} color={colors.white} strokeWidth={3} /> : null}</View>
                <View style={s.flex}>
                  <Text style={s.bodyStrong}>{t("appealConfirm")}</Text>
                  <Text style={s.meta}>{t("appealAudit")}</Text>
                </View>
              </Pressable>
              {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
            </>
          ) : (
            <View style={s.locked}>
              <View style={s.row}>
                <TintedIcon icon={Lock} tint="neutral" size={52} />
                <View style={s.flex}>
                  <Text style={s.title}>{t("appealNotEligible")}</Text>
                  <Text style={s.body}>{t("appealNotAvailable")}</Text>
                </View>
              </View>
              <View style={s.facts}>
                <Fact label={t("claimNumberLabel")} value={c.claim_number} />
                <View style={s.fact}>
                  <Text style={s.meta}>{t("appealDecision")}</Text>
                  <StatusChip label={td(claimStatusKey(c.status), c.status)} tone="neutral" />
                </View>
              </View>
            </View>
          )
        }
      </StatePanel>
    </Screen>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <View style={s.fact}>
      <Text style={s.meta}>{label}</Text>
      <Text style={s.bodyStrong}>{value}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  eligible: { backgroundColor: colors.blue50, borderRadius: radius.feature, padding: space.x4, gap: space.x3, borderWidth: 1, borderColor: colors.blue100 },
  locked: { backgroundColor: colors.white, borderRadius: radius.feature, padding: space.x4, gap: space.x3, borderWidth: 1, borderColor: colors.neutral200 },
  facts: { flexDirection: "row", flexWrap: "wrap", gap: space.x3, paddingTop: space.x3, borderTopWidth: 1, borderTopColor: colors.blue100 },
  fact: { flexBasis: 96, flexGrow: 1, gap: 2 },
  title: { ...type.cardTitle, color: colors.navy950 },
  label: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  bodyStrong: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  input: { minHeight: 140, textAlignVertical: "top", paddingTop: 12 },
  inputSmall: { minHeight: 80, textAlignVertical: "top", paddingTop: 12 },
  error: { ...type.meta, color: colors.dangerText },
  declaration: { flexDirection: "row", gap: space.x3, alignItems: "flex-start", padding: space.x4, borderRadius: radius.card, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white },
  checkbox: { width: 26, height: 26, borderRadius: 7, borderWidth: 2, borderColor: colors.neutral300, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  checkboxOn: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
});
