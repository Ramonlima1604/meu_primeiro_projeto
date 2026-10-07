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
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
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

function valor_decimal(string $valor): float
{
    $valor = trim($valor);

    /*
     * Aceita:
     * 100
     * 100,50
     * 1.000,50
     * 100.50
     */

    $valor = str_replace('R$', '', $valor);
    $valor = trim($valor);

    if (strpos($valor, ',') !== false) {

        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);

    } else {

        /*
         * Se não possui vírgula,
         * consideramos o ponto como decimal.
         */
        $valor = str_replace(',', '.', $valor);
    }

    return (float) $valor;
}

/* =========================================================
   BUSCAR CONFEITEIRA
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        id,
        nome_confeitaria
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
   ID DO RECEBIMENTO
========================================================= */

$recebimentoId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($recebimentoId <= 0) {
    header('Location: recebimentos.php');
    exit;
}

/* =========================================================
   BUSCAR RECEBIMENTO
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.descricao,
        r.valor,
        r.data_recebimento,
        r.forma_pagamento,
        r.observacao,
        r.pedido_id,

        p.numero_pedido,
        p.valor_total AS pedido_valor_total,

        c.nome AS cliente_nome

    FROM recebimentos r

    LEFT JOIN pedidos p
        ON p.id = r.pedido_id
       AND p.confeiteira_id = r.confeiteira_id

    LEFT JOIN clientes c
        ON c.id = p.cliente_id

    WHERE r.id = ?
      AND r.confeiteira_id = ?

    LIMIT 1
");

$stmt->execute([
    $recebimentoId,
    $confeiteiraId
]);

$recebimento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recebimento) {
    exit('Recebimento não encontrado.');
}

/* =========================================================
   VARIÁVEIS
========================================================= */

$erro = '';

$descricao = $recebimento['descricao'];
$valor = number_format(
    (float) $recebimento['valor'],
    2,
    ',',
    '.'
);

$dataRecebimento = date(
    'Y-m-d',
    strtotime($recebimento['data_recebimento'])
);

$formaPagamento = $recebimento['forma_pagamento'];
$observacao = $recebimento['observacao'];

/* =========================================================
   FORMAS DE PAGAMENTO
========================================================= */

$formasPagamento = [
    'pix' => 'PIX',
    'dinheiro' => 'Dinheiro',
    'cartao' => 'Cartão',
    'transferencia' => 'Transferência',
    'outro' => 'Outro'
];

