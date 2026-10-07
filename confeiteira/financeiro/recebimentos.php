<?php

session_start();

require_once __DIR__ . '/../../config/banco.php';

/* =========================================================
   AUTENTICAÇÃO
========================================================= */

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../../login.php');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];

/* =========================================================
   FUNÇÕES
========================================================= */

function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validar_csrf(): void
{
    if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Requisição inválida.');
    }
}

function dinheiro($valor): string
{
    return 'R$ ' . number_format(
        (float) $valor,
        2,
        ',',
        '.'
    );
}

/* =========================================================
   BUSCAR CONFEITEIRA
========================================================= */

$stmt = $pdo->prepare("
    SELECT id, nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuarioId]);

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    exit('Confeiteira não encontrada.');
}

$confeiteiraId = (int) $confeiteira['id'];

/* =========================================================
   EXCLUSÃO
========================================================= */

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {

    validar_csrf();

    $recebimentoId = (int) ($_POST['id'] ?? 0);

    if ($recebimentoId <= 0) {
        $erro = 'Recebimento inválido.';
    } else {

        try {

            $pdo->beginTransaction();

            /*
             * Busca o recebimento pertencente à confeiteira.
             */
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    pedido_id,
                    valor
                FROM recebimentos
                WHERE id = ?
                  AND confeiteira_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $recebimentoId,
                $confeiteiraId
            ]);

            $recebimento = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$recebimento) {
                throw new Exception('Recebimento não encontrado.');
            }

            $pedidoId = !empty($recebimento['pedido_id'])
                ? (int) $recebimento['pedido_id']
                : null;

            /*
             * Se o recebimento pertence a um pedido,
             * bloqueamos o pedido antes de alterar os valores.
             */
            if ($pedidoId !== null) {

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        valor_total
                    FROM pedidos
                    WHERE id = ?
                      AND confeiteira_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    $pedidoId,
                    $confeiteiraId
                ]);

                $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$pedido) {
                    throw new Exception(
                        'O pedido vinculado ao recebimento não foi encontrado.'
                    );
                }
            }

            /*
             * Exclui o recebimento.
             */
            $stmt = $pdo->prepare("
                DELETE FROM recebimentos
                WHERE id = ?
                  AND confeiteira_id = ?
            ");

            $stmt->execute([
                $recebimentoId,
                $confeiteiraId
            ]);

            /*
             * Se estava ligado a um pedido,
             * recalculamos o total recebido usando
             * somente os recebimentos restantes.
             */
            if ($pedidoId !== null) {

                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(valor), 0)
                    FROM recebimentos
                    WHERE pedido_id = ?
                      AND confeiteira_id = ?
                ");

                $stmt->execute([
                    $pedidoId,
                    $confeiteiraId
                ]);

                $totalRecebido = (float) $stmt->fetchColumn();

                $valorTotalPedido = (float) $pedido['valor_total'];

                $valorRestante = max(
                    0,
                    $valorTotalPedido - $totalRecebido
                );

                $stmt = $pdo->prepare("
                    UPDATE pedidos
                    SET
                        valor_recebido = ?,
                        valor_restante = ?
                    WHERE id = ?
                      AND confeiteira_id = ?
                ");

                $stmt->execute([
                    $totalRecebido,
                    $valorRestante,
                    $pedidoId,
                    $confeiteiraId
                ]);
            }

            $pdo->commit();

            $sucesso = 'Recebimento excluído com sucesso.';

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Erro ao excluir recebimento: ' .
                $e->getMessage()
            );

            $erro = 'Não foi possível excluir o recebimento.';
        }
    }
}

/* =========================================================
   FILTROS
========================================================= */

$dataInicial = trim($_GET['data_inicial'] ?? '');
$dataFinal   = trim($_GET['data_final'] ?? '');
$forma       = trim($_GET['forma_pagamento'] ?? '');
$origem      = trim($_GET['origem'] ?? '');
$busca       = trim($_GET['busca'] ?? '');

