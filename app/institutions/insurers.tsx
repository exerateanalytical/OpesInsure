import React, { useMemo, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { AppHeader, Screen, StatusChip, TextField } from '@/components/ui';
import { InsurerCard } from '@/components/InsuranceCards';
import { insurers } from '@/data/insurers';
import { colors, space, type } from '@/theme/tokens';
export default function Insurers(){const [query,setQuery]=useState('');const filtered=useMemo(()=>insurers.filter(i=>i.name.toLowerCase().includes(query.toLowerCase())),[query]);return <Screen><AppHeader title="Insurance companies" subtitle={`${insurers.length} current ASAC directory entries · life and non-life`} back/><TextField label="Search companies" value={query} onChangeText={setQuery} placeholder="Name or branch"/><View style={styles.source}><StatusChip label="Institutional data" tone="info"/><Text style={styles.note}>Company membership verified against ASAC on 22 September 2026. Product availability is shown only when confirmed on an official website.</Text></View>{filtered.map(i=><InsurerCard key={i.id} insurer={i} onPress={()=>router.push({pathname:'/institutions/insurer/[id]',params:{id:i.id}})}/>)}</Screen>}
const styles=StyleSheet.create({source:{gap:space.x2},note:{...type.meta,color:colors.neutral600}});
