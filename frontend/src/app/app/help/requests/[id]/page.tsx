'use client';

import { useEffect } from 'react';
import { useParams, useRouter } from 'next/navigation';

// Support request detail moved to /app/help/support/{id} when Contact
// Support and My Support Requests were merged onto one page. Kept only so
// already-stored notification action_urls and old bookmarks keep working.
export default function LegacyRequestDetailRedirect() {
  const router = useRouter();
  const params = useParams();

  useEffect(() => {
    router.replace(`/app/help/support/${params.id}`);
  }, [router, params.id]);

  return null;
}
