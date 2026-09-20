<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceCommand extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_LOCATE = 'locate';

    public const TYPE_LOCK = 'lock';

    public const TYPE_WIPE = 'wipe';

    public const TYPE_INSTALL_APP = 'install_app';

    public const TYPE_UNINSTALL_APP = 'uninstall_app';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_LOCATE,
        self::TYPE_LOCK,
        self::TYPE_WIPE,
        self::TYPE_INSTALL_APP,
        self::TYPE_UNINSTALL_APP,
    ];

    /** @var list<string> */
    public const PENDING_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_ACKNOWLEDGED,
    ];

    /** @var list<string> */
    public const FINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
    ];

    protected $fillable = [
        'public_id',
        'organization_id',
        'terminal_id',
        'created_by',
        'type',
        'payload',
        'status',
        'idempotency_key',
        'delivery_attempts',
        'queued_at',
        'sent_at',
        'last_delivery_at',
        'acknowledged_at',
        'completed_at',
        'expires_at',
        'result',
        'error_code',
        'error_message',
    ];

    protected $hidden = [
        'id',
        'organization_id',
        'terminal_id',
        'created_by',
        'idempotency_key',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceCommandEvent::class)->orderBy('id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'terminal_id' => 'integer',
            'created_by' => 'integer',
            'payload' => 'array',
            'delivery_attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'last_delivery_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'result' => 'array',
        ];
    }
}
