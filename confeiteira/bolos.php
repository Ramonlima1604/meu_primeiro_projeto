<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");


/*
|--------------------------------------------------------------------------
| IDENTIFICAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([
    $_SESSION["usuario_id"]
]);

$confeiteiraId = $stmt->fetchColumn();

if (!$confeiteiraId) {
    die("Confeiteira não encontrada.");
}


/*
|--------------------------------------------------------------------------
| ATIVAR / DESATIVAR BOLO
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["acao"]) &&
    isset($_GET["id"])
) {

    $id = (int) $_GET["id"];

    if ($id > 0) {

        if ($_GET["acao"] === "desativar") {

            $stmt = $pdo->prepare("
                UPDATE bolos

                SET disponivel = 0

                WHERE id = ?

                AND confeiteira_id = ?
            ");

            $stmt->execute([
                $id,
                $confeiteiraId
            ]);
        }


        if ($_GET["acao"] === "ativar") {

            $stmt = $pdo->prepare("
                UPDATE bolos

                SET disponivel = 1

                WHERE id = ?

                AND confeiteira_id = ?
            ");

            $stmt->execute([
                $id,
                $confeiteiraId
            ]);
        }
    }

    header("Location: bolos.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR BOLOS
|
| O custo do bolo pode vir de duas fontes:
|
| 1. Insumos diretamente vinculados ao bolo
| 2. Receitas vinculadas ao bolo
|
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        b.*,

        COALESCE(insumos.custo_insumos, 0) AS custo_insumos,

        COALESCE(receitas.custo_receitas, 0) AS custo_receitas

    FROM bolos b


    /*
    |--------------------------------------------------------------------------
    | CUSTO DOS INSUMOS DIRETOS
    |--------------------------------------------------------------------------
    */

    LEFT JOIN (

        SELECT
            bi.bolo_id,

            SUM(
                bi.quantidade * i.custo_unitario
            ) AS custo_insumos

        FROM bolo_insumos bi

        INNER JOIN insumos i
            ON i.id = bi.insumo_id

        GROUP BY bi.bolo_id

    ) AS insumos

        ON insumos.bolo_id = b.id


    /*
    |--------------------------------------------------------------------------
    | CUSTO DAS RECEITAS
    |--------------------------------------------------------------------------
    |
    | Para cada receita usada no bolo:
    |
    | quantidade da receita no bolo
    | ×
    | quantidade do insumo na receita
    | ×
    | custo unitário do insumo
    |
    */

    LEFT JOIN (

        SELECT
            br.bolo_id,

            SUM(
                br.quantidade
                * ri.quantidade
                * i.custo_unitario
            ) AS custo_receitas

        FROM bolo_receitas br

        INNER JOIN receitas r
            ON r.id = br.receita_id

        INNER JOIN receita_insumos ri
            ON ri.receita_id = r.id

        INNER JOIN insumos i
            ON i.id = ri.insumo_id

        WHERE r.confeiteira_id = ?

        GROUP BY br.bolo_id

    ) AS receitas

        ON receitas.bolo_id = b.id


    /*
    |--------------------------------------------------------------------------
    | SOMENTE BOLOS DA CONFEITEIRA LOGADA
    |--------------------------------------------------------------------------
    */

    WHERE b.confeiteira_id = ?

    ORDER BY b.id DESC
");


$stmt->execute([
    $confeiteiraId,
    $confeiteiraId
]);

$bolos = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| CALCULAR CUSTOS
|--------------------------------------------------------------------------
*/

foreach ($bolos as &$bolo) {

    $custoInsumos = (float) $bolo["custo_insumos"];

    $custoReceitas = (float) $bolo["custo_receitas"];

    $bolo["custo_total"] =
        $custoInsumos +
        $custoReceitas;
}

unset($bolo);

?>

<!DOCTYPE html>

<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Meus Bolos
    </title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

</head>

<body>

