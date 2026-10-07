<?php

session_start();

require_once '../../config/banco.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../../login.php');
    exit;
}

$usuario_id = (int) $_SESSION['usuario_id'];

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Confeiteira
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Período
|--------------------------------------------------------------------------
*/
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim    = $_GET['data_fim'] ?? date('Y-m-t');

/*
|--------------------------------------------------------------------------
| Validação das datas
|--------------------------------------------------------------------------
*/
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio)) {
    $data_inicio = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) {
    $data_fim = date('Y-m-t');
}

if ($data_inicio > $data_fim) {
    $temp = $data_inicio;
    $data_inicio = $data_fim;
    $data_fim = $temp;
}

/*
|--------------------------------------------------------------------------
| 1. TOTAL RECEBIDO
|
| Fonte oficial: recebimentos
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
    $data_inicio,
    $data_fim
]);

$total_recebido = (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| 2. TOTAL DE DESPESAS
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
    $data_inicio,
    $data_fim
]);

$total_despesas = (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| 3. RESULTADO DE CAIXA
|--------------------------------------------------------------------------
*/
$resultado = $total_recebido - $total_despesas;

/*
|--------------------------------------------------------------------------
| 4. TOTAL VENDIDO NO PERÍODO
|
| Não inclui pedidos cancelados.
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
    $data_inicio,
    $data_fim
]);

$total_vendido = (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| 5. QUANTIDADE DE PEDIDOS
|--------------------------------------------------------------------------
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
    $data_inicio,
    $data_fim
]);

$qtd_pedidos = (int) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| 6. TOTAL EM ABERTO
|
| Aqui mostramos tudo que ainda falta receber
| em pedidos não cancelados.
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT COALESCE(
        SUM(
            CASE
                WHEN valor_restante > 0 THEN valor_restante
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

$total_aberto = (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| 7. RECEBIMENTOS POR FORMA DE PAGAMENTO
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        forma_pagamento,
        COALESCE(SUM(valor), 0) AS total
    FROM recebimentos
    WHERE confeiteira_id = ?
      AND data_recebimento BETWEEN ? AND ?
    GROUP BY forma_pagamento
    ORDER BY total DESC
");

$stmt->execute([
    $confeiteira_id,
    $data_inicio,
    $data_fim
]);

$recebimentos_forma = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 8. DESPESAS POR CATEGORIA
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        categoria,
        COALESCE(SUM(valor), 0) AS total
    FROM despesas
    WHERE confeiteira_id = ?
      AND data_despesa BETWEEN ? AND ?
    GROUP BY categoria
    ORDER BY total DESC
");

$stmt->execute([
    $confeiteira_id,
    $data_inicio,
    $data_fim
]);

$despesas_categoria = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 9. ÚLTIMOS RECEBIMENTOS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        r.*,
        p.numero_pedido,
        c.nome AS cliente_nome
    FROM recebimentos r

    LEFT JOIN pedidos p
        ON p.id = r.pedido_id

    LEFT JOIN clientes c
        ON c.id = p.cliente_id

    WHERE r.confeiteira_id = ?

    ORDER BY r.data_recebimento DESC, r.id DESC

    LIMIT 8
");

$stmt->execute([
    $confeiteira_id
]);

$ultimos_recebimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 10. ÚLTIMAS DESPESAS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT *
    FROM despesas
    WHERE confeiteira_id = ?
    ORDER BY data_despesa DESC, id DESC
    LIMIT 8
");

$stmt->execute([
    $confeiteira_id
]);

$ultimas_despesas = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Funções auxiliares
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

function forma_pagamento_nome($forma)
{
    $formas = [
        'pix' => 'Pix',
        'dinheiro' => 'Dinheiro',
        'cartao' => 'Cartão',
        'transferencia' => 'Transferência',
        'outro' => 'Outro'
    ];

    return $formas[$forma] ?? ucfirst($forma);
}

function categoria_nome($categoria)
{
    $categorias = [
        'ingredientes' => 'Ingredientes',
        'materiais' => 'Materiais',
        'embalagens' => 'Embalagens',
        'decoracao' => 'Decoração',
        'equipamentos' => 'Equipamentos',
        'transporte' => 'Transporte',
        'marketing' => 'Marketing',
        'energia' => 'Energia',
        'agua' => 'Água',
        'internet' => 'Internet',
        'aluguel' => 'Aluguel',
        'outros' => 'Outros'
    ];

    return $categorias[$categoria] ?? ucfirst($categoria);
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

    <title>Financeiro</title>

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
            max-width: 1250px;
            margin: 0 auto;
            padding: 25px;
        }

        .topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }

        .topo h1 {
            margin: 0 0 6px;
            font-size: 30px;
        }

        .topo p {
            margin: 0;
            color: #666;
        }

        .acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
        }

        .btn-verde {
            background: #198754;
            color: white;
        }

        .btn-vermelho {
            background: #dc3545;
            color: white;
        }

        .btn-cinza {
            background: #e9ecef;
            color: #222;
        }

        /*
        |--------------------------------------------------------------------------
        | FILTRO
        |--------------------------------------------------------------------------
        */

        .filtro {
            background: white;
            padding: 18px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .filtro form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .campo label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .campo input {
            width: 100%;
            padding: 11px;
            border: 1px solid #d5d8dc;
            border-radius: 8px;
            font-size: 14px;
        }

        .campo button {
            border: none;
            cursor: pointer;
            padding: 11px 20px;
            border-radius: 8px;
            background: #222;
            color: white;
            font-weight: bold;
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

        .card .titulo {
            color: #666;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .card .valor {
            font-size: 24px;
            font-weight: bold;
        }

        .card .sub {
            color: #777;
            font-size: 12px;
            margin-top: 8px;
        }

        .recebido {
            border-top: 4px solid #198754;
        }

        .despesa {
            border-top: 4px solid #dc3545;
        }

        .resultado {
            border-top: 4px solid #0d6efd;
        }

        .vendas {
            border-top: 4px solid #6f42c1;
        }

        .aberto {
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
        | GRID
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
        | LISTAS
        |--------------------------------------------------------------------------
        */

        .linha {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .linha:last-child {
            border-bottom: none;
        }

        .nome {
            font-weight: bold;
        }

        .detalhe {
            color: #777;
            font-size: 12px;
            margin-top: 4px;
        }

        .valor-lista {
            font-weight: bold;
            white-space: nowrap;
        }

        .vazio {
            color: #777;
            padding: 10px 0;
        }

        /*
        |--------------------------------------------------------------------------
        | RESPONSIVO
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1050px) {

            .cards {
                grid-template-columns: repeat(3, 1fr);
            }

        }

        @media (max-width: 800px) {

            .topo {
                flex-direction: column;
                align-items: flex-start;
            }

            .filtro form {
                grid-template-columns: 1fr;
            }

            .grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 600px) {

            .container {
                padding: 15px;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .acoes {
                width: 100%;
            }

            .acoes .btn {
                flex: 1;
                text-align: center;
            }

            .card .valor {
                font-size: 22px;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <!-- =========================================================
         TOPO
    ========================================================== -->

    <div class="topo">

        <div>

            <h1>Financeiro</h1>

            <p>
                <?= htmlspecialchars(
                    $confeiteira['nome_confeitaria'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>

        </div>

       <div class="acoes">

    <a
        href="../index.php"
        class="btn btn-cinza"
    >
        ← Voltar ao painel
    </a>

    <a
        href="cadastrar_recebimento.php"
        class="btn btn-verde"
    >
        + Recebimento
    </a>

    <a
        href="cadastrar_despesa.php"
        class="btn btn-vermelho"
    >
        + Despesa
    </a>

</div>

    </div>


    <!-- =========================================================
         FILTRO DE PERÍODO
    ========================================================== -->

    <div class="filtro">

        <form method="GET">

            <div class="campo">

                <label for="data_inicio">
                    Data inicial
                </label>

                <input
                    type="date"
                    id="data_inicio"
                    name="data_inicio"
                    value="<?= htmlspecialchars($data_inicio) ?>"
                >

            </div>

            <div class="campo">

                <label for="data_fim">
                    Data final
                </label>

                <input
                    type="date"
                    id="data_fim"
                    name="data_fim"
                    value="<?= htmlspecialchars($data_fim) ?>"
                >

            </div>

            <div class="campo">

                <button type="submit">
                    Filtrar período
                </button>

            </div>

        </form>

    </div>


    <!-- =========================================================
         RESUMO
    ========================================================== -->

    <div class="cards">

        <div class="card recebido">

            <div class="titulo">
                Recebido no período
            </div>

            <div class="valor positivo">
                <?= dinheiro($total_recebido) ?>
            </div>

            <div class="sub">
                Pagamentos efetivamente recebidos
            </div>

        </div>


        <div class="card despesa">

            <div class="titulo">
                Despesas no período
            </div>

            <div class="valor negativo">
                <?= dinheiro($total_despesas) ?>
            </div>

            <div class="sub">
                Gastos registrados
            </div>

        </div>


        <div class="card resultado">

            <div class="titulo">
                Resultado de caixa
            </div>

            <div class="valor <?= $resultado >= 0 ? 'positivo' : 'negativo' ?>">

                <?= dinheiro($resultado) ?>

            </div>

            <div class="sub">
                Recebido − despesas
            </div>

        </div>


        <div class="card vendas">

            <div class="titulo">
                Vendas no período
            </div>

            <div class="valor">
                <?= dinheiro($total_vendido) ?>
            </div>

            <div class="sub">
                <?= $qtd_pedidos ?>
                <?= $qtd_pedidos == 1 ? 'pedido' : 'pedidos' ?>
            </div>

        </div>


        <div class="card aberto">

            <div class="titulo">
                Total em aberto
            </div>

            <div class="valor">
                <?= dinheiro($total_aberto) ?>
            </div>

            <div class="sub">
                Valores ainda não recebidos
            </div>

        </div>

    </div>


    <!-- =========================================================
         FORMAS DE PAGAMENTO / DESPESAS
    ========================================================== -->

    <div class="grid">

        <div class="secao">

            <h2>
                Recebimentos por forma de pagamento
            </h2>

            <?php if (empty($recebimentos_forma)): ?>

                <div class="vazio">
                    Nenhum recebimento no período.
                </div>

            <?php else: ?>

                <?php foreach ($recebimentos_forma as $item): ?>

                    <div class="linha">

                        <div>

                            <div class="nome">
                                <?= htmlspecialchars(
                                    forma_pagamento_nome(
                                        $item['forma_pagamento']
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </div>

                        </div>

                        <div class="valor-lista positivo">

                            <?= dinheiro($item['total']) ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <div class="secao">

            <h2>
                Despesas por categoria
            </h2>

            <?php if (empty($despesas_categoria)): ?>

                <div class="vazio">
                    Nenhuma despesa no período.
                </div>

            <?php else: ?>

                <?php foreach ($despesas_categoria as $item): ?>

                    <div class="linha">

                        <div>

                            <div class="nome">

                                <?= htmlspecialchars(
                                    categoria_nome(
                                        $item['categoria']
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>

                        </div>

                        <div class="valor-lista negativo">

                            <?= dinheiro($item['total']) ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         ÚLTIMOS LANÇAMENTOS
    ========================================================== -->

    <div class="grid">

        <!-- RECEBIMENTOS -->

        <div class="secao">

            <h2>
                Últimos recebimentos
            </h2>

            <?php if (empty($ultimos_recebimentos)): ?>

                <div class="vazio">
                    Nenhum recebimento cadastrado.
                </div>

            <?php else: ?>

                <?php foreach ($ultimos_recebimentos as $item): ?>

                    <div class="linha">

                        <div>

                            <div class="nome">

                                <?= htmlspecialchars(
                                    $item['descricao'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>

                            <div class="detalhe">

                                <?= data_br($item['data_recebimento']) ?>

                                ·

                                <?= htmlspecialchars(
                                    forma_pagamento_nome(
                                        $item['forma_pagamento']
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                                <?php if (!empty($item['numero_pedido'])): ?>

                                    · Pedido #<?= (int) $item['numero_pedido'] ?>

                                <?php endif; ?>

                                <?php if (!empty($item['cliente_nome'])): ?>

                                    ·
                                    <?= htmlspecialchars(
                                        $item['cliente_nome'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                <?php endif; ?>

                            </div>

                        </div>

                        <div class="valor-lista positivo">

                            <?= dinheiro($item['valor']) ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <!-- DESPESAS -->

        <div class="secao">

            <h2>
                Últimas despesas
            </h2>

            <?php if (empty($ultimas_despesas)): ?>

                <div class="vazio">
                    Nenhuma despesa cadastrada.
                </div>

            <?php else: ?>

                <?php foreach ($ultimas_despesas as $item): ?>

                    <div class="linha">

                        <div>

                            <div class="nome">

                                <?= htmlspecialchars(
                                    $item['descricao'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>

                            <div class="detalhe">

                                <?= data_br($item['data_despesa']) ?>

                                ·

                                <?= htmlspecialchars(
                                    categoria_nome(
                                        $item['categoria']
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>

                        </div>

                        <div class="valor-lista negativo">

                            <?= dinheiro($item['valor']) ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         LINKS
    ========================================================== -->

    <div class="acoes">

        <a
            href="recebimentos.php"
            class="btn btn-cinza"
        >
            Ver todos os recebimentos
        </a>

        <a
            href="despesas.php"
            class="btn btn-cinza"
        >
            Ver todas as despesas
        </a>

    </div>

</div>

</body>

</html>