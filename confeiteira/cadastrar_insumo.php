<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");


/*
|--------------------------------------------------------------------------
| ID DA CONFEITEIRA
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


$erro = "";


/*
|--------------------------------------------------------------------------
| SALVAR
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome = trim($_POST["nome"]);

    $tipo = $_POST["tipo"];

    $unidade = trim($_POST["unidade"]);

    $quantidadeEmbalagem =
        (float) $_POST["quantidade_embalagem"];

    $precoEmbalagem =
        (float) $_POST["preco_embalagem"];

    $estoque =
        (float) $_POST["estoque"];

    $estoqueMinimo =
        (float) $_POST["estoque_minimo"];


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    $tiposPermitidos = [
        "ingrediente",
        "material",
        "embalagem",
        "decoracao",
        "outros"
    ];


    if ($nome === "") {

        $erro =
            "Digite o nome do insumo.";

    } elseif (
        !in_array(
            $tipo,
            $tiposPermitidos,
            true
        )
    ) {

        $erro =
            "Tipo de insumo inválido.";

    } elseif ($unidade === "") {

        $erro =
            "Digite a unidade.";

    } elseif (
        $quantidadeEmbalagem <= 0
    ) {

        $erro =
            "A quantidade da embalagem deve ser maior que zero.";

    } elseif (
        $precoEmbalagem < 0
    ) {

        $erro =
            "O preço não pode ser negativo.";

    } elseif (
        $estoque < 0 ||
        $estoqueMinimo < 0
    ) {

        $erro =
            "O estoque não pode ser negativo.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | CÁLCULO DO CUSTO UNITÁRIO
        |--------------------------------------------------------------------------
        */

        $custoUnitario =
            $precoEmbalagem
            / $quantidadeEmbalagem;


        /*
        |--------------------------------------------------------------------------
        | INSERIR
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO insumos
            (
                confeiteira_id,
                nome,
                tipo,
                unidade,
                quantidade_embalagem,
                preco_embalagem,
                custo_unitario,
                estoque,
                estoque_minimo,
                ativo
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                1
            )
        ");


        $stmt->execute([

            $confeiteiraId,

            $nome,

            $tipo,

            $unidade,

            $quantidadeEmbalagem,

            $precoEmbalagem,

            $custoUnitario,

            $estoque,

            $estoqueMinimo

        ]);


        header(
            "Location: insumos.php"
        );

        exit;
    }
}

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
        Novo Insumo
    </title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

</head>

<body>

<div class="admin-container">


    <header class="topo">

        <div>

            <h1>
                Novo Insumo
            </h1>

            <p>
                Cadastrar ingrediente ou material
            </p>

        </div>


        <a href="insumos.php">
            Voltar
        </a>

    </header>


    <main>


        <div class="formulario-container">


            <?php if ($erro): ?>

                <div class="erro">

                    <?= htmlspecialchars($erro) ?>

                </div>

            <?php endif; ?>


            <form method="POST">


                <label>
                    Nome do insumo *
                </label>

                <input
                    type="text"
                    name="nome"
                    placeholder="Ex: Farinha de trigo"
                    required
                >


                <label>
                    Tipo *
                </label>

                <select
                    name="tipo"
                    required
                >

                    <option value="">
                        Selecione
                    </option>

                    <option value="ingrediente">
                        Ingrediente
                    </option>

                    <option value="material">
                        Material
                    </option>

                    <option value="embalagem">
                        Embalagem
                    </option>

                    <option value="decoracao">
                        Decoração
                    </option>

                    <option value="outros">
                        Outros
                    </option>

                </select>


                <label>
                    Unidade *
                </label>

                <select
                    name="unidade"
                    required
                >

                    <option value="">
                        Selecione
                    </option>

                    <option value="g">
                        Gramas (g)
                    </option>

                    <option value="kg">
                        Quilograma (kg)
                    </option>

                    <option value="ml">
                        Mililitros (ml)
                    </option>

                    <option value="l">
                        Litros (L)
                    </option>

                    <option value="un">
                        Unidade (un)
                    </option>

                    <option value="m">
                        Metro (m)
                    </option>

                </select>


                <label>
                    Quantidade da embalagem *
                </label>

                <input
                    type="number"
                    name="quantidade_embalagem"
                    step="0.001"
                    min="0.001"
                    placeholder="Ex: 1000"
                    required
                >

                <small>
                    Exemplo: um pacote de farinha com 1.000 g = 1000.
                </small>


                <label>
                    Preço da embalagem *
                </label>

                <input
                    type="number"
                    name="preco_embalagem"
                    step="0.01"
                    min="0"
                    placeholder="Ex: 5.90"
                    required
                >


                <label>
                    Estoque atual
                </label>

                <input
                    type="number"
                    name="estoque"
                    step="0.001"
                    min="0"
                    value="0"
                >

                <small>
                    Informe a quantidade disponível usando a mesma unidade.
                </small>


                <label>
                    Estoque mínimo
                </label>

                <input
                    type="number"
                    name="estoque_minimo"
                    step="0.001"
                    min="0"
                    value="0"
                >

                <small>
                    Quando o estoque chegar neste valor,
                    o sistema mostrará alerta.
                </small>


                <br><br>


                <button
                    type="submit"
                    class="botao"
                >
                    Cadastrar Insumo
                </button>


            </form>

        </div>

    </main>

</div>

</body>

</html>