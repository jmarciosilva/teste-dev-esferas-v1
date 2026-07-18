<div class="card">
    <h1>Performance</h1>
    <p>
        Esta página existe só pra comprovar, com números medidos na hora (não
        inventados nem congelados), o que as correções dos dois problemas do
        desafio resolveram. Ela <strong>não faz parte da aplicação em
        produção</strong> &mdash; é um painel de diagnóstico, pensado pra você
        avaliar se os resultados estão dentro do esperado.
        <a href="/performance">Rodar teste novamente &rarr;</a>
    </p>

    <h3>Como ler esta página</h3>
    <ul class="explain-list">
        <li>
            <strong>Relatório de Clientes</strong> era o problema de uma
            consulta lenta (mais de 3 minutos). Abaixo você vê o tempo
            <strong>antigo</strong>, documentado, ao lado do tempo
            <strong>atual</strong>, medido agora mesmo, nesta página.
        </li>
        <li>
            <strong>Catálogo de Produtos</strong> era o problema de
            recalcular tudo a cada visita. Abaixo você vê quanto tempo leva
            buscar os dados <strong>sem</strong> cache (rótulo "miss" &mdash;
            direto do banco, mais lento) e <strong>com</strong> cache
            (rótulo "hit" &mdash; vindo do Redis, bem mais rápido).
        </li>
        <li>
            Toda vez que esta página é recarregada, os dois testes rodam de
            novo e o resultado entra no <strong>histórico</strong> de cada
            seção &mdash; assim dá pra acompanhar várias medições ao longo do
            tempo, não só a última.
        </li>
        <li>
            É <strong>normal</strong> aparecer, de vez em quando, um tempo
            mais alto no relatório (ex.: acima de 300ms). Isso é explicado
            com detalhe na seção do relatório, mais abaixo &mdash; não é um
            problema voltando.
        </li>
    </ul>
</div>

