<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Enums;

/**
 * Capacidades conceptuales de un proveedor social (docs/06 Capability Matrix).
 * El frontend habilita/deshabilita formatos según estas capacidades reales.
 */
final class Capability
{
    public const TEXT = 'text';
    public const IMAGE = 'image';
    public const MULTI_IMAGE = 'multi_image';
    public const VIDEO = 'video';
    public const SHORT_VIDEO = 'short_video';
    public const STORY = 'story';
    public const CAROUSEL = 'carousel';
    public const POLL = 'poll';
    public const DOCUMENT = 'document';
    public const LINK = 'link';
    public const SCHEDULE_NATIVE = 'schedule_native';
    public const COMMENTS_READ = 'comments_read';
    public const COMMENTS_REPLY = 'comments_reply';
    public const ANALYTICS_POST = 'analytics_post';
    public const ANALYTICS_ACCOUNT = 'analytics_account';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TEXT, self::IMAGE, self::MULTI_IMAGE, self::VIDEO, self::SHORT_VIDEO,
            self::STORY, self::CAROUSEL, self::POLL, self::DOCUMENT, self::LINK,
            self::SCHEDULE_NATIVE, self::COMMENTS_READ, self::COMMENTS_REPLY,
            self::ANALYTICS_POST, self::ANALYTICS_ACCOUNT,
        ];
    }
}
