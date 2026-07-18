<div class="card">
    <h1>Relatório de Clientes &mdash; Top 20 (últimos 12 meses)</h1>
    <p>
        Tempo de geração:
        <span class="badge timing <?= $elapsedMs < 500 ? 'fast' : '' ?>"><?= $elapsedMs ?> ms</span>
    </p>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Cliente</th>
                <th>E-mail</th>
                <th>Cidade</th>
                <th>Pedidos (12m)</th>
                <th>Total gasto (12m)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $i => $customer): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($customer['name']) ?></td>
                    <td><?= htmlspecialchars($customer['email']) ?></td>
                    <td><?= htmlspecialchars($customer['city']) ?></td>
                    <td><?= $customer['orders_count'] ?></td>
                    <td><?= money($customer['total_spent']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
