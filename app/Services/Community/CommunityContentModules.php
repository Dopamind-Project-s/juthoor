<?php

namespace App\Services\Community;

use App\Models\ArchiveRecord;
use App\Models\CommunityEvent;
use App\Models\CommunityLocation;
use App\Models\Diwan;
use App\Models\EntityAchievement;
use App\Models\EntityNarrative;
use App\Models\MediaItem;
use App\Models\NewsPost;
use Illuminate\Database\Eloquent\Model;

/** The content modules of entity pages; every one uses CommunityContent and EntityContentScope. */
final class CommunityContentModules
{
    /** @var array<string, array{model: class-string<Model>, label: string, icon: string}> */
    public const MODULES = [
        'history' => ['model' => EntityNarrative::class, 'label' => 'التاريخ والأصول', 'icon' => 'hourglass-split'],
        'locations' => ['model' => CommunityLocation::class, 'label' => 'المناطق والانتشار الجغرافي', 'icon' => 'geo-alt'],
        'diwans' => ['model' => Diwan::class, 'label' => 'الدواوين والمضافات', 'icon' => 'house-door'],
        'achievements' => ['model' => EntityAchievement::class, 'label' => 'شخصيات وإنجازات', 'icon' => 'person-badge'],
        'archive' => ['model' => ArchiveRecord::class, 'label' => 'الأرشيف والوثائق', 'icon' => 'archive'],
        'media' => ['model' => MediaItem::class, 'label' => 'مكتبة الوسائط', 'icon' => 'images'],
        'news' => ['model' => NewsPost::class, 'label' => 'الأخبار والإعلانات', 'icon' => 'megaphone'],
        'events' => ['model' => CommunityEvent::class, 'label' => 'المناسبات والفعاليات', 'icon' => 'calendar-event'],
    ];

    /** @return class-string<Model> */
    public static function model(string $module): string
    {
        abort_unless(isset(self::MODULES[$module]), 404);

        return self::MODULES[$module]['model'];
    }

    public static function keyFor(Model $record): string
    {
        foreach (self::MODULES as $key => $module) {
            if ($record instanceof $module['model']) {
                return $key;
            }
        }

        throw new \DomainException('Unknown content module.');
    }
}
