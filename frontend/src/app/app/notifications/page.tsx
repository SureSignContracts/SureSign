// Re-export the notifications page for /app/notifications — same component
// used by /admin/notifications, since the underlying endpoints are already
// scoped to the authenticated user, not the platform.
export { default } from '@/app/admin/notifications/page';
