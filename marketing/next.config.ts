import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: 'standalone',
  images: {
    // Next's image optimizer defaults to Content-Disposition: attachment,
    // which some browsers (confirmed: Chromium here) refuse to paint as an
    // inline <img> — the hero background silently failed to render because
    // of this, not because of any mask/opacity/CSS issue.
    contentDispositionType: 'inline',
  },
};

export default nextConfig;
