<?php

require_once "../config/auth.php";
require_once "../config/banco.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

if (function_exists("exigirTipo")) {
    exigirTipo("confeiteira");
}

$usuario_id = (int) $_SESSION["usuario_id"];


/*
|--------------------------------------------------------------------------
| FUNÇÕES AUXILIARES
|--------------------------------------------------------------------------
*/

function moeda($valor)
{
    return "R$ " . number_format(
        max(0, (float) $valor),
        2,
        ",",
        "."
    );
}


function dataBR($data)
{
    if (!$data) {
        return "-";
    }

    $timestamp = strtotime($data);

    if (!$timestamp) {
        return "-";
    }

    return date("d/m/Y", $timestamp);
}


function statusTexto($status)
{
    $mapa = [
        "orcamento"  => "Orçamento",
        "confirmado" => "Confirmado",
        "producao"   => "Em produção",
        "pronto"     => "Pronto",
        "entregue"   => "Entregue",
        "cancelado"  => "Cancelado"
    ];

    return $mapa[$status] ?? ucfirst((string) $status);
}


function statusClasse($status)
{
    $mapa = [
        "orcamento"  => "status-orcamento",
        "confirmado" => "status-confirmado",
        "producao"   => "status-producao",
        "pronto"     => "status-pronto",
        "entregue"   => "status-entregue",
        "cancelado"  => "status-cancelado"
    ];

    return $mapa[$status] ?? "status-padrao";
}


/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

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

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    die("Confeiteira não encontrada.");
}

$confeiteira_id = (int) $confeiteira["id"];


/*
|--------------------------------------------------------------------------
| VALIDAR PEDIDO
|--------------------------------------------------------------------------
*/

$pedido_id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;

if ($pedido_id <= 0) {
    die("Pedido inválido.");
}


/*
|--------------------------------------------------------------------------
| BUSCAR PEDIDO
|--------------------------------------------------------------------------
|
| O pedido precisa pertencer à confeiteira logada.
|
*/

$stmt = $pdo->prepare("
    SELECT
        p.*,

        c.nome AS cliente_nome,
        c.telefone AS cliente_telefone,
        c.email AS cliente_email,
        c.endereco AS cliente_endereco,
        c.numero AS cliente_numero,
        c.bairro AS cliente_bairro,
        c.cidade AS cliente_cidade,
        c.observacao AS cliente_observacao

    FROM pedidos p

    INNER JOIN clientes c
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

$pedido = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    die("Pedido não encontrado ou não pertence a esta confeiteira.");
}


/*
|--------------------------------------------------------------------------
| BUSCAR ITENS DO PEDIDO
|--------------------------------------------------------------------------
|
| O produto também precisa pertencer à confeiteira do pedido.
|
*/

