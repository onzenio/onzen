<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\MonitoringArtifact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MonitoringArtifact>
 */
class MonitoringArtifactFactory extends Factory
{
    protected $model = MonitoringArtifact::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ref = (string) Str::ulid();

        return [
            'ref' => $ref,
            'account_id' => Account::factory(),
            'client_id' => null,
            'enrollment_id' => null,
            'kind' => 'pdf',
            'source' => 'CONSDECREC15',
            'original_name' => 'recibo.pdf',
            'storage_path' => 'monitoring/factory/'.$ref,
            'hash_sha256' => hash('sha256', $ref),
        ];
    }
}
