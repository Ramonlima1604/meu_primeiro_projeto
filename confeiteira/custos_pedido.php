<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================================
   FUNÇÕES AUXILIARES
========================================================= */

function h($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function moedaCusto($valor)
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function numeroCusto($valor, $casas = 3)
{
    return number_format((float)$valor, $casas, ',', '.');
}

function percentualCusto($valor)
{
    return number_format((float)$valor, 2, ',', '.') . '%';
}

function dataBRCusto($data)
{
    if (empty($data)) {
        return '-';
    }

    $timestamp = strtotime($data);

    if (!$timestamp) {
        return '-';
    }

    return date('d/m/Y', $timestamp);
}

function tipoReceitaNome($tipo)
{
    $tipos = [
        'massa'     => 'Massa',
        'recheio'   => 'Recheio',
        'cobertura' => 'Cobertura',
        'decoracao' => 'Decoração',
        'outros'    => 'Outros'
    ];

    return $tipos[$tipo] ?? ucfirst((string)$tipo);
}

function classeTipoReceita($tipo)
{
    $classes = [
        'massa'     => 'tipo-massa',
        'recheio'   => 'tipo-recheio',
        'cobertura' => 'tipo-cobertura',
        'decoracao' => 'tipo-decoracao',
        'outros'    => 'tipo-outros'
    ];

    return $classes[$tipo] ?? 'tipo-outros';
}

/* =========================================================
   IDENTIFICA CONFEITEIRA LOGADA
========================================================= */

$usuarioId = $_SESSION['usuario_id'] ?? null;

if (!$usuarioId) {
    die("Sessão expirada. Faça login novamente.");
}

$stmt = $pdo->prepare("
    SELECT id, nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuarioId]);

$confeiteira = $stmt->fetch();

if (!$confeiteira) {
    die("Confeiteira não encontrada.");
}

$confeiteiraId = (int)$confeiteira['id'];

/* =========================================================
   PEDIDO
========================================================= */

$pedidoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$pedidoId) {
    die("Pedido inválido.");
}

