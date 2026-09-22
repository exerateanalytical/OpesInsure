import { Insurer } from '@/types/domain';

const ASAC_NON_LIFE = 'https://www.asac-cameroun.org/groupes/assureurs-non-vie/';
const ASAC_LIFE = 'https://www.asac-cameroun.org/groupes/assureurs-vie/';
const verifiedOn = '2026-09-22';
const offer = (id: string, name: string, kind: any, summary: string, sourceUrl: string, onlineAction: any = 'information') =>
  ({ id, name, kind, summary, sourceUrl, onlineAction, verifiedOn });

export const insurers: Insurer[] = [
  {id:'activa',name:'ACTIVA Assurances',branch:'non_life',city:'Douala',phone:'+237 233 501 300',website:'https://www.activa-cameroun.com',initials:'AA',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'afg',name:'AFG Assurances',branch:'non_life',city:'Douala',phone:'+237 243 88 89 54',website:'https://atlantiqueassurances.cm',initials:'AFG',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'afri',name:'AFRI Insurance S.A',branch:'non_life',city:'Douala',phone:'+237 681 07 14 14',initials:'AI',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'area',name:'Assurances et Réassurance Africaines (AREA)',branch:'non_life',city:'Douala',phone:'+237 233 438 232',initials:'AR',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'agc',name:'Assurances Générales du Cameroun (AGC)',branch:'non_life',city:'Douala',phone:'+237 233 43 89 37',website:'https://www.agc-assurances.com',initials:'AGC',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'axa',name:'AXA Assurances Cameroun',branch:'non_life',city:'Douala',phone:'+237 233 42 31 71',website:'https://www.axa.cm',initials:'AXA',offers:[offer('axa-elite','Elite Voyage','health','International medical network and concierge health support.','https://www.axa.cm/sante/elite-voyage/')],source:ASAC_NON_LIFE,verifiedOn},
  {id:'belife-general',name:'Belife General Insurance',branch:'non_life',city:'Douala',phone:'+237 233 42 38 51',website:'https://www.beneficial-general.cm',initials:'BG',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'chanas',name:'Chanas Assurances',branch:'non_life',city:'Douala',phone:'+237 233 42 14 74',website:'https://www.chanasassurances.com',initials:'CA',offers:[
    offer('chanas-auto','Chanas Assur AUTO','motor','Motor third-party liability with optional vehicle protections.','https://www.chanasassurances.com/nos-offres/particulier/assurance-auto/','quote'),
    offer('chanas-voyage','Chanas Assur Voyage','travel','Travel accident, illness, assistance and repatriation cover.','https://www.chanasassurances.com/nos-offres/particulier/assurance-voyage/','quote'),
    offer('chanas-home','Multirisque Habitation','home','Protection for residential buildings and contents.','https://www.chanasassurances.com/nos-offres/particulier/multirisques-habitation/','quote'),
    offer('chanas-health','Chanas Assur Santé','health','Ambulatory and hospital care with selectable territories.','https://www.chanasassurances.com/nos-offres/entreprise/sante-famille/','quote')
  ],source:ASAC_NON_LIFE,verifiedOn},
  {id:'cpa',name:'Compagnie Professionnelle Assurances (CPA)',branch:'non_life',city:'Douala',phone:'+237 233 43 43 81',website:'https://www.cpa-cameroun.com',initials:'CPA',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'gmc',name:'GMC Assurances S.A',branch:'non_life',city:'Douala',phone:'+237 233 43 21 33',website:'https://www.gmcassurances.com',initials:'GMC',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'ld',name:'LD Assurances S.A',branch:'non_life',city:'Douala',phone:'+237 233 439 924',initials:'LD',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'nsia',name:'NSIA Assurances',branch:'non_life',city:'Douala',phone:'+237 233 433 113',website:'https://www.groupensia.com',initials:'NSIA',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'pro-assur',name:'PRO ASSUR',branch:'non_life',city:'Douala',phone:'+237 233 43 70 05',website:'https://wafaassurance.cm',initials:'PA',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'royal-onyx',name:'Royal Onyx Insurance CIE',branch:'non_life',city:'Douala',phone:'+237 243 66 38 83',website:'https://www.royalonyx.cm',initials:'RO',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'saar',name:'SAAR Assurances',branch:'non_life',city:'Douala',phone:'+237 233 43 92 00',website:'https://www.saar-assurances.com',initials:'SA',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'sanlamallianz',name:'SanlamAllianz Cameroun Assurances',branch:'non_life',city:'Douala',phone:'+237 233 502 000',website:'https://cm.sanlamallianz.com',initials:'SA',offers:[
    offer('sa-auto','Assurance Automobile','motor','Motor insurance for individuals.','https://cm.sanlamallianz.com/'),
    offer('sa-voyage','Assurance Voyage','travel','Travel insurance and assistance.','https://cm.sanlamallianz.com/'),
    offer('sa-home','Multirisque Habitation','home','Cover for home-related risks.','https://cm.sanlamallianz.com/'),
    offer('sa-hospicare','HospiCare','health','Digital hospital protection solution.','https://cm.sanlamallianz.com/')
  ],source:ASAC_NON_LIFE,verifiedOn},
  {id:'sunu',name:'SUNU Assurances',branch:'non_life',city:'Douala',phone:'+237 233 42 84 80',website:'https://sunu-group.com',initials:'SUNU',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'zenithe',name:'Zenithe Insurance',branch:'non_life',city:'Douala',phone:'+237 233 43 41 32',website:'https://zenitheinsurance.com',initials:'ZI',offers:[],source:ASAC_NON_LIFE,verifiedOn},
  {id:'acam-vie',name:'ACAM Vie',branch:'life',city:'Douala',phone:'+237 650 591 938',website:'https://www.acamvie.com',initials:'AV',offers:[],source:ASAC_LIFE,verifiedOn},
  {id:'activa-vie',name:'ACTIVA Vie',branch:'life',city:'Douala',phone:'+237 233 501 300',website:'https://www.activa-cameroun.com',initials:'AV',offers:[],source:ASAC_LIFE,verifiedOn},
  {id:'afrilife',name:'Afrilife Insurance Cameroun S.A',branch:'life',city:'Douala',phone:'+237 689 14 14 14',initials:'AF',offers:[],source:ASAC_LIFE,verifiedOn},
  {id:'belife',name:'Belife Insurance',branch:'life',city:'Douala',phone:'+237 233 42 38 51',website:'https://www.beneficial-general.cm',initials:'BI',offers:[],source:ASAC_LIFE,verifiedOn},
  {id:'chanas-vie',name:'Chanas Assurances Vie',branch:'life',city:'Douala',phone:'+237 233 42 14 74',website:'https://www.chanasassurances.com',initials:'CV',offers:[],source:ASAC_LIFE,verifiedOn},
  {id:'nsia-vie',name:'NSIA Vie Assurances',branch:'life',city:'Douala',phone:'+237 233 433 113',website:'https://www.groupensia.com',initials:'NV',offers:[],source:ASAC_LIFE,verifiedOn},
  {id:'saar-vie',name:'SAAR Vie',branch:'life',city:'Yaoundé',phone:'+237 222 233 174',website:'https://www.saar-assurances.com',initials:'SV',offers:[],source:ASAC_LIFE,verifiedOn},
  {id:'sanlamallianz-vie',name:'SanlamAllianz Cameroun Assurances Vie',branch:'life',city:'Douala',phone:'+237 233 430 940',website:'https://cm.sanlamallianz.com',initials:'SAV',offers:[offer('sa-education','Education','education','Education protection and savings solution.','https://cm.sanlamallianz.com/'),offer('sa-family','Protection familiale','life','Family protection solution.','https://cm.sanlamallianz.com/'),offer('sa-retirement','Épargne et retraite','life','Savings and retirement solutions.','https://cm.sanlamallianz.com/')],source:ASAC_LIFE,verifiedOn},
  {id:'sonam-vie',name:'SONAM Vie',branch:'life',city:'Douala',phone:'+237 233 42 13 11',initials:'SV',offers:[],source:ASAC_LIFE,verifiedOn},
  {id:'sunu-vie',name:'SUNU Assurances Vie',branch:'life',city:'Douala',phone:'+237 233 42 12 46',website:'https://cameroun.vie.sunu-group.com',initials:'SV',offers:[],source:ASAC_LIFE,verifiedOn},
  {id:'wafa-vie',name:'WAFA Vie',branch:'life',city:'Douala',phone:'+237 233 43 17 95',initials:'WV',offers:[],source:ASAC_LIFE,verifiedOn}
];

export const verifiedOffers = insurers.flatMap((insurer) => insurer.offers.map((item) => ({ ...item, insurerId: insurer.id, insurerName: insurer.name })));
