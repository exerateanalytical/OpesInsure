import React from "react";
import { FileSignature } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { PartnerProposal, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** Book proposals list shared by /agent/proposals and /broker/proposals. */
export function PartnerProposalsScreen({
  tabs,
  load,
}: {
  tabs: React.ComponentProps<typeof PortalScreen>["tabs"];
  load: () => Promise<PartnerProposal[]>;
}) {
  const { t } = useTranslation();
  const q = useLoad(load, []);
  return (
    <PortalScreen tabs={tabs}>
      <AppHeader title={t("ptProposals")} subtitle={t("ptProposalsSubtitle")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("ptProposalsLoading")}
        emptyTitle={t("ptNoProposals")}
        emptyMessage={t("ptNoProposalsBody")}
      >
        {(x) => (
          <OperationsList
            icon={FileSignature}
            rows={x.map((p) => ({
              id: p.id,
              title: `${p.proposal_number} · ${p.customer_name}`,
              subtitle: [
                p.carrier_name,
                p.total_minor !== null ? money(p.total_minor) : null,
                shortDate(p.submitted_at ?? p.created_at),
              ]
                .filter(Boolean)
                .join(" · "),
              status: p.status,
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}
