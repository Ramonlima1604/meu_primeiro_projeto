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
| ATIVAR / DESATIVAR PRODUTO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $acao = $_POST["acao"] ?? "";
    $id = (int) ($_POST["id"] ?? 0);

    if (
        $id > 0 &&
        in_array($acao, ["ativar", "desativar"], true)
    ) {

        $novoStatus = $acao === "ativar" ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE bolos
            SET disponivel = ?
            WHERE id = ?
              AND confeiteira_id = ?
        ");

        $stmt->execute([
            $novoStatus,
            $id,
            $confeiteiraId
        ]);

        header("Location: produtos.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$busca = trim($_GET["busca"] ?? "");
$categoria = trim($_GET["categoria"] ?? "");
$status = $_GET["status"] ?? "disponiveis";


/*
|--------------------------------------------------------------------------
| BUSCAR CATEGORIAS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT DISTINCT categoria
    FROM bolos
    WHERE confeiteira_id = ?
      AND categoria IS NOT NULL
      AND categoria <> ''
    ORDER BY categoria
");

$stmt->execute([$confeiteiraId]);

$categorias = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| CONSULTA DOS PRODUTOS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        b.id,
        b.nome,
        b.descricao,
        b.categoria,
        b.tamanho,
        b.rendimento,
        b.preco_venda,
        b.foto,
        b.disponivel,
        b.criado_em,

        COALESCE(ci.custo_insumos, 0) AS custo_insumos,
        COALESCE(cr.custo_receitas, 0) AS custo_receitas

    FROM bolos b

    LEFT JOIN (
        SELECT
            bi.bolo_id,
            SUM(
                bi.quantidade * COALESCE(i.custo_unitario, 0)
            ) AS custo_insumos
        FROM bolo_insumos bi
        INNER JOIN insumos i
            ON i.id = bi.insumo_id
        GROUP BY bi.bolo_id
    ) ci
        ON ci.bolo_id = b.id

    LEFT JOIN (
        SELECT
            br.bolo_id,
            SUM(
                CASE
                    WHEN r.rendimento > 0
                    THEN
                        (
                            br.quantidade / r.rendimento
                        ) *
                        COALESCE(rc.custo_receita, 0)
                    ELSE 0
                END
            ) AS custo_receitas
        FROM bolo_receitas br

        INNER JOIN receitas r
            ON r.id = br.receita_id

        LEFT JOIN (
            SELECT
                ri.receita_id,
                SUM(
                    ri.quantidade *
                    COALESCE(i.custo_unitario, 0)
                ) AS custo_receita
            FROM receita_insumos ri

            INNER JOIN insumos i
                ON i.id = ri.insumo_id

            GROUP BY ri.receita_id
        ) rc
            ON rc.receita_id = r.id

        GROUP BY br.bolo_id
    ) cr
        ON cr.bolo_id = b.id

    WHERE b.confeiteira_id = ?
";

$params = [$confeiteiraId];


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

if ($status === "disponiveis") {

    $sql .= " AND b.disponivel = 1";

} elseif ($status === "indisponiveis") {

    $sql .= " AND b.disponivel = 0";
}


/*
|--------------------------------------------------------------------------
| BUSCA
|--------------------------------------------------------------------------
*/

if ($busca !== "") {

    $sql .= "
        AND (
            b.nome LIKE ?
            OR b.descricao LIKE ?
            OR b.categoria LIKE ?
        )
    ";

    $termo = "%" . $busca . "%";

    $params[] = $termo;
    $params[] = $termo;
    $params[] = $termo;
}


/*
|--------------------------------------------------------------------------
| CATEGORIA
|--------------------------------------------------------------------------
*/

if ($categoria !== "") {

    $sql .= " AND b.categoria = ?";

    $params[] = $categoria;
}


$sql .= "
    ORDER BY b.nome ASC
";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$produtos = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| RESUMO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(
            CASE
                WHEN disponivel = 1 THEN 1
                ELSE 0
            END
        ) AS disponiveis,
        SUM(
            CASE
                WHEN disponivel = 0 THEN 1
                ELSE 0
            END
        ) AS indisponiveis
    FROM bolos
    WHERE confeiteira_id = ?
");

$stmt->execute([$confeiteiraId]);

$resumo = $stmt->fetch();

$totalProdutos = (int) ($resumo["total"] ?? 0);
$totalDisponiveis = (int) ($resumo["disponiveis"] ?? 0);
$totalIndisponiveis = (int) ($resumo["indisponiveis"] ?? 0);

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Produtos | <?= htmlspecialchars($confeiteira["nome_confeitaria"]) ?></title>

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
    max-width: 1250px;
    margin: 30px auto;
}


