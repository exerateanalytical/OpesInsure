import {
  Briefcase,
  Car,
  HardHat,
  HeartPulse,
  Home,
  LayoutGrid,
  LucideIcon,
  Plane,
  ShieldPlus,
} from "lucide-react-native";
import type { CopyKey } from "@/i18n/strings";

/** Product categories. `id` matches the purchase flow's /quote/product ids
 * and the backend product line codes used for Explore filtering. */
export type Category = {
  id: "motor" | "health" | "travel" | "home" | "business" | "life" | "accident" | "more";
  label: CopyKey;
  caption: CopyKey;
  icon: LucideIcon;
  lines: string[];
};

export const CATEGORIES: Category[] = [
  { id: "motor", label: "catMotor", caption: "catMotorCaption", icon: Car, lines: ["MOTOR", "AUTO"] },
  { id: "health", label: "catHealth", caption: "catHealthCaption", icon: HeartPulse, lines: ["HEALTH", "MEDICAL"] },
  { id: "travel", label: "catTravel", caption: "catTravelCaption", icon: Plane, lines: ["TRAVEL"] },
  { id: "home", label: "catHome", caption: "catHomeCaption", icon: Home, lines: ["HOME", "PROPERTY", "MRH"] },
  { id: "business", label: "catBusiness", caption: "catBusinessCaption", icon: Briefcase, lines: ["BUSINESS", "SME", "COMMERCIAL", "LIABILITY"] },
  { id: "life", label: "catLife", caption: "catLifeCaption", icon: ShieldPlus, lines: ["LIFE"] },
  { id: "accident", label: "catAccident", caption: "catAccidentCaption", icon: HardHat, lines: ["ACCIDENT", "PA"] },
  { id: "more", label: "catMore", caption: "catMoreCaption", icon: LayoutGrid, lines: [] },
];
