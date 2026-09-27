import React, { useMemo, useState } from "react";
import { Share, StyleSheet, Text } from "react-native";
import { MailPlus, UserRound } from "lucide-react-native";
import { router } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, SectionTitle, TextField } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { FilteredList } from "@/components/partner/FilteredList";
import { byDate, byText, optionsFrom, sortSection, type FilterSection, type Matchers, type Sorters } from "@/components/filters";
import { errorMessage, Notice } from "@/components/portal/Workspace";
import { BrokerInvitation, BrokerStaffMember, BrokerWorkspaceApi, humanize, shortDate } from "@/api/partner";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

const matchers: Matchers<BrokerStaffMember & { id: string }> = {
  role: (m, v) => m.role_code === v,
  status: (m, v) => m.status === v,
};
const sorters: Sorters<BrokerStaffMember & { id: string }> = {
  name: byText((m) => m.full_name),
  recent: byDate((m) => m.since),
};
const haystack = (m: BrokerStaffMember) => [m.full_name, m.phone_e164, m.role_code, m.status];

export default function BrokerStaffScreen() {
  const { t, td } = useTranslation();
  const q = useLoad(() => BrokerWorkspaceApi.staff(), []);
  const members = useMemo(() => (q.data?.members ?? []).map((m) => ({ ...m, id: m.membership_id })), [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "role", title: t("brRole"), options: optionsFrom(members, (m) => ({ value: m.role_code, label: td(`role_${m.role_code}`, humanize(m.role_code)) })) },
      { key: "status", title: t("pcStatus"), options: optionsFrom(members, (m) => ({ value: m.status, label: td(`memberStatus_${m.status}`, humanize(m.status)) })) },
      sortSection(t, [
        { value: "name", label: t("fltSortName") },
        { value: "recent", label: t("fltSortRecent") },
      ]),
    ],
    [members, t, td],
  );
  const [phone, setPhone] = useState("+237");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [issued, setIssued] = useState<BrokerInvitation | null>(null);
  return (
    <Screen>
      <AppHeader title={t("brStaff")} subtitle={t("brStaffSubtitle")} back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("brLoadingStaff")}
        isEmpty={(d) => d.members.length === 0 && d.pending_invitations.length === 0}
        emptyTitle={t("brNoStaff")}
        emptyMessage={t("brNoStaffBody")}
      >
        {(d) => (
          <>
            {d.members.length ? (
              <FilteredList
                list="broker.staff"
                rows={members}
                sections={sections}
                matchers={matchers}
                haystack={haystack}
                sorters={sorters}
                icon={UserRound}
                onPress={(m) => router.push({ pathname: "/broker/staff/[id]", params: { id: m.membership_id } })}
                render={(m) => ({
                  title: m.is_me ? t("brStaffYou", { name: m.full_name }) : m.full_name,
                  subtitle: [td(`role_${m.role_code}`, humanize(m.role_code)), m.phone_e164, m.since ? t("brSince", { date: shortDate(m.since) }) : null].filter(Boolean).join(" · "),
                  status: td(`memberStatus_${m.status}`, humanize(m.status)),
                })}
              />
            ) : null}
            {d.pending_invitations.length > 0 ? (
              <>
                <SectionTitle title={t("brPendingInvitations")} />
                <OperationsList
                  icon={MailPlus}
                  rows={d.pending_invitations.map((i) => ({
                    id: i.id,
                    title: i.recipient,
                    subtitle: `${td(`role_${i.role_code}`, humanize(i.role_code))} · ${t("brExpiresOn", { date: shortDate(i.expires_at) })}`,
                    status: td("memberStatus_PENDING", humanize("PENDING")),
                  }))}
                />
              </>
            ) : null}
            <SectionTitle title={t("brInviteStaff")} />
            <Card>
              {d.can_invite ? (
                <>
                  <TextField label={t("brStaffPhone")} keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
                  <Notice text={error} tone="error" />
                  <Button
                    label={t("brInviteAsStaff")}
                    icon={MailPlus}
                    loading={busy}
                    disabled={phone.replace(/\D/g, "").length < 8}
                    onPress={async () => {
                      setBusy(true);
                      setError(null);
                      try {
                        setIssued(await BrokerWorkspaceApi.inviteStaff({ recipient_phone_e164: phone.trim() }));
                        setPhone("+237");
                        void q.reload();
                      } catch (e) {
                        setError(errorMessage(e));
                      } finally {
                        setBusy(false);
                      }
                    }}
                  />
                  {issued ? (
                    <>
                      <Text style={s.body}>
                        Invitation created for {issued.recipient}. Share this one-time code with them privately;
                        they enter it after signing in with that phone number. It is shown only once.
                      </Text>
                      <Text selectable style={s.code}>{issued.invite_code}</Text>
                      <Button
                        label={t("brShareCode")}
                        variant="secondary"
                        onPress={() => void Share.share({ message: t("brInviteShareMessage", { code: issued.invite_code }) })}
                      />
                    </>
                  ) : null}
                </>
              ) : (
                <Text style={s.body}>{t("brOnlyAdminInvites")}</Text>
              )}
            </Card>
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

const s = StyleSheet.create({
  body: { ...type.body, color: colors.neutral600 },
  code: { ...type.meta, color: colors.navy950, fontFamily: "monospace" },
});
