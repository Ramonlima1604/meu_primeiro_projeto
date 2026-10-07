<?php

require_once "../config/auth.php";
require_once "../config/banco.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

/*
|--------------------------------------------------------------------------
| FUNÇÕES DE UNIDADE
|--------------------------------------------------------------------------
*/

function normalizarUnidade($unidade)
{
    $unidade = strtolower(trim((string) $unidade));

    $mapa = [
        'kg' => 'kg',
        'quilo' => 'kg',
        'quilos' => 'kg',
        'quilograma' => 'kg',
        'quilogramas' => 'kg',

        'g' => 'g',
        'gr' => 'g',
        'grama' => 'g',
        'gramas' => 'g',

        'mg' => 'mg',
        'miligramas' => 'mg',
        'miligrama' => 'mg',

        'l' => 'l',
        'lt' => 'l',
        'litro' => 'l',
        'litros' => 'l',

        'ml' => 'ml',
        'mililitro' => 'ml',
        'mililitros' => 'ml',

        'un' => 'un',
        'und' => 'un',
        'unid' => 'un',
        'unidade' => 'un',
        'unidades' => 'un',
        'pc' => 'un',
        'pcs' => 'un',
        'peça' => 'un',
        'peças' => 'un',
    ];

    return $mapa[$unidade] ?? $unidade;
}


function categoriaUnidade($unidade)
{
    $unidade = normalizarUnidade($unidade);

    if (in_array($unidade, ['kg', 'g', 'mg'])) {
        return 'massa';
    }

    if (in_array($unidade, ['l', 'ml'])) {
        return 'volume';
    }

    if ($unidade === 'un') {
        return 'quantidade';
    }

    return 'outra';
}


function fatorUnidade($unidade)
{
    $unidade = normalizarUnidade($unidade);

    /*
    | Massa em gramas
    */
    if ($unidade === 'kg') {
        return 1000;
    }

    if ($unidade === 'g') {
        return 1;
    }

    if ($unidade === 'mg') {
        return 0.001;
    }

    /*
    | Volume em ml
    */
    if ($unidade === 'l') {
        return 1000;
    }

    if ($unidade === 'ml') {
        return 1;
    }

    /*
    | Quantidade
    */
    if ($unidade === 'un') {
        return 1;
    }

    return null;
}


function unidadesCompativeis($origem, $destino)
{
    $categoriaOrigem = categoriaUnidade($origem);
    $categoriaDestino = categoriaUnidade($destino);

    return (
        $categoriaOrigem !== 'outra' &&
        $categoriaOrigem === $categoriaDestino
    );
}


function converterQuantidade($quantidade, $unidadeOrigem, $unidadeDestino)
{
    $unidadeOrigem = normalizarUnidade($unidadeOrigem);
    $unidadeDestino = normalizarUnidade($unidadeDestino);

    if ($unidadeOrigem === $unidadeDestino) {
        return (float) $quantidade;
    }

    if (!unidadesCompativeis($unidadeOrigem, $unidadeDestino)) {
        return null;
    }

    $fatorOrigem = fatorUnidade($unidadeOrigem);
    $fatorDestino = fatorUnidade($unidadeDestino);

    if ($fatorOrigem === null || $fatorDestino === null) {
        return null;
    }

    /*
    | Converte primeiro para a unidade base:
    | massa = gramas
    | volume = ml
    | quantidade = unidade
    */
    $valorBase = (float) $quantidade * $fatorOrigem;

    return $valorBase / $fatorDestino;
}


function exibirUnidade($unidade)
{
    $unidade = normalizarUnidade($unidade);

    $nomes = [
        'kg' => 'kg',
        'g' => 'g',
        'mg' => 'mg',
        'l' => 'L',
        'ml' => 'ml',
        'un' => 'un',
    ];

    return $nomes[$unidade] ?? $unidade;
}


function nomeTipoReceita($tipo)
{
    switch ($tipo) {

        case 'massa':
            return 'Massa';

        case 'recheio':
            return 'Recheios';

        case 'cobertura':
            return 'Cobertura';

        case 'decoracao':
            return 'Decoração';

        default:
            return 'Outros';
    }
}


function iconeTipoReceita($tipo)
{
    switch ($tipo) {

        case 'massa':
            return '🍰';

        case 'recheio':
            return '🍓';

        case 'cobertura':
            return '🍫';

        case 'decoracao':
            return '🎀';

        default:
            return '🧁';
    }
}


/*
|--------------------------------------------------------------------------
| LOCALIZA A CONFEITEIRA LOGADA
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
    die("Confeiteira não encontrada.");
}

$confeiteira_id = $confeiteira['id'];


/*
|--------------------------------------------------------------------------
| VALIDA O BOLO
|--------------------------------------------------------------------------
*/

