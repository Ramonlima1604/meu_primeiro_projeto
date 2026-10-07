<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");


/*
|--------------------------------------------------------------------------
| CONFEITEIRA LOGADA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$_SESSION["usuario_id"]]);

$confeiteira = $stmt->fetch();

if (!$confeiteira) {
    die("Confeiteira não encontrada.");
}

$confeiteiraId = $confeiteira["id"];


/*
|--------------------------------------------------------------------------
| VARIÁVEIS
|--------------------------------------------------------------------------
*/

$nome = "";
$tipo = "ingrediente";
$unidade = "ml";
$quantidadeEmbalagem = "";
$precoEmbalagem = "";
$estoque = "";
$estoqueMinimo = "";

$erro = "";


/*
|--------------------------------------------------------------------------
| TIPOS
|--------------------------------------------------------------------------
*/

$tipos = [
    "ingrediente" => "Ingrediente",
    "material" => "Material",
    "embalagem" => "Embalagem",
    "decoracao" => "Decoração",
    "outros" => "Outros"
];


/*
|--------------------------------------------------------------------------
| UNIDADES
|--------------------------------------------------------------------------
*/

$unidades = [
    "ml" => "Mililitro (ml)",
    "L"  => "Litro (L)",
    "g"  => "Grama (g)",
    "kg" => "Quilograma (kg)",
    "un" => "Unidade (un)"
];


