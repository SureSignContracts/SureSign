'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';

// My Support Requests was merged into /app/help/support (as its "My
// Requests" tab) so Contact Support and My Support Requests live on one
// page. This route is kept only so old bookmarks/links keep working.
export default function LegacyRequestsRedirect() {
  const router = useRouter();

  useEffect(() => {
    router.replace('/app/help/support?tab=requests');
  }, [router]);

  return null;
}