/* TOPO */

.topo {
    display: flex;
    justify-content: space-between;
    align-items: center;

    gap: 20px;
    margin-bottom: 25px;

    flex-wrap: wrap;
}

.titulo h1 {
    margin: 0 0 6px;
    font-size: 30px;
}

.titulo p {
    margin: 0;
    color: #666;
}


/* BOTÕES */

.btn {
    display: inline-block;

    border: none;
    border-radius: 8px;

    padding: 11px 17px;

    font-size: 14px;
    font-weight: bold;

    cursor: pointer;
}

.btn-principal {
    background: #222;
    color: white;
}

.btn-principal:hover {
    background: #000;
}

.btn-editar {
    background: #f0f0f0;
    color: #222;
}

.btn-editar:hover {
    background: #ddd;
}


/* RESUMO */

.resumo {
    display: grid;
    grid-template-columns: repeat(3, 1fr);

    gap: 15px;
    margin-bottom: 25px;
}

.card-resumo {
    background: white;

    border-radius: 12px;
    padding: 20px;

    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}

.card-resumo .numero {
    font-size: 28px;
    font-weight: bold;

    margin-bottom: 5px;
}

.card-resumo .texto {
    color: #777;
    font-size: 14px;
}


/* FILTROS */

.filtros {
    background: white;

    padding: 18px;

    border-radius: 12px;

    margin-bottom: 20px;

    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}

.form-filtros {
    display: grid;

    grid-template-columns: 2fr 1fr 1fr auto;

    gap: 12px;

    align-items: end;
}

.campo {
    display: flex;
    flex-direction: column;

    gap: 6px;
}

.campo label {
    font-size: 13px;
    font-weight: bold;

    color: #555;
}

.campo input,
.campo select {
    width: 100%;

    padding: 11px;

    border: 1px solid #ddd;

    border-radius: 7px;

    font-size: 14px;

    background: white;
}


/* PRODUTOS */

.produtos {

    display: grid;

    grid-template-columns:
        repeat(auto-fill, minmax(280px, 1fr));

    gap: 20px;
}


/* CARD PRODUTO */

.produto {
    background: white;

    border-radius: 13px;

    overflow: hidden;

    box-shadow: 0 2px 8px rgba(0,0,0,.05);

    display: flex;
    flex-direction: column;
}


/* FOTO */

.produto-foto {

    width: 100%;
    height: 210px;

    background: #eee;

    display: flex;

    align-items: center;
    justify-content: center;

    overflow: hidden;
}

.produto-foto img {

    width: 100%;
    height: 100%;

    object-fit: cover;
}

.sem-foto {

    font-size: 45px;
    color: #bbb;
}


/* INFORMAÇÕES */

.produto-info {

    padding: 18px;

    flex: 1;
}

.produto-nome {

    font-size: 20px;

    font-weight: bold;

    margin-bottom: 7px;
}

.produto-categoria {

    display: inline-block;

    background: #f0f0f0;

    border-radius: 20px;

    padding: 5px 9px;

    font-size: 12px;

    color: #666;

    margin-bottom: 12px;
}

.produto-descricao {

    color: #666;

    font-size: 14px;

    line-height: 1.5;

    min-height: 42px;

    margin-bottom: 15px;
}

.produto-preco {

    font-size: 22px;

    font-weight: bold;

    margin-bottom: 8px;
}

.produto-detalhes {

    font-size: 13px;

    color: #777;

    line-height: 1.6;
}


/* CUSTO */

.custo {

    margin-top: 12px;

    padding-top: 12px;

    border-top: 1px solid #eee;

    font-size: 13px;

    color: #777;
}

.resultado {

    font-weight: bold;

    color: #218838;
}


/* STATUS */

.status {

    display: inline-block;

    padding: 5px 9px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: bold;

    margin-top: 12px;
}

.status.disponivel {

    background: #e8f8ee;

    color: #218838;
}

.status.indisponivel {

    background: #eee;

    color: #777;
}


/* AÇÕES */

.produto-acoes {

    padding: 15px 18px;

    border-top: 1px solid #eee;

    display: flex;

    gap: 8px;

    flex-wrap: wrap;
}

