// Login — autentikasi nyata ke J-ERP (email + password → token Sanctum).

import React, { useState } from 'react';
import { View, Text, StyleSheet, Pressable, TextInput } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { colors, radius } from '../theme/tokens';
import { Button, SectionLabel } from '../components/ui';
import { ShieldCheck, FaceIcon } from '../components/Icons';
import { useApp } from '../state/AppContext';

export default function LoginScreen() {
  const { login } = useApp();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (loading) return;
    setError(null);
    setLoading(true);
    try {
      await login(email.trim(), password);
    } catch (e: any) {
      setError(e?.message || 'Login gagal. Cek email/password & koneksi.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <View style={styles.login}>
        <View style={styles.logo}>
          <ShieldCheck size={32} color={colors.white} />
        </View>
        <Text style={styles.h2}>Approval</Text>
        <Text style={styles.tag}>Heads approvals · pop-up & sign-off</Text>

        <View style={styles.field}>
          <Text style={styles.label}>Work email</Text>
          <TextInput
            style={styles.input}
            value={email}
            onChangeText={setEmail}
            placeholder="email@perusahaan.co.id"
            placeholderTextColor={colors.slate400}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            editable={!loading}
          />
        </View>

        <View style={styles.field}>
          <Text style={styles.label}>Password</Text>
          <View style={[styles.input, styles.inputRow]}>
            <TextInput
              style={styles.flexInput}
              value={password}
              onChangeText={setPassword}
              placeholder="••••••••••"
              placeholderTextColor={colors.slate400}
              secureTextEntry={!show}
              autoCapitalize="none"
              editable={!loading}
              onSubmitEditing={submit}
            />
            <Pressable onPress={() => setShow((s) => !s)}>
              <Text style={styles.show}>{show ? 'hide' : 'show'}</Text>
            </Pressable>
          </View>
        </View>

        {error ? <Text style={styles.error}>{error}</Text> : null}

        <Button label={loading ? 'Masuk…' : 'Sign in'} onPress={submit} />

        <Pressable style={styles.bio} onPress={submit}>
          <FaceIcon size={30} color={colors.blue} />
          <Text style={styles.bioText}>Use Face ID</Text>
          <SectionLabel style={styles.roleNote}>For GM · Director · Owners only</SectionLabel>
        </Pressable>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  login: { flex: 1, alignItems: 'center', paddingHorizontal: 26 },
  logo: {
    width: 64,
    height: 64,
    borderRadius: radius.xl,
    backgroundColor: colors.blue,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 40,
    marginBottom: 14,
    shadowColor: colors.blue,
    shadowOpacity: 0.35,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 12 },
    elevation: 6,
  },
  h2: { fontSize: 21, fontWeight: '700', color: colors.ink, marginBottom: 3 },
  tag: { fontSize: 12.5, color: colors.sub, marginBottom: 26 },
  field: { width: '100%', marginBottom: 12 },
  label: { fontSize: 11, color: colors.sub, fontWeight: '600', marginBottom: 5 },
  input: {
    width: '100%',
    borderWidth: 1.5,
    borderColor: colors.line,
    borderRadius: radius.md,
    padding: 12,
    backgroundColor: colors.white,
    fontSize: 13,
    color: colors.ink,
  },
  inputRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  flexInput: { flex: 1, fontSize: 13, color: colors.ink, padding: 0 },
  show: { color: colors.slate400, fontSize: 11 },
  error: { width: '100%', color: '#dc2626', fontSize: 12, marginBottom: 10 },
  bio: { marginTop: 'auto', marginBottom: 26, alignItems: 'center', gap: 7 },
  bioText: { color: colors.blue, fontSize: 12.5, fontWeight: '700' },
  roleNote: { color: colors.slate400, fontSize: 10.5, textTransform: 'none', letterSpacing: 0 },
});
