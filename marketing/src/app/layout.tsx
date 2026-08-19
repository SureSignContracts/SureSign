import type { Metadata } from 'next';
import { Geist, Geist_Mono } from 'next/font/google';
import './globals.css';

const geistSans = Geist({ subsets: ['latin'], variable: '--font-sans' });
const geistMono = Geist_Mono({ subsets: ['latin'], variable: '--font-mono' });

export const metadata: Metadata = {
  metadataBase: new URL('https://suresigncontracts.app'),
  title: {
    default: 'SureSign | Construction Contract Administration, Connected',
    template: '%s | SureSign',
  },
  description:
    'Automated contract analysis, trade packages, payment applications, statutory notices, programme, risk, drawings, and documentation in one connected platform for construction contract administration.',
  icons: {
    icon: [
      { url: '/favicon.svg', type: 'image/svg+xml' },
      { url: '/favicon.ico', sizes: 'any' },
    ],
  },
  openGraph: {
    title: 'SureSign | Construction Contract Administration, Connected',
    description:
      'One connected platform for construction contract administration: automated contract analysis, trade packages, payment applications, statutory notices, programme, risk, drawings, and documentation.',
    url: 'https://suresigncontracts.app',
    siteName: 'SureSign',
    locale: 'en_GB',
    type: 'website',
    images: [{ url: '/opengraph-image', width: 1200, height: 630, alt: 'SureSign construction contract administration' }],
  },
  twitter: {
    card: 'summary_large_image',
    title: 'SureSign | Construction Contract Administration, Connected',
    description:
      'Human-reviewed contract intelligence connected to commercial workflows and one project record.',
    images: ['/opengraph-image'],
  },
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className={`${geistSans.variable} ${geistMono.variable}`} suppressHydrationWarning>
      <head>
        {/* Blocking, pre-hydration theme resolution — stored preference wins,
            otherwise the OS preference, otherwise light. Runs before first
            paint so there is no flash and nothing for React to reconcile
            against (data-theme is never read during render). */}
        <script
          dangerouslySetInnerHTML={{
            __html: `try{var s=localStorage.getItem('suresign-theme');var t=s||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);}catch(e){}`,
          }}
        />
      </head>
      <body suppressHydrationWarning>
        <a href="#main-content" className="skip-link">Skip to main content</a>
        {children}
      </body>
    </html>
  );
}
