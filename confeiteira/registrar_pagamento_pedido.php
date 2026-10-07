<?php

session_start();

require_once __DIR__ . '/../config/banco.php';

/* =========================================================
   AUTENTICAÇÃO
========================================================= */

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
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validarCsrf(): bool
{
    return isset($_POST['csrf_token'])
        && isset($_SESSION['csrf_token'])
        && hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        );
}

function valorDecimal($valor): float
{
    $valor = trim((string) $valor);

    if ($valor === '') {
        return 0;
    }

    if (
        strpos($valor, ',') !== false
        && strpos($valor, '.') !== false
    ) {

        if (
            strrpos($valor, ',')
            >
            strrpos($valor, '.')
        ) {
            // 1.250,50

            $valor =
                str_replace('.', '', $valor);

            $valor =
                str_replace(',', '.', $valor);

        } else {
            // 1,250.50

            $valor =
                str_replace(',', '', $valor);
        }

    } elseif (
        strpos($valor, ',') !== false
    ) {

        $valor =
            str_replace(',', '.', $valor);
    }

    return is_numeric($valor)
        ? (float) $valor
        : 0;
}

function dataValida(string $data): bool
{
    $date =
        DateTime::createFromFormat(
            'Y-m-d',
            $data
        );

    return $date
        && $date->format('Y-m-d') === $data;
}

/* =========================================================
   CONFEITEIRA
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        id,
        nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([
    $usuario_id
]);

$confeiteira = $stmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$confeiteira) {
    die('Confeiteira não encontrada.');
}

$confeiteira_id =
    (int) $confeiteira['id'];

/* =========================================================
   PEDIDO
========================================================= */

$pedido_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$pedido_id || $pedido_id <= 0) {
    header('Location: pedidos.php');
    exit;
}

/* =========================================================
   BUSCAR PEDIDO
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        p.*,
        c.nome AS cliente_nome
    FROM pedidos p

    LEFT JOIN clientes c
        ON c.id = p.cliente_id
       AND c.confeiteira_id = p.confeiteira_id

    WHERE p.id = ?
      AND p.confeiteira_id = ?

    LIMIT 1
");

$stmt->execute([
    $pedido_id,
    $confeiteira_id
]);

$pedido = $stmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$pedido) {
    die(
        'Pedido não encontrado ou sem permissão.'
    );
}

/* =========================================================
   CALCULAR RECEBIDO REAL
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(valor), 0)
    FROM recebimentos
    WHERE pedido_id = ?
      AND confeiteira_id = ?
");

$stmt->execute([
    $pedido_id,
    $confeiteira_id
]);

$valorRecebido =
    round(
        (float) $stmt->fetchColumn(),
        2
    );

$valorTotal =
    round(
        (float) $pedido['valor_total'],
        2
    );

$valorRestante =
    max(
        round(
            $valorTotal - $valorRecebido,
            2
        ),
        0
    );

/* =========================================================
   ERRO
========================================================= */

$erro = '';

