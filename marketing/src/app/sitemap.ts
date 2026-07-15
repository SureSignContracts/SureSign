import type { MetadataRoute } from 'next';

export default function sitemap(): MetadataRoute.Sitemap {
  const base = 'https://suresigncontracts.app';
  return [
    { url: base, changeFrequency: 'weekly', priority: 1 },
    { url: `${base}/security`, changeFrequency: 'monthly', priority: 0.6 },
    { url: `${base}/book-a-demo`, changeFrequency: 'monthly', priority: 0.8 },
  ];
}
