import React from "react";
import { Alert, StyleSheet, Text, View } from "react-native";
import { FilePen, Play, Trash2 } from "lucide-react-native";
import { Button, Card } from "@/components/ui";
import { TintedIcon } from "@/components/design";
import { policyTitle } from "@/components/claims/claimProduct";
import type { ClaimDraft } from "@/api/customer";
import type { Policy } from "@/api/client";
import { draftMissing } from "@/lib/claimDraft";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/** A saved, not yet submitted claim (GET /mobile/claims/drafts): Resume reopens the wizard; Discard deletes it. */
export function ClaimDraftCard({ draft, policy, onResume, onDiscard, busy }: { draft: ClaimDraft; policy?: Policy | null; onResume: () => void; onDiscard: () => void; busy?: boolean }) {
  const { t, td, date } = useTranslation();
  const p = draft.payload ?? {};
  const title = policyTitle(policy ?? draft.policy ?? null, t("claimDraftUntitled"));
  const files = p.evidence?.length ?? 0;
  const facts = [
    p.incident_type ? td(`incidentKind_${p.incident_type}`, p.incident_type) : null,
    p.incident_at ? date(p.incident_at) : null,
    files ? t("claimDraftFilesCount", { count: files }) : null,
  ].filter(Boolean);
  const confirmDiscard = () =>
    Alert.alert(t("claimDraftDiscardTitle"), t("claimDraftDiscardBody"), [
      { text: t("cancel"), style: "cancel" },
      { text: t("discard"), style: "destructive", onPress: onDiscard },
    ]);
  return (
    <Card>
      <View style={s.row}>
        <TintedIcon icon={FilePen} tint="gold" size={44} />
        <View style={s.flex}>
          <Text style={s.title} numberOfLines={2}>{title}</Text>
          <Text style={s.meta}>{t("claimDraftSavedOn", { date: date(draft.updated_at ?? draft.created_at ?? null, true) })}</Text>
          {facts.length ? <Text style={s.meta}>{facts.join(" · ")}</Text> : null}
          <Text style={s.meta}>{draftMissing(draft) ? t("claimDraftNotReady") : t("claimDraftReady")}</Text>
        </View>
      </View>
      <View style={s.actions}>
        <View style={s.flex}>
          <Button label={t("claimDraftResume")} icon={Play} onPress={onResume} disabled={busy} />
        </View>
        <View style={s.flex}>
          <Button label={t("discard")} icon={Trash2} variant="secondary" onPress={confirmDiscard} disabled={busy} />
        </View>
      </View>
    </Card>
  );
}

const s = StyleSheet.create({
  row: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  flex: { flex: 1, gap: 2 },
  actions: { flexDirection: "row", gap: space.x3 },
  title: { ...type.cardTitle, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
});
