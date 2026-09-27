import React from "react";
import { useLocalSearchParams } from "expo-router";
import { DetailScreen, DetailSection, UnavailableSection, useListRecord } from "@/components/detail";
import { BrokerWorkspaceApi, humanize, shortDate, type BrokerStaffMember } from "@/api/partner";
import { useTranslation } from "@/i18n";

type Row = BrokerStaffMember & { id: string; can_admin: boolean };

const load = async (): Promise<Row[]> => {
  const d = await BrokerWorkspaceApi.staff();
  // can_invite is the server admin signal: admin tools only show with it.
  return d.members.map((m) => ({ ...m, id: m.membership_id, can_admin: d.can_invite }));
};

/** BRK-010 staff member; lifecycle actions are admin-only and await backend endpoints. */
export default function BrokerStaffDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useListRecord(load, id);
  return (
    <DetailScreen title={t("brStaff")} subtitle={(m) => m?.full_name} query={q} isMissing={(m) => m === null}>
      {(m) =>
        m ? (
          <>
            <DetailSection
              title={m.full_name}
              rows={[
                [t("pcStatus"), humanize(m.status)],
                [t("bkRole"), humanize(m.role_code)],
                [t("agClientPhone"), m.phone_e164],
                [t("bkSince"), shortDate(m.since)],
              ]}
            />
            {m.can_admin && !m.is_me ? <UnavailableSection title={t("bkStaffAdmin")} message={t("bkStaffAdminPending")} /> : null}
          </>
        ) : null
      }
    </DetailScreen>
  );
}
