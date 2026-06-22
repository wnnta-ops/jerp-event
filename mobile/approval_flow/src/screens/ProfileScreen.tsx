// Profile — identitas approver + sign out. (Hanya approve/reject + riwayat;
// role-switch / simulasi offline / BPP dihilangkan.)

import React from 'react';
import { View, Text, StyleSheet, ScrollView } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { colors } from '../theme/tokens';
import { Avatar, Button } from '../components/ui';
import { useApp } from '../state/AppContext';

export default function ProfileScreen() {
  const { profile, signOut } = useApp();

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.head}>
        <Text style={styles.title}>Profile</Text>
      </View>

      <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
        <View style={styles.identity}>
          <Avatar initial={profile.init} size="xl" />
          <Text style={styles.name}>{profile.name}</Text>
          <Text style={styles.role}>{profile.role}</Text>
        </View>

        <View style={styles.prow}>
          <Text style={styles.pl}>Status</Text>
          <Text style={styles.pr}>Aktif</Text>
        </View>
        <View style={styles.prow}>
          <Text style={styles.pl}>Critical alerts</Text>
          <Text style={styles.pr}>Enabled</Text>
        </View>

        <View style={styles.signout}>
          <Button label="Sign out" variant="ghost" color={colors.red} onPress={signOut} />
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  head: { paddingHorizontal: 16, paddingTop: 8 },
  title: { fontSize: 18, fontWeight: '800', color: colors.ink },
  scroll: { flex: 1 },
  scrollContent: { paddingHorizontal: 16, paddingBottom: 24 },

  identity: { alignItems: 'center', paddingTop: 10, paddingBottom: 16 },
  name: { fontSize: 17, fontWeight: '800', color: colors.ink, marginTop: 10 },
  role: { fontSize: 12.5, color: colors.sub },

  prow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 13, borderBottomWidth: 1, borderBottomColor: colors.line },
  pl: { fontWeight: '600', fontSize: 13, color: colors.ink },
  pr: { marginLeft: 'auto', color: colors.sub, fontSize: 12 },

  signout: { marginTop: 18 },
});
