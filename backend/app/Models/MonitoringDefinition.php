<?php

namespace App\Models;

use Database\Factories\MonitoringDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Platform-level catálogo definition (no Account scope). The definition id is
 * the stable catalog key; `version` marks the catalog generation, and
 * `availability` declares whether the platform can execute it.
 *
 * @property string $id
 * @property string $name
 * @property string $category
 * @property string $system
 * @property string|null $description
 * @property string $version
 * @property string $availability
 * @property string $strategy
 * @property bool $default_enabled
 * @property bool $is_active
 * @property bool $requires_procuracao
 * @property array<int, string>|null $operations
 * @property array<int, string>|null $procuration_codes
 * @property array<int, string>|null $person_types
 * @property array<int, string>|null $regimes
 * @property array<int, string>|null $services
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'id', 'name', 'category', 'system', 'description', 'version', 'availability',
    'strategy', 'default_enabled', 'is_active', 'requires_procuracao',
    'operations', 'procuration_codes', 'person_types', 'regimes', 'services',
])]
class MonitoringDefinition extends Model
{
    /** @use HasFactory<MonitoringDefinitionFactory> */
    use HasFactory;

    public const CATALOG_VERSION = '2026-08';

    public const AVAILABILITY_PRODUCTION = 'production';

    public const AVAILABILITY_PROSPECCAO = 'prospecção';

    public const STRATEGY_POLLING = 'polling';

    /**
     * Definitions eligible for the automatic monthly cycle. Only consult
     * operations ever run with this strategy; every other definition stays
     * manual-only.
     */
    public const STRATEGY_AUTOMATIC = 'automatic';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * A definition is available only when the catalog generation marks it as
     * production and it has not been deactivated. Fail-closed by default.
     */
    public function isAvailable(): bool
    {
        return $this->availability === self::AVAILABILITY_PRODUCTION
            && (bool) $this->is_active;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_enabled' => 'boolean',
            'is_active' => 'boolean',
            'requires_procuracao' => 'boolean',
            'operations' => 'array',
            'procuration_codes' => 'array',
            'person_types' => 'array',
            'regimes' => 'array',
            'services' => 'array',
        ];
    }
}
