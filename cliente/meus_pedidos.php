<?php

session_start();

require_once "../config/banco.php";


/*
|--------------------------------------------------------------------------
| VERIFICA LOGIN
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["usuario_id"]) ||
    !isset($_SESSION["usuario_tipo"]) ||
    $_SESSION["usuario_tipo"] !== "cliente" ||
    !isset($_SESSION["cliente_id"])
) {
    header("Location: login.php");
    exit;
}

$clienteId = (int) $_SESSION["cliente_id"];


/*
|--------------------------------------------------------------------------
| BUSCA PEDIDOS DO CLIENTE
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.id,
        p.numero_pedido,
        p.data_pedido,
        p.data_entrega,
        p.status,
        p.valor_total,
        p.valor_recebido,
        p.valor_restante,
        p.forma_pagamento,
        p.criado_em,

        cf.nome_confeitaria

    FROM pedidos p

    INNER JOIN confeiteiras cf
        ON cf.id = p.confeiteira_id

    WHERE p.cliente_id = ?

    ORDER BY p.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$clienteId]);

$pedidos = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$statusNomes = [
    "orcamento"  => "Aguardando confirmação",
    "confirmado" => "Confirmado",
    "producao"   => "Em produção",
    "pronto"     => "Pronto para entrega",
    "entregue"   => "Entregue",
    "cancelado"  => "Cancelado"
];

$statusClasses = [
    "orcamento"  => "status-orcamento",
    "confirmado" => "status-confirmado",
    "producao"   => "status-producao",
    "pronto"     => "status-pronto",
    "entregue"   => "status-entregue",
    "cancelado"  => "status-cancelado"
];

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Meus pedidos</title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f5f6fa;

            color: #222;

        }


        .container {

            width: 100%;

            max-width: 1100px;

            margin: 0 auto;

            padding: 30px 20px 50px;

        }


        /*
        ----------------------------------------------------------
        CABEÇALHO
        ----------------------------------------------------------
        */

        .topo {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 25px;

        }


        .topo h1 {

            margin: 0;

            font-size: 30px;

        }


        .topo p {

            margin: 6px 0 0;

            color: #666;

        }


        .botao-voltar {

            display: inline-block;

            padding: 11px 18px;

            border-radius: 8px;

            background: #222;

            color: #fff;

            text-decoration: none;

            font-weight: bold;

        }


        .botao-voltar:hover {

            background: #000;

        }


        /*
        ----------------------------------------------------------
        AVISO SEM PEDIDOS
        ----------------------------------------------------------
        */

        .vazio {

            background: #fff;

            border-radius: 16px;

            padding: 50px 25px;

            text-align: center;

            box-shadow:
                0 4px 20px rgba(0, 0, 0, 0.06);

        }


        .vazio-icone {

            width: 70px;

            height: 70px;

            margin: 0 auto 20px;

            border-radius: 50%;

            background: #f1f3f5;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 32px;

        }


        .vazio h2 {

            margin: 0 0 10px;

        }


        .vazio p {

            color: #666;

            margin-bottom: 25px;

        }


        .botao-catalogo {

            display: inline-block;

            padding: 13px 22px;

            background: #222;

            color: white;

            border-radius: 8px;

            text-decoration: none;

            font-weight: bold;

        }


        /*
        ----------------------------------------------------------
        LISTA
        ----------------------------------------------------------
        */

        .lista {

            display: flex;

            flex-direction: column;

            gap: 18px;

        }


        /*
        ----------------------------------------------------------
        CARD DO PEDIDO
        ----------------------------------------------------------
        */

        .pedido {

            background: #fff;

            border-radius: 16px;

            padding: 22px;

            box-shadow:
                0 3px 15px rgba(0, 0, 0, 0.06);

        }


        .pedido-topo {

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            gap: 15px;

            margin-bottom: 20px;

            padding-bottom: 15px;

            border-bottom: 1px solid #eee;

        }


        .pedido-numero {

            font-size: 20px;

            font-weight: bold;

        }


        .pedido-confeitaria {

            color: #666;

            margin-top: 5px;

        }


        /*
        ----------------------------------------------------------
        STATUS
        ----------------------------------------------------------
        */

        .status {

            display: inline-block;

            padding: 8px 12px;

            border-radius: 20px;

            font-size: 13px;

            font-weight: bold;

            white-space: nowrap;

        }


        .status-orcamento {

            background: #fff3cd;

            color: #856404;

        }


        .status-confirmado {

            background: #d1ecf1;

            color: #0c5460;

        }


        .status-producao {

            background: #cce5ff;

            color: #004085;

        }


        .status-pronto {

            background: #d4edda;

            color: #155724;

        }


        .status-entregue {

            background: #d4edda;

            color: #155724;

        }


        .status-cancelado {

            background: #f8d7da;

            color: #721c24;

        }


        /*
        ----------------------------------------------------------
        INFORMAÇÕES
        ----------------------------------------------------------
        */

        .informacoes {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 15px;

            margin-bottom: 20px;

        }


        .info {

            background: #f8f9fa;

            padding: 13px;

            border-radius: 9px;

        }


        .info strong {

            display: block;

            font-size: 12px;

            color: #777;

            text-transform: uppercase;

            margin-bottom: 5px;

        }


        .info span {

            font-size: 15px;

            font-weight: 600;

        }


        /*
        ----------------------------------------------------------
        FINANCEIRO
        ----------------------------------------------------------
        */

        .financeiro {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            padding-top: 18px;

            border-top: 1px solid #eee;

        }


        .valores {

            display: flex;

            gap: 25px;

            flex-wrap: wrap;

        }


        .valor strong {

            display: block;

            font-size: 12px;

            color: #777;

            text-transform: uppercase;

            margin-bottom: 4px;

        }


        .valor span {

            font-size: 17px;

            font-weight: bold;

        }


        .valor-total span {

            color: #222;

        }


        .valor-recebido span {

            color: #198754;

        }


        .valor-restante span {

            color: #dc3545;

        }


        /*
        ----------------------------------------------------------
        BOTÃO VISUALIZAR
        ----------------------------------------------------------
        */

        .botao-visualizar {

            display: inline-block;

            padding: 11px 18px;

            background: #222;

            color: white;

            text-decoration: none;

            border-radius: 8px;

            font-weight: bold;

            white-space: nowrap;

        }


        .botao-visualizar:hover {

            background: #000;

        }


        /*
        ----------------------------------------------------------
        RESPONSIVO
        ----------------------------------------------------------
        */

        @media (max-width: 800px) {

            .informacoes {

                grid-template-columns:
                    repeat(2, 1fr);

            }

            .financeiro {

                align-items: flex-start;

                flex-direction: column;

            }

        }


        @media (max-width: 600px) {

            .container {

                padding: 20px 12px 40px;

            }


            .topo {

                flex-direction: column;

                align-items: flex-start;

            }


            .topo h1 {

                font-size: 25px;

            }


            .pedido-topo {

                flex-direction: column;

                align-items: flex-start;

            }


            .informacoes {

                grid-template-columns: 1fr;

            }


            .valores {

                flex-direction: column;

                gap: 12px;

            }


            .botao-visualizar {

                width: 100%;

                text-align: center;

            }

        }

    </style>