/*
 * Validação simples das datas.
 */
if (
    $dataInicial !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicial)
) {
    $dataInicial = '';
}

if (
    $dataFinal !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFinal)
) {
    $dataFinal = '';
}

/* =========================================================
   CONSULTA
========================================================= */

$sql = "
    SELECT
        r.id,
        r.descricao,
        r.valor,
        r.data_recebimento,
        r.forma_pagamento,
        r.observacao,
        r.pedido_id,

        p.numero_pedido,
        p.status AS pedido_status,

        c.nome AS cliente_nome

    FROM recebimentos r

    LEFT JOIN pedidos p
        ON p.id = r.pedido_id
       AND p.confeiteira_id = r.confeiteira_id

    LEFT JOIN clientes c
        ON c.id = p.cliente_id

    WHERE r.confeiteira_id = ?
";

$params = [$confeiteiraId];

/* =========================================================
   FILTRO DATA INICIAL
========================================================= */

if ($dataInicial !== '') {

    $sql .= "
        AND r.data_recebimento >= ?
    ";

    $params[] = $dataInicial . ' 00:00:00';
}

/* =========================================================
   FILTRO DATA FINAL
========================================================= */

if ($dataFinal !== '') {

    $sql .= "
        AND r.data_recebimento <= ?
    ";

    $params[] = $dataFinal . ' 23:59:59';
}

/* =========================================================
   FILTRO FORMA DE PAGAMENTO
========================================================= */

$formasPermitidas = [
    'pix',
    'dinheiro',
    'cartao',
    'transferencia',
    'outro'
];

if (in_array($forma, $formasPermitidas, true)) {

    $sql .= "
        AND r.forma_pagamento = ?
    ";

    $params[] = $forma;
}

/* =========================================================
   FILTRO ORIGEM
========================================================= */

if ($origem === 'pedido') {

    $sql .= "
        AND r.pedido_id IS NOT NULL
    ";

} elseif ($origem === 'avulso') {

    $sql .= "
        AND r.pedido_id IS NULL
    ";
}

/* =========================================================
   BUSCA
========================================================= */

if ($busca !== '') {

    $sql .= "
        AND (
            r.descricao LIKE ?
            OR r.observacao LIKE ?
            OR c.nome LIKE ?
            OR CAST(p.numero_pedido AS CHAR) LIKE ?
        )
    ";

    $termo = '%' . $busca . '%';

    $params[] = $termo;
    $params[] = $termo;
    $params[] = $termo;
    $params[] = $termo;
}

/* =========================================================
   ORDENAÇÃO
========================================================= */

$sql .= "
    ORDER BY
        r.data_recebimento DESC,
        r.id DESC
";

/* =========================================================
   EXECUTAR
========================================================= */

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$recebimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   TOTAL
========================================================= */

$totalRecebimentos = 0;

