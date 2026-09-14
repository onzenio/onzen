<?php

namespace App\Models;

use Database\Factories\MonitoringDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $family
 * @property string $name
 * @property string $catalog_version
 * @property string $availability
 * @property string $strategy
 * @property array|null $person_types
 * @property array|null $regimes
 * @property array|null $required_services
 * @property array|null $operations
 * @property bool $automatic
 * @property string|null $unavailability_reason
 */
#[Fillable([
    'code', 'family', 'name', 'catalog_version', 'availability', 'strategy',
    'person_types', 'regimes', 'required_services', 'operations', 'automatic',
    'unavailability_reason',
])]
class MonitoringDefinition extends Model
{
    /** @use HasFactory<MonitoringDefinitionFactory> */
    use HasFactory;

    public const AVAILABLE = 'available';

    public const UNAVAILABLE = 'unavailable';

    public const PROSPECTING = 'prospecting';

    protected function casts(): array
    {
        return [
            'person_types' => 'array',
            'regimes' => 'array',
            'required_services' => 'array',
            'operations' => 'array',
            'automatic' => 'boolean',
        ];
    }

    public function isAvailable(): bool
    {
        return $this->availability === self::AVAILABLE;
    }

    /**
     * @return array{type: string, operation: string}|null
     */
    public function executableOperation(): ?array
    {
        foreach ($this->operations ?? [] as $op) {
            if (($op['type'] ?? null) === 'consultar') {
                return $op;
            }
        }

        return null;
    }
}
