import type { Metadata } from 'next';
import { Geist, Geist_Mono } from 'next/font/google';
import './globals.css';
import Providers from './providers';
import DemoBanner from '@/components/shared/DemoBanner';

const geistSans = Geist({ subsets: ['latin'], variable: '--font-sans' });
const geistMono = Geist_Mono({ subsets: ['latin'], variable: '--font-mono' });

async function getFaviconUrl(): Promise<string | null> {
  try {
    const base = process.env.BACKEND_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';
    const res = await fetch(`${base}/guest-settings`, { next: { revalidate: 300 } });
    if (!res.ok) return null;
    const json = await res.json();
    return json?.data?.favicon_url ?? null;
  } catch {
    return null;
  }
}

export async function generateMetadata(): Promise<Metadata> {
  const faviconUrl = await getFaviconUrl();
  return {
    title: 'SureSign Contracts – Construction Contract Administration',
    description: 'AI-powered construction administration & contract management platform',
    icons: {
      icon: faviconUrl
        ? [{ url: faviconUrl, sizes: 'any' }]
        : [
            { url: '/favicon.svg', type: 'image/svg+xml' },
            { url: '/favicon.ico', sizes: 'any' },
          ],
    },
  };
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className={`${geistSans.variable} ${geistMono.variable}`} suppressHydrationWarning>
      <head>
        {/* Inline script prevents flash of wrong theme/accent before React
            hydrates. Theme is stored per-user (`suresign-theme-${userId}`,
            see useTheme.ts) — this reads the SAME key that hook reads, by
            pulling the current user id out of the auth store's own
            Zustand-persisted blob (`suresign-auth`, written synchronously to
            localStorage on every login/logout — see authStore.ts), rather
            than a second, inconsistent bootstrap key. Falls back to the
            'guest' bucket useTheme.ts itself uses when no user is logged in
            yet. Only a numeric id is ever read here — no email/roles/other
            user data touches this script. */}
        <script dangerouslySetInnerHTML={{ __html: `try{
  var uid='guest';
  var raw=localStorage.getItem('suresign-auth');
  if(raw){var parsed=JSON.parse(raw);var u=parsed&&parsed.state&&parsed.state.user;if(u&&u.id)uid=u.id;}
  var t=localStorage.getItem('suresign-theme-'+uid)||'light';
  document.documentElement.setAttribute('data-theme',t);
}catch(e){}` }} />
      </head>
      <body suppressHydrationWarning>
        <DemoBanner />
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