foreach ($recebimentos as $recebimento) {
    $totalRecebimentos += (float) $recebimento['valor'];
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Recebimentos - <?= h($confeiteira['nome_confeitaria']) ?></title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f6f8;
            color: #222;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 25px;
        }

        .topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .subtitulo {
            color: #666;
            margin-top: 5px;
        }

        .acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 10px 15px;
            border-radius: 8px;
            border: none;
            text-decoration: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
        }

        .btn-principal {
            background: #198754;
            color: white;
        }

        .btn-secundario {
            background: #6c757d;
            color: white;
        }

        .btn-editar {
            background: #0d6efd;
            color: white;
        }

        .btn-excluir {
            background: #dc3545;
            color: white;
        }

        .filtros {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        .filtros-grid {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            align-items: end;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        input,
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 7px;
            background: white;
        }

        .resumo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            background: white;
            padding: 18px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .resumo-label {
            color: #666;
            font-size: 14px;
        }

        .resumo-valor {
            font-size: 25px;
            font-weight: bold;
            color: #198754;
        }

        .alerta {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .alerta-sucesso {
            background: #d1e7dd;
            color: #0f5132;
        }

        .alerta-erro {
            background: #f8d7da;
            color: #842029;
        }

        .tabela-container {
            background: white;
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th,
        td {
            padding: 13px 14px;
            border-bottom: 1px solid #eee;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #f8f9fa;
            font-size: 13px;
        }

        td {
            font-size: 14px;
        }

        .valor {
            font-weight: bold;
            color: #198754;
            white-space: nowrap;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-pedido {
            background: #cff4fc;
            color: #055160;
        }

        .badge-avulso {
            background: #e2e3e5;
            color: #41464b;
        }

        .acoes-linha {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .acoes-linha .btn {
            padding: 7px 10px;
            font-size: 12px;
        }

        .vazio {
            text-align: center;
            padding: 45px 20px;
            color: #777;
        }

        .pedido-info {
            line-height: 1.5;
        }

        .pedido-numero {
            font-weight: bold;
        }

        .cliente {
            color: #666;
            font-size: 13px;
        }

        @media (max-width: 600px) {

            .container {
                padding: 15px;
            }

            h1 {
                font-size: 23px;
            }

            .acoes {
                width: 100%;
            }

            .acoes .btn {
                flex: 1;
                text-align: center;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="topo">

        <div>

            <h1>Recebimentos</h1>

            <div class="subtitulo">
                Controle de todo o dinheiro efetivamente recebido.
            </div>

        </div>

        <div class="acoes">

            <a
                href="index.php"
                class="btn btn-secundario"
            >
                ← Financeiro
            </a>

            <a
                href="cadastrar_recebimento.php"
                class="btn btn-principal"
            >
                + Recebimento avulso
            </a>

        </div>

    </div>

    <?php if ($sucesso): ?>

        <div class="alerta alerta-sucesso">
            <?= h($sucesso) ?>
        </div>

    <?php endif; ?>

    <?php if ($erro): ?>

        <div class="alerta alerta-erro">
            <?= h($erro) ?>
        </div>

    <?php endif; ?>

    <!-- FILTROS -->

    <form
        method="GET"
        class="filtros"
    >

        <div class="filtros-grid">

            <div>

                <label for="data_inicial">
                    Data inicial
                </label>

                <input
                    type="date"
                    id="data_inicial"
                    name="data_inicial"
                    value="<?= h($dataInicial) ?>"
                >

            </div>

            <div>

                <label for="data_final">
                    Data final
                </label>

                <input
                    type="date"
                    id="data_final"
                    name="data_final"
                    value="<?= h($dataFinal) ?>"
                >

            </div>

            <div>

                <label for="forma_pagamento">
                    Forma de pagamento
                </label>

                <select
                    id="forma_pagamento"
                    name="forma_pagamento"
                >

                    <option value="">
                        Todas
                    </option>

                    <option
                        value="pix"
                        <?= $forma === 'pix' ? 'selected' : '' ?>
                    >
                        PIX
                    </option>

                    <option
                        value="dinheiro"
                        <?= $forma === 'dinheiro' ? 'selected' : '' ?>
                    >
                        Dinheiro
                    </option>

                    <option
                        value="cartao"
                        <?= $forma === 'cartao' ? 'selected' : '' ?>
                    >
                        Cartão
                    </option>

                    <option
                        value="transferencia"
                        <?= $forma === 'transferencia' ? 'selected' : '' ?>
                    >
                        Transferência
                    </option>

                    <option
                        value="outro"
                        <?= $forma === 'outro' ? 'selected' : '' ?>
                    >
                        Outro
                    </option>

                </select>

            </div>

            <div>

                <label for="origem">
                    Origem
                </label>

                <select
                    id="origem"
                    name="origem"
                >

                    <option value="">
                        Todas
                    </option>

                    <option
                        value="pedido"
                        <?= $origem === 'pedido' ? 'selected' : '' ?>
                    >
                        Pedido
                    </option>

                    <option
                        value="avulso"
                        <?= $origem === 'avulso' ? 'selected' : '' ?>
                    >
                        Avulso
                    </option>

                </select>

            </div>

            <div>

                <label for="busca">
                    Buscar
                </label>

                <input
                    type="text"
                    id="busca"
                    name="busca"
                    placeholder="Pedido, cliente ou descrição"
                    value="<?= h($busca) ?>"
                >

            </div>

            <div>

                <button
                    type="submit"
                    class="btn btn-principal"
                >
                    Filtrar
                </button>

            </div>

        </div>

    </form>

    <!-- RESUMO -->

    <div class="resumo">

        <div>

            <div class="resumo-label">
                Total recebido nos resultados
            </div>

            <div class="resumo-valor">
                <?= dinheiro($totalRecebimentos) ?>
            </div>

        </div>

        <div>

            <div class="resumo-label">
                Registros encontrados
            </div>

            <strong>
                <?= count($recebimentos) ?>
            </strong>

        </div>

    </div>

    <!-- TABELA -->

    <div class="tabela-container">

        <?php if (!$recebimentos): ?>

            <div class="vazio">

                <strong>
                    Nenhum recebimento encontrado.
                </strong>

                <br>

                Tente alterar os filtros ou registre um novo recebimento.

            </div>

        <?php else: ?>

            <table>

                <thead>

                    <tr>

                        <th>Data</th>

                        <th>Origem</th>

                        <th>Descrição / Pedido</th>

                        <th>Forma</th>

                        <th>Valor</th>

                        <th>Ações</th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach ($recebimentos as $r): ?>

                    <tr>

                        <td>
                            <?= date(
                                'd/m/Y',
                                strtotime($r['data_recebimento'])
                            ) ?>
                        </td>

                        <td>

                            <?php if (!empty($r['pedido_id'])): ?>

                                <span class="badge badge-pedido">
                                    Pedido
                                </span>

                            <?php else: ?>

                                <span class="badge badge-avulso">
                                    Avulso
                                </span>

                            <?php endif; ?>

                        </td>

                        <td>

                            <?php if (!empty($r['pedido_id'])): ?>

                                <div class="pedido-info">

                                    <div class="pedido-numero">
                                        Pedido #<?= h($r['numero_pedido']) ?>
                                    </div>

                                    <?php if (!empty($r['cliente_nome'])): ?>

                                        <div class="cliente">
                                            <?= h($r['cliente_nome']) ?>
                                        </div>

                                    <?php endif; ?>

                                    <?php if (!empty($r['descricao'])): ?>

                                        <div class="cliente">
                                            <?= h($r['descricao']) ?>
                                        </div>

                                    <?php endif; ?>

                                </div>

                            <?php else: ?>

                                <strong>
                                    <?= h($r['descricao']) ?>
                                </strong>

                                <?php if (!empty($r['observacao'])): ?>

                                    <div class="cliente">
                                        <?= h($r['observacao']) ?>
                                    </div>

                                <?php endif; ?>

                            <?php endif; ?>

                        </td>

                        <td>

                            <?php

                            $nomesForma = [
                                'pix' => 'PIX',
                                'dinheiro' => 'Dinheiro',
                                'cartao' => 'Cartão',
                                'transferencia' => 'Transferência',
                                'outro' => 'Outro'
                            ];

                            echo h(
                                $nomesForma[$r['forma_pagamento']]
                                ?? ucfirst($r['forma_pagamento'])
                            );

                            ?>

                        </td>

                        <td class="valor">

                            <?= dinheiro($r['valor']) ?>

                        </td>

                        <td>

                            <div class="acoes-linha">

                                <a
                                    href="editar_recebimento.php?id=<?= (int) $r['id'] ?>"
                                    class="btn btn-editar"
                                >
                                    Editar
                                </a>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Tem certeza que deseja excluir este recebimento? Se ele estiver vinculado a um pedido, o saldo do pedido será recalculado.');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h(csrf_token()) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="acao"
                                        value="excluir"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $r['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-excluir"
                                    >
                                        Excluir
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

</div>

</body>
</html>