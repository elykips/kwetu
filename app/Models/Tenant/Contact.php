<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use App\Models\Tenant;
use App\Models\User;
use App\Traits\BelongsToTenant;
use App\Traits\TracksFeatureUsage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class Contact
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $firstname
 * @property string $lastname
 * @property string|null $company
 * @property string $type
 * @property string|null $description
 * @property int|null $country_id
 * @property string|null $zip
 * @property string|null $city
 * @property string|null $state
 * @property string|null $address
 * @property int|null $assigned_id
 * @property int $status_id
 * @property int $source_id
 * @property string|null $email
 * @property string|null $website
 * @property string|null $phone
 * @property bool|null $is_enabled
 * @property int $addedfrom
 * @property Carbon|null $dateassigned
 * @property Carbon|null $last_status_change
 * @property string|null $default_language
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array $group_id
 * @property Source $source
 * @property Status $status
 * @property Tenant $tenant
 * @property Collection|ContactNote[] $contact_notes
 * @property-read int|null $contact_notes_count
 * @property-read mixed $country_name
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact forTenant($tenant)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereAddedfrom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereAssignedId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereCompany($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereCountryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereDateassigned($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereDefaultLanguage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereFirstname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereIsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereLastStatusChange($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereLastname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereSourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereStatusId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereWebsite($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Contact whereZip($value)
 *
 * @mixin \Eloquent
 */
class Contact extends BaseModel
{
    use BelongsToTenant, TracksFeatureUsage;

    protected $casts = [
        'tenant_id' => 'int',
        'country_id' => 'int',
        'is_enabled' => 'bool',
        'addedfrom' => 'int',
        'dateassigned' => 'datetime',
        'last_status_change' => 'datetime',
        'group_id' => 'array',
    ];

    protected $fillable = [
        'firstname',
        'lastname',
        'company',
        'type',
        'description',
        'country_id',
        'zip',
        'city',
        'state',
        'address',
        'assigned_id',
        'status_id',
        'source_id',
        'group_id',
        'email',
        'website',
        'phone',
        'is_enabled',
        'addedfrom',
        'dateassigned',
        'last_status_change',
        'tenant_id',
    ];

    /**
     * Create a new instance of the model with the correct table name
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if ($subdomain = tenant_subdomain()) {
            $this->setTable($subdomain.'_contacts');
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($contact) {
            do_action('contact.before_create', $contact);
        });

        static::created(function ($contact) {
            do_action('contact.after_create', $contact);
        });

        static::updating(function ($contact) {
            do_action('contact.before_update', $contact);
        });

        static::updated(function ($contact) {
            do_action('contact.after_update', $contact);
        });

        static::deleting(function ($contact) {
            do_action('contact.before_delete', $contact);
        });

        static::deleted(function ($contact) {
            do_action('contact.after_delete', $contact);
        });

        do_action('model.booted', static::class);
    }

    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function contact_notes()
    {
        return $this->hasMany(ContactNote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_id');
    }

    public function getCountryNameAttribute()
    {
        $country = collect(get_country_list())->firstWhere('id', (string) $this->country_id);

        return $country['short_name'] ?? null;
    }

    public function getFeatureSlug(): ?string
    {
        return 'contacts';
    }

    public static function fromTenant(string $subdomain)
    {
        return (new static)->setTable($subdomain.'_contacts');
    }

    public function getGroupIds(): array
    {
        $groups = $this->group_id;

        if (is_null($groups)) {
            return [];
        }

        if (is_array($groups)) {
            return array_map('intval', $groups);
        }

        // Fallback for any remaining string values
        if (is_string($groups)) {
            $decoded = json_decode($groups, true);

            return is_array($decoded) ? array_map('intval', $decoded) : [];
        }

        return [];
    }

    public function setGroupIds(array $groupIds): void
    {
        $cleanIds = array_values(array_unique(array_map('intval', array_filter($groupIds, 'is_numeric'))));
        $this->group_id = $cleanIds;
    }

    // Scope to filter by group
    public function scopeInGroup($query, $groupId)
    {
        return $query->whereJsonContains('group_id', (int) $groupId);
    }

    // Scope to filter by multiple groups (OR condition)
    public function scopeInAnyGroup($query, array $groupIds)
    {
        return $query->where(function ($q) use ($groupIds) {
            foreach ($groupIds as $groupId) {
                $q->orWhereJsonContains('group_id', (int) $groupId);
            }
        });
    }

    // Scope to filter by multiple groups (AND condition)
    public function scopeInAllGroups($query, array $groupIds)
    {
        foreach ($groupIds as $groupId) {
            $query->whereJsonContains('group_id', (int) $groupId);
        }

        return $query;
    }

    // Helper method to check if contact belongs to a group
    public function belongsToGroup($groupId): bool
    {
        return in_array((int) $groupId, $this->getGroupIds());
    }

    // Helper method to add a group
    public function addToGroup($groupId): void
    {
        $groups = $this->getGroupIds();
        if (! in_array((int) $groupId, $groups)) {
            $groups[] = (int) $groupId;
            $this->setGroupIds($groups);
            $this->save();
        }
    }

    // Helper method to remove from group
    public function removeFromGroup($groupId): void
    {
        $groups = $this->getGroupIds();
        $groups = array_filter($groups, fn ($id) => $id != (int) $groupId);
        $this->setGroupIds($groups);
        $this->save();
    }

    // Helper method to set groups (replace all)
    public function setGroups(array $groupIds): void
    {
        $this->setGroupIds($groupIds);
        $this->save();
    }

    // Get groups relationship
    public function groups()
    {
        $groupIds = $this->getGroupIds();

        if (empty($groupIds)) {
            return collect([]);
        }

        return Group::whereIn('id', $groupIds)->get();
    }

    public function assignedGroups()
    {
        return $this->groups();
    }
}
