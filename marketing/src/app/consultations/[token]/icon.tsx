import { renderBrandedIcon, brandedIconSize, brandedIconContentType } from '@/lib/brandedIcon';

export const size = brandedIconSize;
export const contentType = brandedIconContentType;

export default async function Icon() {
  return renderBrandedIcon();
}
