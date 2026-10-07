<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

$stmt->execute([$_SESSION["usuario_id"]]);

$confeiteira = $stmt->fetch();

if (!$confeiteira) {
    die("Confeiteira não encontrada.");
}

$confeiteiraId = (int) $confeiteira["id"];


/*
|--------------------------------------------------------------------------
| BUSCA OS INSUMOS DA CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        tipo,
        unidade,
        estoque
    FROM insumos
    WHERE confeiteira_id = ?
      AND ativo = 1
    ORDER BY nome ASC
");

$stmt->execute([$confeiteiraId]);

$insumos = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| BUSCA AS RECEITAS DA CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        tipo,
        rendimento,
        unidade_rendimento
    FROM receitas
    WHERE confeiteira_id = ?
      AND ativo = 1
    ORDER BY tipo, nome
");

$stmt->execute([$confeiteiraId]);

$receitas = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| AGRUPA RECEITAS POR TIPO
|--------------------------------------------------------------------------
*/

$receitasPorTipo = [];

foreach ($receitas as $receita) {

    $tipo = $receita["tipo"];

    if (!isset($receitasPorTipo[$tipo])) {
        $receitasPorTipo[$tipo] = [];
    }

    $receitasPorTipo[$tipo][] = $receita;
}


/*
|--------------------------------------------------------------------------
| VARIÁVEIS DO FORMULÁRIO
|--------------------------------------------------------------------------
*/

$nome = "";
$descricao = "";
$categoria = "";
$rendimento = "";
$precoVenda = "";

$erro = "";