$bolo_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($bolo_id <= 0) {
    die("Bolo inválido.");
}


/*
|--------------------------------------------------------------------------
| BUSCA O BOLO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM bolos
    WHERE id = ?
      AND confeiteira_id = ?
    LIMIT 1
");

$stmt->execute([
    $bolo_id,
    $confeiteira_id
]);

$bolo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bolo) {
    die("Bolo não encontrado ou não pertence a esta confeiteira.");
}


/*
|--------------------------------------------------------------------------
| MENSAGENS
|--------------------------------------------------------------------------
*/

$mensagem = "";
$erro = "";


/*
|--------------------------------------------------------------------------
| ADICIONAR INSUMO DIRETO AO BOLO
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['adicionar_insumo'])
) {

    $insumo_id = (int) ($_POST['insumo_id'] ?? 0);
    $quantidade = (float) ($_POST['quantidade'] ?? 0);

    if ($insumo_id <= 0 || $quantidade <= 0) {

        $erro = "Informe um insumo e uma quantidade válida.";

    } else {

        $stmt = $pdo->prepare("
            SELECT
                id,
                nome,
                unidade,
                custo_unitario
            FROM insumos
            WHERE id = ?
              AND confeiteira_id = ?
              AND ativo = 1
            LIMIT 1
        ");

        $stmt->execute([
            $insumo_id,
            $confeiteira_id
        ]);

        $insumo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$insumo) {

            $erro = "Insumo não encontrado.";

        } else {

            $stmt = $pdo->prepare("
                SELECT id
                FROM bolo_insumos
                WHERE bolo_id = ?
                  AND insumo_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $bolo_id,
                $insumo_id
            ]);

            $existente = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existente) {

                $stmt = $pdo->prepare("
                    UPDATE bolo_insumos
                    SET quantidade = quantidade + ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $quantidade,
                    $existente['id']
                ]);

            } else {

                $stmt = $pdo->prepare("
                    INSERT INTO bolo_insumos
                    (
                        bolo_id,
                        insumo_id,
                        quantidade
                    )
                    VALUES (?, ?, ?)
                ");

                $stmt->execute([
                    $bolo_id,
                    $insumo_id,
                    $quantidade
                ]);
            }

            header(
                "Location: composicao_bolo.php?id="
                . $bolo_id
                . "&sucesso=insumo"
            );

            exit;
        }
    }
}


/*
|--------------------------------------------------------------------------
| MENSAGEM DE SUCESSO
|--------------------------------------------------------------------------
*/

if (isset($_GET['sucesso'])) {

    if ($_GET['sucesso'] === 'insumo') {
        $mensagem = "Insumo adicionado ao bolo com sucesso.";
    }
}


/*
|--------------------------------------------------------------------------
| REMOVER INSUMO DIRETO
|--------------------------------------------------------------------------
*/