/*
|--------------------------------------------------------------------------
| CADASTRAR
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome = trim($_POST["nome"] ?? "");
    $tipo = trim($_POST["tipo"] ?? "ingrediente");
    $unidade = trim($_POST["unidade"] ?? "ml");

    $quantidadeEmbalagem = str_replace(
        ",",
        ".",
        trim($_POST["quantidade_embalagem"] ?? "0")
    );

    $precoEmbalagem = str_replace(
        ",",
        ".",
        trim($_POST["preco_embalagem"] ?? "0")
    );

    $estoque = str_replace(
        ",",
        ".",
        trim($_POST["estoque"] ?? "0")
    );

    $estoqueMinimo = str_replace(
        ",",
        ".",
        trim($_POST["estoque_minimo"] ?? "0")
    );


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if ($nome === "") {

        $erro = "Informe o nome do item.";

    } elseif (!isset($tipos[$tipo])) {

        $erro = "Selecione um tipo válido.";

    } elseif (!isset($unidades[$unidade])) {

        $erro = "Selecione uma unidade de medida válida.";

    } elseif (
        !is_numeric($quantidadeEmbalagem) ||
        (float) $quantidadeEmbalagem < 0
    ) {

        $erro = "Informe uma quantidade de embalagem válida.";

    } elseif (
        !is_numeric($precoEmbalagem) ||
        (float) $precoEmbalagem < 0
    ) {

        $erro = "Informe um preço de embalagem válido.";

    } elseif (
        !is_numeric($estoque) ||
        (float) $estoque < 0
    ) {

        $erro = "Informe um estoque válido.";

    } elseif (
        !is_numeric($estoqueMinimo) ||
        (float) $estoqueMinimo < 0
    ) {

        $erro = "Informe um estoque mínimo válido.";

    } else {

        $quantidadeEmbalagem = (float) $quantidadeEmbalagem;
        $precoEmbalagem = (float) $precoEmbalagem;
        $estoque = (float) $estoque;
        $estoqueMinimo = (float) $estoqueMinimo;


        /*
        |--------------------------------------------------------------------------
        | CUSTO POR UNIDADE CADASTRADA
        |--------------------------------------------------------------------------
        */

        if ($quantidadeEmbalagem > 0) {

            $custoUnitario =
                $precoEmbalagem / $quantidadeEmbalagem;

        } else {

            $custoUnitario = 0;

        }


        /*
        |--------------------------------------------------------------------------
        | CADASTRO
        |--------------------------------------------------------------------------
        */

        try {

            $stmt = $pdo->prepare("
                INSERT INTO insumos (
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
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
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

            header("Location: estoque.php");
            exit;

        } catch (PDOException $e) {

            $erro = "Não foi possível cadastrar o item. Verifique os dados e tente novamente.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Adicionar item | Estoque</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f5f6fa;
    color: #222;
}

a {
    text-decoration: none;
    color: inherit;
}


/* MENU */

.menu {
    background: #222;
    color: white;
    padding: 16px 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.logo {
    font-size: 20px;
    font-weight: bold;
}

.menu-links {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.menu-links a {
    padding: 9px 12px;
    border-radius: 7px;
    font-size: 14px;
    color: #ddd;
}

.menu-links a:hover {
    background: #333;
    color: white;
}

.menu-links a.ativo {
    background: white;
    color: #222;
}


/* CONTAINER */

.container {
    width: 95%;
    max-width: 900px;
    margin: 30px auto;
}


/* CABEÇALHO */

.topo {
    margin-bottom: 25px;
}

.topo h1 {
    margin: 0 0 7px;
    font-size: 30px;
}

.topo p {
    margin: 0;
    color: #666;
}


/* FORMULÁRIO */

.card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.campo {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.campo.completo {
    grid-column: span 2;
}

.campo label {
    font-weight: bold;
    font-size: 14px;
    color: #444;
}

.campo small {
    color: #777;
    font-size: 12px;
}

.campo input,
.campo select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 15px;
    background: white;
}

.campo input:focus,
.campo select:focus {
    outline: none;
    border-color: #555;
}


/* PREVIEW */

.preview {
    margin-top: 8px;
    padding: 10px 12px;
    background: #f4f8ff;
    border-radius: 7px;
    color: #555;
    font-size: 13px;
}

.preview strong {
    color: #222;
}


/* EXPLICAÇÃO */

.info {
    background: #f7f7f7;
    border-radius: 9px;
    padding: 15px;
    margin: 25px 0;
    color: #555;
    font-size: 14px;
    line-height: 1.5;
}

.info strong {
    color: #222;
}


/* ERRO */

.erro {
    background: #fdecec;
    color: #b42318;
    border: 1px solid #f5c2c0;
    padding: 13px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}


/* BOTÕES */

.botoes {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 25px;
}

.btn {
    border: none;
    border-radius: 8px;
    padding: 12px 18px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
}

.btn-cancelar {
    background: #eee;
    color: #333;
}

.btn-cancelar:hover {
    background: #ddd;
}

.btn-salvar {
    background: #222;
    color: white;
}

.btn-salvar:hover {
    background: #000;
}


/* RESPONSIVO */

@media (max-width: 700px) {

    .menu {
        padding: 15px;
    }

    .menu-links {
        width: 100%;
        overflow-x: auto;
    }

    .container {
        width: 92%;
        margin-top: 20px;
    }

    .card {
        padding: 20px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .campo.completo {
        grid-column: auto;
    }

    .botoes {
        flex-direction: column-reverse;
    }

    .botoes .btn {
        width: 100%;
    }

}

</style>

</head>

<body>


<!-- MENU -->

<div class="menu">

    <div class="logo">
        <?= htmlspecialchars($confeiteira["nome_confeitaria"]) ?>
    </div>

    <div class="menu-links">

        <a href="index.php">
            🏠 Início
        </a>

        <a href="produtos.php">
            🍰 Produtos
        </a>

        <a href="estoque.php" class="ativo">
            📦 Estoque
        </a>

        <a href="clientes.php">
            👥 Clientes
        </a>

        <a href="pedidos.php">
            🛒 Pedidos
        </a>

        <a href="financeiro/index.php">
            💰 Financeiro
        </a>

        <a href="../logout.php">
            🚪 Sair
        </a>

    </div>

</div>


<div class="container">


    <div class="topo">

        <h1>📦 Adicionar item ao estoque</h1>

        <p>
            Cadastre ingredientes, embalagens, materiais e outros itens utilizados na sua confeitaria.
        </p>

    </div>


    <?php if ($erro !== ""): ?>

        <div class="erro">
            <?= htmlspecialchars($erro) ?>
        </div>

    <?php endif; ?>


    <div class="card">

        <form method="POST">

            <div class="form-grid">


                <!-- NOME -->

                <div class="campo completo">

                    <label for="nome">
                        Nome do item *
                    </label>

                    <input
                        type="text"
                        id="nome"
                        name="nome"
                        value="<?= htmlspecialchars($nome) ?>"
                        placeholder="Ex.: Farinha de trigo"
                        maxlength="150"
                        required
                    >

                </div>


                <!-- TIPO -->

                <div class="campo">

                    <label for="tipo">
                        Tipo *
                    </label>

                    <select id="tipo" name="tipo" required>

                        <?php foreach ($tipos as $valor => $nomeTipo): ?>

                            <option
                                value="<?= htmlspecialchars($valor) ?>"
                                <?= $tipo === $valor ? "selected" : "" ?>
                            >
                                <?= htmlspecialchars($nomeTipo) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- UNIDADE -->

                <div class="campo">

                    <label for="unidade">
                        Unidade de medida *
                    </label>

                    <select id="unidade" name="unidade" required>

                        <?php foreach ($unidades as $valor => $nomeUnidade): ?>

                            <option
                                value="<?= htmlspecialchars($valor) ?>"
                                <?= $unidade === $valor ? "selected" : "" ?>
                            >
                                <?= htmlspecialchars($nomeUnidade) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <small>
                        O sistema converte automaticamente ml ↔ L e g ↔ kg na exibição.
                    </small>

                </div>


                <!-- QUANTIDADE EMBALAGEM -->

                <div class="campo">

                    <label for="quantidade_embalagem">
                        Quantidade por embalagem
                    </label>

                    <input
                        type="number"
                        id="quantidade_embalagem"
                        name="quantidade_embalagem"
                        value="<?= htmlspecialchars($quantidadeEmbalagem) ?>"
                        placeholder="Ex.: 1000"
                        min="0"
                        step="0.001"
                    >

                    <div class="preview" id="previewEmbalagem">
                        Informe a quantidade para visualizar a conversão.
                    </div>

                </div>


                <!-- PREÇO -->

                <div class="campo">

                    <label for="preco_embalagem">
                        Preço da embalagem
                    </label>

                    <input
                        type="number"
                        id="preco_embalagem"
                        name="preco_embalagem"
                        value="<?= htmlspecialchars($precoEmbalagem) ?>"
                        placeholder="R$ 0,00"
                        min="0"
                        step="0.01"
                    >

                    <small>
                        Quanto você paga pela embalagem.
                    </small>

                </div>


                <!-- ESTOQUE -->

                <div class="campo">

                    <label for="estoque">
                        Estoque atual *
                    </label>

                    <input
                        type="number"
                        id="estoque"
                        name="estoque"
                        value="<?= htmlspecialchars($estoque) ?>"
                        placeholder="Ex.: 2000"
                        min="0"
                        step="0.001"
                        required
                    >

                    <div class="preview" id="previewEstoque">
                        Informe o estoque para visualizar a conversão.
                    </div>

                </div>


                <!-- ESTOQUE MÍNIMO -->

                <div class="campo">

                    <label for="estoque_minimo">
                        Estoque mínimo
                    </label>

                    <input
                        type="number"
                        id="estoque_minimo"
                        name="estoque_minimo"
                        value="<?= htmlspecialchars($estoqueMinimo) ?>"
                        placeholder="Ex.: 1000"
                        min="0"
                        step="0.001"
                    >

                    <div class="preview" id="previewMinimo">
                        Informe o mínimo para visualizar a conversão.
                    </div>

                </div>

            </div>


            <div class="info">

                <strong>💡 Como funciona?</strong>

                <br><br>

                O sistema mantém a quantidade cadastrada para preservar a precisão dos cálculos.

                Na tela de estoque, valores equivalentes são apresentados automaticamente.

                <br><br>

                <strong>Exemplo:</strong>

                Se você cadastrar:

                <strong>1.000 ml</strong>

                o sistema reconhecerá como:

                <strong>1 L.</strong>

                <br><br>

                Da mesma forma:

                <strong>1.000 g = 1 kg.</strong>

            </div>


            <div class="botoes">

                <a
                    href="estoque.php"
                    class="btn btn-cancelar"
                >
                    Cancelar
                </a>

                <button
                    type="submit"
                    class="btn btn-salvar"
                >
                    💾 Salvar item
                </button>

            </div>

        </form>

    </div>

</div>


<script>

function formatarNumero(valor) {

    return Number(valor).toLocaleString("pt-BR", {
        maximumFractionDigits: 3
    });

}


function converter(valor, unidade) {

    valor = Number(valor);

    if (!Number.isFinite(valor) || valor <= 0) {
        return "";
    }

    if (unidade === "ml" && valor >= 1000) {

        return formatarNumero(valor / 1000) + " L";

    }

    if (unidade === "g" && valor >= 1000) {

        return formatarNumero(valor / 1000) + " kg";

    }

    return formatarNumero(valor) + " " + unidade;
}


function atualizarConversoes() {

    const unidade =
        document.getElementById("unidade").value;

    const embalagem =
        document.getElementById("quantidade_embalagem").value;

    const estoque =
        document.getElementById("estoque").value;

    const minimo =
        document.getElementById("estoque_minimo").value;


    document.getElementById("previewEmbalagem").innerHTML =
        embalagem
            ? "<strong>Equivalente:</strong> " +
              converter(embalagem, unidade)
            : "Informe a quantidade para visualizar a conversão.";


    document.getElementById("previewEstoque").innerHTML =
        estoque
            ? "<strong>Equivalente:</strong> " +
              converter(estoque, unidade)
            : "Informe o estoque para visualizar a conversão.";


    document.getElementById("previewMinimo").innerHTML =
        minimo
            ? "<strong>Equivalente:</strong> " +
              converter(minimo, unidade)
            : "Informe o mínimo para visualizar a conversão.";
}


document
    .getElementById("unidade")
    .addEventListener("change", atualizarConversoes);

document
    .getElementById("quantidade_embalagem")
    .addEventListener("input", atualizarConversoes);

document
    .getElementById("estoque")
    .addEventListener("input", atualizarConversoes);

document
    .getElementById("estoque_minimo")
    .addEventListener("input", atualizarConversoes);

atualizarConversoes();

</script>

</body>

</html>