/* =========================================================
   PROCESSAMENTO
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    validar_csrf();

    $descricao = trim($_POST['descricao'] ?? '');
    $valorInformado = trim($_POST['valor'] ?? '');
    $dataInformada = trim($_POST['data_recebimento'] ?? '');
    $formaInformada = trim($_POST['forma_pagamento'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');

    /* =====================================================
       VALIDAÇÕES
    ===================================================== */

    if ($descricao === '') {
        $erro = 'Informe uma descrição.';
    }

    $novoValor = valor_decimal($valorInformado);

    if ($erro === '' && $novoValor <= 0) {
        $erro = 'Informe um valor maior que zero.';
    }

    if (
        $erro === '' &&
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $dataInformada
        )
    ) {
        $erro = 'Informe uma data válida.';
    }

    if (
        $erro === '' &&
        !array_key_exists(
            $formaInformada,
            $formasPagamento
        )
    ) {
        $erro = 'Forma de pagamento inválida.';
    }

    /* =====================================================
       ATUALIZAÇÃO
    ===================================================== */

    if ($erro === '') {

        try {

            $pdo->beginTransaction();

            /*
             * Busca novamente o recebimento com bloqueio.
             *
             * Isso evita que dois processos alterem
             * o mesmo recebimento simultaneamente.
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

            $recebimentoAtual = $stmt->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$recebimentoAtual) {
                throw new Exception(
                    'Recebimento não encontrado.'
                );
            }

            $pedidoId = !empty(
                $recebimentoAtual['pedido_id']
            )
                ? (int) $recebimentoAtual['pedido_id']
                : null;

            /* =================================================
               SE ESTIVER LIGADO A PEDIDO
            ================================================= */

            $pedido = null;

            if ($pedidoId !== null) {

                /*
                 * Bloqueia o pedido.
                 */
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

                $pedido = $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

                if (!$pedido) {
                    throw new Exception(
                        'Pedido vinculado não encontrado.'
                    );
                }

                $valorTotalPedido =
                    (float) $pedido['valor_total'];

                /*
                 * Soma os outros recebimentos,
                 * excluindo o que está sendo editado.
                 */
                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(SUM(valor), 0)
                    FROM recebimentos
                    WHERE pedido_id = ?
                      AND confeiteira_id = ?
                      AND id <> ?
                ");

                $stmt->execute([
                    $pedidoId,
                    $confeiteiraId,
                    $recebimentoId
                ]);

                $outrosRecebimentos =
                    (float) $stmt->fetchColumn();

                /*
                 * Soma o novo valor.
                 */
                $novoTotalRecebido =
                    $outrosRecebimentos + $novoValor;

                /*
                 * Não permite ultrapassar o pedido.
                 */
                if (
                    $novoTotalRecebido >
                    ($valorTotalPedido + 0.0001)
                ) {

                    $limiteDisponivel =
                        max(
                            0,
                            $valorTotalPedido -
                            $outrosRecebimentos
                        );

                    throw new Exception(
                        'O novo valor ultrapassa o valor disponível do pedido. ' .
                        'O máximo permitido neste recebimento é ' .
                        dinheiro($limiteDisponivel) .
                        '.'
                    );
                }
            }

            /* =================================================
               ATUALIZA RECEBIMENTO
            ================================================= */

            $stmt = $pdo->prepare("
                UPDATE recebimentos
                SET
                    descricao = ?,
                    valor = ?,
                    data_recebimento = ?,
                    forma_pagamento = ?,
                    observacao = ?
                WHERE id = ?
                  AND confeiteira_id = ?
            ");

            $stmt->execute([
                $descricao,
                $novoValor,
                $dataInformada,
                $formaInformada,
                $observacao !== ''
                    ? $observacao
                    : null,
                $recebimentoId,
                $confeiteiraId
            ]);

            /* =================================================
               RECALCULAR PEDIDO
            ================================================= */

            if ($pedidoId !== null) {

                /*
                 * Recalcula novamente depois da alteração.
                 */
                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(SUM(valor), 0)
                    FROM recebimentos
                    WHERE pedido_id = ?
                      AND confeiteira_id = ?
                ");

                $stmt->execute([
                    $pedidoId,
                    $confeiteiraId
                ]);

                $totalRecebido =
                    (float) $stmt->fetchColumn();

                $valorTotalPedido =
                    (float) $pedido['valor_total'];

                $valorRestante = max(
                    0,
                    $valorTotalPedido -
                    $totalRecebido
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

            header(
                'Location: visualizar_recebimento.php?id=' .
                $recebimentoId
            );

            /*
             * Caso não exista visualizar_recebimento.php,
             * podemos alterar este destino para
             * recebimentos.php.
             */
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            /*
             * Mensagens de regra de negócio podem ser
             * mostradas ao usuário.
             */
            $mensagem = $e->getMessage();

            if (
                str_contains(
                    $mensagem,
                    'ultrapassa o valor disponível'
                )
            ) {
                $erro = $mensagem;
            } elseif (
                str_contains(
                    $mensagem,
                    'Recebimento não encontrado'
                )
            ) {
                $erro = $mensagem;
            } elseif (
                str_contains(
                    $mensagem,
                    'Pedido vinculado não encontrado'
                )
            ) {
                $erro = $mensagem;
            } else {

                error_log(
                    'Erro ao editar recebimento: ' .
                    $mensagem
                );

                $erro =
                    'Não foi possível alterar o recebimento.';
            }
        }
    }

    /*
     * Mantém o valor digitado pelo usuário
     * caso tenha ocorrido erro.
     */
    if (
        isset($valorInformado) &&
        $valorInformado !== ''
    ) {
        $valor = $valorInformado;
    }

    if ($dataInformada !== '') {
        $dataRecebimento = $dataInformada;
    }

    if ($formaInformada !== '') {
        $formaPagamento = $formaInformada;
    }
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

    <title>
        Editar recebimento
    </title>

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
            max-width: 800px;
            margin: 0 auto;
            padding: 25px;
        }

        .topo {
            margin-bottom: 20px;
        }

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .subtitulo {
            color: #666;
            margin-top: 6px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
            margin-bottom: 20px;
        }

        .pedido {
            background: #f0f8ff;
            border: 1px solid #cfe8ff;
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .pedido strong {
            display: block;
            margin-bottom: 5px;
        }

        .cliente {
            color: #666;
        }

        .campo {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
            font-size: 14px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 7px;
            font-size: 15px;
            background: white;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .ajuda {
            font-size: 12px;
            color: #777;
            margin-top: 5px;
        }

        .acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 11px 17px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-salvar {
            background: #198754;
            color: white;
        }

        .btn-cancelar {
            background: #6c757d;
            color: white;
        }

        .alerta {
            padding: 14px 17px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .erro {
            background: #f8d7da;
            color: #842029;
        }

        .aviso {
            background: #fff3cd;
            color: #664d03;
        }

        .valor-atual {
            background: #f8f9fa;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .valor-atual span {
            color: #666;
            font-size: 13px;
        }

        .valor-atual strong {
            display: block;
            font-size: 22px;
            margin-top: 4px;
        }

        @media (max-width: 600px) {

            .container {
                padding: 15px;
            }

            h1 {
                font-size: 23px;
            }

            .card {
                padding: 18px;
            }

            .acoes .btn {
                width: 100%;
                text-align: center;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="topo">

        <h1>
            Editar recebimento
        </h1>

        <div class="subtitulo">
            Altere os dados do pagamento sem perder sua vinculação financeira.
        </div>

    </div>

    <?php if ($erro): ?>

        <div class="alerta erro">
            <?= h($erro) ?>
        </div>

    <?php endif; ?>

    <?php if (!empty($recebimento['pedido_id'])): ?>

        <div class="pedido">

            <strong>
                Pedido #<?= h($recebimento['numero_pedido']) ?>
            </strong>

            <?php if (!empty($recebimento['cliente_nome'])): ?>

                <div class="cliente">
                    Cliente:
                    <?= h($recebimento['cliente_nome']) ?>
                </div>

            <?php endif; ?>

            <div class="cliente">
                Valor do pedido:
                <?= dinheiro($recebimento['pedido_valor_total']) ?>
            </div>

        </div>

    <?php else: ?>

        <div class="alerta aviso">

            Este é um recebimento <strong>avulso</strong>.
            Ele não está vinculado a nenhum pedido.

        </div>

    <?php endif; ?>

    <div class="card">

        <div class="valor-atual">

            <span>
                Valor atual
            </span>

            <strong>
                <?= dinheiro($recebimento['valor']) ?>
            </strong>

        </div>

        <form
            method="POST"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="id"
                value="<?= (int) $recebimentoId ?>"
            >

            <div class="campo">

                <label for="descricao">
                    Descrição
                </label>

                <input
                    type="text"
                    id="descricao"
                    name="descricao"
                    maxlength="255"
                    value="<?= h($descricao) ?>"
                    required
                >

            </div>

            <div class="campo">

                <label for="valor">
                    Valor recebido
                </label>

                <input
                    type="text"
                    id="valor"
                    name="valor"
                    inputmode="decimal"
                    placeholder="0,00"
                    value="<?= h($valor) ?>"
                    required
                >

                <div class="ajuda">
                    Informe o valor efetivamente recebido.
                </div>

            </div>

            <div class="campo">

                <label for="data_recebimento">
                    Data do recebimento
                </label>

                <input
                    type="date"
                    id="data_recebimento"
                    name="data_recebimento"
                    value="<?= h($dataRecebimento) ?>"
                    required
                >

            </div>

            <div class="campo">

                <label for="forma_pagamento">
                    Forma de pagamento
                </label>

                <select
                    id="forma_pagamento"
                    name="forma_pagamento"
                    required
                >

                    <?php foreach (
                        $formasPagamento as $codigo => $nome
                    ): ?>

                        <option
                            value="<?= h($codigo) ?>"
                            <?= $formaPagamento === $codigo
                                ? 'selected'
                                : '' ?>
                        >
                            <?= h($nome) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="campo">

                <label for="observacao">
                    Observação
                </label>

                <textarea
                    id="observacao"
                    name="observacao"
                    maxlength="1000"
                    placeholder="Observações sobre o recebimento..."
                ><?= h($observacao) ?></textarea>

            </div>

            <div class="acoes">

                <button
                    type="submit"
                    class="btn btn-salvar"
                >
                    Salvar alterações
                </button>

                <a
                    href="recebimentos.php"
                    class="btn btn-cancelar"
                >
                    Cancelar
                </a>

            </div>

        </form>

    </div>

</div>

</body>

</html>