import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { Plus, Trash2, UsersRound } from "lucide-react-native";
import { Button, Card, TextField } from "@/components/ui";
import { ChoiceChips, errorMessage, Notice } from "@/components/portal/Workspace";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { Beneficiary, BeneficiaryApi } from "@/api/crm";
import { BeneficiaryDraft, BeneficiaryIssue, beneficiaryPayload, validateBeneficiaries } from "@/lib/crm";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

const toDraft = (b: Beneficiary): BeneficiaryDraft => ({
  designation: b.designation,
  full_name: b.full_name ?? "",
  relationship: b.relationship,
  date_of_birth: b.date_of_birth,
  allocation_pct: String(b.allocation_pct),
  party_id: b.party_id,
  revocable: b.revocable,
});
const blank = (designation: BeneficiaryDraft["designation"] = "PRIMARY"): BeneficiaryDraft => ({ designation, full_name: "", allocation_pct: "" });

/** REQ-CRM-004 — current designations, edit (PRIMARY shares total 100 %), versioned history. Hidden when not permitted. */
export function BeneficiariesSection({ policyId }: { policyId: string }) {
  const { t, td, date } = useTranslation();
  const q = useLoad(() => BeneficiaryApi.list(policyId), [policyId]);
  const [editing, setEditing] = useState<BeneficiaryDraft[] | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [showErrors, setShowErrors] = useState(false);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  const [historyOpen, setHistoryOpen] = useState(false);
  const history = useLoad(() => (historyOpen ? BeneficiaryApi.history(policyId) : Promise.resolve([])), [policyId, historyOpen]);

  const status = (q.error as { status?: number } | null)?.status;
  if (status === 403 || status === 404) return null;

  const issues = editing ? validateBeneficiaries(editing) : [];
  const issueText = (i: BeneficiaryIssue) =>
    i.code === "primaryTotal" || i.code === "contingentTotal" ? t(`benErr_${i.code}`, { total: i.total ?? 0 }) : t(`benErr_${i.code}`);
  const set = (idx: number, patch: Partial<BeneficiaryDraft>) => setEditing((rows) => (rows ?? []).map((r, i) => (i === idx ? { ...r, ...patch } : r)));

  return (
    <Card>
      <View style={s.row}>
        <UsersRound size={18} color={colors.blue600} />
        <Text style={s.title}>{t("benTitle")}</Text>
      </View>
      {q.loading && !q.data ? <LoadingState /> : null}
      {q.error && !q.data ? <ErrorState error={q.error} onRetry={q.reload} /> : null}

      {q.data && !editing ? (
        <>
          {q.data.length ? (
            q.data.map((b) => (
              <View key={b.id} style={s.item}>
                <Text style={s.body}>
                  {b.full_name ?? "—"}
                  {b.relationship ? ` · ${td(`relationship_${b.relationship}`, b.relationship)}` : ""}
                </Text>
                <Text style={s.meta}>
                  {t(b.designation === "PRIMARY" ? "benPrimary" : "benContingent")} · {b.allocation_pct}%{!b.revocable ? ` · ${t("benIrrevocable")}` : ""}
                </Text>
              </View>
            ))
          ) : (
            <Text style={s.meta}>{t("benNone")}</Text>
          )}
          <Button
            label={t("benEdit")}
            variant="secondary"
            disabled={q.data.some((b) => !b.revocable)}
            onPress={() => {
              setEditing(q.data?.length ? q.data.map(toDraft) : [{ ...blank(), allocation_pct: "100" }]);
              setShowErrors(false);
              setMsg(null);
            }}
          />
          <Button label={t("benHistory")} variant="tertiary" onPress={() => setHistoryOpen((o) => !o)} />
          {historyOpen && history.data
            ? history.data.map((set) => (
                <View key={set.set_version} style={s.item}>
                  <Text style={s.meta}>
                    {t("benVersion", { n: set.set_version })}
                    {set.effective_from ? ` · ${date(String(set.effective_from))}` : ""}
                    {set.reason ? ` · ${set.reason}` : ""}
                  </Text>
                  <Text style={s.body}>{set.designations.map((d) => `${d.full_name ?? "—"} ${d.allocation_pct}%`).join(", ")}</Text>
                </View>
              ))
            : null}
        </>
      ) : null}

      {editing ? (
        <>
          {editing.map((r, i) => (
            <View key={i} style={s.editor}>
              <ChoiceChips<BeneficiaryDraft["designation"]>
                label={t("benDesignation")}
                value={r.designation}
                onChange={(designation) => set(i, { designation })}
                options={[
                  { value: "PRIMARY", label: t("benPrimary") },
                  { value: "CONTINGENT", label: t("benContingent") },
                ]}
              />
              <TextField label={t("benName")} value={r.full_name} editable={!r.party_id} onChangeText={(full_name) => set(i, { full_name })} />
              <TextField label={t("benRelationship")} value={r.relationship ?? ""} onChangeText={(relationship) => set(i, { relationship })} />
              <TextField label={t("benShare")} keyboardType="decimal-pad" value={String(r.allocation_pct)} onChangeText={(allocation_pct) => set(i, { allocation_pct })} />
              <Button label={t("benRemove")} icon={Trash2} variant="tertiary" onPress={() => setEditing(editing.filter((_, j) => j !== i))} />
            </View>
          ))}
          <Button label={t("benAdd")} icon={Plus} variant="tertiary" disabled={editing.length >= 20} onPress={() => setEditing([...editing, blank()])} />
          <TextField label={t("benReason")} value={reason} onChangeText={setReason} error={showErrors && !reason.trim() ? t("benReasonRequired") : undefined} />
          {showErrors || editing.every((r) => String(r.allocation_pct) !== "")
            ? issues.map((i, k) => (
                <Text key={k} accessibilityRole="alert" style={s.error}>
                  {i.index !== undefined ? `${i.index + 1}. ` : ""}
                  {issueText(i)}
                </Text>
              ))
            : null}
          <Button
            label={t("benSave")}
            loading={busy}
            onPress={async () => {
              setShowErrors(true);
              if (issues.length || !reason.trim()) return;
              setBusy(true);
              setMsg(null);
              try {
                q.setData(await BeneficiaryApi.replace(policyId, { beneficiaries: beneficiaryPayload(editing), reason: reason.trim() }));
                setEditing(null);
                setReason("");
                if (historyOpen) void history.reload();
                setMsg({ text: t("benSaved"), tone: "ok" });
              } catch (e) {
                setMsg({ text: errorMessage(e), tone: "error" });
              } finally {
                setBusy(false);
              }
            }}
          />
          <Button label={t("cancel")} variant="tertiary" onPress={() => setEditing(null)} />
        </>
      ) : null}
      <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />
    </Card>
  );
}

const s = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
  item: { gap: 2, paddingVertical: space.x1 },
  editor: { gap: space.x2, paddingVertical: space.x2, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.neutral200 },
  error: { ...type.meta, color: colors.dangerText },
});
