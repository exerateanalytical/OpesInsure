import React from 'react';
import { AppHeader, Screen, SectionTitle } from '@/components/ui';
import { PolicyCard } from '@/components/InsuranceCards';
import { router } from 'expo-router';
import { usePolicies } from '@/hooks/usePolicies';
import { EmptyState, ErrorState, LoadingState } from '@/components/StatePanel';
export default function Policies(){const{policies,loading,error,reload}=usePolicies();return <Screen><AppHeader title="Policies" subtitle="Your active and previous protection"/>{loading?<LoadingState label="Loading policies…"/>:error?<ErrorState onRetry={()=>void reload()}/>:policies.length?<><SectionTitle title="Your policies"/>{policies.map(policy=><PolicyCard key={policy.id} policy={policy} onPress={()=>router.push({pathname:'/policy/[id]',params:{id:policy.id}})}/>)}</>:<EmptyState title="No policies found" message="Your issued policies will appear here." action="Compare offers" onPress={()=>router.push('/quote/product')}/>}</Screen>}