$stmt = $pdo->prepare("
    SELECT
        pi.id,
        pi.quantidade,
        pi.preco_unitario,
        pi.subtotal,
        pi.observacao,

        b.id AS bolo_id,
        b.nome AS bolo_nome,
        b.tamanho AS bolo_tamanho,
        b.categoria AS bolo_categoria

    FROM pedido_itens pi

    INNER JOIN pedidos p
        ON p.id = pi.pedido_id
       AND p.confeiteira_id = ?

    INNER JOIN bolos b
        ON b.id = pi.bolo_id
       AND b.confeiteira_id = p.confeiteira_id

    WHERE pi.pedido_id = ?

    ORDER BY pi.id ASC
");

$stmt->execute([
    $confeiteira_id,
    $pedido_id
]);

$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| BUSCAR PAGAMENTOS
|--------------------------------------------------------------------------
|
| A tabela recebimentos é a fonte de verdade.
|
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        descricao,
        valor,
        data_recebimento,
        forma_pagamento,
        observacao,
        criado_em

    FROM recebimentos

    WHERE pedido_id = ?
      AND confeiteira_id = ?

    ORDER BY
        data_recebimento ASC,
        id ASC
");

$stmt->execute([
    $pedido_id,
    $confeiteira_id
]);

$pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| CALCULAR VALORES REAIS
|--------------------------------------------------------------------------
*/

$valor_total = max(
    0,
    (float) $pedido["valor_total"]
);

$valor_recebido = 0;

foreach ($pagamentos as $pagamento) {

    $valor_pagamento = (float) $pagamento["valor"];

    /*
     * Não permitimos que eventual valor negativo
     * distorça o resumo financeiro.
     */
    if ($valor_pagamento > 0) {
        $valor_recebido += $valor_pagamento;
    }
}

$valor_restante = max(
    0,
    $valor_total - $valor_recebido
);


/*
|--------------------------------------------------------------------------
| STATUS DO PAGAMENTO
|--------------------------------------------------------------------------
*/

if ($valor_total <= 0) {

    $status_pagamento = "Sem valor";
    $classe_pagamento = "neutro";

} elseif ($valor_recebido <= 0) {

    $status_pagamento = "Não pago";
    $classe_pagamento = "nao-pago";

} elseif ($valor_restante <= 0.009) {

    $status_pagamento = "Pedido totalmente pago";
    $classe_pagamento = "pago";

} else {

    $status_pagamento = "Pagamento parcial";
    $classe_pagamento = "parcial";
}


/*
|--------------------------------------------------------------------------
| PERCENTUAL PAGO
|--------------------------------------------------------------------------
*/

$percentual_pago = 0;

if ($valor_total > 0) {

    $percentual_pago = (
        $valor_recebido / $valor_total
    ) * 100;

    $percentual_pago = min(
        100,
        max(
            0,
            $percentual_pago
        )
    );
}


/*
|--------------------------------------------------------------------------
| DEFINIR SE PODE REGISTRAR PAGAMENTO
|--------------------------------------------------------------------------
|
| Pedido cancelado não recebe novos pagamentos por este fluxo.
|
*/

$pode_registrar_pagamento =
    $valor_restante > 0.009 &&
    $pedido["status"] !== "cancelado";


/*
|--------------------------------------------------------------------------
| DEFINIR SE PEDIDO ESTÁ PAGO
|--------------------------------------------------------------------------
*/

$pedido_pago =
    $valor_total > 0 &&
    $valor_restante <= 0.009;


/*
|--------------------------------------------------------------------------
| MENSAGEM DE PAGAMENTO
|--------------------------------------------------------------------------
*/

$pagamento_sucesso =
    isset($_GET["pagamento"]) &&
    $_GET["pagamento"] === "sucesso";


/*
|--------------------------------------------------------------------------
| NÚMERO DO PEDIDO
|--------------------------------------------------------------------------
*/

if (
    isset($pedido["numero_pedido"]) &&
    (int) $pedido["numero_pedido"] > 0
) {

    $numero_pedido = str_pad(
        (string) (int) $pedido["numero_pedido"],
        3,
        "0",
        STR_PAD_LEFT
    );

} else {

    $numero_pedido = str_pad(
        (string) $pedido_id,
        3,
        "0",
        STR_PAD_LEFT
    );
}


/*
|--------------------------------------------------------------------------
| CONTAGEM DE PAGAMENTOS
|--------------------------------------------------------------------------
*/

$total_pagamentos = count($pagamentos);

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
        Pedido #<?= htmlspecialchars(
            $numero_pedido,
            ENT_QUOTES,
            "UTF-8"
        ) ?>
    </title>


    <style>

        * {
            box-sizing: border-box;
        }


        :root {

            color-scheme: light;

            --bg: #f3f5f8;
            --surface: #fff;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #e5eaf0;

            --primary: #4f46e5;

            --green: #15803d;
            --red: #b91c1c;
            --orange: #b45309;

            --shadow:
                0 8px 24px rgba(
                    31,
                    41,
                    55,
                    .06
                );
        }


        body {

            margin: 0;

            padding:
                32px
                18px
                48px;

            background:
                var(--bg);

            color:
                var(--text);

            font-family:
                Inter,
                "Segoe UI",
                Arial,
                sans-serif;

            line-height:
                1.5;
        }


        .page {

            width:
                min(100%, 1080px);

            margin:
                0 auto;
        }


        .topbar {

            display:
                flex;

            align-items:
                center;

            justify-content:
                space-between;

            gap:
                24px;

            margin-bottom:
                24px;
        }


        .eyebrow {

            margin:
                0 0 4px;

            color:
                var(--muted);

            font-size:
                12px;

            font-weight:
                700;

            letter-spacing:
                .1em;

            text-transform:
                uppercase;
        }


        .title-row {

            display:
                flex;

            align-items:
                center;

            gap:
                12px;

            flex-wrap:
                wrap;
        }


        h1 {

            margin:
                0;

            font-size:
                clamp(
                    25px,
                    4vw,
                    34px
                );

            line-height:
                1.15;
        }


        .client-name {

            margin:
                7px 0 0;

            color:
                var(--muted);

            font-size:
                16px;
        }


        .header-actions {

            display:
                flex;

            align-items:
                center;

            gap:
                10px;

            flex-wrap:
                wrap;
        }


        .button {

            display:
                inline-flex;

            min-height:
                42px;

            align-items:
                center;

            justify-content:
                center;

            gap:
                7px;

            padding:
                10px 15px;

            border:
                1px solid transparent;

            border-radius:
                10px;

            color:
                #fff;

            text-decoration:
                none;

            font-weight:
                650;

            cursor:
                pointer;

            transition:
                transform .15s,
                box-shadow .15s;
        }


        .button:hover {

            transform:
                translateY(-1px);

            box-shadow:
                0 5px 12px
                rgba(
                    31,
                    41,
                    55,
                    .12
                );
        }


        .button-back {

            color:
                #374151;

            background:
                #fff;

            border-color:
                var(--line);
        }


        .button-edit {

            color:
                #fff;

            background:
                var(--primary);
        }


        .button-pay {

            min-height:
                48px;

            padding:
                12px 20px;

            background:
                var(--green);

            font-size:
                16px;
        }


        .button-paid {

            color:
                #166534;

            background:
                #dcfce7;

            cursor:
                default;
        }


        .button-disabled {

            color:
                #6b7280;

            background:
                #f3f4f6;

            border-color:
                var(--line);

            cursor:
                default;
        }


        .button-payment-edit {

            min-height:
                36px;

            padding:
                7px 11px;

            color:
                #374151;

            background:
                #f3f4f6;

            border:
                1px solid
                var(--line);

            font-size:
                12px;
        }


        .card {

            margin-bottom:
                18px;

            padding:
                24px;

            background:
                var(--surface);

            border:
                1px solid
                rgba(
                    229,
                    234,
                    240,
                    .85
                );

            border-radius:
                16px;

            box-shadow:
                var(--shadow);
        }


        .card h2 {

            margin:
                0 0 18px;

            font-size:
                19px;

            letter-spacing:
                -.01em;
        }


        .status {

            display:
                inline-flex;

            align-items:
                center;

            min-height:
                30px;

            padding:
                5px 11px;

            border-radius:
                999px;

            font-size:
                13px;

            font-weight:
                700;

            white-space:
                nowrap;
        }


        .status-orcamento {

            color:
                #854d0e;

            background:
                #fef3c7;
        }


        .status-confirmado {

            color:
                #155e75;

            background:
                #cffafe;
        }


        .status-producao {

            color:
                #1d4ed8;

            background:
                #dbeafe;
        }


        .status-pronto {

            color:
                #166534;

            background:
                #dcfce7;
        }


        .status-entregue {

            color:
                #fff;

            background:
                #15803d;
        }


        .status-cancelado {

            color:
                #991b1b;

            background:
                #fee2e2;
        }


        .status-padrao {

            color:
                #374151;

            background:
                #e5e7eb;
        }


        .alert {

            margin-bottom:
                18px;

            padding:
                14px 16px;

            border:
                1px solid
                #bbf7d0;

            border-radius:
                12px;

            color:
                #166534;

            background:
                #f0fdf4;

            font-weight:
                600;
        }


        .alert-warning {

            border-color:
                #fde68a;

            color:
                #92400e;

            background:
                #fffbeb;
        }


        .finance-card {

            border-top:
                4px solid
                var(--primary);
        }


        .financial-grid {

            display:
                grid;

            grid-template-columns:
                repeat(
                    3,
                    minmax(0, 1fr)
                );

            gap:
                14px;
        }


        .financial-item {

            min-width:
                0;

            padding:
                17px;

            border:
                1px solid
                var(--line);

            border-radius:
                12px;

            background:
                #f9fafb;
        }


        .financial-item small,
        .detail-item small {

            display:
                block;

            margin-bottom:
                7px;

            color:
                var(--muted);

            font-size:
                12px;

            font-weight:
                700;

            letter-spacing:
                .04em;

            text-transform:
                uppercase;
        }


        .financial-item strong {

            display:
                block;

            font-size:
                clamp(
                    21px,
                    3vw,
                    28px
                );

            line-height:
                1.2;
        }


        .financial-total strong {

            color:
                #111827;
        }


        .financial-received strong {

            color:
                var(--green);
        }


        .financial-remaining strong {

            color:
                var(--red);
        }


        .financial-remaining.is-paid strong {

            color:
                var(--green);
        }


        .payment-progress {

            margin-top:
                18px;
        }


        .progress-track {

            height:
                10px;

            overflow:
                hidden;

            border-radius:
                999px;

            background:
                #e5e7eb;
        }


        .progress-fill {

            height:
                100%;

            border-radius:
                inherit;

            background:
                var(--green);

            transition:
                width .2s ease;
        }


        .progress-caption {

            display:
                flex;

            justify-content:
                space-between;

            gap:
                12px;

            margin-top:
                7px;

            color:
                var(--muted);

            font-size:
                12px;
        }


        .payment-status-box {

            display:
                inline-flex;

            align-items:
                center;

            gap:
                7px;

            margin-top:
                16px;

            padding:
                8px 12px;

            border-radius:
                999px;

            font-size:
                13px;

            font-weight:
                700;
        }


        .payment-status-box.pago {

            color:
                #166534;

            background:
                #dcfce7;
        }


        .payment-status-box.parcial {

            color:
                #92400e;

            background:
                #fef3c7;
        }


        .payment-status-box.nao-pago {

            color:
                #991b1b;

            background:
                #fee2e2;
        }


        .payment-status-box.neutro {

            color:
                #374151;

            background:
                #e5e7eb;
        }


        .payment-action {

            margin-top:
                18px;
        }


        .details-grid {

            display:
                grid;

            grid-template-columns:
                repeat(
                    3,
                    minmax(0, 1fr)
                );

            gap:
                14px;
        }


        .detail-item {

            min-width:
                0;

            padding:
                15px;

            border-radius:
                11px;

            background:
                #f8fafc;
        }


        .detail-item strong {

            display:
                block;

            overflow-wrap:
                anywhere;

            font-size:
                15px;
        }


        .table-wrap {

            overflow-x:
                auto;
        }


        table {

            width:
                100%;

            border-collapse:
                collapse;
        }


        th,
        td {

            padding:
                14px 12px;

            border-bottom:
                1px solid
                var(--line);

            text-align:
                left;

            vertical-align:
                top;
        }


        th {

            color:
                var(--muted);

            background:
                #f8fafc;

            font-size:
                12px;

            font-weight:
                700;

            letter-spacing:
                .04em;

            text-transform:
                uppercase;
        }


        td {

            font-size:
                14px;
        }


        tbody tr:last-child td {

            border-bottom:
                0;
        }


        .align-right {

            text-align:
                right;

            white-space:
                nowrap;
        }


        .align-center {

            text-align:
                center;
        }


        .product-name {

            font-weight:
                700;
        }


        .subnote {

            display:
                block;

            margin-top:
                3px;

            color:
                var(--muted);

            font-size:
                12px;
        }


        .money {

            color:
                var(--green);

            font-weight:
                700;

            white-space:
                nowrap;
        }


        .empty {

            padding:
                20px;

            border-radius:
                12px;

            color:
                var(--muted);

            background:
                #f8fafc;

            text-align:
                center;
        }


        .note {

            padding:
                16px;

            border-left:
                3px solid
                #c7d2fe;

            border-radius:
                8px;

            background:
                #f8fafc;

            overflow-wrap:
                anywhere;
        }


        .payment-edit {

            white-space:
                nowrap;
        }


        @media (max-width: 700px) {

            body {

                padding:
                    22px 12px 32px;
            }


            .topbar {

                align-items:
                    flex-start;

                flex-direction:
                    column;

                gap:
                    16px;
            }


            .header-actions {

                width:
                    100%;
            }


            .header-actions .button {

                flex:
                    1;
            }


            .card {

                padding:
                    18px;

                border-radius:
                    14px;
            }


            .financial-grid {

                grid-template-columns:
                    1fr;

                gap:
                    10px;
            }


            .financial-item {

                padding:
                    14px 16px;
            }


            .financial-item strong {

                font-size:
                    23px;
            }


            .details-grid {

                grid-template-columns:
                    repeat(
                        2,
                        minmax(0, 1fr)
                    );

                gap:
                    10px;
            }


            .table-wrap {

                overflow:
                    visible;
            }


            table,
            tbody,
            tr,
            td {

                display:
                    block;

                width:
                    100%;
            }


            thead {

                position:
                    absolute;

                width:
                    1px;

                height:
                    1px;

                overflow:
                    hidden;

                clip:
                    rect(
                        0,
                        0,
                        0,
                        0
                    );

                white-space:
                    nowrap;
            }


            tbody tr {

                margin-bottom:
                    12px;

                padding:
                    6px 12px;

                border:
                    1px solid
                    var(--line);

                border-radius:
                    12px;

                background:
                    #fff;
            }


            tbody tr:last-child {

                margin-bottom:
                    0;
            }


            tbody td {

                display:
                    grid;

                grid-template-columns:
                    minmax(
                        90px,
                        36%
                    )
                    1fr;

                gap:
                    10px;

                padding:
                    10px 0;

                border-bottom:
                    1px solid
                    var(--line);

                text-align:
                    left;

                white-space:
                    normal;
            }


            tbody td:last-child {

                border-bottom:
                    0;
            }


            tbody td::before {

                content:
                    attr(data-label);

                color:
                    var(--muted);

                font-size:
                    11px;

                font-weight:
                    700;

                letter-spacing:
                    .03em;

                text-transform:
                    uppercase;
            }


            tbody td.align-right {

                text-align:
                    left;
            }


            .payment-edit {

                white-space:
                    normal;
            }
        }


        @media (max-width: 420px) {

            .details-grid {

                grid-template-columns:
                    1fr;
            }


            .title-row {

                align-items:
                    flex-start;

                flex-direction:
                    column;

                gap:
                    8px;
            }
        }


        @media print {

            body {

                padding:
                    0;

                background:
                    #fff;
            }


            .page {

                width:
                    100%;
            }


            .card {

                box-shadow:
                    none;

                break-inside:
                    avoid;
            }


            .header-actions,
            .payment-action,
            .payment-edit {

                display:
                    none !important;
            }
        }

    </style>


    <link
        rel="stylesheet"
        href="../assets/pedidos.css"
    >

