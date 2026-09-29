import React, { ReactNode, useMemo } from "react";
import { Image, StyleSheet, Text, View } from "react-native";
import { ArrowRight, ClipboardCheck, FileText, type LucideIcon } from "lucide-react-native";
import { Banner, CtaBar, TintedIcon, type Tint } from "@/components/design";
import { Button, StatusChip } from "@/components/ui";
import { SummaryCard, SummaryField, useMasterLabels } from "@/components/forms/SummaryCard";
import { reviewRows, type SummaryCopy, type SummaryRow } from "@/lib/formSummary";
import type { RiskField } from "@/lib/riskSchema";
import { formatXaf, useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * The one "check before you submit" look, used by every customer form that
 * ends in a submit (quote, disclosures, claims, service requests, refunds,
 * support, KYC, beneficiaries, checkout): an intro banner, summary cards with
 * an Edit action that returns to that step, label -> value rows in words
 * (src/lib/formSummary.ts), documents as thumbnails or file rows, and one
 * primary submit in the footer. Built on the profile summary cards
 * (SummaryCard / SummaryField) so filled forms and reviews read the same.
 */

/** Copy for formSummary: Yes/No, "Other", money as FCFA with the app formatter. */
export function useReviewCopy(): SummaryCopy {
  const { t, language } = useTranslation();
  return useMemo(() => ({ other: t("summaryOther"), yes: t("yes"), no: t("no"), money: (n: number) => formatXaf(n, language) }), [t, language]);
}

/** "Check your details": nothing has been sent yet. */
export function ReviewIntro({ title, body }: { title?: string; body?: string }) {
  const { t } = useTranslation();
  return <Banner icon={ClipboardCheck} tint="blue" title={title ?? t("reviewIntroTitle")} body={body ?? t("reviewIntroBody")} />;
}

/** One summary card: tinted icon, title and an Edit action that jumps back to the step. */
export function ReviewSection({ icon, title, onEdit, editLabel, tint, children }: { icon: LucideIcon; title: string; onEdit?: () => void; editLabel?: string; tint?: Tint; children: ReactNode }) {
  return (
    <SummaryCard icon={icon} title={title} onEdit={onEdit} editLabel={editLabel} tint={tint}>
      {children}
    </SummaryCard>
  );
}

/**
 * Label -> value. A yes/no answer shows as a chip on the right so "No" is as
 * visible as "Yes"; an empty value reads "Not provided".
 */
export function ReviewRow({ label, value, items, answer, note, first }: { label: string; value?: string | null; items?: string[]; answer?: "yes" | "no"; note?: string | null; first?: boolean }) {
  const { t } = useTranslation();
  if (answer)
    return (
      <View style={[st.answer, !first && st.divider]} accessible accessibilityLabel={`${label}: ${answer === "yes" ? t("yes") : t("no")}`}>
        <Text style={st.question}>{label}</Text>
        <StatusChip label={answer === "yes" ? t("yes") : t("no")} tone={answer === "yes" ? "info" : "neutral"} />
      </View>
    );
  return <SummaryField first={first} label={label} value={value} items={items} note={note} />;
}

/** Rows from formSummary (reviewRows / summarizeFields). */
export function ReviewRows({ rows }: { rows: SummaryRow[] }) {
  return (
    <>
      {rows.map((r, i) => (
        <ReviewRow key={r.key} first={i === 0} label={r.label} value={r.value} items={r.items} answer={r.answer} />
      ))}
    </>
  );
}

/**
 * A server-schema step or form as a review card: rows come from the same
 * fields the customer filled (labels, option labels, master-list labels in
 * the app language, hidden fields left out). Nothing visible -> nothing shown.
 */
export function SchemaReviewSection({ icon, title, fields, values, labels, onEdit, editLabel, tint }: { icon: LucideIcon; title: string; fields: RiskField[]; values: Record<string, string>; /** Names the pickers already showed (endpoint pickers: policy, insurer, ...), by field key. */ labels?: Record<string, string>; onEdit?: () => void; editLabel?: string; tint?: Tint }) {
  const { language } = useTranslation();
  const master = useMasterLabels(fields);
  const copy = useReviewCopy();
  const resolve = (f: RiskField, code: string) => (labels?.[f.key] && values[f.key] === code ? labels[f.key] : undefined) ?? master(f, code);
  const rows = reviewRows(fields, values, language === "fr" ? "fr" : "en", resolve, copy);
  if (!rows.length) return null;
  return (
    <ReviewSection icon={icon} title={title} onEdit={onEdit} editLabel={editLabel} tint={tint}>
      <ReviewRows rows={rows} />
    </ReviewSection>
  );
}

export type ReviewFile = {
  key: string;
  name: string;
  meta?: string | null;
  /** Local file (just picked): shown as a thumbnail when it is an image. */
  uri?: string | null;
  image?: boolean;
  icon?: LucideIcon;
};

/** Photos as thumbnails, other files as rows (icon, name, meta). */
export function ReviewDocuments({ files, empty }: { files: ReviewFile[]; empty?: string }) {
  const { t } = useTranslation();
  if (!files.length) return <Text style={st.empty}>{empty ?? t("reviewNoDocuments")}</Text>;
  const thumbs = files.filter((f) => f.image && f.uri);
  const rows = files.filter((f) => !(f.image && f.uri));
  return (
    <View style={st.docs}>
      {thumbs.length ? (
        <View style={st.thumbs}>
          {thumbs.map((f) => (
            <View key={f.key} style={st.thumbCell} accessible accessibilityLabel={f.name}>
              <Image source={{ uri: f.uri! }} style={st.thumb} resizeMode="cover" />
              <Text style={st.thumbName} numberOfLines={1}>{f.name}</Text>
            </View>
          ))}
        </View>
      ) : null}
      {rows.map((f, i) => (
        <View key={f.key} style={[st.file, (i > 0 || thumbs.length > 0) && st.divider]}>
          <TintedIcon icon={f.icon ?? FileText} tint="blue" size={40} />
          <View style={st.flex}>
            <Text style={st.fileName} numberOfLines={2}>{f.name}</Text>
            {f.meta ? <Text style={st.meta}>{f.meta}</Text> : null}
          </View>
        </View>
      ))}
    </View>
  );
}

/** Pinned footer of a review: the single primary submit (and an optional way back to the form). */
export function ReviewFooter({ label, onConfirm, loading, disabled, icon, error, backLabel, onBack }: { label: string; onConfirm: () => void; loading?: boolean; disabled?: boolean; icon?: LucideIcon; error?: string | null; backLabel?: string; onBack?: () => void }) {
  const { t } = useTranslation();
  return (
    <CtaBar>
      {error ? <Text accessibilityRole="alert" style={st.error}>{error}</Text> : null}
      <Button label={label} icon={icon ?? ArrowRight} loading={loading} disabled={disabled || loading} onPress={onConfirm} />
      {onBack ? <Button label={backLabel ?? t("reviewBackToForm")} variant="tertiary" disabled={loading} onPress={onBack} /> : null}
    </CtaBar>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  divider: { borderTopWidth: 1, borderTopColor: colors.neutral100 },
  answer: { flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x3 },
  question: { ...type.body, color: colors.navy950, flex: 1 },
  empty: { ...type.body, color: colors.neutral500 },
  docs: { gap: space.x2 },
  thumbs: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  thumbCell: { width: 88, gap: 4 },
  thumb: { width: 88, height: 88, borderRadius: radius.control, backgroundColor: colors.neutral100 },
  thumbName: { ...type.meta, color: colors.neutral600 },
  file: { flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x2 },
  fileName: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