</head>


<body>


<div class="container">


    <!--
    ============================================================
    CABEÇALHO
    ============================================================
    -->

    <div class="topo">

        <div>

            <h1>
                Meus pedidos
            </h1>

            <p>
                Acompanhe seus pedidos e veja os detalhes.
            </p>

        </div>


        <a
            href="inicio.php"
            class="botao-voltar"
        >
            ← Voltar ao início
        </a>

    </div>


    <?php if (empty($pedidos)): ?>


        <!--
        ========================================================
        NENHUM PEDIDO
        ========================================================
        -->

        <div class="vazio">

            <div class="vazio-icone">
                🛒
            </div>


            <h2>
                Você ainda não fez nenhum pedido
            </h2>


            <p>
                Encontre uma confeitaria e faça seu primeiro pedido.
            </p>


            <a
                href="confeiteiras.php"
                class="botao-catalogo"
            >
                Encontrar uma confeitaria
            </a>

        </div>


    <?php else: ?>


        <!--
        ========================================================
        LISTA DE PEDIDOS
        ========================================================
        -->

        <div class="lista">


            <?php foreach ($pedidos as $pedido): ?>


                <?php

                $numeroPedido = !empty($pedido["numero_pedido"])
                    ? $pedido["numero_pedido"]
                    : $pedido["id"];

                $dataPedido = !empty($pedido["data_pedido"])
                    ? date(
                        "d/m/Y",
                        strtotime($pedido["data_pedido"])
                    )
                    : "-";

                $dataEntrega = !empty($pedido["data_entrega"])
                    ? date(
                        "d/m/Y",
                        strtotime($pedido["data_entrega"])
                    )
                    : "A combinar";

                $status = $pedido["status"];

                $statusTexto =
                    $statusNomes[$status]
                    ?? ucfirst($status);

                $statusClasse =
                    $statusClasses[$status]
                    ?? "";

                $valorTotal = number_format(
                    (float) $pedido["valor_total"],
                    2,
                    ",",
                    "."
                );

                $valorRecebido = number_format(
                    (float) $pedido["valor_recebido"],
                    2,
                    ",",
                    "."
                );

                $valorRestante = number_format(
                    (float) $pedido["valor_restante"],
                    2,
                    ",",
                    "."
                );

                ?>


                <div class="pedido">


                    <!--
                    ------------------------------------------------
                    CABEÇALHO DO PEDIDO
                    ------------------------------------------------
                    -->

                    <div class="pedido-topo">

                        <div>

                            <div class="pedido-numero">

                                Pedido nº
                                <?= htmlspecialchars($numeroPedido) ?>

                            </div>


                            <div class="pedido-confeitaria">

                                <?= htmlspecialchars(
                                    $pedido["nome_confeitaria"]
                                ) ?>

                            </div>

                        </div>


                        <div>

                            <span
                                class="status <?= htmlspecialchars(
                                    $statusClasse
                                ) ?>"
                            >

                                <?= htmlspecialchars(
                                    $statusTexto
                                ) ?>

                            </span>

                        </div>

                    </div>


                    <!--
                    ------------------------------------------------
                    INFORMAÇÕES
                    ------------------------------------------------
                    -->

                    <div class="informacoes">


                        <div class="info">

                            <strong>
                                Data do pedido
                            </strong>

                            <span>
                                <?= htmlspecialchars($dataPedido) ?>
                            </span>

                        </div>


                        <div class="info">

                            <strong>
                                Entrega
                            </strong>

                            <span>
                                <?= htmlspecialchars($dataEntrega) ?>
                            </span>

                        </div>


                        <div class="info">

                            <strong>
                                Pagamento
                            </strong>

                            <span>

                                <?= !empty(
                                    $pedido["forma_pagamento"]
                                )
                                    ? htmlspecialchars(
                                        $pedido["forma_pagamento"]
                                    )
                                    : "Não informado"
                                ?>

                            </span>

                        </div>


                        <div class="info">

                            <strong>
                                Pedido
                            </strong>

                            <span>
                                #<?= htmlspecialchars(
                                    $pedido["id"]
                                ) ?>
                            </span>

                        </div>


                    </div>


                    <!--
                    ------------------------------------------------
                    VALORES
                    ------------------------------------------------
                    -->

                    <div class="financeiro">


                        <div class="valores">


                            <div class="valor valor-total">

                                <strong>
                                    Total
                                </strong>

                                <span>
                                    R$ <?= $valorTotal ?>
                                </span>

                            </div>


                            <div class="valor valor-recebido">

                                <strong>
                                    Pago
                                </strong>

                                <span>
                                    R$ <?= $valorRecebido ?>
                                </span>

                            </div>


                            <div class="valor valor-restante">

                                <strong>
                                    Restante
                                </strong>

                                <span>
                                    R$ <?= $valorRestante ?>
                                </span>

                            </div>


                        </div>


                        <a
                            href="visualizar_pedido.php?id=<?= (int) $pedido["id"] ?>"
                            class="botao-visualizar"
                        >
                            Ver detalhes
                        </a>


                    </div>


                </div>


            <?php endforeach; ?>


        </div>


    <?php endif; ?>


</div>


</body>

</html>