/* =========================================================
   PROCESSAR PAGAMENTO
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validarCsrf()) {

        $erro =
            'A sessão expirou. Atualize a página e tente novamente.';

    } else {

        $valorInformado =
            valorDecimal(
                $_POST['valor'] ?? ''
            );

        $dataRecebimento =
            trim(
                $_POST['data_recebimento']
                ?? date('Y-m-d')
            );

        $formaPagamento =
            trim(
                $_POST['forma_pagamento']
                ?? ''
            );

        $observacao =
            trim(
                $_POST['observacao']
                ?? ''
            );

        /* =============================================
           VALIDAÇÕES
        ============================================= */

        if ($valorInformado <= 0) {

            $erro =
                'Informe um valor de pagamento maior que zero.';

        } elseif (
            !dataValida($dataRecebimento)
        ) {

            $erro =
                'Informe uma data de recebimento válida.';

        } elseif (
            $formaPagamento === ''
        ) {

            $erro =
                'Selecione a forma de pagamento.';

        } elseif (
            $valorInformado > $valorRestante
        ) {

            $erro =
                'O pagamento não pode ser maior que o valor restante.';
        }

        /* =============================================
           SALVAR
        ============================================= */

        if ($erro === '') {

            try {

                $pdo->beginTransaction();

                /*
                 * Bloqueia o pedido para evitar dois
                 * pagamentos simultâneos ultrapassando
                 * o valor restante.
                 */
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        valor_total,
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

                $pedidoBloqueado =
                    $stmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!$pedidoBloqueado) {
                    throw new Exception(
                        'Pedido não encontrado.'
                    );
                }

                $valorTotalBanco =
                    round(
                        (float)
                        $pedidoBloqueado['valor_total'],
                        2
                    );

                /*
                 * Soma novamente os recebimentos
                 * enquanto o pedido está bloqueado.
                 */
                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(SUM(valor), 0)
                    FROM recebimentos
                    WHERE pedido_id = ?
                      AND confeiteira_id = ?
                ");

                $stmt->execute([
                    $pedido_id,
                    $confeiteira_id
                ]);

                $recebidoAtual =
                    round(
                        (float)
                        $stmt->fetchColumn(),
                        2
                    );

                $restanteAtual =
                    max(
                        round(
                            $valorTotalBanco
                            - $recebidoAtual,
                            2
                        ),
                        0
                    );

                /*
                 * Confere novamente depois do lock.
                 */
                if ($valorInformado > $restanteAtual) {

                    throw new Exception(
                        'O valor informado é maior que o saldo restante do pedido.'
                    );
                }

                /* =====================================
                   INSERIR RECEBIMENTO
                ===================================== */

                $stmt = $pdo->prepare("
                    INSERT INTO recebimentos (
                        confeiteira_id,
                        descricao,
                        valor,
                        data_recebimento,
                        forma_pagamento,
                        pedido_id,
                        observacao,
                        criado_em
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        NOW()
                    )
                ");

                $descricao =
                    'Pagamento do pedido #'
                    . $pedido['numero_pedido'];

                $stmt->execute([
                    $confeiteira_id,
                    $descricao,
                    $valorInformado,
                    $dataRecebimento,
                    $formaPagamento,
                    $pedido_id,
                    $observacao
                ]);

                /* =====================================
                   NOVO TOTAL RECEBIDO
                ===================================== */

                $novoRecebido =
                    round(
                        $recebidoAtual
                        + $valorInformado,
                        2
                    );

                $novoRestante =
                    max(
                        round(
                            $valorTotalBanco
                            - $novoRecebido,
                            2
                        ),
                        0
                    );

                /* =====================================
                   ATUALIZAR PEDIDO
                ===================================== */

                $stmt = $pdo->prepare("
                    UPDATE pedidos
                    SET
                        valor_recebido = ?,
                        valor_restante = ?,
                        atualizado_em = NOW()
                    WHERE id = ?
                      AND confeiteira_id = ?
                ");

                $stmt->execute([
                    $novoRecebido,
                    $novoRestante,
                    $pedido_id,
                    $confeiteira_id
                ]);

                $pdo->commit();

                header(
                    'Location: visualizar_pedido.php?id='
                    . $pedido_id
                    . '&pagamento=1'
                );

                exit;

            } catch (Throwable $e) {

                if (
                    $pdo->inTransaction()
                ) {
                    $pdo->rollBack();
                }

                error_log(
                    'Erro ao registrar pagamento do pedido #'
                    . $pedido_id
                    . ': '
                    . $e->getMessage()
                );

                $erro =
                    'Não foi possível registrar o pagamento. Tente novamente.';
            }
        }
    }
}

/* =========================================================
   CSRF
========================================================= */

