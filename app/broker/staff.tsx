import React, { useState } from "react";
import { Share, StyleSheet, Text } from "react-native";
import { MailPlus, UserRound } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, SectionTitle, TextField } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { errorMessage, Notice } from "@/components/portal/Workspace";
import { BrokerInvitation, BrokerWorkspaceApi, humanize, shortDate } from "@/api/partner";
import { colors, type } from "@/theme/tokens";

export default function BrokerStaffScreen() {
  const q = useLoad(() => BrokerWorkspaceApi.staff(), []);
  const [phone, setPhone] = useState("+237");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [issued, setIssued] = useState<BrokerInvitation | null>(null);
  return (
    <Screen>
      <AppHeader title="Staff" subtitle="People who work in your broker office" back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading staff…"
        isEmpty={(d) => d.members.length === 0 && d.pending_invitations.length === 0}
        emptyTitle="No staff yet"
        emptyMessage="Staff memberships appear here once invitations are accepted."
      >
        {(d) => (
          <>
            <OperationsList
              icon={UserRound}
              rows={d.members.map((m) => ({
                id: m.membership_id,
                title: m.is_me ? `${m.full_name} (you)` : m.full_name,
                subtitle: [humanize(m.role_code), m.phone_e164, m.since ? `since ${shortDate(m.since)}` : null].filter(Boolean).join(" · "),
                status: m.status,
              }))}
            />
            {d.pending_invitations.length > 0 ? (
              <>
                <SectionTitle title="Pending invitations" />
                <OperationsList
                  icon={MailPlus}
                  rows={d.pending_invitations.map((i) => ({
                    id: i.id,
                    title: i.recipient,
                    subtitle: `${humanize(i.role_code)} · expires ${shortDate(i.expires_at)}`,
                    status: "PENDING",
                  }))}
                />
              </>
            ) : null}
            <SectionTitle title="Invite staff" />
            <Card>
              {d.can_invite ? (
                <>
                  <TextField label="Staff member phone" keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
                  <Notice text={error} tone="error" />
                  <Button
                    label="Invite as broker staff"
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
                        label="Share code"
                        variant="secondary"
                        onPress={() => void Share.share({ message: `Your OpesInsure broker staff invitation code: ${issued.invite_code}` })}
                      />
                    </>
                  ) : null}
                </>
              ) : (
                <Text style={s.body}>Only a broker administrator can invite staff. Ask your administrator.</Text>
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
