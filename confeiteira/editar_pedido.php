<?php
session_start();

require_once __DIR__ . '/../config/banco.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit;
}

$usuario_id = (int) $_SESSION['usuario_id'];

/* =========================================================
   FUNÇÕES
========================================================= */

function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function gerarCsrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validarCsrf(): bool
{
    return isset($_POST['csrf_token'])
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function valorDecimal($valor): float
{
    $valor = trim((string) $valor);

    if ($valor === '') {
        return 0;
    }

    /*
     * Aceita:
     * 80
     * 80,50
     * 80.50
     * 1.250,50
     * 1,250.50
     */
    if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {

        if (strrpos($valor, ',') > strrpos($valor, '.')) {
            // 1.250,50
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        } else {
            // 1,250.50
            $valor = str_replace(',', '', $valor);
        }

    } elseif (strpos($valor, ',') !== false) {
        // 80,50
        $valor = str_replace(',', '.', $valor);
    }

    return is_numeric($valor) ? (float) $valor : 0;
}

function dataValida(?string $data): bool
{
    if ($data === null || $data === '') {
        return false;
    }

    $d = DateTime::createFromFormat('Y-m-d', $data);

    return $d && $d->format('Y-m-d') === $data;
}

/* =========================================================
   CONFEITEIRA
========================================================= */

$stmt = $pdo->prepare("
    SELECT id, nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuario_id]);

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    die('Confeiteira não encontrada.');
}

$confeiteira_id = (int) $confeiteira['id'];

/* =========================================================
   ID DO PEDIDO
========================================================= */

$pedido_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$pedido_id || $pedido_id <= 0) {
    header('Location: pedidos.php');
    exit;
}

/* =========================================================
   BUSCAR PEDIDO
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        p.*
    FROM pedidos p
    WHERE p.id = ?
      AND p.confeiteira_id = ?
    LIMIT 1
");

$stmt->execute([
    $pedido_id,
    $confeiteira_id
]);

$pedido = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    die('Pedido não encontrado ou sem permissão.');
}

/* =========================================================
   ITENS ORIGINAIS
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        pi.id,
        pi.bolo_id,
        pi.quantidade,
        pi.preco_unitario,
        pi.subtotal,
        pi.observacao,
        b.nome AS bolo_nome,
        b.disponivel
    FROM pedido_itens pi
    INNER JOIN bolos b
        ON b.id = pi.bolo_id
       AND b.confeiteira_id = ?
    WHERE pi.pedido_id = ?
    ORDER BY pi.id ASC
");

$stmt->execute([
    $confeiteira_id,
    $pedido_id
]);

$itensOriginais = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$itensOriginais) {
    die('Este pedido não possui itens.');
}

/*
 * IDs dos produtos que já fazem parte do pedido.
 * Isso permite manter um produto que posteriormente
 * ficou indisponível.
 */
$bolosOriginais = [];

foreach ($itensOriginais as $item) {
    $bolosOriginais[] = (int) $item['bolo_id'];
}

/* =========================================================
   VALOR JÁ RECEBIDO
========================================================= */

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(valor), 0)
    FROM recebimentos
    WHERE pedido_id = ?
      AND confeiteira_id = ?
");

$stmt->execute([
    $pedido_id,
    $confeiteira_id
]);

$valorRecebidoAtual = round((float) $stmt->fetchColumn(), 2);

/* =========================================================
   DADOS PARA O FORMULÁRIO
========================================================= */