$csrf = csrfToken();

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
        Registrar pagamento -
        Pedido #<?= h($pedido['numero_pedido']) ?>
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f8;
            font-family: Arial, sans-serif;
            color: #222;
        }

        .container {
            width: min(700px, 94%);
            margin: 35px auto;
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
            padding: 25px;
            border-radius: 12px;
            box-shadow:
                0 2px 10px
                rgba(0,0,0,.06);
            margin-bottom: 20px;
        }

        .resumo {
            display: grid;
            grid-template-columns:
                repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 25px;
        }

        .box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
        }

        .box strong {
            display: block;
            font-size: 20px;
            margin-top: 5px;
        }

        .restante {
            background: #fff3cd;
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
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 7px;
            font-size: 15px;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .campo {
            margin-bottom: 18px;
        }

        .grid {
            display: grid;
            grid-template-columns:
                1fr 1fr;
            gap: 15px;
        }

        .acoes {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            border-radius: 7px;
            padding: 12px 18px;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
            display: inline-block;
        }

        .btn-primary {
            background: #198754;
            color: #fff;
        }

        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }

        .erro {
            background: #f8d7da;
            color: #842029;
            border: 1px solid #f5c2c7;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .sem-saldo {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
            padding: 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        @media (max-width: 650px) {

            .resumo,
            .grid {
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
                Registrar pagamento
            </h1>

            <div>
                Pedido
                <strong>
                    #<?= h($pedido['numero_pedido']) ?>
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

    <?php if ($erro !== ''): ?>

        <div class="erro">
            <?= h($erro) ?>
        </div>

    <?php endif; ?>

    <div class="card">

        <div class="resumo">

            <div class="box">

                Total do pedido

                <strong>
                    R$
                    <?= number_format(
                        $valorTotal,
                        2,
                        ',',
                        '.'
                    ) ?>
                </strong>

            </div>

            <div class="box">

                Já recebido

                <strong>
                    R$
                    <?= number_format(
                        $valorRecebido,
                        2,
                        ',',
                        '.'
                    ) ?>
                </strong>

            </div>

            <div class="box restante">

                Falta receber

                <strong>
                    R$
                    <?= number_format(
                        $valorRestante,
                        2,
                        ',',
                        '.'
                    ) ?>
                </strong>

            </div>

        </div>

        <?php if ($valorRestante <= 0): ?>

            <div class="sem-saldo">

                <strong>
                    Pedido totalmente pago.
                </strong>

                <br>

                Não existe saldo restante para registrar
                um novo pagamento.

            </div>

        <?php else: ?>

            <form
                method="POST"
                id="formPagamento"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($csrf) ?>"
                >

                <div class="campo">

                    <label for="valor">
                        Valor recebido
                    </label>

                    <input
                        type="text"
                        name="valor"
                        id="valor"
                        placeholder="Ex.: 150,00"
                        value="<?= isset($_POST['valor'])
                            ? h($_POST['valor'])
                            : '' ?>"
                        required
                    >

                    <small>
                        Máximo permitido:
                        <strong>
                            R$
                            <?= number_format(
                                $valorRestante,
                                2,
                                ',',
                                '.'
                            ) ?>
                        </strong>
                    </small>

                </div>

                <div class="grid">

                    <div class="campo">

                        <label for="data_recebimento">
                            Data do recebimento
                        </label>

                        <input
                            type="date"
                            name="data_recebimento"
                            id="data_recebimento"
                            value="<?= h(
                                $_POST['data_recebimento']
                                ?? date('Y-m-d')
                            ) ?>"
                            required
                        >

                    </div>

                    <div class="campo">

                        <label for="forma_pagamento">
                            Forma de pagamento
                        </label>

                        <select
                            name="forma_pagamento"
                            id="forma_pagamento"
                            required
                        >

                            <option value="">
                                Selecione...
                            </option>

                            <option
                                value="pix"
                                <?= (
                                    ($_POST['forma_pagamento'] ?? '')
                                    === 'pix'
                                )
                                    ? 'selected'
                                    : '' ?>
                            >
                                PIX
                            </option>

                            <option
                                value="dinheiro"
                                <?= (
                                    ($_POST['forma_pagamento'] ?? '')
                                    === 'dinheiro'
                                )
                                    ? 'selected'
                                    : '' ?>
                            >
                                Dinheiro
                            </option>

                            <option
                                value="cartao"
                                <?= (
                                    ($_POST['forma_pagamento'] ?? '')
                                    === 'cartao'
                                )
                                    ? 'selected'
                                    : '' ?>
                            >
                                Cartão
                            </option>

                            <option
                                value="transferencia"
                                <?= (
                                    ($_POST['forma_pagamento'] ?? '')
                                    === 'transferencia'
                                )
                                    ? 'selected'
                                    : '' ?>
                            >
                                Transferência
                            </option>

                            <option
                                value="outro"
                                <?= (
                                    ($_POST['forma_pagamento'] ?? '')
                                    === 'outro'
                                )
                                    ? 'selected'
                                    : '' ?>
                            >
                                Outro
                            </option>

                        </select>

                    </div>

                </div>

                <div class="campo">

                    <label for="observacao">
                        Observação
                    </label>

                    <textarea
                        name="observacao"
                        id="observacao"
                        placeholder="Ex.: entrada, parcela, pagamento final..."
                    ><?= h(
                        $_POST['observacao'] ?? ''
                    ) ?></textarea>

                </div>

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
                        Registrar pagamento
                    </button>

                </div>

            </form>

        <?php endif; ?>

    </div>

</div>

<script>

(function () {

    const form =
        document.getElementById(
            'formPagamento'
        );

    if (!form) {
        return;
    }

    form.addEventListener(
        'submit',
        function (event) {

            const campo =
                document.getElementById(
                    'valor'
                );

            let valor =
                campo.value.trim();

            if (!valor) {
                event.preventDefault();

                alert(
                    'Informe o valor recebido.'
                );

                campo.focus();

                return;
            }

            /*
             * Normalização visual antes do envio.
             */
            if (
                valor.includes(',')
                &&
                valor.includes('.')
            ) {

                if (
                    valor.lastIndexOf(',')
                    >
                    valor.lastIndexOf('.')
                ) {

                    valor =
                        valor
                            .replace(/\./g, '')
                            .replace(',', '.');

                } else {

                    valor =
                        valor.replace(/,/g, '');
                }

            } else if (
                valor.includes(',')
            ) {

                valor =
                    valor.replace(',', '.');
            }

            const numero =
                parseFloat(valor);

            if (
                !Number.isFinite(numero)
                || numero <= 0
            ) {

                event.preventDefault();

                alert(
                    'Informe um valor válido.'
                );

                campo.focus();

                return;
            }

            campo.value =
                numero.toFixed(2);

        }
    );

})();

</script>

</body>
</html>