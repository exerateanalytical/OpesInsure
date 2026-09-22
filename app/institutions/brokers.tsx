import React, { useMemo, useState } from 'react';
import { StyleSheet, Text } from 'react-native';
import { router } from 'expo-router';
import { AlertTriangle } from 'lucide-react-native';
import { AppHeader, Card, Screen, TextField } from '@/components/ui';
import { BrokerCard } from '@/components/InsuranceCards';
import { brokers } from '@/data/brokers';
import { colors, type } from '@/theme/tokens';
export default function Brokers(){const [query,setQuery]=useState('');const filtered=useMemo(()=>brokers.filter(b=>`${b.name} ${b.city}`.toLowerCase().includes(query.toLowerCase())),[query]);return <Screen><AppHeader title="Insurance brokers" subtitle={`${brokers.length} records from the official MINFI 2022 publication`} back/><Card><AlertTriangle size={20} color={colors.warning}/><Text style={styles.title}>Licence status must be re-verified</Text><Text style={styles.body}>This directory faithfully preserves MINFI’s dated publication. It must not be interpreted as proof that a broker remains licensed today. Current verification will connect to MINFI when authoritative live data becomes available.</Text></Card><TextField label="Search broker directory" value={query} onChangeText={setQuery} placeholder="Broker name or city"/>{filtered.map(b=><BrokerCard key={b.id} broker={b} onPress={()=>router.push({pathname:'/institutions/broker/[id]',params:{id:b.id}})}/>)}</Screen>}
const styles=StyleSheet.create({title:{...type.cardTitle,color:colors.navy950},body:{...type.body,color:colors.neutral600}});
