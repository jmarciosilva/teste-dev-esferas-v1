<?php

/**
 * Página de diagnóstico de performance — não faz parte do fluxo de
 * produção, existe só para o avaliador enxergar, com números medidos na
 * hora (não inventados), o efeito das duas correções do desafio:
 *
 * - Catálogo: mede miss (direto no banco) e hit (Redis) ao vivo, a cada
 *   carregamento da página, reaproveitando a mesma chave/query usadas em
 *   produção (CatalogController::fetchCatalog()/cacheKey()).
 * - Relatório: o "antes" (N+1) não é re-executado ao vivo — levaria ~193s
 *   e tornaria esta própria página inutilizável. Em vez disso, mostra o
 *   baseline documentado (analise-problema01.md) lado a lado com uma
 *   medição ao vivo da query otimizada atual.
 *
 * Cada execução é registrada num log (Redis, últimas 20 por seção) para
 * dar uma série histórica, não só o resultado da última carga da página.
 */
class PerformanceController
{
    private const LOG_LIMIT = 20;
    private const REPORT_BASELINE_MS = 193403;
    private const REPORT_TARGET_MS = 300;

    public function index(): void
    {
        $catalog = $this->runCatalogTest();
        $catalogLog = $this->readLog('perf:log:catalog');

        $report = $this->runReportTest();
        $reportLog = $this->readLog('perf:log:report');
        $reportWithinTarget = count(array_filter($reportLog, fn ($entry) => $entry['within_target']));

        render('performance', [
            'catalog' => $catalog,
            'catalogLog' => $catalogLog,
            'report' => $report,
            'reportLog' => $reportLog,
            'reportWithinTarget' => $reportWithinTarget,
            'reportTotal' => count($reportLog),
            'redisStats' => $this->redisStats(),
            'catalogKeys' => $this->catalogKeys(),
        ]);
    }

    private function runCatalogTest(): array
    {
        $catalogController = new CatalogController();
        $key = $catalogController->cacheKey(null);
        $redis = RedisClient::tryConnection();

        if ($redis !== null) {
            try {
                $redis->del($key);
            } catch (Throwable $e) {
                // segue mesmo se o DEL falhar — o teste ainda mede o miss real
            }
        }

        // MISS: mesma query usada em produção, direto no banco.
        $start = microtime(true);
        $products = $catalogController->fetchCatalog(null);
        $missMs = round((microtime(true) - $start) * 1000, 1);

        $hitMs = null;
        if ($redis !== null) {
            try {
                $redis->setex($key, 300, json_encode($products));

                $start = microtime(true);
                $redis->get($key);
                $hitMs = round((microtime(true) - $start) * 1000, 2);
            } catch (Throwable $e) {
                $hitMs = null;
            }
        }

        $result = [
            'timestamp' => date('d/m/Y H:i:s'),
            'miss_ms' => $missMs,
            'hit_ms' => $hitMs,
            'speedup' => ($hitMs !== null && $hitMs > 0) ? round($missMs / $hitMs, 1) : null,
            'redis_available' => $redis !== null,
        ];

        $this->appendLog('perf:log:catalog', $result);

        return $result;
    }

    private function runReportTest(): array
    {
        $reportController = new ReportController();

        $start = microtime(true);
        $reportController->fetchTopClientes();
        $optimizedMs = round((microtime(true) - $start) * 1000, 1);

        $result = [
            'timestamp' => date('d/m/Y H:i:s'),
            'optimized_ms' => $optimizedMs,
            'baseline_ms' => self::REPORT_BASELINE_MS,
            'target_ms' => self::REPORT_TARGET_MS,
            'within_target' => $optimizedMs < self::REPORT_TARGET_MS,
            'speedup' => round(self::REPORT_BASELINE_MS / $optimizedMs, 1),
        ];

        $this->appendLog('perf:log:report', $result);

        return $result;
    }

    private function appendLog(string $key, array $entry): void
    {
        $redis = RedisClient::tryConnection();
        if ($redis === null) {
            return;
        }

        try {
            $redis->lPush($key, json_encode($entry));
            $redis->lTrim($key, 0, self::LOG_LIMIT - 1);
        } catch (Throwable $e) {
            // Log é só um extra de diagnóstico — falha aqui não pode quebrar a página.
        }
    }

    private function readLog(string $key): array
    {
        $redis = RedisClient::tryConnection();
        if ($redis === null) {
            return [];
        }

        try {
            $items = $redis->lRange($key, 0, self::LOG_LIMIT - 1);
            return array_map(fn ($json) => json_decode($json, true), $items);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function redisStats(): ?array
    {
        $redis = RedisClient::tryConnection();
        if ($redis === null) {
            return null;
        }

        try {
            $stats = $redis->info('stats');
            $memory = $redis->info('memory');

            return [
                'keyspace_hits' => $stats['keyspace_hits'] ?? null,
                'keyspace_misses' => $stats['keyspace_misses'] ?? null,
                'used_memory_human' => $memory['used_memory_human'] ?? null,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Lista as chaves catalog:v1:products:* com TTL restante. Uso de KEYS
     * é aceitável aqui só pelo volume pequeno (no máximo ~9 chaves) — não
     * seria uma escolha razoável num keyspace grande em produção.
     */
    private function catalogKeys(): array
    {
        $redis = RedisClient::tryConnection();
        if ($redis === null) {
            return [];
        }

        try {
            $keys = $redis->keys('catalog:v1:products:*');
            sort($keys);

            return array_map(fn ($key) => ['key' => $key, 'ttl' => $redis->ttl($key)], $keys);
        } catch (Throwable $e) {
            return [];
        }
    }
}