/*
|--------------------------------------------------------------------------
| CADASTRO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome = trim($_POST["nome"] ?? "");

    $descricao = trim($_POST["descricao"] ?? "");

    $categoria = trim($_POST["categoria"] ?? "");

    $rendimento = trim($_POST["rendimento"] ?? "");

    $precoVenda = str_replace(
        ",",
        ".",
        trim($_POST["preco_venda"] ?? "0")
    );


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if ($nome === "") {

        $erro = "Informe o nome do produto.";

    } elseif (
        !is_numeric($precoVenda) ||
        (float)$precoVenda < 0
    ) {

        $erro = "Informe um preço de venda válido.";
    }


    /*
    |--------------------------------------------------------------------------
    | FOTO
    |--------------------------------------------------------------------------
    */

    $nomeFoto = null;

    if (
        $erro === "" &&
        isset($_FILES["foto"]) &&
        $_FILES["foto"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {

        if (
            $_FILES["foto"]["error"] !== UPLOAD_ERR_OK
        ) {

            $erro = "Não foi possível enviar a foto.";

        } else {

            $arquivo = $_FILES["foto"];


            /*
            | Limite de 5 MB
            */

            if (
                $arquivo["size"] >
                5 * 1024 * 1024
            ) {

                $erro =
                    "A foto deve ter no máximo 5 MB.";
            }


            /*
            | Verifica se é realmente uma imagem
            */

            if ($erro === "") {

                $infoImagem =
                    @getimagesize(
                        $arquivo["tmp_name"]
                    );

                if ($infoImagem === false) {

                    $erro =
                        "O arquivo enviado não é uma imagem válida.";
                }
            }


            /*
            | Tipos permitidos
            */

            if ($erro === "") {

                $mimePermitidos = [

                    "image/jpeg" => "jpg",

                    "image/png" => "png",

                    "image/webp" => "webp",

                    "image/gif" => "gif"
                ];

                $mime =
                    $infoImagem["mime"] ?? "";

                if (
                    !isset(
                        $mimePermitidos[$mime]
                    )
                ) {

                    $erro =
                        "Formato de imagem não permitido. Use JPG, PNG, WEBP ou GIF.";

                } else {

                    $extensao =
                        $mimePermitidos[$mime];


                    /*
                    | Nome aleatório para evitar conflitos
                    */

                    $nomeFoto =
                        uniqid(
                            "produto_",
                            true
                        ) .
                        "." .
                        $extensao;
                }
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | COMPOSIÇÃO
    |--------------------------------------------------------------------------
    */

    $insumosSelecionados =
        $_POST["insumos"] ?? [];

    $receitasSelecionadas =
        $_POST["receitas"] ?? [];


    if (
        !is_array(
            $insumosSelecionados
        )
    ) {

        $insumosSelecionados = [];
    }


    if (
        !is_array(
            $receitasSelecionadas
        )
    ) {

        $receitasSelecionadas = [];
    }


    /*
    |--------------------------------------------------------------------------
    | SALVA PRODUTO
    |--------------------------------------------------------------------------
    */

    if ($erro === "") {

        $pastaUpload =
            __DIR__ .
            "/../uploads/produtos/";


        if (!is_dir($pastaUpload)) {

            if (
                !mkdir(
                    $pastaUpload,
                    0755,
                    true
                )
            ) {

                $erro =
                    "Não foi possível criar a pasta de imagens.";
            }
        }


        if ($erro === "") {

            $pdo->beginTransaction();

            try {


                /*
                |--------------------------------------------------------------------------
                | MOVE A FOTO
                |--------------------------------------------------------------------------
                */

                if ($nomeFoto !== null) {

                    $destino =
                        $pastaUpload .
                        $nomeFoto;


                    if (
                        !move_uploaded_file(
                            $_FILES["foto"]["tmp_name"],
                            $destino
                        )
                    ) {

                        throw new Exception(
                            "Não foi possível salvar a foto."
                        );
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | CADASTRA O PRODUTO
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("

                    INSERT INTO bolos (

                        confeiteira_id,

                        nome,

                        descricao,

                        categoria,

                        rendimento,

                        preco_venda,

                        foto,

                        disponivel

                    )

                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)

                ");


                $stmt->execute([

                    $confeiteiraId,

                    $nome,

                    $descricao !== ""
                        ? $descricao
                        : null,

                    $categoria !== ""
                        ? $categoria
                        : null,

                    $rendimento !== ""
                        ? $rendimento
                        : null,

                    (float)$precoVenda,

                    $nomeFoto

                ]);


                $boloId =
                    (int)$pdo->lastInsertId();


                /*
                |--------------------------------------------------------------------------
                | ADICIONA INSUMOS
                |--------------------------------------------------------------------------
                */

                if (
                    !empty(
                        $insumosSelecionados
                    )
                ) {

                    $stmtInsumo =
                        $pdo->prepare("

                            SELECT id

                            FROM insumos

                            WHERE id = ?

                              AND confeiteira_id = ?

                              AND ativo = 1

                            LIMIT 1

                        ");


                    $stmtInserirInsumo =
                        $pdo->prepare("

                            INSERT INTO bolo_insumos (

                                bolo_id,

                                insumo_id,

                                quantidade

                            )

                            VALUES (?, ?, ?)

                        ");


                    foreach (
                        $insumosSelecionados
                        as $insumoId => $dados
                    ) {

                        $insumoId =
                            (int)$insumoId;


                        if (
                            !is_array($dados)
                        ) {

                            continue;
                        }


                        $quantidade =
                            str_replace(

                                ",",

                                ".",

                                trim(
                                    $dados["quantidade"]
                                    ?? ""
                                )
                            );


                        if (
                            $insumoId <= 0 ||
                            $quantidade === "" ||
                            !is_numeric($quantidade)
                        ) {

                            continue;
                        }


                        $quantidade =
                            (float)$quantidade;


                        if (
                            $quantidade <= 0
                        ) {

                            continue;
                        }


                        /*
                        | Garante que o insumo pertence à confeiteira
                        */

                        $stmtInsumo->execute([

                            $insumoId,

                            $confeiteiraId

                        ]);


                        if (
                            !$stmtInsumo->fetch()
                        ) {

                            continue;
                        }


                        $stmtInserirInsumo->execute([

                            $boloId,

                            $insumoId,

                            $quantidade

                        ]);
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | ADICIONA RECEITAS
                |--------------------------------------------------------------------------
                */

                if (
                    !empty(
                        $receitasSelecionadas
                    )
                ) {

                    $stmtReceita =
                        $pdo->prepare("

                            SELECT id

                            FROM receitas

                            WHERE id = ?

                              AND confeiteira_id = ?

                              AND ativo = 1

                            LIMIT 1

                        ");


                    $stmtInserirReceita =
                        $pdo->prepare("

                            INSERT INTO bolo_receitas (

                                bolo_id,

                                receita_id,

                                quantidade,

                                ordem

                            )

                            VALUES (?, ?, ?, ?)

                        ");


                    $ordem = 1;


                    foreach (
                        $receitasSelecionadas
                        as $receitaId => $dados
                    ) {

                        $receitaId =
                            (int)$receitaId;


                        if (
                            !is_array($dados)
                        ) {

                            continue;
                        }


                        $quantidade =
                            str_replace(

                                ",",

                                ".",

                                trim(
                                    $dados["quantidade"]
                                    ?? ""
                                )
                            );


                        if (
                            $receitaId <= 0 ||
                            $quantidade === "" ||
                            !is_numeric($quantidade)
                        ) {

                            continue;
                        }


                        $quantidade =
                            (float)$quantidade;


                        if (
                            $quantidade <= 0
                        ) {

                            continue;
                        }


                        /*
                        | Garante que a receita pertence à confeiteira
                        */

                        $stmtReceita->execute([

                            $receitaId,

                            $confeiteiraId

                        ]);


                        if (
                            !$stmtReceita->fetch()
                        ) {

                            continue;
                        }


                        $stmtInserirReceita->execute([

                            $boloId,

                            $receitaId,

                            $quantidade,

                            $ordem

                        ]);


                        $ordem++;
                    }
                }


                $pdo->commit();


                header(
                    "Location: produtos.php?sucesso=1"
                );

                exit;


            } catch (Throwable $e) {

                if (
                    $pdo->inTransaction()
                ) {

                    $pdo->rollBack();
                }


                /*
                | Se a foto foi salva e algo deu errado,
                | remove a imagem para não deixar arquivo órfão.
                */

                if (
                    $nomeFoto !== null
                ) {

                    $arquivoSalvo =
                        $pastaUpload .
                        $nomeFoto;


                    if (
                        is_file(
                            $arquivoSalvo
                        )
                    ) {

                        @unlink(
                            $arquivoSalvo
                        );
                    }
                }


                $erro =
                    "Não foi possível cadastrar o produto.";
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| NOMES DOS TIPOS DE RECEITA
|--------------------------------------------------------------------------
*/

$nomesTipos = [

    "massa" =>
        "Massas",

    "recheio" =>
        "Recheios",

    "cobertura" =>
        "Coberturas",

    "decoracao" =>
        "Decorações",

    "outros" =>
        "Outros"
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

    <title>
        Cadastrar Produto -
        <?= htmlspecialchars(
            $confeiteira["nome_confeitaria"]
        ) ?>
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f6f8;
            color: #222;
        }

        header {
            background: #ffffff;
            border-bottom: 1px solid #ddd;
            padding: 15px 25px;
        }

        .topo {
            max-width: 1200px;
            margin: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .logo {
            font-size: 21px;
            font-weight: bold;
        }

        .logo span {
            color: #d35400;
        }

        .menu {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .menu a {
            text-decoration: none;
            color: #333;
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 14px;
        }

        .menu a:hover {
            background: #f0f0f0;
        }

        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px 50px;
        }

        .titulo {
            margin-bottom: 25px;
        }

        .titulo h1 {
            margin: 0 0 5px;
            font-size: 28px;
        }

        .titulo p {
            margin: 0;
            color: #666;
        }

        .alerta {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            background: #ffe8e8;
            color: #a40000;
            border: 1px solid #ffcaca;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 20px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .campo {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .campo.full {
            grid-column: 1 / -1;
        }

        label {
            font-weight: bold;
            font-size: 14px;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 15px;
            background: #fff;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #d35400;
        }

        .foto-area {
            border: 2px dashed #ccc;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            background: #fafafa;
        }

        .foto-area p {
            color: #666;
            margin-top: 0;
        }

        .preview {
            max-width: 250px;
            max-height: 200px;
            margin: 15px auto 0;
            display: none;
            border-radius: 10px;
            object-fit: cover;
        }

        .lista {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .item {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 13px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .item:hover {
            background: #fafafa;
        }

        .item-check {
            width: auto;
        }

        .item-info {
            flex: 1;
        }

        .item-nome {
            font-weight: bold;
        }

        .item-detalhe {
            color: #777;
            font-size: 13px;
            margin-top: 3px;
        }

        .quantidade {
            width: 120px;
        }

        .tipo-receita {
            margin-bottom: 25px;
        }

        .tipo-receita h3 {
            margin: 0 0 10px;
            font-size: 16px;
            color: #444;
        }

        .vazio {
            color: #777;
            padding: 10px 0;
        }

        .botoes {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 25px;
        }

        .btn {
            display: inline-block;
            border: none;
            border-radius: 9px;
            padding: 13px 20px;
            text-decoration: none;
            font-size: 15px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-principal {
            background: #d35400;
            color: white;
        }

        .btn-principal:hover {
            background: #b94700;
        }

        .btn-secundario {
            background: #e9ecef;
            color: #333;
        }

        .btn-secundario:hover {
            background: #ddd;
        }

        .ajuda {
            color: #777;
            font-size: 13px;
            margin-top: 6px;
        }

        @media (max-width: 800px) {

            .grid {
                grid-template-columns: 1fr;
            }

            .campo.full {
                grid-column: auto;
            }

            .topo {
                flex-direction: column;
                align-items: flex-start;
            }

            .item {
                flex-wrap: wrap;
            }

            .quantidade {
                width: 100%;
            }

            .botoes {
                flex-direction: column-reverse;
            }

            .botoes .btn {
                width: 100%;
                text-align: center;
            }
        }

    </style>

</head>

<body>

<header>

    <div class="topo">

        <div class="logo">

            🍰

            <span>
                <?= htmlspecialchars(
                    $confeiteira["nome_confeitaria"]
                ) ?>
            </span>

        </div>

        <nav class="menu">

            <a href="index.php">
                🏠 Início
            </a>

            <a href="produtos.php">
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

        </nav>

    </div>

</header>


<main class="container">

    <div class="titulo">

        <h1>
            ➕ Novo Produto
        </h1>

        <p>
            Cadastre o produto e sua composição em um único lugar.
        </p>

    </div>


    <?php if ($erro !== ""): ?>

        <div class="alerta">

            <?= htmlspecialchars($erro) ?>

        </div>

    <?php endif; ?>


    <form
        method="POST"
        enctype="multipart/form-data"
    >


        <!-- PRODUTO -->

        <div class="card">

            <h2>
                🍰 Informações do produto
            </h2>

            <div class="grid">


                <div class="campo full">

                    <label for="nome">
                        Nome do produto *
                    </label>

                    <input
                        type="text"
                        id="nome"
                        name="nome"
                        value="<?= htmlspecialchars($nome) ?>"
                        required
                        placeholder="Ex.: Bolo de Chocolate"
                    >

                </div>


                <div class="campo">

                    <label for="categoria">
                        Categoria
                    </label>

                    <input
                        type="text"
                        id="categoria"
                        name="categoria"
                        value="<?= htmlspecialchars($categoria) ?>"
                        placeholder="Ex.: Bolos"
                    >

                </div>


                <div class="campo">

                    <label for="preco_venda">
                        Preço de venda *
                    </label>

                    <input
                        type="number"
                        id="preco_venda"
                        name="preco_venda"
                        value="<?= htmlspecialchars($precoVenda) ?>"
                        min="0"
                        step="0.01"
                        required
                        placeholder="0,00"
                    >

                </div>


                <div class="campo">

                    <label for="rendimento">
                        Rendimento
                    </label>

                    <input
                        type="text"
                        id="rendimento"
                        name="rendimento"
                        value="<?= htmlspecialchars($rendimento) ?>"
                        placeholder="Ex.: 15 fatias"
                    >

                </div>


                <div class="campo full">

                    <label for="descricao">
                        Descrição
                    </label>

                    <textarea
                        id="descricao"
                        name="descricao"
                        placeholder="Descrição do produto..."
                    ><?= htmlspecialchars($descricao) ?></textarea>

                </div>

            </div>

        </div>


        <!-- FOTO -->

        <div class="card">

            <h2>
                📷 Foto do produto
            </h2>

            <div class="foto-area">

                <p>
                    Escolha uma foto para aparecer no catálogo.
                </p>

                <input
                    type="file"
                    name="foto"
                    id="foto"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                >

                <img
                    id="preview"
                    class="preview"
                    alt="Prévia da foto"
                >

                <div class="ajuda">
                    JPG, PNG, WEBP ou GIF — máximo de 5 MB.
                </div>

            </div>

        </div>


        <!-- INGREDIENTES -->

        <div class="card">

            <h2>
                🥚 Ingredientes utilizados
            </h2>

            <p class="ajuda">
                Selecione os ingredientes utilizados diretamente neste produto
                e informe a quantidade.
            </p>


            <?php if (empty($insumos)): ?>

                <div class="vazio">

                    Você ainda não possui ingredientes cadastrados no estoque.

                    <br><br>

                    <a href="cadastrar_estoque.php">
                        ➕ Cadastrar ingrediente
                    </a>

                </div>

            <?php else: ?>

                <div class="lista">

                    <?php foreach ($insumos as $insumo): ?>

                        <div class="item">

                            <input
                                type="checkbox"
                                class="item-check"
                                name="insumos[<?= (int)$insumo["id"] ?>][selecionado]"
                                value="1"
                                onchange="ativarQuantidade(this)"
                            >

                            <div class="item-info">

                                <div class="item-nome">

                                    <?= htmlspecialchars(
                                        $insumo["nome"]
                                    ) ?>

                                </div>

                                <div class="item-detalhe">

                                    <?= htmlspecialchars(
                                        $insumo["tipo"]
                                    ) ?>

                                    • Unidade:

                                    <?= htmlspecialchars(
                                        $insumo["unidade"]
                                    ) ?>


                                    <?php if (
                                        (float)$insumo["estoque"] > 0
                                    ): ?>

                                        • Estoque:

                                        <?= rtrim(
                                            rtrim(
                                                number_format(
                                                    (float)$insumo["estoque"],
                                                    3,
                                                    ",",
                                                    "."
                                                ),
                                                "0"
                                            ),
                                            ","
                                        ) ?>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <input
                                type="number"
                                class="quantidade"
                                name="insumos[<?= (int)$insumo["id"] ?>][quantidade]"
                                placeholder="Quantidade"
                                min="0.001"
                                step="0.001"
                                disabled
                            >

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>


        <!-- RECEITAS -->

        <div class="card">

            <h2>
                🧁 Receitas utilizadas
            </h2>

            <p class="ajuda">

                Se o produto utiliza uma massa, recheio, cobertura ou outra
                receita cadastrada, selecione aqui e informe a quantidade.

            </p>


            <?php if (empty($receitasPorTipo)): ?>

                <div class="vazio">

                    Você ainda não possui receitas cadastradas.

                    <br><br>

                    As receitas poderão ser cadastradas posteriormente pelo
                    sistema de composição.

                </div>

            <?php else: ?>


                <?php foreach (
                    $nomesTipos
                    as $tipo => $nomeTipo
                ): ?>


                    <?php if (
                        !empty(
                            $receitasPorTipo[$tipo]
                        )
                    ): ?>

                        <div class="tipo-receita">

                            <h3>

                                <?= $nomeTipo ?>

                            </h3>


                            <div class="lista">


                                <?php foreach (
                                    $receitasPorTipo[$tipo]
                                    as $receita
                                ): ?>


                                    <div class="item">


                                        <input
                                            type="checkbox"
                                            class="item-check"
                                            name="receitas[<?= (int)$receita["id"] ?>][selecionado]"
                                            value="1"
                                            onchange="ativarQuantidade(this)"
                                        >


                                        <div class="item-info">

                                            <div class="item-nome">

                                                <?= htmlspecialchars(
                                                    $receita["nome"]
                                                ) ?>

                                            </div>


                                            <div class="item-detalhe">

                                                <?= $nomeTipo ?>


                                                <?php if (

                                                    $receita["rendimento"] !== null &&

                                                    $receita["rendimento"] !== ""

                                                ): ?>

                                                    • Rendimento:

                                                    <?= htmlspecialchars(
                                                        $receita["rendimento"]
                                                    ) ?>

                                                    <?= htmlspecialchars(
                                                        $receita[
                                                            "unidade_rendimento"
                                                        ] ?? ""
                                                    ) ?>

                                                <?php endif; ?>

                                            </div>

                                        </div>


                                        <input
                                            type="number"
                                            class="quantidade"
                                            name="receitas[<?= (int)$receita["id"] ?>][quantidade]"
                                            placeholder="Quantidade"
                                            min="0.001"
                                            step="0.001"
                                            disabled
                                        >

                                    </div>


                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endif; ?>


                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <!-- BOTÕES -->

        <div class="botoes">

            <a
                href="produtos.php"
                class="btn btn-secundario"
            >
                ← Voltar
            </a>


            <button
                type="submit"
                class="btn btn-principal"
            >
                💾 Salvar Produto
            </button>

        </div>


    </form>

</main>


<script>


    /*
    |--------------------------------------------------------------------------
    | ATIVA/DESATIVA CAMPO DE QUANTIDADE
    |--------------------------------------------------------------------------
    */

    function ativarQuantidade(checkbox) {

        const item =
            checkbox.closest(".item");

        if (!item) {
            return;
        }

        const quantidade =
            item.querySelector(
                ".quantidade"
            );

        if (!quantidade) {
            return;
        }

        quantidade.disabled =
            !checkbox.checked;


        if (checkbox.checked) {

            quantidade.focus();

        } else {

            quantidade.value = "";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | PRÉVIA DA FOTO
    |--------------------------------------------------------------------------
    */

    document
        .getElementById("foto")
        .addEventListener(
            "change",
            function () {

                const arquivo =
                    this.files[0];

                const preview =
                    document.getElementById(
                        "preview"
                    );


                if (!arquivo) {

                    preview.style.display =
                        "none";

                    preview.removeAttribute(
                        "src"
                    );

                    return;
                }


                const leitor =
                    new FileReader();


                leitor.onload =
                    function (evento) {

                        preview.src =
                            evento.target.result;

                        preview.style.display =
                            "block";
                    };


                leitor.readAsDataURL(
                    arquivo
                );

            }
        );

</script>

</body>

</html>