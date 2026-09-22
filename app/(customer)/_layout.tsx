import React from 'react';
import { Redirect, Slot } from 'expo-router';
import { roleToPortal, useSession } from '@/store/session';
export default function CustomerGuard(){const status=useSession(s=>s.status);const workspace=useSession(s=>s.activeWorkspace);if(status==='booting')return null;if(status!=='authenticated')return <Redirect href="/(auth)/sign-in"/>;if(!workspace||roleToPortal(workspace.role_code)!=='customer')return <Redirect href="/access-denied"/>;return <Slot/>;}