.produto-acoes .btn {

    flex: 1;

    text-align: center;

    min-width: 100px;
}


/* VAZIO */

.vazio {

    background: white;

    border-radius: 12px;

    padding: 60px 20px;

    text-align: center;

    color: #777;
}

.vazio h3 {

    color: #333;

    margin-top: 0;
}


/* RESPONSIVO */

@media (max-width: 900px) {

    .form-filtros {

        grid-template-columns: 1fr 1fr;
    }

    .form-filtros .botao-filtro {

        grid-column: span 2;
    }

    .resumo {

        grid-template-columns: 1fr;
    }

}

@media (max-width: 600px) {

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

    .titulo h1 {

        font-size: 25px;
    }

    .topo .btn {

        width: 100%;

        text-align: center;
    }

    .form-filtros {

        grid-template-columns: 1fr;
    }

    .form-filtros .botao-filtro {

        grid-column: auto;
    }

    .produto-foto {

        height: 230px;
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

        <a href="produtos.php" class="ativo">
            🍰 Produtos
        </a>

        <a href="estoque.php">
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


<!-- CONTEÚDO -->

<div class="container">


    <!-- TOPO -->

    <div class="topo">

        <div class="titulo">

            <h1>🍰 Produtos</h1>

            <p>
                Cadastre e organize os produtos que você oferece aos seus clientes.
            </p>

        </div>


        <a
            href="cadastrar_produto.php"
            class="btn btn-principal"
        >
            + Novo produto
        </a>

    </div>


    <!-- RESUMO -->

    <div class="resumo">

        <div class="card-resumo">

            <div class="numero">
                <?= $totalProdutos ?>
            </div>

            <div class="texto">
                Produtos cadastrados
            </div>

        </div>


        <div class="card-resumo">

            <div class="numero">
                <?= $totalDisponiveis ?>
            </div>

            <div class="texto">
                Disponíveis para venda
            </div>

        </div>


        <div class="card-resumo">

            <div class="numero">
                <?= $totalIndisponiveis ?>
            </div>

            <div class="texto">
                Indisponíveis
            </div>

        </div>

    </div>


    <!-- FILTROS -->

    <div class="filtros">

        <form
            method="GET"
            class="form-filtros"
        >


            <div class="campo">

                <label>
                    Procurar produto
                </label>

                <input
                    type="text"
                    name="busca"
                    value="<?= htmlspecialchars($busca) ?>"
                    placeholder="Ex.: bolo de chocolate..."
                >

            </div>


            <div class="campo">

                <label>
                    Categoria
                </label>

                <select name="categoria">

                    <option value="">
                        Todas
                    </option>

                    <?php foreach ($categorias as $cat): ?>

                        <option
                            value="<?= htmlspecialchars($cat["categoria"]) ?>"
                            <?= $categoria === $cat["categoria"] ? "selected" : "" ?>
                        >
                            <?= htmlspecialchars($cat["categoria"]) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="campo">

                <label>
                    Status
                </label>

                <select name="status">

                    <option
                        value="disponiveis"
                        <?= $status === "disponiveis" ? "selected" : "" ?>
                    >
                        Disponíveis
                    </option>

                    <option
                        value="indisponiveis"
                        <?= $status === "indisponiveis" ? "selected" : "" ?>
                    >
                        Indisponíveis
                    </option>

                    <option
                        value="todos"
                        <?= $status === "todos" ? "selected" : "" ?>
                    >
                        Todos
                    </option>

                </select>

            </div>


            <div class="botao-filtro">

                <button
                    type="submit"
                    class="btn btn-principal"
                >
                    Filtrar
                </button>

            </div>

        </form>

    </div>


    <!-- PRODUTOS -->

    <?php if (count($produtos) > 0): ?>

        <div class="produtos">

            <?php foreach ($produtos as $produto): ?>

                <?php

                $custoTotal =
                    (float) $produto["custo_insumos"] +
                    (float) $produto["custo_receitas"];

                $precoVenda =
                    (float) $produto["preco_venda"];

                $resultado =
                    $precoVenda - $custoTotal;

                ?>


                <div class="produto">


                    <!-- FOTO -->

                    <div class="produto-foto">

                        <?php if (
                            !empty($produto["foto"]) &&
                            file_exists("../uploads/produtos/" . $produto["foto"])
                        ): ?>

                            <img
                                src="../uploads/produtos/<?= htmlspecialchars($produto["foto"]) ?>"
                                alt="<?= htmlspecialchars($produto["nome"]) ?>"
                            >

                        <?php else: ?>

                            <div class="sem-foto">
                                🍰
                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- INFORMAÇÕES -->

                    <div class="produto-info">


                        <div class="produto-nome">

                            <?= htmlspecialchars($produto["nome"]) ?>

                        </div>


                        <?php if (!empty($produto["categoria"])): ?>

                            <span class="produto-categoria">

                                <?= htmlspecialchars($produto["categoria"]) ?>

                            </span>

                        <?php endif; ?>


                        <?php if (!empty($produto["descricao"])): ?>

                            <div class="produto-descricao">

                                <?= nl2br(
                                    htmlspecialchars(
                                        $produto["descricao"]
                                    )
                                ) ?>

                            </div>

                        <?php else: ?>

                            <div class="produto-descricao">

                                Nenhuma descrição cadastrada.

                            </div>

                        <?php endif; ?>


                        <div class="produto-preco">

                            R$

                            <?= number_format(
                                $precoVenda,
                                2,
                                ",",
                                "."
                            ) ?>

                        </div>


                        <?php if (
                            !empty($produto["tamanho"]) ||
                            !empty($produto["rendimento"])
                        ): ?>

                            <div class="produto-detalhes">

                                <?php if (!empty($produto["tamanho"])): ?>

                                    <strong>Tamanho:</strong>
                                    <?= htmlspecialchars($produto["tamanho"]) ?>

                                    <br>

                                <?php endif; ?>


                                <?php if (!empty($produto["rendimento"])): ?>

                                    <strong>Rendimento:</strong>
                                    <?= htmlspecialchars($produto["rendimento"]) ?>

                                <?php endif; ?>

                            </div>

                        <?php endif; ?>


                        <?php if ($custoTotal > 0): ?>

                            <div class="custo">

                                Custo estimado:
                                <strong>
                                    R$
                                    <?= number_format(
                                        $custoTotal,
                                        2,
                                        ",",
                                        "."
                                    ) ?>
                                </strong>

                                <br>

                                Resultado:
                                <span class="resultado">

                                    R$
                                    <?= number_format(
                                        $resultado,
                                        2,
                                        ",",
                                        "."
                                    ) ?>

                                </span>

                            </div>

                        <?php endif; ?>


                        <?php if ($produto["disponivel"]): ?>

                            <span class="status disponivel">
                                ✓ Disponível
                            </span>

                        <?php else: ?>

                            <span class="status indisponivel">
                                Indisponível
                            </span>

                        <?php endif; ?>

                    </div>


                    <!-- AÇÕES -->

                    <div class="produto-acoes">


                        <a
                            href="editar_produto.php?id=<?= (int) $produto["id"] ?>"
                            class="btn btn-editar"
                        >
                            ✏️ Editar
                        </a>


                        <?php if ($produto["disponivel"]): ?>

                            <form
                                method="POST"
                                style="flex:1;"
                            >

                                <input
                                    type="hidden"
                                    name="acao"
                                    value="desativar"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int) $produto["id"] ?>"
                                >

                                <button
                                    type="submit"
                                    class="btn"
                                    style="width:100%;background:#fdecec;color:#c0392b;"
                                    onclick="return confirm('Deseja deixar este produto indisponível?');"
                                >
                                    Ocultar
                                </button>

                            </form>

                        <?php else: ?>

                            <form
                                method="POST"
                                style="flex:1;"
                            >

                                <input
                                    type="hidden"
                                    name="acao"
                                    value="ativar"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int) $produto["id"] ?>"
                                >

                                <button
                                    type="submit"
                                    class="btn"
                                    style="width:100%;background:#e8f8ee;color:#218838;"
                                >
                                    Disponibilizar
                                </button>

                            </form>

                        <?php endif; ?>


                    </div>

                </div>

            <?php endforeach; ?>

        </div>


    <?php else: ?>


        <div class="vazio">

            <h3>
                🍰 Nenhum produto encontrado
            </h3>

            <p>
                Você ainda não possui produtos cadastrados com esses filtros.
            </p>

            <br>

            <a
                href="cadastrar_produto.php"
                class="btn btn-principal"
            >
                + Cadastrar primeiro produto
            </a>

        </div>


    <?php endif; ?>


</div>

</body>

</html>