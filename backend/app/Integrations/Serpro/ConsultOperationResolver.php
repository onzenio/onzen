<?php

namespace App\Integrations\Serpro;

use App\Models\MonitoringDefinition;

/**
 * Resolve a operação executável de consulta para uma definição do catálogo.
 *
 * Fail-closed: definição indisponível, em prospecção ou sem operação de
 * Consultar resolve em erro explícito — nunca em um palpite silencioso.
 * Operações de Declarar/Emitir permanecem fora do fluxo de polling.
 */
final class ConsultOperationResolver
{
    /**
     * @param  string|null  $requestedCode  Código já fixado na execução (ex.: detalhe da cadeia PGDAS-D).
     * @param  bool  $explicitAction  Execução marcada como ação fiscal explícita (nunca no polling).
     */
    public function resolve(MonitoringDefinition $definition, ?string $requestedCode = null, bool $explicitAction = false): string
    {
        $definitionId = (string) $definition->getKey();
        $requested = strtoupper(trim((string) $requestedCode));

        if ($requested !== '') {
            // Explicit fiscal actions (e.g. GERARDAS12 DAS emission confirmed
            // by the user) run as flagged runs only; polling never sets the
            // flag, so the polling path keeps rejecting these operations.
            if ($explicitAction && ConsultCatalog::isExplicitEmission($requested)) {
                return $requested;
            }
            $this->assertAllowed($requested, $definitionId);

            return $requested;
        }

        if (ConsultCatalog::isProspeccao($definitionId)) {
            throw new \DomainException('parcelment_modality_unavailable');
        }

        if (! $definition->isAvailable()) {
            throw new \InvalidArgumentException('consult_operation_unresolved');
        }

        $operations = $definition->operations;
        if (is_array($operations)) {
            foreach ($operations as $code) {
                $code = strtoupper(trim((string) $code));
                if ($code === '' || ConsultCatalog::isExplicitFiscalAction($code)) {
                    continue;
                }
                $this->assertAllowed($code, $definitionId);

                return $code;
            }
        }

        $index = ConsultCatalog::indexOperation($definitionId);
        if ($index !== null) {
            return $index;
        }

        throw new \InvalidArgumentException('consult_operation_unresolved');
    }

    private function assertAllowed(string $code, string $definitionId): void
    {
        if (ConsultCatalog::isProspeccao($definitionId)) {
            throw new \DomainException('parcelment_modality_unavailable');
        }
        if (ConsultCatalog::isExplicitFiscalAction($code)) {
            throw new \InvalidArgumentException('forbidden_fiscal_action');
        }
        if (ConsultCatalog::isForbiddenPolling($code)) {
            throw new \InvalidArgumentException('forbidden_fiscal_action');
        }
        if (ConsultCatalog::isConsultDefinition($definitionId) && str_starts_with($code, 'MONITOR_')) {
            throw new \InvalidArgumentException('consult_operation_unresolved');
        }
    }
}