</head>


<body>


<main class="page">


    <!-- =====================================================
         CABEÇALHO
    ====================================================== -->

    <header class="topbar">

        <div>

            <p class="eyebrow">
                Pedido
            </p>


            <div class="title-row">

                <h1>
                    #<?= htmlspecialchars(
                        $numero_pedido,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </h1>


                <span
                    class="status <?= htmlspecialchars(
                        statusClasse(
                            $pedido["status"]
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

                    <?= htmlspecialchars(
                        statusTexto(
                            $pedido["status"]
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </span>

            </div>


            <p class="client-name">

                <?= htmlspecialchars(
                    $pedido["cliente_nome"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </p>

        </div>


        <div class="header-actions">

            <a
                href="pedidos.php"
                class="button button-back"
            >
                ← Voltar
            </a>


            <a
                href="editar_pedido.php?id=<?= (int) $pedido["id"] ?>"
                class="button button-edit"
            >
                ✏️ Editar pedido
            </a>

        </div>

    </header>


    <!-- =====================================================
         MENSAGEM DE PAGAMENTO
    ====================================================== -->

    <?php if ($pagamento_sucesso): ?>

        <div class="alert">

            ✓ Pagamento registrado com sucesso.

        </div>

    <?php endif; ?>


    <!-- =====================================================
         RESUMO FINANCEIRO
    ====================================================== -->

    <section class="card finance-card">

        <h2>
            Resumo financeiro
        </h2>


        <div class="financial-grid">


            <div class="financial-item financial-total">

                <small>
                    Total
                </small>

                <strong>
                    <?= moeda($valor_total) ?>
                </strong>

            </div>


            <div class="financial-item financial-received">

                <small>
                    Recebido
                </small>

                <strong>
                    <?= moeda($valor_recebido) ?>
                </strong>

            </div>


            <div
                class="
                    financial-item
                    financial-remaining
                    <?= $pedido_pago
                        ? "is-paid"
                        : "" ?>
                "
            >

                <small>
                    Restante
                </small>

                <strong>
                    <?= moeda($valor_restante) ?>
                </strong>

            </div>

        </div>


        <div
            class="payment-status-box <?= htmlspecialchars(
                $classe_pagamento,
                ENT_QUOTES,
                "UTF-8"
            ) ?>"
        >

            <?php if ($pedido_pago): ?>

                ✓

            <?php elseif ($valor_recebido > 0): ?>

                ◐

            <?php else: ?>

                !

            <?php endif; ?>


            <?= htmlspecialchars(
                $status_pagamento,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>


        <div
            class="payment-progress"
            aria-label="<?= htmlspecialchars(
                number_format(
                    $percentual_pago,
                    0,
                    ",",
                    "."
                ) . "% do pedido pago",
                ENT_QUOTES,
                "UTF-8"
            ) ?>"
        >

            <div
                class="progress-track"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?= (int) round(
                    $percentual_pago
                ) ?>"
            >

                <div
                    class="progress-fill"
                    style="
                        width:
                        <?= number_format(
                            $percentual_pago,
                            2,
                            ".",
                            ""
                        ) ?>%;
                    "
                ></div>

            </div>


            <div class="progress-caption">

                <span>
                    Progresso do pagamento
                </span>

                <strong>
                    <?= number_format(
                        $percentual_pago,
                        0,
                        ",",
                        "."
                    ) ?>%
                </strong>

            </div>

        </div>


        <div class="payment-action">

            <?php if ($pode_registrar_pagamento): ?>

                <a
                    href="registrar_pagamento_pedido.php?id=<?= (int) $pedido["id"] ?>"
                    class="button button-pay"
                >
                    💰 Registrar pagamento
                </a>


            <?php elseif ($pedido_pago): ?>

                <span class="button button-paid">
                    ✓ Pedido pago
                </span>


            <?php elseif ($pedido["status"] === "cancelado"): ?>

                <span class="button button-disabled">
                    🔒 Pedido cancelado
                </span>

            <?php endif; ?>

        </div>

    </section>


    <!-- =====================================================
         DADOS DO PEDIDO
    ====================================================== -->

    <section class="card">

        <h2>
            Dados do pedido
        </h2>


        <div class="details-grid">


            <div class="detail-item">

                <small>
                    Cliente
                </small>

                <strong>

                    <?= htmlspecialchars(
                        $pedido["cliente_nome"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </strong>

            </div>


            <div class="detail-item">

                <small>
                    Telefone
                </small>

                <strong>

                    <?= htmlspecialchars(
                        $pedido["cliente_telefone"] ?: "-",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </strong>

            </div>


            <div class="detail-item">

                <small>
                    Data do pedido
                </small>

                <strong>

                    <?= htmlspecialchars(
                        dataBR(
                            $pedido["data_pedido"]
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </strong>

            </div>


            <div class="detail-item">

                <small>
                    Data de entrega
                </small>

                <strong>

                    <?= htmlspecialchars(
                        dataBR(
                            $pedido["data_entrega"]
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </strong>

            </div>


            <div class="detail-item">

                <small>
                    Forma de pagamento
                </small>

                <strong>

                    <?= $pedido["forma_pagamento"]

                        ? htmlspecialchars(
                            $pedido["forma_pagamento"],
                            ENT_QUOTES,
                            "UTF-8"
                        )

                        : "Não definida"
                    ?>

                </strong>

            </div>


            <div class="detail-item">

                <small>
                    Status
                </small>

                <strong>

                    <?= htmlspecialchars(
                        statusTexto(
                            $pedido["status"]
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </strong>

            </div>

        </div>

    </section>


    <!-- =====================================================
         PRODUTOS
    ====================================================== -->

    <section class="card">

        <h2>
            Produtos
        </h2>


        <?php if (empty($itens)): ?>

            <div class="empty">

                Nenhum produto foi encontrado
                neste pedido.

            </div>


        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Produto
                            </th>

                            <th class="align-center">
                                Qtd.
                            </th>

                            <th class="align-right">
                                Valor unit.
                            </th>

                            <th class="align-right">
                                Subtotal
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php foreach ($itens as $item): ?>

                        <?php

                        $quantidadeFormatada =
                            rtrim(
                                rtrim(
                                    number_format(
                                        (float) $item["quantidade"],
                                        3,
                                        ".",
                                        ""
                                    ),
                                    "0"
                                ),
                                "."
                            );

                        ?>


                        <tr>

                            <td data-label="Produto">

                                <span class="product-name">

                                    <?= htmlspecialchars(
                                        $item["bolo_nome"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </span>


                                <?php if (
                                    !empty(
                                        $item["bolo_tamanho"]
                                    ) ||
                                    !empty(
                                        $item["bolo_categoria"]
                                    )
                                ): ?>

                                    <span class="subnote">

                                        <?php if (
                                            !empty(
                                                $item["bolo_categoria"]
                                            )
                                        ): ?>

                                            <?= htmlspecialchars(
                                                $item["bolo_categoria"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        <?php endif; ?>


                                        <?php if (
                                            !empty(
                                                $item["bolo_categoria"]
                                            ) &&
                                            !empty(
                                                $item["bolo_tamanho"]
                                            )
                                        ): ?>

                                            ·

                                        <?php endif; ?>


                                        <?php if (
                                            !empty(
                                                $item["bolo_tamanho"]
                                            )
                                        ): ?>

                                            <?= htmlspecialchars(
                                                $item["bolo_tamanho"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        <?php endif; ?>

                                    </span>

                                <?php endif; ?>


                                <?php if (
                                    !empty(
                                        $item["observacao"]
                                    )
                                ): ?>

                                    <span class="subnote">

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $item["observacao"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            )
                                        ) ?>

                                    </span>

                                <?php endif; ?>

                            </td>


                            <td
                                class="align-center"
                                data-label="Qtd."
                            >

                                <?= htmlspecialchars(
                                    str_replace(
                                        ".",
                                        ",",
                                        $quantidadeFormatada
                                    ),
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <td
                                class="align-right"
                                data-label="Valor unit."
                            >

                                <?= moeda(
                                    $item["preco_unitario"]
                                ) ?>

                            </td>


                            <td
                                class="align-right"
                                data-label="Subtotal"
                            >

                                <strong>

                                    <?= moeda(
                                        $item["subtotal"]
                                    ) ?>

                                </strong>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>


    <!-- =====================================================
         OBSERVAÇÃO
    ====================================================== -->

    <?php if (!empty($pedido["observacao"])): ?>

        <section class="card">

            <h2>
                Observação
            </h2>


            <div class="note">

                <?= nl2br(
                    htmlspecialchars(
                        $pedido["observacao"],
                        ENT_QUOTES,
                        "UTF-8"
                    )
                ) ?>

            </div>

        </section>

    <?php endif; ?>


    <!-- =====================================================
         HISTÓRICO DE PAGAMENTOS
    ====================================================== -->

    <section class="card">


        <div
            class="topbar"
            style="margin:0 0 14px;"
        >

            <h2 style="margin:0;">

                Histórico de pagamentos

            </h2>


            <span class="eyebrow">

                <?= $total_pagamentos ?>

                <?= $total_pagamentos === 1
                    ? "pagamento"
                    : "pagamentos"
                ?>

            </span>

        </div>


        <?php if (empty($pagamentos)): ?>

            <div class="empty">

                Nenhum pagamento registrado
                neste pedido.

            </div>


        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Data
                            </th>

                            <th>
                                Forma de pagamento
                            </th>

                            <th class="align-right">
                                Valor
                            </th>

                            <th>
                                Observação
                            </th>

                            <th class="align-right">
                                Ações
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php foreach ($pagamentos as $pagamento): ?>

                        <tr>

                            <td data-label="Data">

                                <?= htmlspecialchars(
                                    dataBR(
                                        $pagamento[
                                            "data_recebimento"
                                        ]
                                    ),
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <td data-label="Forma de pagamento">

                                <?= htmlspecialchars(
                                    $pagamento[
                                        "forma_pagamento"
                                    ] ?: "-",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <td
                                class="align-right"
                                data-label="Valor"
                            >

                                <span class="money">

                                    <?= moeda(
                                        $pagamento["valor"]
                                    ) ?>

                                </span>

                            </td>


                            <td data-label="Observação">

                                <?= !empty(
                                    $pagamento["observacao"]
                                )

                                    ? nl2br(
                                        htmlspecialchars(
                                            $pagamento[
                                                "observacao"
                                            ],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        )
                                    )

                                    : "-"
                                ?>

                            </td>


                            <td
                                class="
                                    align-right
                                    payment-edit
                                "
                                data-label="Ações"
                            >

                                <a
                                    href="financeiro/editar_recebimento.php?id=<?= (int) $pagamento["id"] ?>"
                                    class="
                                        button
                                        button-payment-edit
                                    "
                                >
                                    ✏️ Editar
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>


</main>


</body>

</html>