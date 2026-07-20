<?php

/**
 * Löst einen Prozessor-Alias zu einer vollständigen Config auf (Merge aus
 * 'defaults' und dem jeweiligen 'processors'-Eintrag). Wirft eine
 * RuntimeException, wenn der Alias nicht existiert.
 *
 * @param string|null $alias Falls null oder leer: erster Prozessor der Liste.
 */
function pixelhue_resolve_processor_config(array $rootConfig, ?string $alias): array
{
    $processors = $rootConfig['processors'] ?? [];
    if (empty($processors)) {
        throw new RuntimeException('Keine Prozessoren in config.php konfiguriert.');
    }

    if ($alias === null || $alias === '') {
        $entry = $processors[0];
    } else {
        $entry = null;
        foreach ($processors as $p) {
            if (($p['alias'] ?? null) === $alias) {
                $entry = $p;
                break;
            }
        }
        if ($entry === null) {
            throw new RuntimeException("Unbekannter Prozessor-Alias: {$alias}");
        }
    }

    // defaults zuerst, dann Prozessor-Eintrag drüberlegen (überschreibt defaults)
    return array_merge($rootConfig['defaults'] ?? [], $entry);
}

/**
 * Liste aller bekannten Prozessor-Aliase (für UI-Dropdown / Übersicht).
 */
function pixelhue_list_processor_aliases(array $rootConfig): array
{
    return array_map(
        static fn($p) => $p['alias'] ?? '(ohne Alias)',
        $rootConfig['processors'] ?? []
    );
}
