import React from "react";
import { StepIndicator } from "@/components/design";
import { useTranslation } from "@/i18n";

/** The four FNOL wizard steps: Select Policy → Incident → Evidence → Review. */
export function useClaimWizardSteps() {
  const { t } = useTranslation();
  return [t("claimStepSelectPolicy"), t("claimStepIncident"), t("claimStepEvidence"), t("claimStepReview")];
}

export function ClaimWizardSteps({ current }: { current: 0 | 1 | 2 | 3 }) {
  return <StepIndicator steps={useClaimWizardSteps()} current={current} />;
}