$stmtPedido = $pdo->prepare("
    SELECT
        p.*,
        c.nome AS cliente_nome,
        c.telefone AS cliente_telefone,
        c.email AS cliente_email
    FROM pedidos p
    INNER JOIN clientes c
        ON c.id = p.cliente_id
    WHERE p.id = ?
      AND p.confeiteira_id = ?
    LIMIT 1
");

$stmtPedido->execute([
    $pedidoId,
    $confeiteiraId
]);

$pedido = $stmtPedido->fetch();

if (!$pedido) {
    die("Pedido não encontrado ou você não possui acesso a este pedido.");
}

/* =========================================================
   ITENS DO PEDIDO
========================================================= */

$stmtItens = $pdo->prepare("
    SELECT
        pi.*,
        b.nome AS bolo_nome,
        b.preco_venda,
        b.categoria,
        b.tamanho,
        b.rendimento AS bolo_rendimento
    FROM pedido_itens pi
    INNER JOIN bolos b
        ON b.id = pi.bolo_id
       AND b.confeiteira_id = ?
    WHERE pi.pedido_id = ?
    ORDER BY pi.id ASC
");

$stmtItens->execute([
    $confeiteiraId,
    $pedidoId
]);

$itensPedido = $stmtItens->fetchAll();

/* =========================================================
   VARIÁVEIS GERAIS
========================================================= */

$itensAnalise = [];

$totalVenda = 0;
$totalCustoProducao = 0;

$alertasRendimento = [];

$insumosConsolidados = [];

/* =========================================================
   PROCESSAMENTO DOS PRODUTOS
========================================================= */

foreach ($itensPedido as $item) {

    $boloId = (int)$item['bolo_id'];
    $quantidadePedido = (float)$item['quantidade'];

    $precoUnitario = (float)$item['preco_unitario'];
    $subtotalVenda = (float)$item['subtotal'];

    /*
     * Segurança adicional:
     * o produto precisa pertencer à confeiteira logada.
     */
    $stmtBolo = $pdo->prepare("
        SELECT
            id,
            nome,
            preco_venda,
            categoria,
            tamanho,
            rendimento
        FROM bolos
        WHERE id = ?
          AND confeiteira_id = ?
        LIMIT 1
    ");

    $stmtBolo->execute([
        $boloId,
        $confeiteiraId
    ]);

    $bolo = $stmtBolo->fetch();

    if (!$bolo) {
        continue;
    }

    $receitasProduto = [];
    $insumosDiretosProduto = [];

    $custoReceitasProduto = 0;
    $custoDiretoProduto = 0;

    /* =====================================================
       RECEITAS DO PRODUTO
    ===================================================== */

    $stmtReceitas = $pdo->prepare("
        SELECT
            br.id AS bolo_receita_id,
            br.receita_id,
            br.quantidade AS quantidade_utilizacao,
            br.unidade_utilizacao,
            br.ordem,

            r.nome AS receita_nome,
            r.tipo AS receita_tipo,
            r.descricao AS receita_descricao,
            r.rendimento AS receita_rendimento,
            r.unidade_rendimento
        FROM bolo_receitas br
        INNER JOIN receitas r
            ON r.id = br.receita_id
           AND r.confeiteira_id = ?
        WHERE br.bolo_id = ?
        ORDER BY
            CASE r.tipo
                WHEN 'massa' THEN 1
                WHEN 'recheio' THEN 2
                WHEN 'cobertura' THEN 3
                WHEN 'decoracao' THEN 4
                ELSE 5
            END,
            br.ordem ASC,
            br.id ASC
    ");

    $stmtReceitas->execute([
        $confeiteiraId,
        $boloId
    ]);

    $receitas = $stmtReceitas->fetchAll();

    foreach ($receitas as $receita) {

        $receitaId = (int)$receita['receita_id'];

        $quantidadeUtilizacao = (float)$receita['quantidade_utilizacao'];
        $rendimentoReceita = (float)$receita['receita_rendimento'];

        $custoTotalReceita = 0;

        $insumosReceita = [];

        /* ================================================
           INSUMOS DA RECEITA
        ================================================ */

        $stmtInsumosReceita = $pdo->prepare("
            SELECT
                ri.id,
                ri.quantidade,
                ri.insumo_id,

                i.nome AS insumo_nome,
                i.tipo AS insumo_tipo,
                i.unidade,
                i.custo_unitario
            FROM receita_insumos ri
            INNER JOIN insumos i
                ON i.id = ri.insumo_id
               AND i.confeiteira_id = ?
            WHERE ri.receita_id = ?
            ORDER BY ri.id ASC
        ");

        $stmtInsumosReceita->execute([
            $confeiteiraId,
            $receitaId
        ]);

        $insumosDaReceita = $stmtInsumosReceita->fetchAll();

        foreach ($insumosDaReceita as $insumo) {

            $quantidadeInsumo = (float)$insumo['quantidade'];
            $custoUnitario = (float)$insumo['custo_unitario'];

            $custoInsumoReceita = $quantidadeInsumo * $custoUnitario;

            $custoTotalReceita += $custoInsumoReceita;

            $insumoProcessado = [
                'id' => (int)$insumo['insumo_id'],
                'nome' => $insumo['insumo_nome'],
                'tipo' => $insumo['insumo_tipo'],
                'unidade' => $insumo['unidade'],
                'quantidade_receita' => $quantidadeInsumo,
                'custo_unitario' => $custoUnitario,
                'custo_total_receita' => $custoInsumoReceita
            ];

            $insumosReceita[] = $insumoProcessado;

            /* ============================================
               CONSOLIDADO DE INSUMOS
            ============================================ */

            if (!isset($insumosConsolidados[$insumo['insumo_id']])) {

                $insumosConsolidados[$insumo['insumo_id']] = [
                    'id' => (int)$insumo['insumo_id'],
                    'nome' => $insumo['insumo_nome'],
                    'tipo' => $insumo['insumo_tipo'],
                    'unidade' => $insumo['unidade'],
                    'quantidade' => 0,
                    'custo' => 0
                ];
            }

            /*
             * Quantidade da receita utilizada pelo produto.
             * Só aplicamos o fator de rendimento abaixo.
             */
        }

        /* ================================================
           FATOR DE RENDIMENTO
        ================================================ */

        $fatorRendimento = 0;

        if ($rendimentoReceita > 0) {

            $fatorRendimento =
                $quantidadeUtilizacao / $rendimentoReceita;

        } else {

            /*
             * Sem rendimento cadastrado não é possível
             * fazer uma conversão proporcional segura.

             * Mantemos a lógica anterior:
             * considera a quantidade utilizada como fator.
             */

            $fatorRendimento = $quantidadeUtilizacao;

            $alertasRendimento[] = [
                'bolo' => $bolo['nome'],
                'receita' => $receita['receita_nome']
            ];
        }

        $custoReceitaUtilizada =
            $custoTotalReceita * $fatorRendimento;

        $custoReceitaNoPedido =
            $custoReceitaUtilizada * $quantidadePedido;

        $custoReceitasProduto += $custoReceitaUtilizada;

        /*
         * Agora que temos o fator, podemos calcular
         * a quantidade efetivamente consumida no produto.
         */

        foreach ($insumosReceita as &$insumoProcessado) {

            $quantidadeUtilizadaNoProduto =
                $insumoProcessado['quantidade_receita']
                * $fatorRendimento;

            $quantidadeNoPedido =
                $quantidadeUtilizadaNoProduto
                * $quantidadePedido;

            $custoNoPedido =
                $quantidadeNoPedido
                * $insumoProcessado['custo_unitario'];

            $insumoProcessado['quantidade_produto'] =
                $quantidadeUtilizadaNoProduto;

            $insumoProcessado['quantidade_pedido'] =
                $quantidadeNoPedido;

            $insumoProcessado['custo_pedido'] =
                $custoNoPedido;

            $insumoId = $insumoProcessado['id'];

            if (!isset($insumosConsolidados[$insumoId])) {

                $insumosConsolidados[$insumoId] = [
                    'id' => $insumoId,
                    'nome' => $insumoProcessado['nome'],
                    'tipo' => $insumoProcessado['tipo'],
                    'unidade' => $insumoProcessado['unidade'],
                    'quantidade' => 0,
                    'custo' => 0
                ];
            }

            $insumosConsolidados[$insumoId]['quantidade']
                += $quantidadeNoPedido;

            $insumosConsolidados[$insumoId]['custo']
                += $custoNoPedido;
        }

        unset($insumoProcessado);

        $receitasProduto[] = [
            'id' => (int)$receita['bolo_receita_id'],
            'receita_id' => $receitaId,
            'nome' => $receita['receita_nome'],
            'tipo' => $receita['receita_tipo'],
            'descricao' => $receita['receita_descricao'],
            'quantidade_utilizacao' => $quantidadeUtilizacao,
            'unidade_utilizacao' => $receita['unidade_utilizacao'],
            'rendimento' => $rendimentoReceita,
            'unidade_rendimento' => $receita['unidade_rendimento'],
            'fator' => $fatorRendimento,
            'custo_total_receita' => $custoTotalReceita,
            'custo_utilizado' => $custoReceitaUtilizada,
            'custo_pedido' => $custoReceitaNoPedido,
            'insumos' => $insumosReceita
        ];
    }

    /* =====================================================
       INSUMOS DIRETOS DO PRODUTO
    ===================================================== */

    $stmtInsumosDiretos = $pdo->prepare("
        SELECT
            bi.id,
            bi.insumo_id,
            bi.quantidade,

            i.nome AS insumo_nome,
            i.tipo AS insumo_tipo,
            i.unidade,
            i.custo_unitario
        FROM bolo_insumos bi
        INNER JOIN insumos i
            ON i.id = bi.insumo_id
           AND i.confeiteira_id = ?
        WHERE bi.bolo_id = ?
        ORDER BY bi.id ASC
    ");

    $stmtInsumosDiretos->execute([
        $confeiteiraId,
        $boloId
    ]);

    $insumosDiretos = $stmtInsumosDiretos->fetchAll();

    foreach ($insumosDiretos as $insumo) {

        $quantidadeProduto = (float)$insumo['quantidade'];
        $custoUnitario = (float)$insumo['custo_unitario'];

        $custoProduto =
            $quantidadeProduto * $custoUnitario;

        $custoPedido =
            $custoProduto * $quantidadePedido;

        $custoDiretoProduto += $custoProduto;

        $insumosDiretosProduto[] = [
            'id' => (int)$insumo['insumo_id'],
            'nome' => $insumo['insumo_nome'],
            'tipo' => $insumo['insumo_tipo'],
            'unidade' => $insumo['unidade'],
            'quantidade_produto' => $quantidadeProduto,
            'quantidade_pedido' =>
                $quantidadeProduto * $quantidadePedido,
            'custo_unitario' => $custoUnitario,
            'custo_produto' => $custoProduto,
            'custo_pedido' => $custoPedido
        ];

        /*
         * Consolidado
         */

        $insumoId = (int)$insumo['insumo_id'];

        if (!isset($insumosConsolidados[$insumoId])) {

            $insumosConsolidados[$insumoId] = [
                'id' => $insumoId,
                'nome' => $insumo['insumo_nome'],
                'tipo' => $insumo['insumo_tipo'],
                'unidade' => $insumo['unidade'],
                'quantidade' => 0,
                'custo' => 0
            ];
        }

        $insumosConsolidados[$insumoId]['quantidade']
            += $quantidadeProduto * $quantidadePedido;

        $insumosConsolidados[$insumoId]['custo']
            += $custoPedido;
    }

    /* =====================================================
       TOTAL DO PRODUTO
    ===================================================== */

    $custoProducaoUnitario =
        $custoReceitasProduto + $custoDiretoProduto;

    $custoProducaoPedido =
        $custoProducaoUnitario * $quantidadePedido;

    $itensAnalise[] = [
        'bolo_id' => $boloId,
        'nome' => $bolo['nome'],
        'categoria' => $bolo['categoria'],
        'tamanho' => $bolo['tamanho'],
        'rendimento' => $bolo['rendimento'],

        'quantidade' => $quantidadePedido,

        'preco_unitario' => $precoUnitario,
        'subtotal_venda' => $subtotalVenda,

        'receitas' => $receitasProduto,
        'insumos_diretos' => $insumosDiretosProduto,

        'custo_receitas_unitario' =>
            $custoReceitasProduto,

        'custo_direto_unitario' =>
            $custoDiretoProduto,

        'custo_unitario' =>
            $custoProducaoUnitario,

        'custo_pedido' =>
            $custoProducaoPedido
    ];

    $totalVenda += $subtotalVenda;
    $totalCustoProducao += $custoProducaoPedido;
}

/* =========================================================
   RESULTADO FINANCEIRO ESTIMADO
========================================================= */

$lucroBruto = $totalVenda - $totalCustoProducao;

$percentualCusto = 0;
$margemLucro = 0;

if ($totalVenda > 0) {

    $percentualCusto =
        ($totalCustoProducao / $totalVenda) * 100;

    $margemLucro =
        ($lucroBruto / $totalVenda) * 100;
}

$quantidadeTotalProdutos = 0;

foreach ($itensAnalise as $item) {
    $quantidadeTotalProdutos += (float)$item['quantidade'];
}

$custoMedioProduto = 0;
$precoMedioProduto = 0;
$lucroMedioProduto = 0;

if ($quantidadeTotalProdutos > 0) {

    $custoMedioProduto =
        $totalCustoProducao / $quantidadeTotalProdutos;

    $precoMedioProduto =
        $totalVenda / $quantidadeTotalProdutos;

    $lucroMedioProduto =
        $lucroBruto / $quantidadeTotalProdutos;
}

/* =========================================================
   CONSOLIDADO
========================================================= */

$insumosConsolidados = array_values($insumosConsolidados);

usort(
    $insumosConsolidados,
    function ($a, $b) {
        return strcasecmp($a['nome'], $b['nome']);
    }
);

foreach ($insumosConsolidados as &$insumo) {

    if ($totalCustoProducao > 0) {

        $insumo['participacao'] =
            ($insumo['custo'] / $totalCustoProducao) * 100;

    } else {

        $insumo['participacao'] = 0;
    }
}

unset($insumo);

/* =========================================================
   STATUS DO PEDIDO
========================================================= */

$statusNomes = [
    'orcamento'  => 'Orçamento',
    'confirmado' => 'Confirmado',
    'producao'   => 'Em produção',
    'pronto'     => 'Pronto',
    'entregue'   => 'Entregue',
    'cancelado'  => 'Cancelado'
];

$statusClasses = [
    'orcamento'  => 'status-orcamento',
    'confirmado' => 'status-confirmado',
    'producao'   => 'status-producao',
    'pronto'     => 'status-pronto',
    'entregue'   => 'status-entregue',
    'cancelado'  => 'status-cancelado'
];

$statusPedido = $pedido['status'] ?? 'orcamento';

$statusNome =
    $statusNomes[$statusPedido]
    ?? ucfirst($statusPedido);

$statusClasse =
    $statusClasses[$statusPedido]
    ?? 'status-orcamento';

/* =========================================================
   HTML
========================================================= */
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
        Custos do Pedido #<?= h($pedido['numero_pedido'] ?: $pedido['id']) ?>
    </title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

    <link
        rel="stylesheet"
        href="../assets/pedidos.css"
    >

    <style>

        :root {
            --custos-bg: #f3f5f8;
            --custos-surface: #ffffff;
            --custos-text: #1f2937;
            --custos-muted: #6b7280;
            --custos-line: #e5eaf0;

            --custos-primary: #4f46e5;
            --custos-success: #15803d;
            --custos-danger: #b91c1c;
            --custos-warning: #b45309;

            --custos-shadow:
                0 8px 24px rgba(31, 41, 55, .06);
        }

        body {
            background: var(--custos-bg) !important;
            color: var(--custos-text) !important;
            font-family:
                Inter,
                "Segoe UI",
                Arial,
                sans-serif !important;

            padding: 32px 18px !important;
        }

        .custos-container {
            width: min(100%, 1120px);
            margin: 0 auto;
        }

        .topo-custos {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;

            margin-bottom: 20px;
            padding: 22px 24px;

            background: var(--custos-surface);
            border: 1px solid var(--custos-line);
            border-radius: 16px;

            box-shadow: var(--custos-shadow);
        }

        .topo-custos h1 {
            margin: 0 0 5px;
            font-size: clamp(23px, 3vw, 30px);
            color: var(--custos-text);
        }

        .topo-custos p {
            margin: 0;
            color: var(--custos-muted);
        }

        .topo-acoes {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .botao {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;

            min-height: 42px;
            padding: 10px 15px;

            border: 1px solid transparent;
            border-radius: 10px;

            background: var(--custos-primary);
            color: #fff;

            font-size: 14px;
            font-weight: 650;
            text-decoration: none;

            cursor: pointer;
            transition: .15s ease;
        }

        .botao:hover {
            transform: translateY(-1px);
            box-shadow:
                0 5px 12px rgba(31, 41, 55, .12);
        }

        .botao-voltar {
            background: #fff;
            color: #374151;
            border-color: var(--custos-line);
        }

        .botao-imprimir {
            background: #374151;
            color: #fff;
        }

        .pedido-resumo {
            display: grid;
            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 14px;
            margin-bottom: 18px;
        }

        .info-box {
            padding: 18px;

            background: var(--custos-surface);
            border: 1px solid var(--custos-line);
            border-radius: 14px;

            box-shadow: var(--custos-shadow);
        }

        .info-label {
            display: block;
            margin-bottom: 6px;

            color: var(--custos-muted);
            font-size: 12px;
            font-weight: 650;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .info-value {
            color: var(--custos-text);
            font-size: 16px;
            font-weight: 700;
        }

        .card-custos {
            margin-bottom: 18px;
            padding: 24px;

            background: var(--custos-surface);
            border: 1px solid var(--custos-line);
            border-radius: 16px;

            box-shadow: var(--custos-shadow);
        }

        .card-custos h2 {
            margin: 0 0 18px;
            color: var(--custos-text);
            font-size: 20px;
        }

        .financeiro-grid {
            display: grid;
            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 14px;
        }

        .financeiro-box {
            padding: 18px;

            border: 1px solid var(--custos-line);
            border-radius: 13px;

            background: #f8fafc;
        }

        .financeiro-box .label {
            display: block;
            margin-bottom: 7px;

            color: var(--custos-muted);
            font-size: 12px;
            font-weight: 650;
            text-transform: uppercase;
        }

        .financeiro-box .valor {
            font-size: 22px;
            font-weight: 800;
            color: var(--custos-text);
        }

        .valor-venda {
            color: var(--custos-primary) !important;
        }

        .valor-custo {
            color: var(--custos-danger) !important;
        }

        .valor-lucro {
            color: var(--custos-success) !important;
        }

        .valor-margem {
            color: var(--custos-success) !important;
        }

        .barra-container {
            margin-top: 18px;
        }

        .barra-info {
            display: flex;
            justify-content: space-between;
            gap: 10px;

            margin-bottom: 7px;

            color: var(--custos-muted);
            font-size: 13px;
        }

        .barra {
            height: 12px;
            overflow: hidden;

            border-radius: 999px;
            background: #e5e7eb;
        }

        .barra-preenchida {
            height: 100%;
            min-width: 0;

            border-radius: inherit;
            background: var(--custos-danger);

            transition: width .2s ease;
        }

        .alerta {
            margin-bottom: 18px;
            padding: 14px 16px;

            border: 1px solid #fde68a;
            border-radius: 12px;

            background: #fffbeb;
            color: #92400e;

            font-size: 14px;
        }

        .alerta strong {
            display: block;
            margin-bottom: 5px;
        }

        .produto-card {
            margin-bottom: 18px;
            overflow: hidden;

            background: var(--custos-surface);
            border: 1px solid var(--custos-line);
            border-radius: 16px;

            box-shadow: var(--custos-shadow);
        }

        .produto-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;

            padding: 20px 22px;

            border-bottom: 1px solid var(--custos-line);
            background: #fafbfc;
        }

        .produto-header h3 {
            margin: 0 0 5px;
            color: var(--custos-text);
            font-size: 19px;
        }

        .produto-meta {
            color: var(--custos-muted);
            font-size: 13px;
        }

        .quantidade-produto {
            padding: 7px 11px;

            border-radius: 999px;

            background: #eef2ff;
            color: #4338ca;

            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .produto-body {
            padding: 22px;
        }

        .secao-custo {
            margin-bottom: 25px;
        }

        .secao-custo:last-child {
            margin-bottom: 0;
        }

        .secao-titulo {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;

            margin-bottom: 12px;
        }

        .secao-titulo h4 {
            margin: 0;

            color: var(--custos-text);
            font-size: 16px;
        }

        .secao-titulo span {
            color: var(--custos-muted);
            font-size: 13px;
        }

        .tabela-wrapper {
            overflow-x: auto;

            border: 1px solid var(--custos-line);
            border-radius: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th,
        td {
            padding: 12px 13px;

            border-bottom: 1px solid var(--custos-line);

            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #f8fafc;
            color: var(--custos-muted);

            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        td {
            color: #374151;
            font-size: 14px;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .alinha-direita {
            text-align: right;
        }

        .tipo-receita {
            display: inline-flex;

            padding: 5px 9px;

            border-radius: 999px;

            font-size: 11px;
            font-weight: 700;
        }

        .tipo-massa {
            background: #fef3c7;
            color: #92400e;
        }

        .tipo-recheio {
            background: #fce7f3;
            color: #9d174d;
        }

        .tipo-cobertura {
            background: #ede9fe;
            color: #5b21b6;
        }

        .tipo-decoracao {
            background: #dcfce7;
            color: #166534;
        }

        .tipo-outros {
            background: #e5e7eb;
            color: #374151;
        }

        .custo-destaque {
            font-weight: 750;
            color: var(--custos-text);
        }

        .produto-total {
            display: grid;
            grid-template-columns:
                repeat(3, minmax(0, 1fr));

            gap: 12px;
            margin-top: 18px;
        }

        .produto-total-box {
            padding: 15px;

            border: 1px solid var(--custos-line);
            border-radius: 11px;

            background: #f8fafc;
        }

        .produto-total-box span {
            display: block;
            margin-bottom: 5px;

            color: var(--custos-muted);
            font-size: 12px;
        }

        .produto-total-box strong {
            font-size: 17px;
        }

        .resultado-final {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));

            gap: 14px;
        }

        .resultado-box {
            padding: 20px;

            border: 1px solid var(--custos-line);
            border-radius: 13px;

            background: #f8fafc;
        }

        .resultado-box .label {
            display: block;
            margin-bottom: 7px;

            color: var(--custos-muted);
            font-size: 12px;
            font-weight: 650;
            text-transform: uppercase;
        }

        .resultado-box strong {
            color: var(--custos-text);
            font-size: 22px;
        }

        .resultado-explicacao {
            margin-top: 16px;
            padding: 15px;

            border-radius: 11px;
            background: #f8fafc;

            color: var(--custos-muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .vazio {
            padding: 18px;

            border: 1px dashed var(--custos-line);
            border-radius: 11px;

            background: #f8fafc;
            color: var(--custos-muted);

            text-align: center;
        }

        .status-pedido {
            display: inline-flex;

            padding: 6px 10px;

            border-radius: 999px;

            font-size: 12px;
            font-weight: 700;
        }

        .status-orcamento {
            background: #fef3c7;
            color: #92400e;
        }

        .status-confirmado {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-producao {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-pronto {
            background: #dcfce7;
            color: #166534;
        }

        .status-entregue {
            background: #d1fae5;
            color: #065f46;
        }

        .status-cancelado {
            background: #fee2e2;
            color: #991b1b;
        }

        .subtitulo-consolidado {
            margin: -10px 0 16px;

            color: var(--custos-muted);
            font-size: 13px;
        }

        @media (max-width: 900px) {

            .pedido-resumo,
            .financeiro-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

        }

        @media (max-width: 700px) {

            body {
                padding: 20px 12px !important;
            }

            .topo-custos {
                align-items: flex-start;
                flex-direction: column;
                padding: 18px;
            }

            .topo-acoes {
                width: 100%;
            }

            .topo-acoes .botao {
                flex: 1;
            }

            .pedido-resumo,
            .financeiro-grid,
            .produto-total,
            .resultado-final {
                grid-template-columns: 1fr;
            }

            .card-custos {
                padding: 18px;
            }

            .produto-header {
                align-items: flex-start;
                flex-direction: column;
                padding: 18px;
            }

            .produto-body {
                padding: 18px;
            }

        }

        @media print {

            body {
                padding: 0 !important;
                background: #fff !important;
            }

            .topo-acoes {
                display: none !important;
            }

            .topo-custos,
            .info-box,
            .card-custos,
            .produto-card {
                box-shadow: none !important;
            }

            .topo-custos,
            .info-box,
            .card-custos,
            .produto-card {
                break-inside: avoid;
            }

            .produto-card {
                border: 1px solid #ddd;
            }

        }

    </style>

</head>

<body>

<div class="custos-container">

    <!-- =====================================================
         CABEÇALHO
    ====================================================== -->

    <div class="topo-custos">

        <div>

            <h1>
                📊 Custos do Pedido
                #<?= h($pedido['numero_pedido'] ?: $pedido['id']) ?>
            </h1>

            <p>
                <?= h($confeiteira['nome_confeitaria']) ?>
                <?php if (!empty($pedido['cliente_nome'])): ?>
                    • <?= h($pedido['cliente_nome']) ?>
                <?php endif; ?>
            </p>

        </div>

        <div class="topo-acoes">

            <a
                href="visualizar_pedido.php?id=<?= (int)$pedido['id'] ?>"
                class="botao botao-voltar"
            >
                ← Voltar
            </a>

            <button
                type="button"
                class="botao botao-imprimir"
                onclick="window.print()"
            >
                🖨️ Imprimir
            </button>

        </div>

    </div>


    <!-- =====================================================
         RESUMO DO PEDIDO
    ====================================================== -->

    <div class="pedido-resumo">

        <div class="info-box">

            <span class="info-label">
                Cliente
            </span>

            <div class="info-value">
                <?= h($pedido['cliente_nome'] ?: '-') ?>
            </div>

        </div>


        <div class="info-box">

            <span class="info-label">
                Data do pedido
            </span>

            <div class="info-value">
                <?= dataBRCusto($pedido['data_pedido']) ?>
            </div>

        </div>


        <div class="info-box">

            <span class="info-label">
                Entrega
            </span>

            <div class="info-value">
                <?= dataBRCusto($pedido['data_entrega']) ?>
            </div>

        </div>


        <div class="info-box">

            <span class="info-label">
                Status
            </span>

            <div>

                <span class="status-pedido <?= h($statusClasse) ?>">
                    <?= h($statusNome) ?>
                </span>

            </div>

        </div>

    </div>


    <?php if (!empty($alertasRendimento)): ?>

        <!-- =================================================
             ALERTA DE RENDIMENTO
        ================================================== -->

        <div class="alerta">

            <strong>
                ⚠️ Atenção ao rendimento
            </strong>

            Algumas receitas utilizadas neste pedido não possuem
            rendimento cadastrado. Nesses casos, o sistema utilizou
            a quantidade de utilização como fator de cálculo.

            <br><br>

            <?php foreach ($alertasRendimento as $alerta): ?>

                • <?= h($alerta['bolo']) ?>
                —
                <?= h($alerta['receita']) ?>

                <br>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         RESUMO FINANCEIRO
    ====================================================== -->

    <div class="card-custos">

        <h2>
            💰 Resumo financeiro
        </h2>

        <div class="financeiro-grid">

            <div class="financeiro-box">

                <span class="label">
                    Valor de venda
                </span>

                <div class="valor valor-venda">
                    <?= moedaCusto($totalVenda) ?>
                </div>

            </div>


            <div class="financeiro-box">

                <span class="label">
                    Custo de produção
                </span>

                <div class="valor valor-custo">
                    <?= moedaCusto($totalCustoProducao) ?>
                </div>

            </div>


            <div class="financeiro-box">

                <span class="label">
                    Lucro bruto estimado
                </span>

                <div class="valor valor-lucro">
                    <?= moedaCusto($lucroBruto) ?>
                </div>

            </div>


            <div class="financeiro-box">

                <span class="label">
                    Margem estimada
                </span>

                <div class="valor valor-margem">
                    <?= percentualCusto($margemLucro) ?>
                </div>

            </div>

        </div>


        <div class="barra-container">

            <div class="barra-info">

                <span>
                    Percentual do preço consumido pelo custo
                </span>

                <strong>
                    <?= percentualCusto($percentualCusto) ?>
                </strong>

            </div>

            <div class="barra">

                <div
                    class="barra-preenchida"
                    style="width: <?= min(100, max(0, $percentualCusto)) ?>%;"
                ></div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         PRODUTOS DO PEDIDO
    ====================================================== -->

    <?php if (empty($itensAnalise)): ?>

        <div class="card-custos">

            <div class="vazio">

                Nenhum produto válido foi encontrado neste pedido.

            </div>

        </div>

    <?php else: ?>

        <?php foreach ($itensAnalise as $item): ?>

            <div class="produto-card">

                <!-- =========================================
                     CABEÇALHO DO PRODUTO
                ========================================== -->

                <div class="produto-header">

                    <div>

                        <h3>
                            <?= h($item['nome']) ?>
                        </h3>

                        <div class="produto-meta">

                            <?php if (!empty($item['categoria'])): ?>

                                <?= h($item['categoria']) ?>

                            <?php endif; ?>

                            <?php if (!empty($item['tamanho'])): ?>

                                <?php if (!empty($item['categoria'])): ?>
                                    •
                                <?php endif; ?>

                                <?= h($item['tamanho']) ?>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="quantidade-produto">

                        Quantidade:
                        <?= numeroCusto($item['quantidade'], 3) ?>

                    </div>

                </div>


                <div class="produto-body">

                    <!-- =====================================
                         RECEITAS
                    ====================================== -->

                    <div class="secao-custo">

                        <div class="secao-titulo">

                            <h4>
                                🧁 Receitas utilizadas
                            </h4>

                            <span>
                                <?= moedaCusto($item['custo_receitas_unitario']) ?>
                                por unidade
                            </span>

                        </div>


                        <?php if (empty($item['receitas'])): ?>

                            <div class="vazio">

                                Nenhuma receita vinculada diretamente
                                a este produto.

                            </div>

                        <?php else: ?>

                            <div class="tabela-wrapper">

                                <table>

                                    <thead>

                                        <tr>

                                            <th>
                                                Tipo
                                            </th>

                                            <th>
                                                Receita
                                            </th>

                                            <th>
                                                Utilização
                                            </th>

                                            <th>
                                                Rendimento
                                            </th>

                                            <th>
                                                Fator
                                            </th>

                                            <th class="alinha-direita">
                                                Custo total
                                            </th>

                                            <th class="alinha-direita">
                                                Custo utilizado
                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                    <?php foreach ($item['receitas'] as $receita): ?>

                                        <tr>

                                            <td>

                                                <span
                                                    class="tipo-receita <?= h(classeTipoReceita($receita['tipo'])) ?>"
                                                >
                                                    <?= h(tipoReceitaNome($receita['tipo'])) ?>
                                                </span>

                                            </td>

                                            <td>

                                                <strong>
                                                    <?= h($receita['nome']) ?>
                                                </strong>

                                            </td>

                                            <td>

                                                <?= numeroCusto(
                                                    $receita['quantidade_utilizacao']
                                                ) ?>

                                                <?php if (!empty($receita['unidade_utilizacao'])): ?>

                                                    <?= h($receita['unidade_utilizacao']) ?>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <?php if ($receita['rendimento'] > 0): ?>

                                                    <?= numeroCusto(
                                                        $receita['rendimento']
                                                    ) ?>

                                                    <?php if (!empty($receita['unidade_rendimento'])): ?>

                                                        <?= h($receita['unidade_rendimento']) ?>

                                                    <?php endif; ?>

                                                <?php else: ?>

                                                    Não informado

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <?= numeroCusto(
                                                    $receita['fator'],
                                                    4
                                                ) ?>

                                            </td>

                                            <td class="alinha-direita">

                                                <?= moedaCusto(
                                                    $receita['custo_total_receita']
                                                ) ?>

                                            </td>

                                            <td class="alinha-direita custo-destaque">

                                                <?= moedaCusto(
                                                    $receita['custo_utilizado']
                                                ) ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>


                            <!-- =================================
                                 INSUMOS DAS RECEITAS
                            ================================== -->

                            <?php foreach ($item['receitas'] as $receita): ?>

                                <?php if (!empty($receita['insumos'])): ?>

                                    <div
                                        style="
                                            margin-top: 14px;
                                            padding: 14px;
                                            border: 1px solid #e5eaf0;
                                            border-radius: 11px;
                                            background: #fafbfc;
                                        "
                                    >

                                        <div
                                            style="
                                                margin-bottom: 10px;
                                                color: #374151;
                                                font-size: 13px;
                                                font-weight: 700;
                                            "
                                        >

                                            <?= h($receita['nome']) ?>

                                            — composição

                                        </div>


                                        <div class="tabela-wrapper">

                                            <table>

                                                <thead>

                                                    <tr>

                                                        <th>
                                                            Insumo
                                                        </th>

                                                        <th>
                                                            Unidade
                                                        </th>

                                                        <th>
                                                            Quantidade / receita
                                                        </th>

                                                        <th>
                                                            Quantidade utilizada
                                                        </th>

                                                        <th>
                                                            Custo unitário
                                                        </th>

                                                        <th class="alinha-direita">
                                                            Custo no pedido
                                                        </th>

                                                    </tr>

                                                </thead>

                                                <tbody>

                                                <?php foreach ($receita['insumos'] as $insumo): ?>

                                                    <tr>

                                                        <td>
                                                            <?= h($insumo['nome']) ?>
                                                        </td>

                                                        <td>
                                                            <?= h($insumo['unidade']) ?>
                                                        </td>

                                                        <td>

                                                            <?= numeroCusto(
                                                                $insumo['quantidade_receita']
                                                            ) ?>

                                                        </td>

                                                        <td>

                                                            <?= numeroCusto(
                                                                $insumo['quantidade_pedido']
                                                            ) ?>

                                                        </td>

                                                        <td>

                                                            <?= moedaCusto(
                                                                $insumo['custo_unitario']
                                                            ) ?>

                                                        </td>

                                                        <td class="alinha-direita custo-destaque">

                                                            <?= moedaCusto(
                                                                $insumo['custo_pedido']
                                                            ) ?>

                                                        </td>

                                                    </tr>

                                                <?php endforeach; ?>

                                                </tbody>

                                            </table>

                                        </div>

                                    </div>

                                <?php endif; ?>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>


                    <!-- =====================================
                         INSUMOS DIRETOS
                    ====================================== -->

                    <div class="secao-custo">

                        <div class="secao-titulo">

                            <h4>
                                📦 Insumos diretos do produto
                            </h4>

                            <span>
                                <?= moedaCusto($item['custo_direto_unitario']) ?>
                                por unidade
                            </span>

                        </div>


                        <?php if (empty($item['insumos_diretos'])): ?>

                            <div class="vazio">

                                Nenhum insumo direto vinculado
                                a este produto.

                            </div>

                        <?php else: ?>

                            <div class="tabela-wrapper">

                                <table>

                                    <thead>

                                        <tr>

                                            <th>
                                                Insumo
                                            </th>

                                            <th>
                                                Tipo
                                            </th>

                                            <th>
                                                Unidade
                                            </th>

                                            <th>
                                                Quantidade
                                            </th>

                                            <th>
                                                Custo unitário
                                            </th>

                                            <th class="alinha-direita">
                                                Custo no pedido
                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                    <?php foreach ($item['insumos_diretos'] as $insumo): ?>

                                        <tr>

                                            <td>
                                                <strong>
                                                    <?= h($insumo['nome']) ?>
                                                </strong>
                                            </td>

                                            <td>
                                                <?= h($insumo['tipo']) ?>
                                            </td>

                                            <td>
                                                <?= h($insumo['unidade']) ?>
                                            </td>

                                            <td>

                                                <?= numeroCusto(
                                                    $insumo['quantidade_pedido']
                                                ) ?>

                                            </td>

                                            <td>

                                                <?= moedaCusto(
                                                    $insumo['custo_unitario']
                                                ) ?>

                                            </td>

                                            <td class="alinha-direita custo-destaque">

                                                <?= moedaCusto(
                                                    $insumo['custo_pedido']
                                                ) ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- =====================================
                         TOTAL DO PRODUTO
                    ====================================== -->

                    <div class="produto-total">

                        <div class="produto-total-box">

                            <span>
                                Custo das receitas / unidade
                            </span>

                            <strong>
                                <?= moedaCusto(
                                    $item['custo_receitas_unitario']
                                ) ?>
                            </strong>

                        </div>


                        <div class="produto-total-box">

                            <span>
                                Insumos diretos / unidade
                            </span>

                            <strong>
                                <?= moedaCusto(
                                    $item['custo_direto_unitario']
                                ) ?>
                            </strong>

                        </div>


                        <div class="produto-total-box">

                            <span>
                                Custo total no pedido
                            </span>

                            <strong>
                                <?= moedaCusto(
                                    $item['custo_pedido']
                                ) ?>
                            </strong>

                        </div>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>


    <!-- =====================================================
         INSUMOS CONSOLIDADOS
    ====================================================== -->

    <div class="card-custos">

        <h2>
            📦 Consumo consolidado de insumos
        </h2>

        <p class="subtitulo-consolidado">
            Soma dos insumos utilizados em todos os produtos deste pedido.
        </p>


        <?php if (empty($insumosConsolidados)): ?>

            <div class="vazio">

                Nenhum insumo foi encontrado para este pedido.

            </div>

        <?php else: ?>

            <div class="tabela-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Insumo
                            </th>

                            <th>
                                Tipo
                            </th>

                            <th>
                                Unidade
                            </th>

                            <th>
                                Quantidade total
                            </th>

                            <th>
                                Custo total
                            </th>

                            <th>
                                Participação no custo
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($insumosConsolidados as $insumo): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= h($insumo['nome']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= h($insumo['tipo']) ?>
                            </td>

                            <td>
                                <?= h($insumo['unidade']) ?>
                            </td>

                            <td>

                                <?= numeroCusto(
                                    $insumo['quantidade']
                                ) ?>

                            </td>

                            <td class="custo-destaque">

                                <?= moedaCusto(
                                    $insumo['custo']
                                ) ?>

                            </td>

                            <td>

                                <?= percentualCusto(
                                    $insumo['participacao']
                                ) ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         RESULTADO ESTIMADO
    ====================================================== -->

    <div class="card-custos">

        <h2>
            📈 Resultado estimado
        </h2>

        <div class="resultado-final">

            <div class="resultado-box">

                <span class="label">
                    Faturamento do pedido
                </span>

                <strong class="valor-venda">
                    <?= moedaCusto($totalVenda) ?>
                </strong>

            </div>


            <div class="resultado-box">

                <span class="label">
                    Custo de produção
                </span>

                <strong class="valor-custo">
                    <?= moedaCusto($totalCustoProducao) ?>
                </strong>

            </div>


            <div class="resultado-box">

                <span class="label">
                    Percentual de custo
                </span>

                <strong>
                    <?= percentualCusto($percentualCusto) ?>
                </strong>

            </div>


            <div class="resultado-box">

                <span class="label">
                    Lucro bruto estimado
                </span>

                <strong class="valor-lucro">
                    <?= moedaCusto($lucroBruto) ?>
                </strong>

            </div>


            <div class="resultado-box">

                <span class="label">
                    Margem bruta estimada
                </span>

                <strong class="valor-margem">
                    <?= percentualCusto($margemLucro) ?>
                </strong>

            </div>


            <div class="resultado-box">

                <span class="label">
                    Custo médio por produto
                </span>

                <strong>
                    <?= moedaCusto($custoMedioProduto) ?>
                </strong>

            </div>


            <div class="resultado-box">

                <span class="label">
                    Venda média por produto
                </span>

                <strong>
                    <?= moedaCusto($precoMedioProduto) ?>
                </strong>

            </div>


            <div class="resultado-box">

                <span class="label">
                    Lucro bruto médio por produto
                </span>

                <strong class="valor-lucro">
                    <?= moedaCusto($lucroMedioProduto) ?>
                </strong>

            </div>

        </div>


        <div class="resultado-explicacao">

            <strong>
                ℹ️ Como este resultado é calculado
            </strong>

            <br><br>

            <strong>Faturamento:</strong>
            valor total de venda dos produtos do pedido.

            <br>

            <strong>Custo de produção:</strong>
            soma dos custos das receitas utilizadas e dos
            insumos adicionados diretamente aos produtos.

            <br>

            <strong>Lucro bruto estimado:</strong>
            faturamento menos custo de produção.

            <br>

            <strong>Margem estimada:</strong>
            percentual do faturamento que permanece após
            descontar o custo de produção.

            <br><br>

            Este cálculo ainda não considera despesas gerais da
            confeitaria, como energia, água, gás, aluguel,
            mão de obra, taxas de cartão, impostos, transporte
            ou outras despesas. Portanto, o valor apresentado
            como lucro é um <strong>lucro bruto estimado</strong>,
            e não o lucro líquido final.

        </div>

    </div>

</div>

</body>
</html>