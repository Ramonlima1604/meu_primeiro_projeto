<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");


/*
|--------------------------------------------------------------------------
| IDENTIFICAR CONFEITEIRA LOGADA
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
| FUNÇÃO DE FORMATAÇÃO E CONVERSÃO
|--------------------------------------------------------------------------
*/

function formatarQuantidadeComConversao($valor, $unidade)
{
    $valor = (float) $valor;

    if ($valor <= 0) {
        return "0 " . $unidade;
    }

    $numero = rtrim(
        rtrim(
            number_format($valor, 3, ",", "."),
            "0"
        ),
        ","
    );


    /*
    |----------------------------------------------------------------------
    | ML → L
    |----------------------------------------------------------------------
    */

    if ($unidade === "ml" && $valor >= 1000) {

        $litros = $valor / 1000;

        $litrosFormatados = rtrim(
            rtrim(
                number_format($litros, 3, ",", "."),
                "0"
            ),
            ","
        );

        return $litrosFormatados . " L";
    }


    /*
    |----------------------------------------------------------------------
    | G → KG
    |----------------------------------------------------------------------
    */

    if ($unidade === "g" && $valor >= 1000) {

        $kg = $valor / 1000;

        $kgFormatado = rtrim(
            rtrim(
                number_format($kg, 3, ",", "."),
                "0"
            ),
            ","
        );

        return $kgFormatado . " kg";
    }


    return $numero . " " . $unidade;
}


/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$busca = trim($_GET["busca"] ?? "");
$tipo = trim($_GET["tipo"] ?? "");
$status = $_GET["status"] ?? "ativos";


/*
|--------------------------------------------------------------------------
| ATIVAR / DESATIVAR
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $acao = $_POST["acao"] ?? "";
    $id = (int) ($_POST["id"] ?? 0);

    if ($id > 0 && in_array($acao, ["ativar", "desativar"], true)) {

        $novoStatus = $acao === "ativar" ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE insumos
            SET ativo = ?
            WHERE id = ?
              AND confeiteira_id = ?
        ");

        $stmt->execute([
            $novoStatus,
            $id,
            $confeiteiraId
        ]);

        header("Location: estoque.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| TIPOS
|--------------------------------------------------------------------------
*/

$tipos = [
    "ingrediente" => "Ingredientes",
    "material" => "Materiais",
    "embalagem" => "Embalagens",
    "decoracao" => "Decoração",
    "outros" => "Outros"
];


/*
|--------------------------------------------------------------------------
| CONSULTA
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        nome,
        tipo,
        unidade,
        quantidade_embalagem,
        preco_embalagem,
        custo_unitario,
        estoque,
        estoque_minimo,
        ativo,
        criado_em
    FROM insumos
    WHERE confeiteira_id = ?
";

$params = [$confeiteiraId];


if ($status === "ativos") {

    $sql .= " AND ativo = 1";

} elseif ($status === "inativos") {

    $sql .= " AND ativo = 0";
}


if ($busca !== "") {

    $sql .= " AND nome LIKE ?";

    $params[] = "%" . $busca . "%";
}


if ($tipo !== "" && isset($tipos[$tipo])) {

    $sql .= " AND tipo = ?";

    $params[] = $tipo;
}


$sql .= "
    ORDER BY
        CASE
            WHEN estoque_minimo > 0
            AND estoque <= estoque_minimo
            THEN 0
            ELSE 1
        END,
        nome ASC
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$itens = $stmt->fetchAll();


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
                WHEN estoque_minimo > 0
                AND estoque <= estoque_minimo
                THEN 1
                ELSE 0
            END
        ) AS baixo_estoque
    FROM insumos
    WHERE confeiteira_id = ?
      AND ativo = 1
");

$stmt->execute([$confeiteiraId]);

$resumo = $stmt->fetch();

$totalItens = (int) ($resumo["total"] ?? 0);

$baixoEstoque =
    (int) ($resumo["baixo_estoque"] ?? 0);

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
    Estoque |
    <?= htmlspecialchars($confeiteira["nome_confeitaria"]) ?>
</title>

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
    align-items: center;
    justify-content: space-between;
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
    background: #fff;
    color: #222;
}


/* CONTAINER */

