<?php

session_start();

require_once '../config/banco.php';

/*
|--------------------------------------------------------------------------
| PROTEÇÃO DE CACHE
|--------------------------------------------------------------------------
|
| Evita que o navegador mostre o painel novamente pelo botão "Voltar"
| depois que o usuário sair da conta.
|
*/

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');


/*
|--------------------------------------------------------------------------
| AUTENTICAÇÃO
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['usuario_id']) ||
    empty($_SESSION['usuario_id'])
) {
    header('Location: ../login.php');
    exit;
}

$usuario_id = (int) $_SESSION['usuario_id'];


/*
|--------------------------------------------------------------------------
| CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome_confeitaria,
        telefone,
        cidade
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


/*
|--------------------------------------------------------------------------
| FUNÇÕES
|--------------------------------------------------------------------------
*/

function dinheiro($valor)
{
    return 'R$ ' . number_format(
        (float) $valor,
        2,
        ',',
        '.'
    );
}

function data_br($data)
{
    if (!$data) {
        return '-';
    }

    $partes = explode('-', $data);

    if (count($partes) !== 3) {
        return $data;
    }

    return $partes[2] . '/' . $partes[1] . '/' . $partes[0];
}

function status_nome($status)
{
    $status_lista = [
        'orcamento' => 'Orçamento',
        'confirmado' => 'Confirmado',
        'producao' => 'Em produção',
        'pronto' => 'Pronto',
        'entregue' => 'Entregue',
        'cancelado' => 'Cancelado'
    ];

    return $status_lista[$status] ?? ucfirst($status);
}

function status_classe($status)
{
    $classes = [
        'orcamento' => 'status-orcamento',
        'confirmado' => 'status-confirmado',
        'producao' => 'status-producao',
        'pronto' => 'status-pronto',
        'entregue' => 'status-entregue',
        'cancelado' => 'status-cancelado'
    ];

    return $classes[$status] ?? 'status-padrao';
}


/*
|--------------------------------------------------------------------------
| DATAS
|--------------------------------------------------------------------------
*/

$hoje = date('Y-m-d');

$inicio_mes = date('Y-m-01');

$fim_mes = date('Y-m-t');

$amanha = date(
    'Y-m-d',
    strtotime('+1 day')
);


/*
|--------------------------------------------------------------------------
| PRODUTOS
|--------------------------------------------------------------------------
*/

/*
 * Total de produtos cadastrados
 */

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM bolos
    WHERE confeiteira_id = ?
");

$stmt->execute([
    $confeiteira_id
]);

$total_produtos = (int) $stmt->fetchColumn();


/*
 * Produtos disponíveis
 */

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM bolos
    WHERE confeiteira_id = ?
      AND disponivel = 1
");

$stmt->execute([
    $confeiteira_id
]);

$total_produtos_disponiveis = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| PEDIDOS
|--------------------------------------------------------------------------
*/

/*
 * Pedidos do mês
 */

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM pedidos
    WHERE confeiteira_id = ?
      AND data_pedido BETWEEN ? AND ?
      AND status <> 'cancelado'
");

$stmt->execute([
    $confeiteira_id,
    $inicio_mes,
    $fim_mes
]);

$total_pedidos_mes = (int) $stmt->fetchColumn();


/*
 * Pedidos em produção
 */

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM pedidos
    WHERE confeiteira_id = ?
      AND status = 'producao'
");

$stmt->execute([
    $confeiteira_id
]);

$pedidos_producao = (int) $stmt->fetchColumn();


/*
 * Pedidos prontos
 */

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM pedidos
    WHERE confeiteira_id = ?
      AND status = 'pronto'
");

$stmt->execute([
    $confeiteira_id
]);

$pedidos_prontos = (int) $stmt->fetchColumn();


/*
 * Pedidos confirmados
 */

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM pedidos
    WHERE confeiteira_id = ?
      AND status = 'confirmado'
");

$stmt->execute([
    $confeiteira_id
]);

$pedidos_confirmados = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| VENDAS DO MÊS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(valor_total), 0)
    FROM pedidos
    WHERE confeiteira_id = ?
      AND data_pedido BETWEEN ? AND ?
      AND status <> 'cancelado'
");

$stmt->execute([
    $confeiteira_id,
    $inicio_mes,
    $fim_mes
]);