if (isset($_GET['remover_insumo'])) {

    $bolo_insumo_id = (int) $_GET['remover_insumo'];

    $stmt = $pdo->prepare("
        DELETE bi
        FROM bolo_insumos bi

        INNER JOIN insumos i
            ON i.id = bi.insumo_id

        WHERE bi.id = ?
          AND bi.bolo_id = ?
          AND i.confeiteira_id = ?
    ");

    $stmt->execute([
        $bolo_insumo_id,
        $bolo_id,
        $confeiteira_id
    ]);

    header(
        "Location: composicao_bolo.php?id="
        . $bolo_id
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| REMOVER RECEITA DO BOLO
|--------------------------------------------------------------------------
*/

if (isset($_GET['remover_receita'])) {

    $bolo_receita_id = (int) $_GET['remover_receita'];

    $stmt = $pdo->prepare("
        DELETE br
        FROM bolo_receitas br

        INNER JOIN receitas r
            ON r.id = br.receita_id

        WHERE br.id = ?
          AND br.bolo_id = ?
          AND r.confeiteira_id = ?
    ");

    $stmt->execute([
        $bolo_receita_id,
        $bolo_id,
        $confeiteira_id
    ]);

    header(
        "Location: composicao_bolo.php?id="
        . $bolo_id
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| INSUMOS DISPONÍVEIS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        tipo,
        unidade,
        custo_unitario
    FROM insumos
    WHERE confeiteira_id = ?
      AND ativo = 1
    ORDER BY nome ASC
");

$stmt->execute([$confeiteira_id]);

$insumos = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| RECEITAS DISPONÍVEIS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.nome,
        r.tipo,
        r.rendimento,
        r.unidade_rendimento,

        COALESCE(
            (
                SELECT SUM(
                    ri.quantidade * i.custo_unitario
                )
                FROM receita_insumos ri

                INNER JOIN insumos i
                    ON i.id = ri.insumo_id

                WHERE ri.receita_id = r.id
            ),
            0
        ) AS custo_receita

    FROM receitas r

    WHERE r.confeiteira_id = ?
      AND r.ativo = 1

    ORDER BY
        FIELD(
            r.tipo,
            'massa',
            'recheio',
            'cobertura',
            'decoracao',
            'outros'
        ),
        r.nome ASC
");

$stmt->execute([$confeiteira_id]);

$receitas = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| RECEITAS JÁ ADICIONADAS AO BOLO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        br.id,
        br.bolo_id,
        br.receita_id,
        br.quantidade,
        br.unidade_utilizacao,
        br.ordem,

        r.nome,
        r.tipo,
        r.rendimento,
        r.unidade_rendimento,

        COALESCE(
            (
                SELECT SUM(
                    ri.quantidade * i.custo_unitario
                )
                FROM receita_insumos ri

                INNER JOIN insumos i
                    ON i.id = ri.insumo_id

                WHERE ri.receita_id = r.id
            ),
            0
        ) AS custo_receita

    FROM bolo_receitas br

    INNER JOIN receitas r
        ON r.id = br.receita_id

    WHERE br.bolo_id = ?
      AND r.confeiteira_id = ?

    ORDER BY
        FIELD(
            r.tipo,
            'massa',
            'recheio',
            'cobertura',
            'decoracao',
            'outros'
        ),
        br.ordem ASC,
        r.nome ASC
");

$stmt->execute([
    $bolo_id,
    $confeiteira_id
]);

$receitas_bolo = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| INSUMOS DIRETOS JÁ ADICIONADOS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        bi.id,
        bi.quantidade,

        i.nome,
        i.tipo,
        i.unidade,
        i.custo_unitario,

        (
            bi.quantidade * i.custo_unitario
        ) AS custo_total

    FROM bolo_insumos bi

    INNER JOIN insumos i
        ON i.id = bi.insumo_id

    WHERE bi.bolo_id = ?
      AND i.confeiteira_id = ?

    ORDER BY i.nome ASC
");

$stmt->execute([
    $bolo_id,
    $confeiteira_id
]);

$insumos_bolo = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| CÁLCULO DO CUSTO DAS RECEITAS
|--------------------------------------------------------------------------
|
| IMPORTANTE:
|
| O banco pode guardar:
|
| rendimento = 1 kg
| utilização = 500 g
|
| Neste caso:
|
| 500 g = 0,5 kg
|
| Portanto:
|
| custo utilizado = custo da receita × 0,5
|
|--------------------------------------------------------------------------
*/

$custo_receitas = 0;

foreach ($receitas_bolo as &$receita) {

    $custo_receita = (float) $receita['custo_receita'];

    $quantidade = (float) $receita['quantidade'];

    $rendimento = (float) $receita['rendimento'];

    $unidade_utilizacao =
        $receita['unidade_utilizacao']
        ??
        $receita['unidade_rendimento']
        ??
        '';

    $unidade_rendimento =
        $receita['unidade_rendimento']
        ??
        '';


    /*
    |--------------------------------------------------------------
    | Receita com rendimento
    |--------------------------------------------------------------
    */

    if (
        $rendimento > 0
        &&
        $unidade_rendimento !== ''
        &&
        $unidade_utilizacao !== ''
    ) {

        $quantidade_convertida = converterQuantidade(
            $quantidade,
            $unidade_utilizacao,
            $unidade_rendimento
        );

        /*
        |----------------------------------------------------------
        | Conversão válida
        |----------------------------------------------------------
        */

        if ($quantidade_convertida !== null) {

            $proporcao =
                $quantidade_convertida /
                $rendimento;

            $custo_utilizado =
                $custo_receita *
                $proporcao;

            $percentual_receita =
                $proporcao * 100;

            $receita['quantidade_convertida'] =
                $quantidade_convertida;

        } else {

            /*
            |------------------------------------------------------
            | Caso a unidade antiga não seja compatível
            |------------------------------------------------------
            |
            | Mantemos o cálculo antigo para não quebrar registros
            | já existentes.
            |
            */

            $custo_utilizado =
                $custo_receita *
                (
                    $quantidade /
                    $rendimento
                );

            $percentual_receita =
                (
                    $quantidade /
                    $rendimento
                ) * 100;

            $receita['quantidade_convertida'] =
                null;
        }

    } elseif ($rendimento > 0) {

        /*
        |----------------------------------------------------------
        | Rendimento existe, mas unidade não está preenchida.
        |----------------------------------------------------------
        */

        $custo_utilizado =
            $custo_receita *
            (
                $quantidade /
                $rendimento
            );

        $percentual_receita =
            (
                $quantidade /
                $rendimento
            ) * 100;

        $receita['quantidade_convertida'] =
            null;

    } else {

        /*
        |----------------------------------------------------------
        | Receita sem rendimento
        |----------------------------------------------------------
        */

        $custo_utilizado =
            $custo_receita *
            $quantidade;

        $percentual_receita = 0;

        $receita['quantidade_convertida'] =
            null;
    }


    $receita['custo_utilizado'] =
        $custo_utilizado;

    $receita['percentual_receita'] =
        $percentual_receita;

    $receita['unidade_utilizacao_normalizada'] =
        normalizarUnidade($unidade_utilizacao);

    $receita['unidade_rendimento_normalizada'] =
        normalizarUnidade($unidade_rendimento);

    $custo_receitas += $custo_utilizado;
}

unset($receita);


/*
|--------------------------------------------------------------------------
| CUSTO DOS INSUMOS DIRETOS
|--------------------------------------------------------------------------
*/

$custo_insumos = 0;

foreach ($insumos_bolo as $item) {

    $custo_insumos +=
        (float) $item['custo_total'];
}


/*
|--------------------------------------------------------------------------
| CUSTO TOTAL
|--------------------------------------------------------------------------
*/

$custo_total =
    $custo_receitas +
    $custo_insumos;


/*
|--------------------------------------------------------------------------
| PREÇO DE VENDA
|--------------------------------------------------------------------------
*/

$preco_venda =
    (float) ($bolo['preco_venda'] ?? 0);


$resultado =
    $preco_venda -
    $custo_total;


$margem = 0;

if ($preco_venda > 0) {

    $margem =
        (
            $resultado /
            $preco_venda
        ) * 100;
}


/*
|--------------------------------------------------------------------------
| AGRUPA RECEITAS
|--------------------------------------------------------------------------
*/

$grupos = [
    'massa' => [],
    'recheio' => [],
    'cobertura' => [],
    'decoracao' => [],
    'outros' => []
];

foreach ($receitas_bolo as $receita) {

    $tipo = $receita['tipo'];

    if (!isset($grupos[$tipo])) {
        $tipo = 'outros';
    }

    $grupos[$tipo][] = $receita;
}


/*
|--------------------------------------------------------------------------
| PERCENTUAL DO CUSTO SOBRE PREÇO
|--------------------------------------------------------------------------
*/

$percentual_custo = 0;

if ($preco_venda > 0) {

    $percentual_custo =
        (
            $custo_total /
            $preco_venda
        ) * 100;
}

$percentual_barra =
    min(100, max(0, $percentual_custo));

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
        Composição -
        <?= htmlspecialchars($bolo['nome']) ?>
    </title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            background: #f4f6f8;
            margin: 0;
        }

        .container {
            max-width: 1250px;
            margin: 30px auto;
            padding: 20px;
        }

        .topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }

        .topo h1 {
            margin: 0 0 5px;
        }

        .topo p {
            margin: 0;
            color: #666;
        }

        .botao-voltar {
            text-decoration: none;
            background: #343a40;
            color: #fff;
            padding: 11px 18px;
            border-radius: 7px;
            white-space: nowrap;
        }

        .card {
            background: #fff;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .card-subtitulo {
            color: #666;
            margin-bottom: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }

        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .info-box strong {
            display: block;
            color: #666;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .info-box span {
            font-size: 18px;
            font-weight: bold;
        }

        .grid {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }

        input,
        select {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 7px;
            background: #fff;
        }

        button {
            border: none;
            cursor: pointer;
        }

        .btn {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 7px;
            text-decoration: none;
            color: #fff;
            margin-top: 10px;
        }

        .btn-primary {
            background: #198754;
        }

        .btn-primary:hover {
            background: #157347;
        }

        .btn-danger {
            background: #dc3545;
        }

        .btn-danger:hover {
            background: #bb2d3b;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-info {
            background: #0d6efd;
        }

        .btn-dark {
            background: #343a40;
        }

        .alert {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .sucesso {
            background: #d1e7dd;
            color: #0f5132;
        }

        .erro {
            background: #f8d7da;
            color: #842029;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .section-header h2 {
            margin: 0;
        }

        .section-header p {
            margin: 4px 0 0;
            color: #666;
        }

        .section-header .btn {
            margin-top: 0;
        }

        .grupo {
            margin-top: 25px;
        }

        .grupo:first-child {
            margin-top: 10px;
        }

        .grupo h3 {
            margin: 0;
            padding: 12px 15px;
            background: #f1f3f5;
            border-radius: 8px;
        }

        .grupo-titulo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .grupo-custo {
            font-size: 14px;
            color: #555;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            border-bottom: 1px solid #e9ecef;
            padding: 11px;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #fafafa;
            font-size: 13px;
        }

        td {
            font-size: 14px;
        }

        .acoes {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .acoes a {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 5px;
            color: #fff;
            text-decoration: none;
            font-size: 12px;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 5px;
            background: #e9ecef;
            font-size: 12px;
        }

        .badge-conversao {
            background: #cff4fc;
            color: #055160;
        }

        .receita-custo {
            color: #0d6efd;
            font-weight: bold;
        }

        .custo {
            color: #dc3545;
            font-weight: bold;
        }

        .resultado {
            color: #198754;
            font-weight: bold;
        }

        .resultado-negativo {
            color: #dc3545;
            font-weight: bold;
        }

        .vazio {
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            color: #666;
            text-align: center;
        }

        .resumo {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(190px, 1fr));
            gap: 15px;
        }

        .resumo-box {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }

        .resumo-box strong {
            display: block;
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
        }

        .valor {
            font-size: 24px;
            font-weight: bold;
        }

        .barra {
            margin-top: 20px;
            background: #e9ecef;
            border-radius: 20px;
            height: 18px;
            overflow: hidden;
        }

        .barra-progresso {
            height: 100%;
            background: #198754;
            border-radius: 20px;
        }

        .observacao {
            margin-top: 15px;
            padding: 13px;
            background: #fff3cd;
            color: #664d03;
            border-radius: 7px;
            font-size: 13px;
        }

        .informacao-unidade {
            margin-top: 10px;
            padding: 12px;
            background: #e7f1ff;
            color: #084298;
            border-radius: 7px;
            font-size: 13px;
        }

        .acoes-principais {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .acoes-principais .btn {
            margin-top: 0;
        }

        .destaque {
            border-left: 5px solid #198754;
        }

        .destaque-custo {
            border-left: 5px solid #dc3545;
        }

        .linha-conversao {
            background: #f8fbff;
        }

        .texto-pequeno {
            display: block;
            font-size: 12px;
            color: #777;
            margin-top: 4px;
        }

        @media (max-width: 800px) {

            .container {
                margin: 10px auto;
                padding: 10px;
            }

            .topo {
                flex-direction: column;
                align-items: flex-start;
            }

            .botao-voltar {
                width: 100%;
                text-align: center;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .acoes-principais {
                flex-direction: column;
            }

            .acoes-principais .btn {
                width: 100%;
                text-align: center;
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

        <div>

            <h1>
                🍰 Composição do Bolo
            </h1>

            <p>
                <strong>
                    <?= htmlspecialchars($bolo['nome']) ?>
                </strong>
            </p>

        </div>

        <a
            href="bolos.php"
            class="botao-voltar"
        >
            ← Voltar para bolos
        </a>

    </div>


    <!-- =========================================================
         MENSAGENS
    ========================================================== -->

    <?php if ($mensagem): ?>

        <div class="alert sucesso">

            <?= htmlspecialchars($mensagem) ?>

        </div>

    <?php endif; ?>


    <?php if ($erro): ?>

        <div class="alert erro">

            <?= htmlspecialchars($erro) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         INFORMAÇÕES DO BOLO
    ========================================================== -->

    <div class="card">

        <h2>
            🍰 <?= htmlspecialchars($bolo['nome']) ?>
        </h2>

        <p class="card-subtitulo">
            Informações cadastradas para este produto.
        </p>

        <div class="info-grid">

            <div class="info-box">

                <strong>
                    Categoria
                </strong>

                <span>
                    <?= htmlspecialchars(
                        $bolo['categoria'] ?? '-'
                    ) ?>
                </span>

            </div>


            <div class="info-box">

                <strong>
                    Tamanho
                </strong>

                <span>
                    <?= htmlspecialchars(
                        $bolo['tamanho'] ?? '-'
                    ) ?>
                </span>

            </div>


            <div class="info-box">

                <strong>
                    Rendimento
                </strong>

                <span>
                    <?= htmlspecialchars(
                        $bolo['rendimento'] ?? '-'
                    ) ?>
                </span>

            </div>


            <div class="info-box">

                <strong>
                    Preço de venda
                </strong>

                <span>

                    R$
                    <?= number_format(
                        $preco_venda,
                        2,
                        ',',
                        '.'
                    ) ?>

                </span>

            </div>

        </div>

    </div>


    <!-- =========================================================
         RESUMO RÁPIDO
    ========================================================== -->

    <div class="card destaque">

        <div class="section-header">

            <div>

                <h2>
                    📊 Visão geral da composição
                </h2>

                <p>
                    Veja rapidamente quanto as receitas e os insumos
                    representam no custo deste bolo.
                </p>

            </div>

        </div>


        <div class="resumo">

            <div class="resumo-box">

                <strong>
                    RECEITAS
                </strong>

                <div class="valor receita-custo">

                    R$
                    <?= number_format(
                        $custo_receitas,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>


            <div class="resumo-box">

                <strong>
                    INSUMOS DIRETOS
                </strong>

                <div class="valor custo">

                    R$
                    <?= number_format(
                        $custo_insumos,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>


            <div class="resumo-box">

                <strong>
                    CUSTO DE PRODUÇÃO ESTIMADO
                </strong>

                <div class="valor custo">

                    R$
                    <?= number_format(
                        $custo_total,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>


            <div class="resumo-box">

                <strong>
                    RESULTADO ESTIMADO
                </strong>

                <div
                    class="valor
                    <?= $resultado >= 0
                        ? 'resultado'
                        : 'resultado-negativo'
                    ?>"
                >

                    R$
                    <?= number_format(
                        $resultado,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         RECEITAS DO BOLO
    ========================================================== -->

    <div class="card">

        <div class="section-header">

            <div>

                <h2>
                    🧁 Receitas utilizadas no bolo
                </h2>

                <p>
                    Massa, recheios, cobertura e decoração são
                    calculados proporcionalmente ao rendimento.
                </p>

            </div>


            <a
                href="composicao_bolo_receitas.php?id=<?= $bolo_id ?>"
                class="btn btn-primary"
            >
                ➕ Adicionar / Gerenciar receitas
            </a>

        </div>


        <div class="informacao-unidade">

            <strong>
                📏 Conversão automática de unidades:
            </strong>

            o sistema entende conversões entre
            <strong>kg ↔ g ↔ mg</strong>,
            <strong>L ↔ ml</strong>
            e mantém as unidades de quantidade
            como <strong>unidade</strong>.

            <br><br>

            Exemplo:
            uma receita com rendimento de
            <strong>1 kg</strong>
            e utilização de
            <strong>500 g</strong>
            representa
            <strong>50%</strong>
            da receita.

        </div>


        <?php if (empty($receitas_bolo)): ?>

            <div class="vazio" style="margin-top:20px;">

                <strong>
                    Nenhuma receita adicionada.
                </strong>

                <br><br>

                Clique em
                <strong>
                    "Adicionar / Gerenciar receitas"
                </strong>
                para montar a composição.

            </div>

        <?php else: ?>


            <?php foreach ($grupos as $tipo => $lista): ?>

                <?php if (!empty($lista)): ?>

                    <?php

                    $custo_grupo = 0;

                    foreach ($lista as $item) {

                        $custo_grupo +=
                            (float) $item['custo_utilizado'];
                    }

                    ?>


                    <div class="grupo">

                        <div class="grupo-titulo">

                            <h3>

                                <?= iconeTipoReceita($tipo) ?>

                                <?= nomeTipoReceita($tipo) ?>

                            </h3>


                            <span class="grupo-custo">

                                Custo:

                                <strong>

                                    R$
                                    <?= number_format(
                                        $custo_grupo,
                                        2,
                                        ',',
                                        '.'
                                    ) ?>

                                </strong>

                            </span>

                        </div>


                        <table>

                            <thead>

                            <tr>

                                <th>
                                    Receita
                                </th>

                                <th>
                                    Rendimento
                                </th>

                                <th>
                                    Utilizado
                                </th>

                                <th>
                                    Conversão
                                </th>

                                <th>
                                    Custo da receita
                                </th>

                                <th>
                                    Custo no bolo
                                </th>

                                <th>
                                    Uso
                                </th>

                                <th>
                                    Ações
                                </th>

                            </tr>

                            </thead>


                            <tbody>

                            <?php foreach ($lista as $item): ?>

                                <?php

                                $unidadeUtilizacao =
                                    $item['unidade_utilizacao']
                                    ??
                                    $item['unidade_rendimento']
                                    ??
                                    '';

                                $unidadeRendimento =
                                    $item['unidade_rendimento']
                                    ??
                                    '';

                                $quantidadeConvertida =
                                    $item['quantidade_convertida']
                                    ??
                                    null;

                                ?>


                                <tr>

                                    <td>

                                        <strong>

                                            <?= htmlspecialchars(
                                                $item['nome']
                                            ) ?>

                                        </strong>

                                        <span class="texto-pequeno">

                                            <?= htmlspecialchars(
                                                nomeTipoReceita(
                                                    $item['tipo']
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?php if (
                                            (float) $item['rendimento'] > 0
                                        ): ?>

                                            <strong>

                                                <?= number_format(
                                                    $item['rendimento'],
                                                    3,
                                                    ',',
                                                    '.'
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    exibirUnidade(
                                                        $unidadeRendimento
                                                    )
                                                ) ?>

                                            </strong>

                                        <?php else: ?>

                                            <span class="badge">
                                                Não informado
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <strong>

                                            <?= number_format(
                                                $item['quantidade'],
                                                3,
                                                ',',
                                                '.'
                                            ) ?>

                                            <?= htmlspecialchars(
                                                exibirUnidade(
                                                    $unidadeUtilizacao
                                                )
                                            ) ?>

                                        </strong>

                                    </td>


                                    <td class="linha-conversao">

                                        <?php if (
                                            $quantidadeConvertida !== null
                                            &&
                                            $unidadeRendimento !== ''
                                            &&
                                            normalizarUnidade(
                                                $unidadeUtilizacao
                                            )
                                            !==
                                            normalizarUnidade(
                                                $unidadeRendimento
                                            )
                                        ): ?>

                                            <span class="badge badge-conversao">

                                                =

                                                <?= number_format(
                                                    $quantidadeConvertida,
                                                    3,
                                                    ',',
                                                    '.'
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    exibirUnidade(
                                                        $unidadeRendimento
                                                    )
                                                ) ?>

                                            </span>

                                            <span class="texto-pequeno">

                                                unidade usada no cálculo

                                            </span>

                                        <?php elseif (
                                            $quantidadeConvertida !== null
                                        ): ?>

                                            <span class="badge">

                                                <?= number_format(
                                                    $quantidadeConvertida,
                                                    3,
                                                    ',',
                                                    '.'
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    exibirUnidade(
                                                        $unidadeRendimento
                                                    )
                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="badge">
                                                Sem conversão
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        R$
                                        <?= number_format(
                                            $item['custo_receita'],
                                            2,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>


                                    <td>

                                        <strong class="receita-custo">

                                            R$
                                            <?= number_format(
                                                $item['custo_utilizado'],
                                                2,
                                                ',',
                                                '.'
                                            ) ?>

                                        </strong>

                                    </td>


                                    <td>

                                        <?php if (
                                            $item['rendimento'] > 0
                                        ): ?>

                                            <span class="badge">

                                                <?= number_format(
                                                    $item[
                                                        'percentual_receita'
                                                    ],
                                                    2,
                                                    ',',
                                                    '.'
                                                ) ?>%

                                            </span>

                                        <?php else: ?>

                                            -

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <div class="acoes">

                                            <a
                                                href="composicao_receita.php?id=<?= $item['receita_id'] ?>"
                                                class="btn-info"
                                            >
                                                Ver receita
                                            </a>


                                            <a
                                                href="composicao_bolo.php?id=<?= $bolo_id ?>&remover_receita=<?= $item['id'] ?>"
                                                class="btn-danger"
                                                onclick="return confirm('Remover esta receita do bolo?');"
                                            >
                                                Remover
                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            <?php endforeach; ?>


            <div class="acoes-principais">

                <a
                    href="composicao_bolo_receitas.php?id=<?= $bolo_id ?>"
                    class="btn btn-primary"
                >
                    ➕ Adicionar outra receita
                </a>


                <a
                    href="receitas.php"
                    class="btn btn-secondary"
                >
                    📖 Gerenciar receitas
                </a>

            </div>


        <?php endif; ?>

    </div>


    <!-- =========================================================
         INSUMOS DIRETOS
    ========================================================== -->

    <div class="card">

        <div class="section-header">

            <div>

                <h2>
                    📦 Insumos diretos do bolo
                </h2>

                <p>
                    Use esta área para itens que não fazem parte
                    de uma receita, como embalagens, decoração,
                    topo, velas e outros materiais.
                </p>

            </div>

        </div>


        <form method="POST">

            <div class="grid">

                <div>

                    <label>
                        Insumo
                    </label>

                    <select
                        name="insumo_id"
                        required
                    >

                        <option value="">
                            Selecione um insumo
                        </option>


                        <?php foreach ($insumos as $insumo): ?>

                            <option
                                value="<?= $insumo['id'] ?>"
                            >

                                <?= htmlspecialchars(
                                    $insumo['nome']
                                ) ?>

                                -
                                <?= htmlspecialchars(
                                    $insumo['tipo']
                                ) ?>

                                -
                                R$
                                <?= number_format(
                                    $insumo['custo_unitario'],
                                    4,
                                    ',',
                                    '.'
                                ) ?>

                                /
                                <?= htmlspecialchars(
                                    $insumo['unidade']
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div>

                    <label>
                        Quantidade
                    </label>

                    <input
                        type="number"
                        name="quantidade"
                        step="0.001"
                        min="0.001"
                        required
                    >

                </div>

            </div>


            <button
                type="submit"
                name="adicionar_insumo"
                class="btn btn-primary"
            >
                ➕ Adicionar insumo
            </button>

        </form>


        <?php if (empty($insumos_bolo)): ?>

            <div
                class="vazio"
                style="margin-top:20px;"
            >

                Nenhum insumo direto foi adicionado.

            </div>

        <?php else: ?>


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
                        Quantidade
                    </th>

                    <th>
                        Custo unitário
                    </th>

                    <th>
                        Custo total
                    </th>

                    <th>
                        Ações
                    </th>

                </tr>

                </thead>


                <tbody>

                <?php foreach ($insumos_bolo as $item): ?>

                    <tr>

                        <td>

                            <strong>

                                <?= htmlspecialchars(
                                    $item['nome']
                                ) ?>

                            </strong>

                        </td>


                        <td>

                            <span class="badge">

                                <?= htmlspecialchars(
                                    $item['tipo']
                                ) ?>

                            </span>

                        </td>


                        <td>

                            <?= number_format(
                                $item['quantidade'],
                                3,
                                ',',
                                '.'
                            ) ?>

                            <?= htmlspecialchars(
                                $item['unidade']
                            ) ?>

                        </td>


                        <td>

                            R$
                            <?= number_format(
                                $item['custo_unitario'],
                                4,
                                ',',
                                '.'
                            ) ?>

                        </td>


                        <td>

                            <strong class="custo">

                                R$
                                <?= number_format(
                                    $item['custo_total'],
                                    2,
                                    ',',
                                    '.'
                                ) ?>

                            </strong>

                        </td>


                        <td>

                            <a
                                href="composicao_bolo.php?id=<?= $bolo_id ?>&remover_insumo=<?= $item['id'] ?>"
                                class="btn btn-danger"
                                onclick="return confirm('Remover este insumo do bolo?');"
                            >
                                Remover
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>


    <!-- =========================================================
         RESUMO FINANCEIRO
    ========================================================== -->

    <div class="card destaque-custo">

        <div class="section-header">

            <div>

                <h2>
                    💰 Resumo financeiro do bolo
                </h2>

                <p>
                    Este cálculo considera o custo proporcional das
                    receitas e dos insumos diretamente vinculados ao bolo.
                </p>

            </div>

        </div>


        <div class="resumo">

            <div class="resumo-box">

                <strong>
                    CUSTO DAS RECEITAS
                </strong>

                <div class="valor receita-custo">

                    R$
                    <?= number_format(
                        $custo_receitas,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>


            <div class="resumo-box">

                <strong>
                    INSUMOS DIRETOS
                </strong>

                <div class="valor custo">

                    R$
                    <?= number_format(
                        $custo_insumos,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>


            <div class="resumo-box">

                <strong>
                    CUSTO DE PRODUÇÃO ESTIMADO
                </strong>

                <div class="valor custo">

                    R$
                    <?= number_format(
                        $custo_total,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>


            <div class="resumo-box">

                <strong>
                    PREÇO DE VENDA
                </strong>

                <div class="valor">

                    R$
                    <?= number_format(
                        $preco_venda,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>


            <div class="resumo-box">

                <strong>
                    RESULTADO ANTES DAS OUTRAS DESPESAS
                </strong>

                <div
                    class="valor
                    <?= $resultado >= 0
                        ? 'resultado'
                        : 'resultado-negativo'
                    ?>"
                >

                    R$
                    <?= number_format(
                        $resultado,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>


            <div class="resumo-box">

                <strong>
                    MARGEM SOBRE O PREÇO
                </strong>

                <div
                    class="valor
                    <?= $margem >= 0
                        ? 'resultado'
                        : 'resultado-negativo'
                    ?>"
                >

                    <?= number_format(
                        $margem,
                        2,
                        ',',
                        '.'
                    ) ?>%

                </div>

            </div>

        </div>


        <?php if ($preco_venda > 0): ?>

            <div class="barra">

                <div
                    class="barra-progresso"
                    style="width: <?= $percentual_barra ?>%;"
                ></div>

            </div>


            <div
                style="
                    margin-top:8px;
                    font-size:13px;
                    color:#666;
                "
            >

                O custo estimado representa

                <strong>

                    <?= number_format(
                        $percentual_custo,
                        2,
                        ',',
                        '.'
                    ) ?>%

                </strong>

                do preço de venda.

            </div>

        <?php endif; ?>


        <div class="observacao">

            <strong>
                ⚠️ Atenção:
            </strong>

            o resultado apresentado é um
            <strong>
                resultado bruto estimado
            </strong>
            antes de outras despesas, como mão de obra,
            gás, energia elétrica, taxas de cartão,
            entrega, perdas, impostos e despesas administrativas.

        </div>

    </div>


    <!-- =========================================================
         AÇÕES FINAIS
    ========================================================== -->

    <div class="card">

        <h2>
            ⚙️ Gerenciamento
        </h2>


        <div class="acoes-principais">

            <a
                href="composicao_bolo_receitas.php?id=<?= $bolo_id ?>"
                class="btn btn-primary"
            >
                🧁 Gerenciar receitas
            </a>


            <a
                href="composicao_bolo.php?id=<?= $bolo_id ?>"
                class="btn btn-secondary"
            >
                🔄 Atualizar composição
            </a>


            <a
                href="bolos.php"
                class="btn btn-dark"
            >
                ← Voltar para bolos
            </a>

        </div>

    </div>

</div>

</body>

</html>