.container {
    width: 95%;
    max-width: 1250px;
    margin: 30px auto;
}


/* CABEÇALHO */

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
    padding: 11px 17px;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
    font-size: 14px;
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
    grid-template-columns: repeat(2, 1fr);
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

.alerta-numero {
    color: #c0392b;
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


/* TABELA */

.tabela-container {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}

.tabela {
    width: 100%;
    border-collapse: collapse;
}

.tabela th {
    background: #fafafa;
    padding: 15px;
    text-align: left;
    font-size: 13px;
    color: #666;
    border-bottom: 1px solid #eee;
}

.tabela td {
    padding: 15px;
    border-bottom: 1px solid #eee;
    font-size: 14px;
}

.tabela tr:last-child td {
    border-bottom: none;
}

.nome-item {
    font-weight: bold;
    color: #222;
}

.tipo {
    display: inline-block;
    padding: 5px 9px;
    background: #f1f1f1;
    border-radius: 20px;
    font-size: 12px;
}

.estoque-normal {
    font-weight: bold;
}

.estoque-baixo {
    color: #c0392b;
    font-weight: bold;
}

.conversao {
    display: block;
    color: #777;
    font-size: 12px;
    margin-top: 3px;
}

.situacao {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.situacao.ok {
    background: #e8f8ee;
    color: #218838;
}

.situacao.alerta {
    background: #fdecec;
    color: #c0392b;
}

.situacao.inativo {
    background: #eee;
    color: #777;
}

.acoes {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.btn-pequeno {
    padding: 7px 10px;
    font-size: 12px;
}


/* VAZIO */

.vazio {
    text-align: center;
    padding: 50px 20px;
    color: #777;
}

.vazio h3 {
    margin-top: 0;
    color: #333;
}


/* RESPONSIVO */

@media (max-width: 900px) {

    .form-filtros {
        grid-template-columns: 1fr 1fr;
    }

    .form-filtros .botao-filtro {
        grid-column: span 2;
    }

    .tabela-container {
        overflow-x: auto;
    }

    .tabela {
        min-width: 850px;
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

    .resumo {
        grid-template-columns: 1fr;
    }

    .form-filtros {
        grid-template-columns: 1fr;
    }

    .form-filtros .botao-filtro {
        grid-column: auto;
    }

    .topo .btn {
        width: 100%;
        text-align: center;
    }

}

</style>

</head>

<body>


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


    <!-- CABEÇALHO -->

    <div class="topo">

        <div class="titulo">

            <h1>📦 Estoque</h1>

            <p>
                Controle os ingredientes, materiais e embalagens da sua confeitaria.
            </p>

        </div>

        <a
            href="cadastrar_estoque.php"
            class="btn btn-principal"
        >
            + Adicionar item
        </a>

    </div>


    <!-- RESUMO -->

    <div class="resumo">

        <div class="card-resumo">

            <div class="numero">
                <?= $totalItens ?>
            </div>

            <div class="texto">
                Itens ativos no estoque
            </div>

        </div>


        <div class="card-resumo">

            <div class="numero <?= $baixoEstoque > 0 ? 'alerta-numero' : '' ?>">
                <?= $baixoEstoque ?>
            </div>

            <div class="texto">
                Itens com estoque baixo
            </div>

        </div>

    </div>


    <!-- FILTROS -->

    <div class="filtros">

        <form method="GET" class="form-filtros">

            <div class="campo">

                <label>
                    Procurar item
                </label>

                <input
                    type="text"
                    name="busca"
                    value="<?= htmlspecialchars($busca) ?>"
                    placeholder="Ex.: farinha, chocolate..."
                >

            </div>


            <div class="campo">

                <label>
                    Tipo
                </label>

                <select name="tipo">

                    <option value="">
                        Todos
                    </option>

                    <?php foreach ($tipos as $valor => $nome): ?>

                        <option
                            value="<?= htmlspecialchars($valor) ?>"
                            <?= $tipo === $valor ? "selected" : "" ?>
                        >
                            <?= htmlspecialchars($nome) ?>
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
                        value="ativos"
                        <?= $status === "ativos" ? "selected" : "" ?>
                    >
                        Ativos
                    </option>

                    <option
                        value="inativos"
                        <?= $status === "inativos" ? "selected" : "" ?>
                    >
                        Inativos
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


    <!-- TABELA -->

    <div class="tabela-container">

        <?php if (count($itens) > 0): ?>

            <table class="tabela">

                <thead>

                    <tr>

                        <th>Item</th>

                        <th>Tipo</th>

                        <th>Unidade</th>

                        <th>Estoque</th>

                        <th>Situação</th>

                        <th>Ações</th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach ($itens as $item): ?>

                    <?php

                    $estoque = (float) $item["estoque"];

                    $estoqueMinimo =
                        (float) $item["estoque_minimo"];

                    $estoqueBaixo =
                        $item["ativo"] &&
                        $estoqueMinimo > 0 &&
                        $estoque <= $estoqueMinimo;


                    $estoqueExibicao =
                        formatarQuantidadeComConversao(
                            $estoque,
                            $item["unidade"]
                        );


                    $minimoExibicao =
                        formatarQuantidadeComConversao(
                            $estoqueMinimo,
                            $item["unidade"]
                        );


                    $embalagemExibicao =
                        formatarQuantidadeComConversao(
                            $item["quantidade_embalagem"],
                            $item["unidade"]
                        );

                    ?>

                    <tr>

                        <td>

                            <div class="nome-item">
                                <?= htmlspecialchars($item["nome"]) ?>
                            </div>

                        </td>


                        <td>

                            <span class="tipo">

                                <?= htmlspecialchars(
                                    $tipos[$item["tipo"]]
                                    ?? ucfirst($item["tipo"])
                                ) ?>

                            </span>

                        </td>


                        <td>

                            <?= htmlspecialchars($item["unidade"]) ?>

                        </td>


                        <td>

                            <div class="<?= $estoqueBaixo ? 'estoque-baixo' : 'estoque-normal' ?>">

                                <?= htmlspecialchars($estoqueExibicao) ?>

                            </div>


                            <?php if ($estoqueMinimo > 0): ?>

                                <small style="color:#888;">

                                    Mínimo:
                                    <?= htmlspecialchars($minimoExibicao) ?>

                                </small>

                            <?php endif; ?>


                            <?php if ((float) $item["quantidade_embalagem"] > 0): ?>

                                <small class="conversao">

                                    Embalagem:
                                    <?= htmlspecialchars($embalagemExibicao) ?>

                                </small>

                            <?php endif; ?>

                        </td>


                        <td>

                            <?php if (!$item["ativo"]): ?>

                                <span class="situacao inativo">
                                    Inativo
                                </span>

                            <?php elseif ($estoqueBaixo): ?>

                                <span class="situacao alerta">
                                    ⚠ Estoque baixo
                                </span>

                            <?php else: ?>

                                <span class="situacao ok">
                                    ✓ Normal
                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <div class="acoes">

                                <a
                                    href="editar_estoque.php?id=<?= (int) $item["id"] ?>"
                                    class="btn btn-editar btn-pequeno"
                                >
                                    ✏️ Editar
                                </a>


                                <?php if ($item["ativo"]): ?>

                                    <form
                                        method="POST"
                                        style="display:inline;"
                                    >

                                        <input
                                            type="hidden"
                                            name="acao"
                                            value="desativar"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $item["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-pequeno"
                                            style="background:#fdecec;color:#c0392b;"
                                            onclick="return confirm('Deseja desativar este item?');"
                                        >
                                            Excluir
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <form
                                        method="POST"
                                        style="display:inline;"
                                    >

                                        <input
                                            type="hidden"
                                            name="acao"
                                            value="ativar"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $item["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-pequeno"
                                            style="background:#e8f8ee;color:#218838;"
                                        >
                                            Ativar
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php else: ?>

            <div class="vazio">

                <h3>
                    📦 Nenhum item encontrado
                </h3>

                <p>
                    Comece adicionando os ingredientes,
                    materiais ou embalagens que você utiliza.
                </p>

                <a
                    href="cadastrar_estoque.php"
                    class="btn btn-principal"
                >
                    + Adicionar primeiro item
                </a>

            </div>

        <?php endif; ?>

    </div>

</div>

</body>

</html>