<?php

use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\Concerns\HasCommunityEntities;
use App\Models\EntityContentLink;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Services\Community\EntityContentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Stand-in for a future content module (news, events…): it follows the HasCommunityEntities contract with its own
 * publication status and audience, so the shared scope is tested without building a Phase 3.2+ module.
 */
class ScopedNotice extends Model
{
    use HasCommunityEntities;

    protected $table = 'scoped_notices';

    protected $guarded = [];

    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $query->where('status', 'published')->where(fn ($audience) => $audience->where('audience', 'public')
            ->when($viewer, fn ($members) => $members->orWhere(fn ($own) => $own->where('audience', 'members')
                ->whereIn('owner_entity_id', CommunityEntity::query()->whereIn('family_id', $viewer->familyMemberships()->select('family_id'))->select('id')))));
    }
}

beforeEach(function () {
    Schema::create('scoped_notices', function (Blueprint $table) {
        $table->id();
        $table->foreignId('owner_entity_id')->constrained('community_entities');
        $table->string('title');
        $table->string('status', 16);
        $table->string('audience', 16);
        $table->timestamps();
    });

    $this->family = Family::query()->create(['name_ar' => 'عائلة', 'slug' => 'scope']);
    $entity = fn (string $slug, string $type, ?CommunityEntity $parent = null) => CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $parent?->id, 'entity_type' => $type, 'name_ar' => $slug, 'slug' => $slug, 'publication_status' => 'published']);
    $this->clan = $entity('clan', 'clan');
    $this->fakhdA = $entity('a', 'fakhd', $this->clan);
    $this->fakhdB = $entity('b', 'fakhd', $this->clan);
    $this->subA = $entity('a-sub', 'sub_fakhd', $this->fakhdA);

    $scope = app(EntityContentScope::class);
    $this->notices = [];
    $notice = function (string $key, CommunityEntity $owner, array $links, string $status = 'published', string $audience = 'public') use ($scope): void {
        $record = ScopedNotice::query()->create(['owner_entity_id' => $owner->id, 'title' => $key, 'status' => $status, 'audience' => $audience]);
        foreach ($links as [$relation, $target]) {
            $scope->link($record, $target, $relation);
        }
        $this->notices[$key] = $record;
    };
    $subject = EntityContentLink::RELATION_SUBJECT;
    $audience = EntityContentLink::RELATION_AUDIENCE;
    $notice('about-a', $this->fakhdA, [[$subject, $this->fakhdA]]);
    $notice('about-sub', $this->subA, [[$subject, $this->subA]]);
    $notice('about-clan', $this->clan, [[$subject, $this->clan]]);
    $notice('clan-wide', $this->clan, [[$audience, $this->clan]]);
    $notice('about-b', $this->fakhdB, [[$subject, $this->fakhdB]]);
    $notice('about-a-and-sub', $this->fakhdA, [[$subject, $this->fakhdA], [$subject, $this->subA]]);
    $notice('to-a-branches', $this->fakhdA, [[$audience, $this->fakhdA]]);
    $notice('draft-about-sub', $this->subA, [[$subject, $this->subA]], 'draft');
    $notice('members-about-sub', $this->subA, [[$subject, $this->subA]], 'published', 'members');
});

/** @return array<int, string> */
function noticesOn(CommunityEntity $entity, string $mode, ?User $viewer = null, ?array $eligible = null): array
{
    return ScopedNotice::query()->onEntityPage($entity, $mode, $viewer, $eligible)->orderBy('title')->pluck('title')->all();
}

test('subject content rolls up to ancestors and is counted once', function () {
    $titles = noticesOn($this->clan, EntityContentScope::WITH_DESCENDANTS);

    expect($titles)->toBe(['about-a', 'about-a-and-sub', 'about-b', 'about-clan', 'about-sub', 'clan-wide'])
        ->and(ScopedNotice::query()->onEntityPage($this->clan, EntityContentScope::WITH_DESCENDANTS, null)->count())->toBe(6);
});

