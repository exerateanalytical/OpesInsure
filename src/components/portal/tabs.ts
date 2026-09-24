import {
  CircleDollarSign,
  ClipboardCheck,
  ContactRound,
  FileText,
  HandCoins,
  House,
  ShieldAlert,
  UserPlus,
  Wallet,
} from "lucide-react-native";
import type { PortalTab } from "./PortalShell";

/*
 * Max five tabs per portal. Everything else on the owner's menu lives on
 * the portal's dashboard grid (see app/{agent,broker,carrier}/index.tsx);
 * Account and Notifications stay in the PortalHeader icons.
 */

export const agentTabs: PortalTab[] = [
  { label: "Home", icon: House, href: "/agent" },
  { label: "Leads", icon: UserPlus, href: "/agent/leads" },
  { label: "Customers", icon: ContactRound, href: "/agent/clients" },
  { label: "Policies", icon: FileText, href: "/agent/policies" },
  { label: "Earnings", icon: CircleDollarSign, href: "/agent/wallet" },
];

export const brokerTabs: PortalTab[] = [
  { label: "Home", icon: House, href: "/broker" },
  { label: "Customers", icon: ContactRound, href: "/broker/clients" },
  { label: "Policies", icon: FileText, href: "/broker/policies" },
  { label: "Claims", icon: ShieldAlert, href: "/broker/claims" },
  { label: "Earnings", icon: Wallet, href: "/broker/commissions" },
];

export const carrierTabs: PortalTab[] = [
  { label: "Home", icon: House, href: "/carrier" },
  { label: "Underwriting", icon: ClipboardCheck, href: "/carrier/referrals" },
  { label: "Claims", icon: ShieldAlert, href: "/carrier/claims" },
  { label: "Policies", icon: FileText, href: "/carrier/policies" },
  { label: "Payments", icon: HandCoins, href: "/carrier/payments" },
];
