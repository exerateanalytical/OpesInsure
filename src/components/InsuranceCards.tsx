import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Building2, CheckCircle2, ChevronRight, MapPin, ShieldCheck } from 'lucide-react-native';
import { Card, StatusChip } from './ui';
import type { Institution } from '@/api/extra';
import { Policy } from '@/api/client';
import { colors, radius, space, type } from '@/theme/tokens';

export function InsurerCard({ insurer, onPress }: { insurer: Institution; onPress: () => void }) {
  const offers = insurer.products?.length ?? 0;
  return <Pressable onPress={onPress} accessibilityRole="button"><Card><View style={styles.row}><View style={styles.logo}><Text style={styles.logoText}>{insurer.initials}</Text></View><View style={styles.copy}><Text style={styles.title}>{insurer.name}</Text>{insurer.city ? <Text style={styles.meta}>{insurer.city}</Text> : null}</View><ChevronRight size={20} color={colors.neutral500}/></View>{offers > 0 && <View style={styles.verified}><CheckCircle2 size={16} color={colors.success}/><Text style={styles.verifiedText}>{offers} product{offers > 1 ? 's' : ''} available on OpesInsure</Text></View>}</Card></Pressable>;
}

export function BrokerCard({ broker, onPress }: { broker: Institution; onPress: () => void }) {
  return <Pressable onPress={onPress} accessibilityRole="button"><Card><View style={styles.row}><View style={styles.brokerIcon}><Building2 size={20} color={colors.navy800}/></View><View style={styles.copy}><Text style={styles.title}>{broker.name}</Text>{broker.city ? <View style={styles.inline}><MapPin size={14} color={colors.neutral500}/><Text style={styles.meta}>{broker.city}</Text></View> : null}</View><ChevronRight size={20} color={colors.neutral500}/></View>{broker.licence_number ? <StatusChip label={`Licence ${broker.licence_number}`} tone="info"/> : null}</Card></Pressable>;
}

export function PolicyCard({policy,onPress}:{policy:Policy;onPress?:()=>void}) { const active=policy.status==='ACTIVE';return <Pressable accessibilityRole={onPress?'button':undefined} onPress={onPress}><Card><View style={styles.between}><View style={styles.inline}><View style={styles.logo}><ShieldCheck size={20} color={colors.blue600}/></View><View><Text style={styles.title}>Insurance policy</Text><Text style={styles.meta}>Carrier reference available in details</Text></View></View><StatusChip label={policy.status} tone={active?'success':'neutral'}/></View><Text style={styles.ref}>{policy.policy_number}</Text><View style={styles.between}><Text style={styles.meta}>{new Date(policy.coverage_starts_at).toLocaleDateString()} — {new Date(policy.coverage_ends_at).toLocaleDateString()}</Text>{onPress&&<Text style={styles.link}>View policy</Text>}</View></Card></Pressable>; }

const styles=StyleSheet.create({row:{flexDirection:'row',alignItems:'center',gap:space.x3},copy:{flex:1,gap:3},logo:{width:42,height:42,borderRadius:radius.control,backgroundColor:colors.blue50,alignItems:'center',justifyContent:'center'},logoText:{...type.caption,color:colors.blue700},brokerIcon:{width:42,height:42,borderRadius:radius.control,backgroundColor:colors.neutral100,alignItems:'center',justifyContent:'center'},title:{...type.label,color:colors.navy950},meta:{...type.meta,color:colors.neutral600},verified:{flexDirection:'row',alignItems:'center',gap:space.x2},verifiedText:{...type.meta,color:colors.successText},inline:{flexDirection:'row',alignItems:'center',gap:6},between:{flexDirection:'row',alignItems:'center',justifyContent:'space-between',gap:space.x3},ref:{...type.body,color:colors.navy950,fontVariant:['tabular-nums']},link:{...type.label,color:colors.blue600}});