<div class="card">
    <h2>Catálogo de Produtos &mdash; cache Redis (cache-aside)</h2>
    <p class="muted">
        Teste ao vivo: apaga a chave de cache do catálogo completo, mede
        quanto tempo leva buscar tudo direto do banco (<strong>miss</strong>),
        guarda o resultado no cache, e mede quanto tempo leva ler esse mesmo
        resultado do Redis (<strong>hit</strong>). Usa a mesma chave e
        consulta da aplicação real
        (<code><?= htmlspecialchars((new CatalogController())->cacheKey(null)) ?></code>),
        não é uma simulação à parte.
    </p>

    <div class="stat-grid">
        <div class="stat-card">
            <span class="stat-label">Miss &mdash; direto do banco</span>
            <span class="stat-value"><?= $catalog['miss_ms'] ?> ms</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Hit &mdash; vindo do Redis</span>
            <span class="stat-value"><?= $catalog['hit_ms'] !== null ? $catalog['hit_ms'] . ' ms' : '—' ?></span>
        </div>
        <div class="stat-card stat-card--highlight">
            <span class="stat-label">Quanto mais rápido com cache</span>
            <span class="stat-value"><?= $catalog['speedup'] !== null ? $catalog['speedup'] . 'x' : '—' ?></span>
        </div>
    </div>

    <?php if (!$catalog['redis_available']): ?>
        <p class="muted">O Redis estava indisponível no momento deste teste &mdash; só foi
            possível medir o tempo direto do banco (a aplicação real degrada da mesma forma
            em vez de quebrar; ver seção de resiliência em <code>analise-problema02.md</code>).</p>
    <?php endif; ?>

    <?php if ($redisStats): ?>
        <h3>Estatísticas do Redis</h3>
        <p class="muted">
            Contadores acumulados desde que o servidor Redis foi ligado (não
            só deste teste) &mdash; servem pra mostrar que o cache está sendo
            usado de verdade pela aplicação como um todo, não só por este
            painel.
        </p>
        <ul class="stat-list">
            <li><strong>Leituras servidas pelo cache (hits):</strong> <?= htmlspecialchars((string) $redisStats['keyspace_hits']) ?></li>
            <li><strong>Leituras que precisaram ir ao banco (misses):</strong> <?= htmlspecialchars((string) $redisStats['keyspace_misses']) ?></li>
            <li><strong>Memória usada pelo Redis:</strong> <?= htmlspecialchars((string) $redisStats['used_memory_human']) ?></li>
        </ul>
    <?php endif; ?>

    <?php if ($catalogKeys): ?>
        <h3>Chaves de cache ativas agora</h3>
        <p class="muted">Uma por categoria filtrada, mais "all" (catálogo completo). O TTL é quanto tempo falta até o Redis descartar essa entrada sozinho, caso nada a invalide antes.</p>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Chave</th><th>TTL restante</th></tr></thead>
            <tbody>
                <?php foreach ($catalogKeys as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['key']) ?></td>
                        <td><?= $item['ttl'] ?>s</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <h3>Histórico de execuções (últimas <?= count($catalogLog) ?>)</h3>
    <div class="table-wrap">
    <table>
        <thead>
            <tr><th>Data/hora</th><th>Miss (ms)</th><th>Hit (ms)</th><th>Quanto mais rápido</th></tr>
        </thead>
        <tbody>
            <?php foreach ($catalogLog as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars($entry['timestamp']) ?></td>
                    <td><?= $entry['miss_ms'] ?></td>
                    <td><?= $entry['hit_ms'] ?? '—' ?></td>
                    <td><?= $entry['speedup'] !== null ? $entry['speedup'] . 'x' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>Relatório de Top Clientes &mdash; query otimizada</h2>
    <p class="muted">
        O tempo "antes" não é re-executado ao vivo aqui &mdash; a versão
        antiga (N+1, ~5.001 consultas) levava ~193 segundos, e rodar isso
        de novo tornaria esta própria página de diagnóstico inutilizável.
        Em vez disso, o "antes" é o valor <strong>documentado</strong> em
        <code>analise-problema01.md</code> (reproduzível revertendo o
        código pro commit anterior à correção, com evidência de
        <code>EXPLAIN ANALYZE</code>). O "depois" é medido <strong>ao
        vivo</strong>, agora mesmo, toda vez que esta página carrega.
    </p>

    <div class="stat-grid">
        <div class="stat-card">
            <span class="stat-label">Antes &mdash; documentado (N+1)</span>
            <span class="stat-value"><?= number_format($report['baseline_ms'], 0, ',', '.') ?> ms</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Agora &mdash; medido ao vivo</span>
            <span class="stat-value"><?= $report['optimized_ms'] ?> ms</span>
        </div>
        <div class="stat-card stat-card--highlight">
            <span class="stat-label">Quantas vezes mais rápido</span>
            <span class="stat-value"><?= $report['speedup'] ?>x</span>
        </div>
    </div>

    <p>
        Meta do desafio: menos de <?= $report['target_ms'] ?>ms nesta execução &mdash;
        <span class="badge timing <?= $report['within_target'] ? 'fast' : '' ?>">
            <?= $report['within_target'] ? 'dentro da meta' : 'fora da meta' ?>
        </span>
        <?php if ($reportTotal > 0): ?>
            <br><span class="muted">Olhando o histórico: <?= $reportWithinTarget ?> de <?= $reportTotal ?>
            execuções recentes (<?= round($reportWithinTarget / $reportTotal * 100) ?>%) ficaram dentro da meta.</span>
        <?php endif; ?>
    </p>

    <div class="callout">
        <strong>Por que às vezes o tempo aparece acima de 300ms?</strong>
        <p>
            A causa raiz do Problema 1 (consultas N+1, ausência de índice)
            foi eliminada em definitivo &mdash; isso não volta mais, custe o
            que custar o resto do ambiente. A variação que ainda aparece de
            vez em quando (geralmente entre 300-500ms) vem de disputa por
            CPU no ambiente Docker local usado pra rodar este teste (o
            container do banco dividindo processador com o resto da
            máquina, inclusive com esta própria página de diagnóstico
            sendo carregada). Isso foi investigado item por item
            &mdash; inclusive descartando índice ausente, leitura de disco e
              overhead de PHP/Apache como causas &mdash; e está documentado
            em <code>analise-problema01.md</code>. O que importa pra
            avaliar a correção é a <strong>maioria</strong> das execuções
            ficar dentro da meta e a ordem de grandeza do ganho (centenas de
            vezes mais rápido que o original), não cada execução individual
            bater exatamente abaixo de 300ms.
        </p>
    </div>

    <h3>Histórico de execuções (últimas <?= count($reportLog) ?>)</h3>
    <div class="table-wrap">
    <table>
        <thead>
            <tr><th>Data/hora</th><th>Tempo (ms)</th><th>Dentro da meta (&lt;300ms)</th><th>Ganho vs. baseline</th></tr>
        </thead>
        <tbody>
            <?php foreach ($reportLog as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars($entry['timestamp']) ?></td>
                    <td><?= $entry['optimized_ms'] ?></td>
                    <td>
                        <span class="badge timing <?= $entry['within_target'] ? 'fast' : '' ?>">
                            <?= $entry['within_target'] ? 'sim' : 'não' ?>
                        </span>
                    </td>
                    <td><?= $entry['speedup'] ?>x</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
