import { roleToPortal, useSession } from "@/store/session";

/** True when the active workspace is the Commercial Agent portal (screens shared with the customer app switch layout on this). */
export const useIsAgentPortal = () => useSession((s) => roleToPortal(s.activeWorkspace?.role_code) === "agent");