<div class="admin-container">


    <!--
    |--------------------------------------------------------------------------
    | TOPO
    |--------------------------------------------------------------------------
    -->

    <header class="topo">

        <div>

            <h1>
                Meus Bolos
            </h1>

            <p>
                Cadastro e composição dos produtos
            </p>

        </div>


        <a href="index.php">
            Dashboard
        </a>

    </header>


    <main>


        <!--
        |--------------------------------------------------------------------------
        | AÇÕES
        |--------------------------------------------------------------------------
        -->

        <div class="pagina-acoes">

            <a
                href="cadastrar_bolo.php"
                class="botao"
            >
                + Novo Bolo
            </a>

        </div>


        <!--
        |--------------------------------------------------------------------------
        | TABELA
        |--------------------------------------------------------------------------
        -->

        <div class="tabela-container">

            <table>

                <thead>

                    <tr>

                        <th>
                            Bolo
                        </th>

                        <th>
                            Categoria
                        </th>

                        <th>
                            Tamanho
                        </th>

                        <th>
                            Preço
                        </th>

                        <th>
                            Custo
                        </th>

                        <th>
                            Resultado
                        </th>

                        <th>
                            Margem
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Ações
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach ($bolos as $bolo): ?>

                    <?php

                    $preco =
                        (float) $bolo["preco_venda"];

                    $custo =
                        (float) $bolo["custo_total"];

                    $resultado =
                        $preco - $custo;

                    $margem = 0;

                    if ($preco > 0) {

                        $margem =
                            ($resultado / $preco) * 100;
                    }

                    ?>


                    <tr>


                        <!-- BOLO -->

                        <td>

                            <strong>

                                <?= htmlspecialchars(
                                    $bolo["nome"]
                                ) ?>

                            </strong>

                        </td>


                        <!-- CATEGORIA -->

                        <td>

                            <?= htmlspecialchars(
                                $bolo["categoria"]
                            ) ?>

                        </td>


                        <!-- TAMANHO -->

                        <td>

                            <?= htmlspecialchars(
                                $bolo["tamanho"]
                            ) ?>

                        </td>


                        <!-- PREÇO -->

                        <td>

                            R$

                            <?= number_format(
                                $preco,
                                2,
                                ",",
                                "."
                            ) ?>

                        </td>


                        <!-- CUSTO -->

                        <td>

                            R$

                            <?= number_format(
                                $custo,
                                2,
                                ",",
                                "."
                            ) ?>

                        </td>


                        <!-- RESULTADO -->

                        <td>

                            R$

                            <?= number_format(
                                $resultado,
                                2,
                                ",",
                                "."
                            ) ?>

                        </td>


                        <!-- MARGEM -->

                        <td>

                            <?= number_format(
                                $margem,
                                2,
                                ",",
                                "."
                            ) ?>%

                        </td>


                        <!-- STATUS -->

                        <td>

                            <?php if (
                                $bolo["disponivel"] == 1
                            ): ?>

                                <span class="status ativo">
                                    Disponível
                                </span>

                            <?php else: ?>

                                <span class="status inativo">
                                    Indisponível
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- AÇÕES -->

                        <td>

                            <a
                                href="editar_bolo.php?id=<?= (int) $bolo["id"] ?>"
                                class="acao"
                            >
                                Editar
                            </a>


                            <a
                                href="composicao_bolo.php?id=<?= (int) $bolo["id"] ?>"
                                class="acao"
                            >
                                Composição
                            </a>


                            <br>


                            <a
                                href="composicao_bolo_receitas.php?id=<?= (int) $bolo["id"] ?>"
                                class="acao"
                            >
                                Receitas
                            </a>


                            <?php if (
                                $bolo["disponivel"] == 1
                            ): ?>

                                <a
                                    href="bolos.php?acao=desativar&id=<?= (int) $bolo["id"] ?>"
                                    class="acao perigo"
                                    onclick="return confirm('Deseja desativar este bolo?')"
                                >
                                    Desativar
                                </a>

                            <?php else: ?>

                                <a
                                    href="bolos.php?acao=ativar&id=<?= (int) $bolo["id"] ?>"
                                    class="acao sucesso"
                                >
                                    Ativar
                                </a>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (
                    count($bolos) === 0
                ): ?>

                    <tr>

                        <td colspan="9">

                            Nenhum bolo cadastrado.

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </main>

</div>

</body>

</html>