<?php

namespace App\Services;

use App\Models\SuresignNotification;
use App\Models\User;

class NotificationService
{
    public const DOCUMENT_GENERATED = 'document_generated';
    public const FILE_UPLOADED = 'file_uploaded';
    public const FILE_DELETED = 'file_deleted';
    public const TEMPLATE_UPLOADED = 'template_uploaded';
    public const TEMPLATE_UPDATED = 'template_updated';
    public const TEMPLATE_DELETED = 'template_deleted';
    public const TRADE_PACKAGE_GENERATED = 'trade_package_generated';
    public const TRADE_PACKAGE_CREATED = 'trade_package_created';
    public const PROJECT_CREATED = 'project_created';
    public const PROJECT_UPDATED = 'project_updated';
    public const SYNC_COMPLETED = 'sync_completed';
    public const SYNC_FAILED = 'sync_failed';
    public const USER_INVITED = 'user_invited';
    public const SYSTEM = 'system';

    public static function send(User $user, string $type, string $title, string $message, array $data = []): SuresignNotification
    {
        return SuresignNotification::create([
            'user_id' => $user->id,
            'type'    => $type,
            'title'   => $title,
            'message' => $message,
            'data'    => $data,
            'is_read' => false,
        ]);
    }
}