$clientesStmt = $pdo->prepare("
    SELECT
        id,
        nome,
        telefone,
        ativo
    FROM clientes
    WHERE confeiteira_id = ?
      AND (
            ativo = 1
            OR id = ?
      )
    ORDER BY nome ASC
");

$clientesStmt->execute([
    $confeiteira_id,
    (int) $pedido['cliente_id']
]);

$clientes = $clientesStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   PRODUTOS
========================================================= */

/*
 * Produtos disponíveis + produtos que já estão neste pedido.
 * Assim um produto que foi desativado continua aparecendo.
 */
if ($bolosOriginais) {

    $placeholders = implode(',', array_fill(0, count($bolosOriginais), '?'));

    $params = [$confeiteira_id];

    foreach ($bolosOriginais as $idBolo) {
        $params[] = $idBolo;
    }

    $sql = "
        SELECT
            id,
            nome,
            preco_venda,
            disponivel
        FROM bolos
        WHERE confeiteira_id = ?
          AND (
                disponivel = 1
                OR id IN ($placeholders)
          )
        ORDER BY nome ASC
    ";

    $bolosStmt = $pdo->prepare($sql);
    $bolosStmt->execute($params);

} else {

    $bolosStmt = $pdo->prepare("
        SELECT
            id,
            nome,
            preco_venda,
            disponivel
        FROM bolos
        WHERE confeiteira_id = ?
          AND disponivel = 1
        ORDER BY nome ASC
    ");

    $bolosStmt->execute([$confeiteira_id]);
}

$bolos = $bolosStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   STATUS PERMITIDOS
========================================================= */

$statusPermitidos = [
    'orcamento',
    'confirmado',
    'producao',
    'pronto',
    'entregue',
    'cancelado'
];

$mensagemErro = '';

/* =========================================================
   PROCESSAR POST
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validarCsrf()) {
        $mensagemErro = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {

        $cliente_id = filter_input(
            INPUT_POST,
            'cliente_id',
            FILTER_VALIDATE_INT
        );

        $data_pedido = trim($_POST['data_pedido'] ?? '');
        $data_entrega = trim($_POST['data_entrega'] ?? '');

        $status = trim($_POST['status'] ?? 'orcamento');
        $forma_pagamento = trim($_POST['forma_pagamento'] ?? '');
        $observacao = trim($_POST['observacao'] ?? '');

        $itensPost = $_POST['itens'] ?? [];

        /* =============================================
           VALIDAÇÕES BÁSICAS
        ============================================= */

        if (!$cliente_id || $cliente_id <= 0) {
            $mensagemErro = 'Selecione um cliente.';
        }

        elseif (!dataValida($data_pedido)) {
            $mensagemErro = 'Informe uma data de pedido válida.';
        }

        elseif ($data_entrega !== '' && !dataValida($data_entrega)) {
            $mensagemErro = 'Informe uma data de entrega válida.';
        }

        elseif (!in_array($status, $statusPermitidos, true)) {
            $mensagemErro = 'Status inválido.';
        }

        elseif (!is_array($itensPost) || count($itensPost) === 0) {
            $mensagemErro = 'Adicione pelo menos um produto ao pedido.';
        }

        /* =============================================
           VALIDAR CLIENTE
        ============================================= */

        if ($mensagemErro === '') {

            /*
             * Cliente atual pode continuar mesmo se ficou
             * inativo.
             *
             * Cliente inativo diferente do atual não pode
             * ser escolhido.
             */
            $stmt = $pdo->prepare("
                SELECT id, ativo
                FROM clientes
                WHERE id = ?
                  AND confeiteira_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $cliente_id,
                $confeiteira_id
            ]);

            $clienteSelecionado = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$clienteSelecionado) {

                $mensagemErro = 'Cliente inválido.';

            } elseif (
                (int) $clienteSelecionado['ativo'] !== 1
                && (int) $cliente_id !== (int) $pedido['cliente_id']
            ) {

                $mensagemErro = 'O cliente selecionado está inativo.';
            }
        }

        /* =============================================
           PROCESSAR ITENS
        ============================================= */

        $itensProcessados = [];
        $novoValorTotal = 0;

        if ($mensagemErro === '') {

            foreach ($itensPost as $item) {

                if (!is_array($item)) {
                    continue;
                }

                $bolo_id = isset($item['bolo_id'])
                    ? (int) $item['bolo_id']
                    : 0;

                $quantidade = isset($item['quantidade'])
                    ? valorDecimal($item['quantidade'])
                    : 0;

                $preco_unitario = isset($item['preco_unitario'])
                    ? valorDecimal($item['preco_unitario'])
                    : 0;

                $observacaoItem = trim(
                    $item['observacao'] ?? ''
                );

                /*
                 * Linha completamente vazia.
                 */
                if (
                    $bolo_id <= 0
                    && $quantidade <= 0
                    && $preco_unitario <= 0
                ) {
                    continue;
                }

                if ($bolo_id <= 0) {
                    $mensagemErro = 'Selecione um produto em todos os itens.';
                    break;
                }

                if ($quantidade < 0.001) {
                    $mensagemErro = 'A quantidade de cada produto deve ser maior que zero.';
                    break;
                }

                if ($preco_unitario < 0) {
                    $mensagemErro = 'O preço do produto não pode ser negativo.';
                    break;
                }

                $itensProcessados[] = [
                    'bolo_id' => $bolo_id,
                    'quantidade' => round($quantidade, 3),
                    'preco_unitario' => round($preco_unitario, 2),
                    'observacao' => $observacaoItem
                ];

                $novoValorTotal +=
                    $quantidade * $preco_unitario;
            }

            $novoValorTotal = round($novoValorTotal, 2);

            if ($mensagemErro === '' && count($itensProcessados) === 0) {
                $mensagemErro = 'Adicione pelo menos um produto ao pedido.';
            }
        }

        /* =============================================
           VALIDAR PRODUTOS
        ============================================= */

        if ($mensagemErro === '') {

            $idsProdutosEnviados = [];

            foreach ($itensProcessados as $item) {
                $idsProdutosEnviados[] = (int) $item['bolo_id'];
            }

            $idsProdutosEnviados = array_values(
                array_unique($idsProdutosEnviados)
            );

            $placeholders = implode(
                ',',
                array_fill(
                    0,
                    count($idsProdutosEnviados),
                    '?'
                )
            );

            $params = [$confeiteira_id];

            foreach ($idsProdutosEnviados as $idBolo) {
                $params[] = $idBolo;
            }

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    disponivel
                FROM bolos
                WHERE confeiteira_id = ?
                  AND id IN ($placeholders)
            ");

            $stmt->execute($params);

            $bolosEncontrados = $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

            $mapaBolos = [];

            foreach ($bolosEncontrados as $bolo) {
                $mapaBolos[(int) $bolo['id']] =
                    (int) $bolo['disponivel'];
            }

            foreach ($idsProdutosEnviados as $bolo_id) {

                if (!isset($mapaBolos[$bolo_id])) {
                    $mensagemErro =
                        'Um dos produtos selecionados não pertence à sua confeitaria.';
                    break;
                }

                /*
                 * Produto indisponível só pode continuar
                 * se ele já fazia parte do pedido original.
                 */
                if (
                    $mapaBolos[$bolo_id] !== 1
                    && !in_array(
                        $bolo_id,
                        $bolosOriginais,
                        true
                    )
                ) {

                    $mensagemErro =
                        'Um dos produtos selecionados está indisponível para novos pedidos.';
                    break;
                }
            }
        }

        /* =============================================
           SALVAR
        ============================================= */

        if ($mensagemErro === '') {

            try {

                $pdo->beginTransaction();

                /*
                 * Bloqueia o pedido durante a edição.
                 */
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        cliente_id,
                        valor_total,
                        valor_recebido,
                        valor_restante,
                        status
                    FROM pedidos
                    WHERE id = ?
                      AND confeiteira_id = ?
                    FOR UPDATE
                ");

                $stmt->execute([
                    $pedido_id,
                    $confeiteira_id
                ]);

                $pedidoBloqueado = $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

                if (!$pedidoBloqueado) {
                    throw new Exception(
                        'Pedido não encontrado.'
                    );
                }

                /*
                 * Busca novamente os pagamentos enquanto
                 * o pedido está bloqueado.
                 */
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(valor), 0)
                    FROM recebimentos
                    WHERE pedido_id = ?
                      AND confeiteira_id = ?
                ");

                $stmt->execute([
                    $pedido_id,
                    $confeiteira_id
                ]);

                $valorRecebidoReal = round(
                    (float) $stmt->fetchColumn(),
                    2
                );

                /*
                 * Não pode alterar o pedido para um valor
                 * menor que o que já foi recebido.
                 */
                if ($novoValorTotal < $valorRecebidoReal) {

                    throw new Exception(
                        'O novo valor do pedido não pode ser menor que o valor já recebido.'
                    );
                }

                $novoValorRestante = round(
                    $novoValorTotal - $valorRecebidoReal,
                    2
                );

                /* =====================================
                   ATUALIZAR PEDIDO
                ===================================== */

                $stmt = $pdo->prepare("
                    UPDATE pedidos
                    SET
                        cliente_id = ?,
                        data_pedido = ?,
                        data_entrega = ?,
                        status = ?,
                        valor_total = ?,
                        valor_recebido = ?,
                        valor_restante = ?,
                        forma_pagamento = ?,
                        observacao = ?,
                        atualizado_em = NOW()
                    WHERE id = ?
                      AND confeiteira_id = ?
                ");

                $stmt->execute([
                    $cliente_id,
                    $data_pedido,
                    $data_entrega !== ''
                        ? $data_entrega
                        : null,
                    $status,
                    $novoValorTotal,
                    $valorRecebidoReal,
                    $novoValorRestante,
                    $forma_pagamento,
                    $observacao,
                    $pedido_id,
                    $confeiteira_id
                ]);

                /* =====================================
                   APAGAR ITENS ANTIGOS
                ===================================== */

                $stmt = $pdo->prepare("
                    DELETE FROM pedido_itens
                    WHERE pedido_id = ?
                ");

                $stmt->execute([
                    $pedido_id
                ]);

                /* =====================================
                   INSERIR NOVOS ITENS
                ===================================== */

                $stmtInserirItem = $pdo->prepare("
                    INSERT INTO pedido_itens (
                        pedido_id,
                        bolo_id,
                        quantidade,
                        preco_unitario,
                        subtotal,
                        observacao
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($itensProcessados as $item) {

                    $subtotal = round(
                        $item['quantidade']
                        * $item['preco_unitario'],
                        2
                    );

                    $stmtInserirItem->execute([
                        $pedido_id,
                        $item['bolo_id'],
                        $item['quantidade'],
                        $item['preco_unitario'],
                        $subtotal,
                        $item['observacao']
                    ]);
                }

                $pdo->commit();

                header(
                    'Location: visualizar_pedido.php?id='
                    . $pedido_id
                    . '&atualizado=1'
                );

                exit;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Erro ao editar pedido #' .
                    $pedido_id .
                    ': ' .
                    $e->getMessage()
                );

                $mensagemErro = $e->getMessage();
            }
        }
    }
}

