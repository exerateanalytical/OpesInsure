export type AppRole = 'customer' | 'agent' | 'broker_admin' | 'broker_staff' | 'carrier' | 'platform_admin';
export type InsuranceBranch = 'non_life' | 'life';
export type ProductKind = 'motor' | 'health' | 'travel' | 'home' | 'life' | 'accident' | 'education' | 'business';

export type VerifiedOffer = {
  id: string; name: string; kind: ProductKind; summary: string; sourceUrl: string; verifiedOn: string;
  onlineAction: 'quote' | 'information' | 'appointment';
};

export type Insurer = {
  id: string; name: string; branch: InsuranceBranch; city: string; phone?: string; website?: string;
  initials: string; offers: VerifiedOffer[]; source: string; verifiedOn: string;
};

export type Broker = {
  id: string; name: string; city: string; address?: string; phones?: string[]; licenceSourceYear: number;
  status: 'historical_official_listing' | 'current_verified'; source: string;
};