$vendas_mes = (float) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| RECEBIMENTOS DO MÊS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(valor), 0)
    FROM recebimentos
    WHERE confeiteira_id = ?
      AND data_recebimento BETWEEN ? AND ?
");

$stmt->execute([
    $confeiteira_id,
    $inicio_mes,
    $fim_mes
]);

$recebido_mes = (float) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| DESPESAS DO MÊS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(valor), 0)
    FROM despesas
    WHERE confeiteira_id = ?
      AND data_despesa BETWEEN ? AND ?
");

$stmt->execute([
    $confeiteira_id,
    $inicio_mes,
    $fim_mes
]);

$despesas_mes = (float) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| RESULTADO
|--------------------------------------------------------------------------
*/

$resultado_mes = $recebido_mes - $despesas_mes;


/*
|--------------------------------------------------------------------------
| TOTAL A RECEBER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COALESCE(
        SUM(
            CASE
                WHEN valor_restante > 0
                THEN valor_restante
                ELSE 0
            END
        ),
        0
    )
    FROM pedidos
    WHERE confeiteira_id = ?
      AND status <> 'cancelado'
");

$stmt->execute([
    $confeiteira_id
]);

$total_a_receber = (float) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| PRÓXIMAS ENTREGAS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.numero_pedido,
        p.data_entrega,
        p.status,
        p.valor_total,
        p.valor_restante,
        c.nome AS cliente_nome
    FROM pedidos p

    LEFT JOIN clientes c
        ON c.id = p.cliente_id

    WHERE p.confeiteira_id = ?
      AND p.data_entrega >= ?
      AND p.status <> 'cancelado'

    ORDER BY
        p.data_entrega ASC,
        p.id ASC

    LIMIT 8
");

$stmt->execute([
    $confeiteira_id,
    $hoje
]);

$proximas_entregas = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| ÚLTIMOS PEDIDOS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.numero_pedido,
        p.data_pedido,
        p.data_entrega,
        p.status,
        p.valor_total,
        p.valor_recebido,
        p.valor_restante,
        c.nome AS cliente_nome
    FROM pedidos p

    LEFT JOIN clientes c
        ON c.id = p.cliente_id

    WHERE p.confeiteira_id = ?

    ORDER BY p.id DESC

    LIMIT 8
");

$stmt->execute([
    $confeiteira_id
]);

$ultimos_pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| PRODUTOS RECENTES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        categoria,
        tamanho,
        preco_venda,
        disponivel
    FROM bolos
    WHERE confeiteira_id = ?
    ORDER BY id DESC
    LIMIT 5
");

$stmt->execute([
    $confeiteira_id
]);

$produtos_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        <?= htmlspecialchars(
            $confeiteira['nome_confeitaria'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>
        - Painel
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f6f8;
            color: #222;
        }

        .container {
            max-width: 1350px;
            margin: 0 auto;
            padding: 25px;
        }

        /*
        |--------------------------------------------------------------------------
        | TOPO
        |--------------------------------------------------------------------------
        */

        .topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 18px;
        }

        .saudacao h1 {
            margin: 0 0 6px;
            font-size: 30px;
        }

        .saudacao p {
            margin: 0;
            color: #666;
        }


        /*
        |--------------------------------------------------------------------------
        | BOTÕES DO TOPO
        |--------------------------------------------------------------------------
        */

        .acoes-topo {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }


        /*
        |--------------------------------------------------------------------------
        | ATALHOS PRINCIPAIS
        |--------------------------------------------------------------------------
        */

        .acoes {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 0 0 20px;
            align-items: center;
        }

        .acoes .btn {
            white-space: nowrap;
        }


        /*
        |--------------------------------------------------------------------------
        | BOTÕES
        |--------------------------------------------------------------------------
        */

        .btn {
            display: inline-block;
            padding: 11px 15px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            transition: .15s;
        }

        .btn-principal {
            background: #198754;
            color: white;
        }

        .btn-principal:hover {
            background: #157347;
        }

        .btn-secundario {
            background: #222;
            color: white;
        }

        .btn-secundario:hover {
            background: #000;
        }

        .btn-cinza {
            background: #e5e7eb;
            color: #222;
        }

        .btn-cinza:hover {
            background: #d1d5db;
        }

        /*
        |----------------------------------------------------------------------
        | BOTÃO SAIR
        |----------------------------------------------------------------------
        */

        .btn-sair {
            background: #dc3545;
            color: white;
        }

        .btn-sair:hover {
            background: #bb2d3b;
        }


        /*
        |--------------------------------------------------------------------------
        | CARDS
        |--------------------------------------------------------------------------
        */

        .cards {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .card-titulo {
            font-size: 13px;
            color: #666;
            margin-bottom: 10px;
        }

        .card-valor {
            font-size: 25px;
            font-weight: bold;
        }

        .card-sub {
            margin-top: 7px;
            font-size: 12px;
            color: #777;
        }

        .card-vendas {
            border-top: 4px solid #6f42c1;
        }

        .card-recebido {
            border-top: 4px solid #198754;
        }

        .card-despesas {
            border-top: 4px solid #dc3545;
        }

        .card-resultado {
            border-top: 4px solid #0d6efd;
        }

        .card-aberto {
            border-top: 4px solid #fd7e14;
        }

        .positivo {
            color: #198754;
        }

        .negativo {
            color: #dc3545;
        }


        /*
        |--------------------------------------------------------------------------
        | ATALHOS ANTIGOS
        |--------------------------------------------------------------------------
        */

        .atalhos {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-bottom: 25px;
        }

        .atalho {
            background: white;
            border-radius: 10px;
            padding: 17px;
            text-decoration: none;
            color: #222;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
            border: 1px solid #eee;
            transition: .15s;
        }

        .atalho:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 16px rgba(0,0,0,.08);
        }

        .atalho-titulo {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .atalho-texto {
            color: #777;
            font-size: 12px;
        }


        /*
        |--------------------------------------------------------------------------
        | GRID PRINCIPAL
        |--------------------------------------------------------------------------
        */

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .secao {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .secao h2 {
            margin: 0 0 18px;
            font-size: 19px;
        }


        /*
        |--------------------------------------------------------------------------
        | ENTREGAS
        |--------------------------------------------------------------------------
        */

        .entrega {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 13px 0;
            border-bottom: 1px solid #eee;
        }

        .entrega:last-child {
            border-bottom: none;
        }

        .entrega-principal {
            font-weight: bold;
        }

        .entrega-detalhe {
            margin-top: 4px;
            font-size: 12px;
            color: #777;
        }

        .data-entrega {
            text-align: right;
            white-space: nowrap;
            font-weight: bold;
        }


        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        .status {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            margin-top: 5px;
        }

        .status-orcamento {
            background: #f1f3f5;
            color: #555;
        }

        .status-confirmado {
            background: #fff3cd;
            color: #856404;
        }

        .status-producao {
            background: #cfe2ff;
            color: #084298;
        }

        .status-pronto {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-entregue {
            background: #e2e3e5;
            color: #41464b;
        }

        .status-cancelado {
            background: #f8d7da;
            color: #842029;
        }

        .status-padrao {
            background: #eee;
            color: #555;
        }


        /*
        |--------------------------------------------------------------------------
        | PEDIDOS
        |--------------------------------------------------------------------------
        */

        .pedido {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 13px 0;
            border-bottom: 1px solid #eee;
        }

        .pedido:last-child {
            border-bottom: none;
        }

        .pedido-nome {
            font-weight: bold;
        }

        .pedido-info {
            color: #777;
            font-size: 12px;
            margin-top: 4px;
        }

        .pedido-valor {
            text-align: right;
            white-space: nowrap;
            font-weight: bold;
        }

        .pedido-pago {
            color: #198754;
            font-size: 11px;
            margin-top: 4px;
        }

        .pedido-aberto {
            color: #fd7e14;
            font-size: 11px;
            margin-top: 3px;
        }


        /*
        |--------------------------------------------------------------------------
        | PRODUTOS
        |--------------------------------------------------------------------------
        */

        .produto {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .produto:last-child {
            border-bottom: none;
        }

        .produto-nome {
            font-weight: bold;
        }

        .produto-info {
            color: #777;
            font-size: 12px;
            margin-top: 4px;
        }

        .produto-preco {
            font-weight: bold;
            white-space: nowrap;
        }


        /*
        |--------------------------------------------------------------------------
        | RESUMO OPERACIONAL
        |--------------------------------------------------------------------------
        */

        .operacional {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .operacional-card {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 15px;
        }

        .operacional-numero {
            font-size: 24px;
            font-weight: bold;
        }

        .operacional-label {
            color: #777;
            font-size: 12px;
            margin-top: 5px;
        }


        /*
        |--------------------------------------------------------------------------
        | LINHAS FINANCEIRAS
        |--------------------------------------------------------------------------
        */

        .linha {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .linha:last-child {
            border-bottom: none;
        }


        /*
        |--------------------------------------------------------------------------
        | VAZIO
        |--------------------------------------------------------------------------
        */

        .vazio {
            color: #777;
            padding: 10px 0;
        }


        /*
        |--------------------------------------------------------------------------
        | RODAPÉ
        |--------------------------------------------------------------------------
        */

        .rodape {
            margin-top: 30px;
            color: #888;
            font-size: 12px;
            text-align: center;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVO
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1100px) {

            .cards {
                grid-template-columns: repeat(3, 1fr);
            }

            .atalhos {
                grid-template-columns: repeat(3, 1fr);
            }

            .operacional {
                grid-template-columns: repeat(2, 1fr);
            }

        }


        @media (max-width: 800px) {

            .topo {
                flex-direction: column;
                align-items: flex-start;
            }

            .acoes-topo {
                width: 100%;
                justify-content: flex-start;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .cards {
                grid-template-columns: 1fr 1fr;
            }

            .atalhos {
                grid-template-columns: 1fr 1fr;
            }

        }


        @media (max-width: 550px) {

            .container {
                padding: 15px;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .atalhos {
                grid-template-columns: 1fr;
            }

            .operacional {
                grid-template-columns: 1fr;
            }

            .topo {
                flex-direction: column;
                align-items: flex-start;
            }

            .acoes-topo {
                width: 100%;
                justify-content: flex-start;
            }

            .acoes-topo .btn {
                flex: 1;
                text-align: center;
            }

            .acoes {
                width: 100%;
            }

            .acoes .btn {
                flex: 1;
                text-align: center;
            }

            .entrega,
            .pedido,
            .produto {
                flex-direction: column;
            }

            .data-entrega,
            .pedido-valor {
                text-align: left;
            }

        }

    </style>

</head>

<body>

<div class="container">


    <!-- =========================================================
         CABEÇALHO
    ========================================================== -->

    <div class="topo">

        <div class="saudacao">

            <h1>

                Olá,
                <?= htmlspecialchars(
                    $confeiteira['nome_confeitaria'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>! 👋

            </h1>

            <p>
                Aqui está o resumo da sua confeitaria.
            </p>

        </div>


        <div class="acoes-topo">

            <a
                href="cadastrar_pedido.php"
                class="btn btn-principal"
            >
                + Novo pedido
            </a>


            <a
                href="cadastrar_produto.php"
                class="btn btn-secundario"
            >
                + Novo produto
            </a>


            <a
                href="sair.php"
                class="btn btn-sair"
            >
                🚪 Sair
            </a>

        </div>

    </div>


    <!-- =========================================================
         ATALHOS PRINCIPAIS
    ========================================================== -->

    <div class="acoes">

        <a
            href="minha_conta.php"
            class="btn btn-cinza"
        >
            👤 Minha Conta
        </a>


        <a
            href="pedidos.php"
            class="btn btn-principal"
        >
            📋 Pedidos
        </a>


        <a
            href="produtos.php"
            class="btn btn-principal"
        >
            🍰 Produtos
        </a>


        <a
            href="estoque.php"
            class="btn btn-principal"
        >
            📦 Estoque
        </a>


        <a
            href="clientes.php"
            class="btn btn-principal"
        >
            👥 Clientes
        </a>


        <a
            href="financeiro/index.php"
            class="btn btn-principal"
        >
            💰 Financeiro
        </a>

    </div>


    <!-- =========================================================
         RESUMO FINANCEIRO
    ========================================================== -->

    <div class="cards">


        <div class="card card-vendas">

            <div class="card-titulo">
                Vendas no mês
            </div>

            <div class="card-valor">
                <?= dinheiro($vendas_mes) ?>
            </div>

            <div class="card-sub">

                <?= $total_pedidos_mes ?>

                <?= $total_pedidos_mes == 1
                    ? 'pedido'
                    : 'pedidos'
                ?>

            </div>

        </div>


        <div class="card card-recebido">

            <div class="card-titulo">
                Recebido no mês
            </div>

            <div class="card-valor positivo">
                <?= dinheiro($recebido_mes) ?>
            </div>

            <div class="card-sub">
                Pagamentos efetivamente recebidos
            </div>

        </div>


        <div class="card card-despesas">

            <div class="card-titulo">
                Despesas no mês
            </div>

            <div class="card-valor negativo">
                <?= dinheiro($despesas_mes) ?>
            </div>

            <div class="card-sub">
                Gastos registrados
            </div>

        </div>


        <div class="card card-resultado">

            <div class="card-titulo">
                Resultado do mês
            </div>

            <div class="card-valor <?= $resultado_mes >= 0 ? 'positivo' : 'negativo' ?>">
                <?= dinheiro($resultado_mes) ?>
            </div>

            <div class="card-sub">
                Recebido − despesas
            </div>

        </div>


        <div class="card card-aberto">

            <div class="card-titulo">
                A receber
            </div>

            <div class="card-valor">
                <?= dinheiro($total_a_receber) ?>
            </div>

            <div class="card-sub">
                Pedidos ainda em aberto
            </div>

        </div>

    </div>


    <!-- =========================================================
         ENTREGAS + PEDIDOS
    ========================================================== -->

    <div class="grid">


        <!-- PRÓXIMAS ENTREGAS -->

        <div class="secao">

            <h2>
                📅 Próximas entregas
            </h2>


            <?php if (empty($proximas_entregas)): ?>

                <div class="vazio">
                    Nenhuma entrega programada.
                </div>

            <?php else: ?>

                <?php foreach ($proximas_entregas as $entrega): ?>

                    <div class="entrega">

                        <div>

                            <div class="entrega-principal">

                                Pedido #<?= (int) $entrega['numero_pedido'] ?>


                                <?php if (!empty($entrega['cliente_nome'])): ?>

                                    -

                                    <?= htmlspecialchars(
                                        $entrega['cliente_nome'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                <?php endif; ?>

                            </div>


                            <div class="entrega-detalhe">

                                Valor:

                                <?= dinheiro(
                                    $entrega['valor_total']
                                ) ?>

                            </div>


                            <span
                                class="status <?= status_classe($entrega['status']) ?>"
                            >

                                <?= htmlspecialchars(
                                    status_nome($entrega['status']),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </span>

                        </div>


                        <div class="data-entrega">

                            <?= data_br(
                                $entrega['data_entrega']
                            ) ?>


                            <?php if ($entrega['data_entrega'] === $hoje): ?>

                                <div class="status status-producao">
                                    HOJE
                                </div>

                            <?php elseif ($entrega['data_entrega'] === $amanha): ?>

                                <div class="status status-confirmado">
                                    AMANHÃ
                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <!-- ÚLTIMOS PEDIDOS -->

        <div class="secao">

            <h2>
                🛒 Últimos pedidos
            </h2>


            <?php if (empty($ultimos_pedidos)): ?>

                <div class="vazio">
                    Nenhum pedido cadastrado.
                </div>

            <?php else: ?>

                <?php foreach ($ultimos_pedidos as $pedido): ?>

                    <div class="pedido">

                        <div>

                            <div class="pedido-nome">

                                Pedido #<?= (int) $pedido['numero_pedido'] ?>


                                <?php if (!empty($pedido['cliente_nome'])): ?>

                                    -

                                    <?= htmlspecialchars(
                                        $pedido['cliente_nome'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                <?php endif; ?>

                            </div>


                            <div class="pedido-info">

                                <?= data_br(
                                    $pedido['data_pedido']
                                ) ?>


                                <?php if ($pedido['data_entrega']): ?>

                                    · Entrega:

                                    <?= data_br(
                                        $pedido['data_entrega']
                                    ) ?>

                                <?php endif; ?>

                            </div>


                            <span
                                class="status <?= status_classe($pedido['status']) ?>"
                            >

                                <?= htmlspecialchars(
                                    status_nome($pedido['status']),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </span>

                        </div>


                        <div class="pedido-valor">

                            <?= dinheiro(
                                $pedido['valor_total']
                            ) ?>


                            <?php if ((float) $pedido['valor_recebido'] > 0): ?>

                                <div class="pedido-pago">

                                    Recebido:

                                    <?= dinheiro(
                                        $pedido['valor_recebido']
                                    ) ?>

                                </div>

                            <?php endif; ?>


                            <?php if ((float) $pedido['valor_restante'] > 0): ?>

                                <div class="pedido-aberto">

                                    Falta:

                                    <?= dinheiro(
                                        $pedido['valor_restante']
                                    ) ?>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         RESUMO OPERACIONAL
    ========================================================== -->

    <div class="secao">

        <h2>
            📊 Resumo operacional
        </h2>


        <div class="operacional">


            <div class="operacional-card">

                <div class="operacional-numero">
                    <?= $total_produtos ?>
                </div>

                <div class="operacional-label">
                    Produtos cadastrados
                </div>

            </div>


            <div class="operacional-card">

                <div class="operacional-numero">
                    <?= $total_produtos_disponiveis ?>
                </div>

                <div class="operacional-label">
                    Produtos disponíveis
                </div>

            </div>


            <div class="operacional-card">

                <div class="operacional-numero">
                    <?= $pedidos_confirmados ?>
                </div>

                <div class="operacional-label">
                    Pedidos confirmados
                </div>

            </div>


            <div class="operacional-card">

                <div class="operacional-numero">
                    <?= $pedidos_producao ?>
                </div>

                <div class="operacional-label">
                    Pedidos em produção
                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         PRODUTOS RECENTES + SITUAÇÃO FINANCEIRA
    ========================================================== -->

    <div
        class="grid"
        style="margin-top: 25px;"
    >


        <!-- PRODUTOS RECENTES -->

        <div class="secao">

            <h2>
                🧁 Produtos recentes
            </h2>


            <?php if (empty($produtos_recentes)): ?>

                <div class="vazio">
                    Nenhum produto cadastrado ainda.
                </div>


                <a
                    href="cadastrar_produto.php"
                    class="btn btn-principal"
                    style="margin-top: 10px;"
                >
                    Cadastrar primeiro produto
                </a>

            <?php else: ?>


                <?php foreach ($produtos_recentes as $produto): ?>

                    <div class="produto">

                        <div>

                            <div class="produto-nome">

                                <?= htmlspecialchars(
                                    $produto['nome'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>


                            <div class="produto-info">

                                <?= htmlspecialchars(
                                    $produto['categoria'] ?: 'Sem categoria',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>


                                <?php if (!empty($produto['tamanho'])): ?>

                                    ·

                                    <?= htmlspecialchars(
                                        $produto['tamanho'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                <?php endif; ?>

                            </div>

                        </div>


                        <div class="produto-preco">

                            <?= dinheiro(
                                $produto['preco_venda']
                            ) ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <!-- SITUAÇÃO FINANCEIRA -->

        <div class="secao">

            <h2>
                💰 Situação financeira
            </h2>


            <div class="linha">

                <div>
                    Vendas no mês
                </div>

                <strong>
                    <?= dinheiro($vendas_mes) ?>
                </strong>

            </div>


            <div class="linha">

                <div>
                    Recebido no mês
                </div>

                <strong class="positivo">
                    <?= dinheiro($recebido_mes) ?>
                </strong>

            </div>


            <div class="linha">

                <div>
                    Despesas no mês
                </div>

                <strong class="negativo">
                    <?= dinheiro($despesas_mes) ?>
                </strong>

            </div>


            <div class="linha">

                <div>
                    Resultado
                </div>

                <strong
                    class="<?= $resultado_mes >= 0
                        ? 'positivo'
                        : 'negativo' ?>"
                >
                    <?= dinheiro($resultado_mes) ?>
                </strong>

            </div>


            <div class="linha">

                <div>
                    Total a receber
                </div>

                <strong>
                    <?= dinheiro($total_a_receber) ?>
                </strong>

            </div>


            <div style="margin-top: 15px;">

                <a
                    href="financeiro/index.php"
                    class="btn btn-secundario"
                >
                    Abrir financeiro
                </a>

            </div>

        </div>

    </div>


    <!-- =========================================================
         RODAPÉ
    ========================================================== -->

    <div class="rodape">

        Sistema de gestão da confeitaria

    </div>

</div>

</body>

</html>