test('direct mode shows only content about or addressed to the level itself', function () {
    expect(noticesOn($this->clan, EntityContentScope::DIRECT))->toBe(['about-clan', 'clan-wide'])
        ->and(noticesOn($this->fakhdA, EntityContentScope::DIRECT))->toBe(['about-a', 'about-a-and-sub', 'to-a-branches']);
});

test('content addressed to a parent reaches descendant pages but never siblings', function () {
    expect(noticesOn($this->fakhdA, EntityContentScope::WITH_DESCENDANTS))->toBe(['about-a', 'about-a-and-sub', 'about-sub', 'clan-wide', 'to-a-branches'])
        ->and(noticesOn($this->subA, EntityContentScope::WITH_DESCENDANTS))->toBe(['about-a-and-sub', 'about-sub', 'clan-wide', 'to-a-branches'])
        ->and(noticesOn($this->fakhdB, EntityContentScope::WITH_DESCENDANTS))->toBe(['about-b', 'clan-wide']);
});

test('publication status, audience and entity eligibility apply on top of placement', function () {
    $member = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $member->id, 'role' => 'reader']);
    $outsider = User::factory()->create();

    expect(noticesOn($this->subA, EntityContentScope::DIRECT))->toBe(['about-a-and-sub', 'about-sub'])
        ->and(noticesOn($this->subA, EntityContentScope::DIRECT, $outsider))->toBe(['about-a-and-sub', 'about-sub'])
        ->and(noticesOn($this->subA, EntityContentScope::DIRECT, $member))->toBe(['about-a-and-sub', 'about-sub', 'members-about-sub']);

    // A hidden entity contributes nothing to its ancestors' aggregated pages.
    $eligible = [$this->clan->id, $this->fakhdB->id];
    expect(noticesOn($this->clan, EntityContentScope::WITH_DESCENDANTS, null, $eligible))->toBe(['about-b', 'about-clan', 'clan-wide']);
});

test('visibility on a page grants no edit permission to that page\'s managers', function () {
    // Fresh copies carry the stored account flags, as authenticated requests do.
    $manager = User::factory()->create()->fresh();
    FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $manager->id, 'role' => 'reader']);
    CommunityEntityManager::query()->create(['community_entity_id' => $this->fakhdA->id, 'user_id' => $manager->id, 'role' => 'admin']);
    $editor = User::factory()->create()->fresh();
    FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $editor->id, 'role' => 'editor']);

    expect(noticesOn($this->fakhdA, EntityContentScope::WITH_DESCENDANTS))->toContain('clan-wide')
        ->and($this->notices['clan-wide']->isEditableBy($manager))->toBeFalse()
        ->and($this->notices['about-a']->isEditableBy($manager))->toBeTrue()
        ->and($this->notices['about-sub']->isEditableBy($manager))->toBeTrue()
        ->and($this->notices['about-b']->isEditableBy($manager))->toBeFalse()
        ->and($this->notices['clan-wide']->isEditableBy($editor))->toBeTrue();
});

test('links are idempotent and stay inside the owner family', function () {
    $scope = app(EntityContentScope::class);
    $scope->link($this->notices['about-a'], $this->fakhdA, EntityContentLink::RELATION_SUBJECT);
    expect($this->notices['about-a']->entityLinks()->count())->toBe(1);

    $foreign = CommunityEntity::query()->create(['family_id' => Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other'])->id, 'entity_type' => 'clan', 'name_ar' => 'x', 'slug' => 'x', 'publication_status' => 'published']);
    expect(fn () => $scope->link($this->notices['about-a'], $foreign, EntityContentLink::RELATION_AUDIENCE))->toThrow(DomainException::class)
        ->and(fn () => $scope->link($this->notices['about-a'], $this->clan, 'owner'))->toThrow(DomainException::class)
        ->and(fn () => noticesOn($this->clan, 'everything'))->toThrow(DomainException::class);
});
