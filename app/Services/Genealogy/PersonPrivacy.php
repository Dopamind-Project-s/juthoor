<?php

namespace App\Services\Genealogy;

use App\Models\Family;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Field-level privacy for people (JTH-28, UC-12). Every surface that shows a sensitive person field asks this class.
 *
 * Levels, from widest to narrowest audience: public < family (any member of the person's family) < restricted
 * (family administrators and super admins). Fields without an explicit level fall back to a default-deny level,
 * and a person who is living or of unknown status never exposes a field publicly.
 */
class PersonPrivacy
{
    public const PUBLIC = 'public';

    public const FAMILY = 'family';

    public const RESTRICTED = 'restricted';

    /** @var array<string, string> */
    public const LEVELS = [self::PUBLIC => 'للجميع', self::FAMILY => 'أعضاء العائلة', self::RESTRICTED => 'مدراء العائلة فقط'];

    /** @var array<string, string> sensitive field => default level */
    public const FIELDS = [
        'birth_year' => self::FAMILY, 'birth_date' => self::FAMILY,
        'death_year' => self::FAMILY, 'death_date' => self::FAMILY,
        'gender' => self::FAMILY, 'notes' => self::RESTRICTED,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        'birth_year' => 'سنة الميلاد', 'birth_date' => 'تاريخ الميلاد', 'death_year' => 'سنة الوفاة',
        'death_date' => 'تاريخ الوفاة', 'gender' => 'الجنس', 'notes' => 'ملاحظات خاصة',
    ];

    private const RANK = [self::PUBLIC => 0, self::FAMILY => 1, self::RESTRICTED => 2];

    /** The widest level the viewer may read for records of this family. */
    public function audience(?User $user, Family $family): string
    {
        if (! $user) {
            return self::PUBLIC;
        }
        if (Gate::forUser($user)->allows('update', $family)) {
            return self::RESTRICTED;
        }

        return Gate::forUser($user)->allows('view', $family) ? self::FAMILY : self::PUBLIC;
    }

    /**
     * @param  array<string, mixed>|null  $visibility  explicit levels (field_visibility)
     */
    public function level(string $field, ?array $visibility, mixed $isLiving): string
    {
        $level = $visibility[$field] ?? self::FIELDS[$field];
        $level = isset(self::RANK[$level]) ? $level : self::FIELDS[$field];
        $deceased = in_array($isLiving, [false, 0, '0'], true);

        return ! $deceased && $level === self::PUBLIC ? self::FAMILY : $level;
    }

    public function canView(Person $person, string $field, ?User $user): bool
    {
        if (! isset(self::FIELDS[$field])) {
            return true;
        }

        return $this->allows($this->audience($user, $person->familyTree->family), $this->level($field, $person->field_visibility, $person->is_living));
    }

    /**
     * Sensitive fields with a value, each with its level and whether the viewer may read it.
     *
     * @return array<string, array{label: string, level: string, visible: bool, value: mixed}>
     */
    public function fields(Person $person, ?User $user): array
    {
        $audience = $this->audience($user, $person->familyTree->family);
        $result = [];
        foreach (self::FIELDS as $field => $default) {
            $value = $person->getAttribute($field);
            if ($value === null || $value === '') {
                continue;
            }
            $level = $this->level($field, $person->field_visibility, $person->is_living);
            $visible = $this->allows($audience, $level);
            $result[$field] = ['label' => self::LABELS[$field], 'level' => $level, 'visible' => $visible, 'value' => $visible ? $value : null];
        }

        return $result;
    }

    /**
     * Removes values the viewer may not read from a person snapshot (audit log, change request diff). The stricter of
     * the snapshot's own level and the person's current level applies, so tightening privacy also hides history.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function redactSnapshot(array $snapshot, Family $family, ?User $user, ?Person $current = null, string $placeholder = '🔒 محجوب'): array
    {
        $audience = $this->audience($user, $family);
        $visibility = $snapshot['field_visibility'] ?? null;
        $visibility = is_string($visibility) ? json_decode($visibility, true) : $visibility;
        foreach (self::FIELDS as $field => $default) {
            if (! array_key_exists($field, $snapshot) || $snapshot[$field] === null) {
                continue;
            }
            $level = $this->level($field, is_array($visibility) ? $visibility : null, $snapshot['is_living'] ?? null);
            if ($current) {
                $currentLevel = $this->level($field, $current->field_visibility, $current->is_living);
                $level = self::RANK[$currentLevel] > self::RANK[$level] ? $currentLevel : $level;
            }
            if (! $this->allows($audience, $level)) {
                $snapshot[$field] = $placeholder;
            }
        }
        if ($audience !== self::RESTRICTED) {
            unset($snapshot['field_visibility']);
        }

        return $snapshot;
    }

    public function allows(string $audience, string $level): bool
    {
        return self::RANK[$audience] >= self::RANK[$level];
    }
}
