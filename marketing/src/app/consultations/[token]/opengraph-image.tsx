import { renderBrandedOgImage, brandedOgImageSize, brandedOgImageContentType } from '@/lib/brandedOgImage';

export const alt = 'SureSign construction contract administration';
export const size = brandedOgImageSize;
export const contentType = brandedOgImageContentType;

export default async function OpenGraphImage() {
  return renderBrandedOgImage();
}