/* =========================================================
   RECONSTRUIR DADOS DO FORMULÁRIO
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mensagemErro !== '') {

    $formClienteId = (int) ($_POST['cliente_id'] ?? $pedido['cliente_id']);
    $formDataPedido = $_POST['data_pedido'] ?? $pedido['data_pedido'];
    $formDataEntrega = $_POST['data_entrega'] ?? $pedido['data_entrega'];
    $formStatus = $_POST['status'] ?? $pedido['status'];
    $formFormaPagamento = $_POST['forma_pagamento'] ?? $pedido['forma_pagamento'];
    $formObservacao = $_POST['observacao'] ?? $pedido['observacao'];

    $formItens = $_POST['itens'] ?? [];

    if (!is_array($formItens) || count($formItens) === 0) {
        $formItens = $itensOriginais;
    }

} else {

    $formClienteId = (int) $pedido['cliente_id'];
    $formDataPedido = $pedido['data_pedido'];
    $formDataEntrega = $pedido['data_entrega'];
    $formStatus = $pedido['status'];
    $formFormaPagamento = $pedido['forma_pagamento'];
    $formObservacao = $pedido['observacao'];

    $formItens = $itensOriginais;
}

/* =========================================================
   CSRF
========================================================= */

$csrf = gerarCsrf();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Editar Pedido #<?= h($pedido['numero_pedido']) ?></title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #222;
        }

        .container {
            width: min(1100px, 94%);
            margin: 30px auto;
        }

        .topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .topo h1 {
            margin: 0;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 7px;
            font-size: 15px;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }

        .item {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            background: #fafafa;
        }

        .item-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 10px;
            align-items: end;
        }

        .subtotal {
            font-weight: bold;
            padding: 11px;
            background: #eee;
            border-radius: 7px;
        }

        button,
        .btn {
            border: none;
            border-radius: 7px;
            padding: 11px 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }

        .btn-primary {
            background: #198754;
            color: #fff;
        }

        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }

        .btn-danger {
            background: #dc3545;
            color: #fff;
        }

        .btn-add {
            background: #0d6efd;
            color: #fff;
        }

        .acoes {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .total-box {
            text-align: right;
            font-size: 24px;
            font-weight: bold;
            margin-top: 20px;
        }

        .recebido {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .erro {
            background: #f8d7da;
            color: #842029;
            border: 1px solid #f5c2c7;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .produto-indisponivel {
            color: #b02a37;
            font-size: 12px;
        }

        @media (max-width: 800px) {

            .grid,
            .grid-3,
            .item-grid {
                grid-template-columns: 1fr;
            }

            .topo {
                flex-direction: column;
                align-items: flex-start;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="topo">

        <div>
            <h1>
                Editar Pedido
                #<?= h($pedido['numero_pedido']) ?>
            </h1>

            <div>
                Cliente:
                <strong>
                    <?= h($pedido['cliente_id']) ?>
                </strong>
            </div>
        </div>

        <a
            href="visualizar_pedido.php?id=<?= (int) $pedido_id ?>"
            class="btn btn-secondary"
        >
            ← Voltar
        </a>

    </div>

    <?php if ($mensagemErro !== ''): ?>

        <div class="erro">
            <?= h($mensagemErro) ?>
        </div>

    <?php endif; ?>

    <div class="recebido">

        <strong>Valor já recebido:</strong>

        R$
        <?= number_format(
            $valorRecebidoAtual,
            2,
            ',',
            '.'
        ) ?>

        <br>

        <small>
            O valor recebido é controlado pelo módulo financeiro.
            Para alterar o pagamento, utilize os recebimentos do pedido.
        </small>

    </div>

    <form
        method="POST"
        id="formPedido"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($csrf) ?>"
        >

        <div class="card">

            <h2>Dados do pedido</h2>

            <div class="grid">

                <div>

                    <label for="cliente_id">
                        Cliente
                    </label>

                    <select
                        name="cliente_id"
                        id="cliente_id"
                        required
                    >

                        <option value="">
                            Selecione...
                        </option>

                        <?php foreach ($clientes as $cliente): ?>

                            <option
                                value="<?= (int) $cliente['id'] ?>"
                                <?= $formClienteId == $cliente['id']
                                    ? 'selected'
                                    : '' ?>
                            >

                                <?= h($cliente['nome']) ?>

                                <?php if ((int) $cliente['ativo'] !== 1): ?>
                                    (inativo)
                                <?php endif; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label for="status">
                        Status
                    </label>

                    <select
                        name="status"
                        id="status"
                        required
                    >

                        <option
                            value="orcamento"
                            <?= $formStatus === 'orcamento'
                                ? 'selected'
                                : '' ?>
                        >
                            Orçamento
                        </option>

                        <option
                            value="confirmado"
                            <?= $formStatus === 'confirmado'
                                ? 'selected'
                                : '' ?>
                        >
                            Confirmado
                        </option>

                        <option
                            value="producao"
                            <?= $formStatus === 'producao'
                                ? 'selected'
                                : '' ?>
                        >
                            Em produção
                        </option>

                        <option
                            value="pronto"
                            <?= $formStatus === 'pronto'
                                ? 'selected'
                                : '' ?>
                        >
                            Pronto
                        </option>

                        <option
                            value="entregue"
                            <?= $formStatus === 'entregue'
                                ? 'selected'
                                : '' ?>
                        >
                            Entregue
                        </option>

                        <option
                            value="cancelado"
                            <?= $formStatus === 'cancelado'
                                ? 'selected'
                                : '' ?>
                        >
                            Cancelado
                        </option>

                    </select>

                </div>

            </div>

            <br>

            <div class="grid-3">

                <div>

                    <label for="data_pedido">
                        Data do pedido
                    </label>

                    <input
                        type="date"
                        name="data_pedido"
                        id="data_pedido"
                        value="<?= h($formDataPedido) ?>"
                        required
                    >

                </div>

                <div>

                    <label for="data_entrega">
                        Data de entrega
                    </label>

                    <input
                        type="date"
                        name="data_entrega"
                        id="data_entrega"
                        value="<?= h($formDataEntrega) ?>"
                    >

                </div>

                <div>

                    <label for="forma_pagamento">
                        Forma de pagamento
                    </label>

                    <select
                        name="forma_pagamento"
                        id="forma_pagamento"
                    >

                        <option value="">
                            Não informado
                        </option>

                        <option
                            value="pix"
                            <?= strtolower($formFormaPagamento) === 'pix'
                                ? 'selected'
                                : '' ?>
                        >
                            PIX
                        </option>

                        <option
                            value="dinheiro"
                            <?= strtolower($formFormaPagamento) === 'dinheiro'
                                ? 'selected'
                                : '' ?>
                        >
                            Dinheiro
                        </option>

                        <option
                            value="cartao"
                            <?= strtolower($formFormaPagamento) === 'cartao'
                                ? 'selected'
                                : '' ?>
                        >
                            Cartão
                        </option>

                        <option
                            value="transferencia"
                            <?= strtolower($formFormaPagamento) === 'transferencia'
                                ? 'selected'
                                : '' ?>
                        >
                            Transferência
                        </option>

                        <option
                            value="outro"
                            <?= strtolower($formFormaPagamento) === 'outro'
                                ? 'selected'
                                : '' ?>
                        >
                            Outro
                        </option>

                    </select>

                </div>

            </div>

            <br>

            <div>

                <label for="observacao">
                    Observação
                </label>

                <textarea
                    name="observacao"
                    id="observacao"
                ><?= h($formObservacao) ?></textarea>

            </div>

        </div>

        <div class="card">

            <div class="acoes">

                <h2 style="margin:0;">
                    Produtos do pedido
                </h2>

                <button
                    type="button"
                    class="btn btn-add"
                    id="adicionarItem"
                >
                    + Adicionar produto
                </button>

            </div>

            <br>

            <div id="listaItens">

                <?php foreach ($formItens as $indice => $item): ?>

                    <?php
                    $boloId = (int) ($item['bolo_id'] ?? 0);

                    $quantidade = $item['quantidade'] ?? 1;

                    $preco = $item['preco_unitario']
                        ?? $item['preco_venda']
                        ?? 0;

                    $observacaoItem =
                        $item['observacao'] ?? '';

                    $subtotal = round(
                        (float) $quantidade
                        * (float) $preco,
                        2
                    );

                    $disponivel = true;

                    foreach ($bolos as $boloTemp) {

                        if (
                            (int) $boloTemp['id']
                            === $boloId
                        ) {
                            $disponivel =
                                (int) $boloTemp['disponivel'] === 1;
                            break;
                        }
                    }
                    ?>

                    <div class="item">

                        <div class="item-grid">

                            <div>

                                <label>
                                    Produto
                                </label>

                                <select
                                    name="itens[<?= $indice ?>][bolo_id]"
                                    class="bolo-select"
                                    required
                                >

                                    <option value="">
                                        Selecione...
                                    </option>

                                    <?php foreach ($bolos as $bolo): ?>

                                        <option
                                            value="<?= (int) $bolo['id'] ?>"
                                            data-preco="<?= h($bolo['preco_venda']) ?>"
                                            data-disponivel="<?= (int) $bolo['disponivel'] ?>"
                                            <?= $boloId == $bolo['id']
                                                ? 'selected'
                                                : '' ?>
                                        >

                                            <?= h($bolo['nome']) ?>

                                            <?php if ((int) $bolo['disponivel'] !== 1): ?>
                                                (indisponível)
                                            <?php endif; ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <?php if (!$disponivel): ?>

                                    <div class="produto-indisponivel">
                                        Este produto está indisponível,
                                        mas pertence a este pedido.
                                    </div>

                                <?php endif; ?>

                            </div>

                            <div>

                                <label>
                                    Quantidade
                                </label>

                                <input
                                    type="number"
                                    name="itens[<?= $indice ?>][quantidade]"
                                    class="quantidade"
                                    value="<?= h($quantidade) ?>"
                                    min="0.001"
                                    step="0.001"
                                    required
                                >

                            </div>

                            <div>

                                <label>
                                    Preço unitário
                                </label>

                                <input
                                    type="text"
                                    name="itens[<?= $indice ?>][preco_unitario]"
                                    class="preco"
                                    value="<?= number_format(
                                        (float) $preco,
                                        2,
                                        ',',
                                        '.'
                                    ) ?>"
                                    required
                                >

                            </div>

                            <div>

                                <label>
                                    Subtotal
                                </label>

                                <div class="subtotal">
                                    R$
                                    <span class="subtotal-valor">
                                        <?= number_format(
                                            $subtotal,
                                            2,
                                            ',',
                                            '.'
                                        ) ?>
                                    </span>
                                </div>

                            </div>

                            <div>

                                <button
                                    type="button"
                                    class="btn btn-danger remover-item"
                                >
                                    Remover
                                </button>

                            </div>

                        </div>

                        <br>

                        <div>

                            <label>
                                Observação do produto
                            </label>

                            <input
                                type="text"
                                name="itens[<?= $indice ?>][observacao]"
                                value="<?= h($observacaoItem) ?>"
                                placeholder="Ex.: sabor, decoração, tamanho..."
                            >

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

            <div class="total-box">

                Total do pedido:
                R$
                <span id="valorTotal">
                    <?= number_format(
                        (float) $pedido['valor_total'],
                        2,
                        ',',
                        '.'
                    ) ?>
                </span>

            </div>

        </div>

        <div class="card">

            <div class="acoes">

                <a
                    href="visualizar_pedido.php?id=<?= (int) $pedido_id ?>"
                    class="btn btn-secondary"
                >
                    Cancelar
                </a>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Salvar alterações
                </button>

            </div>

        </div>

    </form>

</div>

<script>

(function () {

    const listaItens =
        document.getElementById('listaItens');

    const adicionarItem =
        document.getElementById('adicionarItem');

    const valorTotal =
        document.getElementById('valorTotal');

    let indice =
        listaItens.querySelectorAll('.item').length;

    function converterNumero(valor) {

        if (valor === null || valor === undefined) {
            return 0;
        }

        valor = String(valor).trim();

        if (!valor) {
            return 0;
        }

        if (
            valor.includes('.') &&
            valor.includes(',')
        ) {

            if (
                valor.lastIndexOf(',') >
                valor.lastIndexOf('.')
            ) {

                valor =
                    valor.replace(/\./g, '')
                         .replace(',', '.');

            } else {

                valor =
                    valor.replace(/,/g, '');
            }

        } else if (valor.includes(',')) {

            valor =
                valor.replace(',', '.');
        }

        const numero =
            parseFloat(valor);

        return Number.isFinite(numero)
            ? numero
            : 0;
    }

    function formatarMoeda(valor) {

        return Number(valor || 0).toLocaleString(
            'pt-BR',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );
    }

    function recalcular() {

        let total = 0;

        document
            .querySelectorAll('.item')
            .forEach(function (item) {

                const quantidade =
                    converterNumero(
                        item.querySelector('.quantidade')?.value
                    );

                const preco =
                    converterNumero(
                        item.querySelector('.preco')?.value
                    );

                const subtotal =
                    quantidade * preco;

                const subtotalElement =
                    item.querySelector('.subtotal-valor');

                if (subtotalElement) {
                    subtotalElement.textContent =
                        formatarMoeda(subtotal);
                }

                total += subtotal;
            });

        valorTotal.textContent =
            formatarMoeda(total);
    }

    function atualizarPreco(select) {

        const item =
            select.closest('.item');

        if (!item) {
            return;
        }

        const option =
            select.options[select.selectedIndex];

        if (!option) {
            return;
        }

        /*
         * Só preenche automaticamente o preço
         * quando o campo estiver vazio ou zero.
         *
         * Isso evita alterar manualmente um preço
         * que já foi definido no pedido.
         */
        const precoInput =
            item.querySelector('.preco');

        const precoAtual =
            converterNumero(precoInput.value);

        const precoProduto =
            converterNumero(
                option.dataset.preco || 0
            );

        if (
            precoAtual <= 0 &&
            precoProduto > 0
        ) {

            precoInput.value =
                formatarMoeda(precoProduto);
        }

        recalcular();
    }

    function atualizarIndices() {

        const itens =
            listaItens.querySelectorAll('.item');

        itens.forEach(function (item, index) {

            item
                .querySelector('.bolo-select')
                ?.setAttribute(
                    'name',
                    `itens[${index}][bolo_id]`
                );

            item
                .querySelector('.quantidade')
                ?.setAttribute(
                    'name',
                    `itens[${index}][quantidade]`
                );

            item
                .querySelector('.preco')
                ?.setAttribute(
                    'name',
                    `itens[${index}][preco_unitario]`
                );

            item
                .querySelector('input[type="text"]:not(.preco)')
                ?.setAttribute(
                    'name',
                    `itens[${index}][observacao]`
                );
        });

        indice = itens.length;
    }

    function criarItem() {

        const div =
            document.createElement('div');

        div.className = 'item';

        div.innerHTML = `

            <div class="item-grid">

                <div>

                    <label>Produto</label>

                    <select
                        name="itens[${indice}][bolo_id]"
                        class="bolo-select"
                        required
                    >

                        <option value="">
                            Selecione...
                        </option>

                        <?php foreach ($bolos as $bolo): ?>

                            <option
                                value="<?= (int) $bolo['id'] ?>"
                                data-preco="<?= h($bolo['preco_venda']) ?>"
                                data-disponivel="<?= (int) $bolo['disponivel'] ?>"
                            >

                                <?= h($bolo['nome']) ?>

                                <?php if ((int) $bolo['disponivel'] !== 1): ?>
                                    (indisponível)
                                <?php endif; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label>Quantidade</label>

                    <input
                        type="number"
                        name="itens[${indice}][quantidade]"
                        class="quantidade"
                        value="1"
                        min="0.001"
                        step="0.001"
                        required
                    >

                </div>

                <div>

                    <label>Preço unitário</label>

                    <input
                        type="text"
                        name="itens[${indice}][preco_unitario]"
                        class="preco"
                        value="0,00"
                        required
                    >

                </div>

                <div>

                    <label>Subtotal</label>

                    <div class="subtotal">
                        R$
                        <span class="subtotal-valor">
                            0,00
                        </span>
                    </div>

                </div>

                <div>

                    <button
                        type="button"
                        class="btn btn-danger remover-item"
                    >
                        Remover
                    </button>

                </div>

            </div>

            <br>

            <div>

                <label>
                    Observação do produto
                </label>

                <input
                    type="text"
                    name="itens[${indice}][observacao]"
                    placeholder="Ex.: sabor, decoração, tamanho..."
                >

            </div>
        `;

        listaItens.appendChild(div);

        indice++;

        recalcular();
    }

    adicionarItem.addEventListener(
        'click',
        criarItem
    );

    listaItens.addEventListener(
        'click',
        function (event) {

            if (
                event.target.classList.contains(
                    'remover-item'
                )
            ) {

                const itens =
                    listaItens.querySelectorAll('.item');

                /*
                 * Não deixa remover o último item.
                 */
                if (itens.length <= 1) {

                    alert(
                        'O pedido precisa ter pelo menos um produto.'
                    );

                    return;
                }

                const item =
                    event.target.closest('.item');

                if (item) {
                    item.remove();
                }

                atualizarIndices();
                recalcular();
            }
        }
    );

    listaItens.addEventListener(
        'change',
        function (event) {

            if (
                event.target.classList.contains(
                    'bolo-select'
                )
            ) {

                atualizarPreco(
                    event.target
                );
            }

            recalcular();
        }
    );

    listaItens.addEventListener(
        'input',
        function () {
            recalcular();
        }
    );

    document
        .getElementById('formPedido')
        .addEventListener(
            'submit',
            function (event) {

                const itens =
                    listaItens.querySelectorAll('.item');

                if (itens.length === 0) {

                    event.preventDefault();

                    alert(
                        'Adicione pelo menos um produto.'
                    );

                    return;
                }

                let valido = true;

                itens.forEach(function (item) {

                    const select =
                        item.querySelector('.bolo-select');

                    const quantidade =
                        converterNumero(
                            item.querySelector(
                                '.quantidade'
                            )?.value
                        );

                    const preco =
                        converterNumero(
                            item.querySelector(
                                '.preco'
                            )?.value
                        );

                    if (
                        !select.value ||
                        quantidade < 0.001 ||
                        preco < 0
                    ) {

                        valido = false;
                    }
                });

                if (!valido) {

                    event.preventDefault();

                    alert(
                        'Verifique os produtos, quantidades e preços.'
                    );

                    return;
                }

                atualizarIndices();
            }
        );

    recalcular();

})();

</script>

</body>
</html>