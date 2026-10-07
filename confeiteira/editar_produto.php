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

$stmt->execute([
    $_SESSION["usuario_id"]
]);

$confeiteira = $stmt->fetch();

if (!$confeiteira) {

    die(
        "Cadastro da confeiteira não encontrado."
    );
}

$confeiteiraId =
    (int) $confeiteira["id"];


/*
|--------------------------------------------------------------------------
| ID DO PRODUTO
|--------------------------------------------------------------------------
*/

$produtoId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$produtoId) {

    header(
        "Location: produtos.php"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR PRODUTO
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

    $produtoId,

    $confeiteiraId

]);

$produto = $stmt->fetch();

if (!$produto) {

    header(
        "Location: produtos.php"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| VARIÁVEIS
|--------------------------------------------------------------------------
*/

$erro = "";

$nome =
    $produto["nome"];

$descricao =
    $produto["descricao"] ?? "";

$categoria =
    $produto["categoria"] ?? "";

$rendimento =
    $produto["rendimento"] ?? "";

$precoVenda =
    $produto["preco_venda"] ?? "0.00";

$fotoAtual =
    $produto["foto"] ?? null;


/*
|--------------------------------------------------------------------------
| CATEGORIAS
|--------------------------------------------------------------------------
*/

$categorias = [

    "Bolo",

    "Torta",

    "Bolo no pote",

    "Brownie",

    "Cupcake",

    "Doces",

    "Kit festa",

    "Outros"

];


/*
|--------------------------------------------------------------------------
| BUSCAR INGREDIENTES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        tipo,
        unidade,
        estoque,
        ativo
    FROM insumos
    WHERE confeiteira_id = ?
    AND ativo = 1
    ORDER BY nome ASC
");

$stmt->execute([
    $confeiteiraId
]);

$insumos =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| BUSCAR RECEITAS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        tipo,
        rendimento,
        unidade_rendimento,
        ativo
    FROM receitas
    WHERE confeiteira_id = ?
    AND ativo = 1
    ORDER BY
        FIELD(
            tipo,
            'massa',
            'recheio',
            'cobertura',
            'decoracao',
            'outros'
        ),
        nome ASC
");

$stmt->execute([
    $confeiteiraId
]);

$receitas =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| BUSCAR COMPOSIÇÃO ATUAL
|--------------------------------------------------------------------------
*/


/*
| Ingredientes atuais
*/

$stmt = $pdo->prepare("
    SELECT
        insumo_id,
        quantidade
    FROM bolo_insumos
    WHERE bolo_id = ?
");

$stmt->execute([
    $produtoId
]);

$ingredientesAtuais = [];

foreach (
    $stmt->fetchAll()
    as $item
) {

    $ingredientesAtuais[
        (int) $item["insumo_id"]
    ] =
        (float) $item["quantidade"];
}


/*
| Receitas atuais
*/

$stmt = $pdo->prepare("
    SELECT
        receita_id,
        quantidade,
        ordem
    FROM bolo_receitas
    WHERE bolo_id = ?
    ORDER BY ordem ASC, id ASC
");

$stmt->execute([
    $produtoId
]);

$receitasAtuais = [];

foreach (
    $stmt->fetchAll()
    as $item
) {

    $receitasAtuais[
        (int) $item["receita_id"]
    ] =
        (float) $item["quantidade"];
}


/*
|--------------------------------------------------------------------------
| EXCLUIR FOTO
|--------------------------------------------------------------------------
*/

$excluirFoto = false;


/*
|--------------------------------------------------------------------------
| SALVAR ALTERAÇÕES
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
) {

    $nome =
        trim(
            $_POST["nome"] ?? ""
        );

    $descricao =
        trim(
            $_POST["descricao"] ?? ""
        );

    $categoria =
        trim(
            $_POST["categoria"] ?? ""
        );

    $rendimento =
        trim(
            $_POST["rendimento"] ?? ""
        );

    $precoVenda =
        str_replace(
            ",",
            ".",
            trim(
                $_POST["preco_venda"]
                ?? ""
            )
        );


    $ingredientesSelecionados =
        $_POST["ingredientes"] ?? [];


    $receitasSelecionadas =
        $_POST["receitas"] ?? [];


    $excluirFoto =
        isset(
            $_POST["excluir_foto"]
        ) &&
        $_POST["excluir_foto"] === "1";


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES BÁSICAS
    |--------------------------------------------------------------------------
    */

    if ($nome === "") {

        $erro =
            "Informe o nome do produto.";

    } elseif (

        $precoVenda === "" ||

        !is_numeric(
            $precoVenda
        )

    ) {

        $erro =
            "Informe um preço de venda válido.";

    } elseif (
        (float) $precoVenda < 0
    ) {

        $erro =
            "O preço de venda não pode ser negativo.";
    }


    /*
    |--------------------------------------------------------------------------
    | FOTO
    |--------------------------------------------------------------------------
    */

    $novaFoto = null;


    if (

        $erro === "" &&

        isset(
            $_FILES["foto"]
        ) &&

        $_FILES["foto"]["error"] !==
            UPLOAD_ERR_NO_FILE

    ) {

        if (

            $_FILES["foto"]["error"] !==
            UPLOAD_ERR_OK

        ) {

            $erro =
                "Não foi possível enviar a nova imagem.";

        } else {

            $tamanhoArquivo =
                $_FILES["foto"]["size"];


            if (
                $tamanhoArquivo >
                5 * 1024 * 1024
            ) {

                $erro =
                    "A imagem deve ter no máximo 5 MB.";

            } else {

                $extensao =
                    strtolower(
                        pathinfo(
                            $_FILES["foto"]["name"],
                            PATHINFO_EXTENSION
                        )
                    );


                $extensoesPermitidas = [

                    "jpg",

                    "jpeg",

                    "png",

                    "webp"

                ];


                if (

                    !in_array(
                        $extensao,
                        $extensoesPermitidas,
                        true
                    )

                ) {

                    $erro =
                        "Formato de imagem não permitido. Use JPG, JPEG, PNG ou WEBP.";

                } else {

                    $novaFoto =
                        "produto_" .
                        $confeiteiraId .
                        "_" .
                        time() .
                        "_" .
                        bin2hex(
                            random_bytes(4)
                        ) .
                        "." .
                        $extensao;
                }
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR INGREDIENTES
    |--------------------------------------------------------------------------
    */

    $ingredientesValidos = [];


    if (

        $erro === "" &&

        !empty(
            $ingredientesSelecionados
        )

    ) {

        foreach (

            $ingredientesSelecionados

            as $insumoId => $quantidade

        ) {

            $insumoId =
                (int) $insumoId;


            $quantidade =
                str_replace(

                    ",",

                    ".",

                    trim(
                        (string)
                        $quantidade
                    )
                );


            if (
                $insumoId <= 0
            ) {

                continue;
            }


            if (

                $quantidade === "" ||

                !is_numeric(
                    $quantidade
                )

            ) {

                $erro =
                    "Informe uma quantidade válida para todos os ingredientes.";

                break;
            }


            if (
                (float)
                $quantidade <= 0
            ) {

                $erro =
                    "A quantidade dos ingredientes deve ser maior que zero.";

                break;
            }


            /*
            |--------------------------------------------------------------
            | GARANTIR PROPRIEDADE DO INSUMO
            |--------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    nome,
                    unidade
                FROM insumos
                WHERE id = ?
                AND confeiteira_id = ?
                AND ativo = 1
                LIMIT 1
            ");

            $stmt->execute([

                $insumoId,

                $confeiteiraId

            ]);

            $insumo =
                $stmt->fetch();


            if (!$insumo) {

                $erro =
                    "Um dos ingredientes selecionados não é válido.";

                break;
            }


            $ingredientesValidos[] = [

                "id" =>
                    $insumoId,

                "quantidade" =>
                    (float) $quantidade

            ];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR RECEITAS
    |--------------------------------------------------------------------------
    */

    $receitasValidas = [];


    if (

        $erro === "" &&

        !empty(
            $receitasSelecionadas
        )

    ) {

        foreach (

            $receitasSelecionadas

            as $receitaId => $quantidade

        ) {

            $receitaId =
                (int) $receitaId;


            $quantidade =
                str_replace(

                    ",",

                    ".",

                    trim(
                        (string)
                        $quantidade
                    )
                );


            if (
                $receitaId <= 0
            ) {

                continue;
            }


            if (

                $quantidade === "" ||

                !is_numeric(
                    $quantidade
                )

            ) {

                $erro =
                    "Informe uma quantidade válida para todas as receitas.";

                break;
            }


            if (
                (float)
                $quantidade <= 0
            ) {

                $erro =
                    "A quantidade das receitas deve ser maior que zero.";

                break;
            }


            /*
            |--------------------------------------------------------------
            | GARANTIR PROPRIEDADE DA RECEITA
            |--------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    nome,
                    tipo,
                    rendimento,
                    unidade_rendimento
                FROM receitas
                WHERE id = ?
                AND confeiteira_id = ?
                AND ativo = 1
                LIMIT 1
            ");

            $stmt->execute([

                $receitaId,

                $confeiteiraId

            ]);

            $receita =
                $stmt->fetch();


            if (!$receita) {

                $erro =
                    "Uma das receitas selecionadas não é válida.";

                break;
            }


            $receitasValidas[] = [

                "id" =>
                    $receitaId,

                "quantidade" =>
                    (float) $quantidade

            ];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SALVAR
    |--------------------------------------------------------------------------
    */

    if ($erro === "") {

        $fotoAntiga =
            $fotoAtual;


        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------
            | PASTA DE FOTOS
            |--------------------------------------------------------------
            */

            $pastaFotos =
                dirname(__DIR__) .
                "/uploads/produtos";


            if (
                !is_dir(
                    $pastaFotos
                )
            ) {

                if (
                    !mkdir(
                        $pastaFotos,
                        0755,
                        true
                    )
                ) {

                    throw new Exception(
                        "Não foi possível criar a pasta de imagens."
                    );
                }
            }


            /*
            |--------------------------------------------------------------
            | SALVAR NOVA FOTO
            |--------------------------------------------------------------
            */

            if (
                $novaFoto !== null
            ) {

                $caminhoNovaFoto =
                    $pastaFotos .
                    "/" .
                    $novaFoto;


                if (

                    !move_uploaded_file(

                        $_FILES["foto"]["tmp_name"],

                        $caminhoNovaFoto

                    )

                ) {

                    throw new Exception(
                        "Não foi possível salvar a nova imagem."
                    );
                }


                $fotoAtual =
                    $novaFoto;


            } elseif (
                $excluirFoto
            ) {

                $fotoAtual =
                    null;
            }


            /*
            |--------------------------------------------------------------
            | ATUALIZAR PRODUTO
            |--------------------------------------------------------------
            */

            $stmt = $pdo->prepare("

                UPDATE bolos

                SET

                    nome = ?,

                    descricao = ?,

                    categoria = ?,

                    rendimento = ?,

                    preco_venda = ?,

                    foto = ?

                WHERE id = ?

                AND confeiteira_id = ?

            ");


            $stmt->execute([

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

                (float) $precoVenda,

                $fotoAtual,

                $produtoId,

                $confeiteiraId

            ]);


            /*
            |--------------------------------------------------------------
            | RECRIAR INGREDIENTES DO PRODUTO
            |--------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare("

                    DELETE FROM bolo_insumos

                    WHERE bolo_id = ?

                ");


            $stmt->execute([
                $produtoId
            ]);


            if (
                !empty(
                    $ingredientesValidos
                )
            ) {

                $stmtIngrediente =
                    $pdo->prepare("

                        INSERT INTO bolo_insumos (

                            bolo_id,

                            insumo_id,

                            quantidade

                        )

                        VALUES (?, ?, ?)

                    ");


                foreach (

                    $ingredientesValidos

                    as $ingrediente

                ) {

                    $stmtIngrediente->execute([

                        $produtoId,

                        $ingrediente["id"],

                        $ingrediente["quantidade"]

                    ]);
                }
            }


            /*
            |--------------------------------------------------------------
            | RECRIAR RECEITAS DO PRODUTO
            |--------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare("

                    DELETE FROM bolo_receitas

                    WHERE bolo_id = ?

                ");


            $stmt->execute([
                $produtoId
            ]);


            if (
                !empty(
                    $receitasValidas
                )
            ) {

                $stmtReceita =
                    $pdo->prepare("

                        INSERT INTO bolo_receitas (

                            bolo_id,

                            receita_id,

                            quantidade,

                            ordem

                        )

                        VALUES (?, ?, ?, ?)

                    ");


                $ordem = 0;


                foreach (

                    $receitasValidas

                    as $receita

                ) {

                    $ordem++;


                    $stmtReceita->execute([

                        $produtoId,

                        $receita["id"],

                        $receita["quantidade"],

                        $ordem

                    ]);
                }
            }


            /*
            |--------------------------------------------------------------
            | FINALIZAR
            |--------------------------------------------------------------
            */

            $pdo->commit();


            /*
            |--------------------------------------------------------------
            | APAGAR FOTO ANTIGA
            |--------------------------------------------------------------
            */

            if (

                $fotoAtual !==
                    $fotoAntiga &&

                !empty(
                    $fotoAntiga
                )

            ) {

                $caminhoFotoAntiga =
                    $pastaFotos .
                    "/" .
                    basename(
                        $fotoAntiga
                    );


                if (
                    file_exists(
                        $caminhoFotoAntiga
                    )
                ) {

                    @unlink(
                        $caminhoFotoAntiga
                    );
                }
            }


            header(
                "Location: produtos.php"
            );

            exit;


        } catch (
            Throwable $e
        ) {

            if (
                $pdo->inTransaction()
            ) {

                $pdo->rollBack();
            }


            /*
            |--------------------------------------------------------------
            | APAGAR NOVA FOTO SE HOUVE ERRO
            |--------------------------------------------------------------
            */

            if (

                $novaFoto !== null &&

                isset(
                    $pastaFotos
                ) &&

                file_exists(

                    $pastaFotos .
                    "/" .
                    $novaFoto

                )

            ) {

                @unlink(

                    $pastaFotos .
                    "/" .
                    $novaFoto

                );
            }


            $erro =
                "Não foi possível atualizar o produto. " .
                $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| AGRUPAR RECEITAS
|--------------------------------------------------------------------------
*/

$receitasPorTipo = [];


foreach (
    $receitas
    as $receita
) {

    $tipo =
        $receita["tipo"];


    if (
        !isset(
            $receitasPorTipo[$tipo]
        )
    ) {

        $receitasPorTipo[$tipo] = [];
    }


    $receitasPorTipo[$tipo][] =
        $receita;
}


/*
|--------------------------------------------------------------------------
| NOMES DOS TIPOS
|--------------------------------------------------------------------------
*/

$nomesTiposReceita = [

    "massa" =>
        "Massas",

    "recheio" =>
        "Recheios",

    "cobertura" =>
        "Coberturas",

    "decoracao" =>
        "Decorações",

    "outros" =>
        "Outras"
];


/*
|--------------------------------------------------------------------------
| URL DA FOTO
|--------------------------------------------------------------------------
*/

$urlFoto = null;


if (
    !empty(
        $fotoAtual
    )
) {

    $urlFoto =
        "../uploads/produtos/" .
        rawurlencode(
            basename(
                $fotoAtual
            )
        );
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

    <title>

        Editar produto -

        <?= htmlspecialchars(
            $confeiteira[
                "nome_confeitaria"
            ]
        ) ?>

    </title>


    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f7f7f8;
            color: #222;
        }

        .topo {
            height: 72px;
            background: #ffffff;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 42px;
            height: 42px;
            background: #8b3d5c;
            color: #ffffff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .logo-text strong {
            display: block;
            font-size: 18px;
        }

        .logo-text span {
            display: block;
            font-size: 12px;
            color: #888;
        }

        .usuario {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .usuario-info {
            text-align: right;
        }

        .usuario-info strong {
            display: block;
            font-size: 14px;
        }

        .usuario-info span {
            font-size: 12px;
            color: #888;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f1e4e9;
            color: #8b3d5c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .layout {
            display: flex;
            min-height: calc(100vh - 72px);
        }

        .menu {
            width: 235px;
            background: #ffffff;
            border-right: 1px solid #e5e5e5;
            padding: 25px 15px;
        }

        .menu-titulo {
            font-size: 11px;
            font-weight: bold;
            color: #999;
            text-transform: uppercase;
            padding: 0 12px;
            margin-bottom: 10px;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 12px;
            margin-bottom: 5px;
            border-radius: 10px;
            text-decoration: none;
            color: #555;
            font-size: 14px;
        }

        .menu a:hover {
            background: #f8eef2;
            color: #8b3d5c;
        }

        .menu a.ativo {
            background: #8b3d5c;
            color: #ffffff;
        }

        .menu-icone {
            width: 22px;
            text-align: center;
            font-size: 18px;
        }

        .menu-separador {
            height: 1px;
            background: #eeeeee;
            margin: 20px 10px;
        }

        .menu a.sair {
            color: #c0392b;
        }

        .conteudo {
            flex: 1;
            padding: 35px;
            max-width: 1250px;
        }

        .cabecalho {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .voltar {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid #ddd;
            text-decoration: none;
            color: #555;
            font-size: 20px;
        }

        .cabecalho h1 {
            font-size: 27px;
            margin-bottom: 5px;
        }

        .cabecalho p {
            font-size: 13px;
            color: #888;
        }

        .erro {
            background: #fdeceb;
            color: #a93226;
            border: 1px solid #f3c8c4;
            border-radius: 10px;
            padding: 13px 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .box {
            background: #ffffff;
            border: 1px solid #e7e7e7;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .box-titulo {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eeeeee;
        }

        .box-titulo h2 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .box-titulo p {
            color: #888;
            font-size: 12px;
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

        .campo-completo {
            grid-column: 1 / -1;
        }

        label {
            font-size: 13px;
            font-weight: bold;
            color: #444;
        }

        input,
        select,
        textarea {
            width: 100%;
            border: 1px solid #dcdcdc;
            border-radius: 9px;
            padding: 12px;
            font-family: inherit;
            font-size: 14px;
            outline: none;
            background: #ffffff;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #8b3d5c;
            box-shadow: 0 0 0 3px rgba(
                139,
                61,
                92,
                0.08
            );
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .foto-area {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .preview {
            width: 140px;
            height: 140px;
            border-radius: 14px;
            background: #f5e9ed;
            border: 2px dashed #dcb9c6;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            color: #8b3d5c;
            font-size: 42px;
            flex-shrink: 0;
        }

        .preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .foto-controles {
            flex: 1;
        }

        .foto-controles p {
            color: #888;
            font-size: 12px;
            line-height: 1.5;
            margin-bottom: 10px;
        }

        .foto-input {
            font-size: 12px;
            padding: 9px;
        }

        .foto-excluir {
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 7px;
            color: #c0392b;
            font-size: 12px;
        }

        .foto-excluir input {
            width: auto;
        }

        .composicao-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        .composicao-bloco {
            border: 1px solid #e7e7e7;
            border-radius: 12px;
            padding: 18px;
        }

        .composicao-bloco h3 {
            font-size: 15px;
            margin-bottom: 5px;
        }

        .composicao-bloco > p {
            color: #888;
            font-size: 12px;
            margin-bottom: 15px;
        }

        .item-composicao {
            display: grid;
            grid-template-columns: 1fr 100px;
            gap: 10px;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eeeeee;
        }

        .item-composicao:last-child {
            border-bottom: none;
        }

        .item-nome {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .item-nome strong {
            font-size: 13px;
        }

        .item-nome span {
            color: #999;
            font-size: 11px;
        }

        .quantidade input {
            padding: 9px;
            text-align: center;
        }

        .tipo-receita {
            margin-top: 20px;
            margin-bottom: 8px;
            font-size: 11px;
            font-weight: bold;
            color: #8b3d5c;
            text-transform: uppercase;
        }

        .sem-itens {
            padding: 15px;
            background: #fafafa;
            border-radius: 8px;
            color: #999;
            font-size: 12px;
            text-align: center;
        }

        .acoes-final {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        .botao {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            border-radius: 9px;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            border: none;
        }

        .botao-cancelar {
            background: #ffffff;
            color: #555;
            border: 1px solid #ddd;
        }

        .botao-salvar {
            background: #8b3d5c;
            color: #ffffff;
        }

        .botao-salvar:hover {
            background: #74324c;
        }

        @media (max-width: 900px) {

            .composicao-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 750px) {

            .topo {
                padding: 0 18px;
            }

            .usuario-info {
                display: none;
            }

            .menu {
                width: 70px;
                padding: 20px 10px;
            }

            .menu-titulo {
                display: none;
            }

            .menu a {
                justify-content: center;
                padding: 13px 5px;
            }

            .menu a span:not(.menu-icone) {
                display: none;
            }

            .menu-separador {
                margin: 15px 5px;
            }

            .conteudo {
                padding: 25px 18px;
            }

        }

        @media (max-width: 600px) {

            .grid {
                grid-template-columns: 1fr;
            }

            .campo-completo {
                grid-column: auto;
            }

            .foto-area {
                flex-direction: column;
                align-items: flex-start;
            }

            .preview {
                width: 150px;
                height: 150px;
            }

            .box {
                padding: 18px;
            }

            .acoes-final {
                flex-direction: column-reverse;
            }

            .acoes-final .botao {
                width: 100%;
            }

        }

    </style>

</head>

<body>


<header class="topo">

    <div class="logo">

        <div class="logo-icon">
            🍰
        </div>

        <div class="logo-text">

            <strong>

                <?= htmlspecialchars(
                    $confeiteira[
                        "nome_confeitaria"
                    ]
                ) ?>

            </strong>

            <span>
                Painel da confeiteira
            </span>

        </div>

    </div>


    <div class="usuario">

        <div class="usuario-info">

            <strong>

                <?= htmlspecialchars(
                    $_SESSION[
                        "usuario_nome"
                    ] ?? "Confeiteira"
                ) ?>

            </strong>

            <span>
                Área administrativa
            </span>

        </div>


        <div class="avatar">

            <?= strtoupper(
                substr(
                    $_SESSION[
                        "usuario_nome"
                    ] ?? "C",
                    0,
                    1
                )
            ) ?>

        </div>

    </div>

</header>


<div class="layout">


<aside class="menu">

    <div class="menu-titulo">
        Menu
    </div>


    <a href="index.php">

        <span class="menu-icone">
            🏠
        </span>

        <span>
            Início
        </span>

    </a>


    <a
        href="produtos.php"
        class="ativo"
    >

        <span class="menu-icone">
            🍰
        </span>

        <span>
            Produtos
        </span>

    </a>


    <a href="estoque.php">

        <span class="menu-icone">
            📦
        </span>

        <span>
            Estoque
        </span>

    </a>


    <a href="clientes.php">

        <span class="menu-icone">
            👥
        </span>

        <span>
            Clientes
        </span>

    </a>


    <a href="pedidos.php">

        <span class="menu-icone">
            🛒
        </span>

        <span>
            Pedidos
        </span>

    </a>


    <a href="financeiro/index.php">

        <span class="menu-icone">
            💰
        </span>

        <span>
            Financeiro
        </span>

    </a>


    <div class="menu-separador"></div>


    <a href="minha_conta.php">

        <span class="menu-icone">
            ⚙️
        </span>

        <span>
            Minha conta
        </span>

    </a>


    <a
        href="../logout.php"
        class="sair"
    >

        <span class="menu-icone">
            🚪
        </span>

        <span>
            Sair
        </span>

    </a>

</aside>


<main class="conteudo">


    <div class="cabecalho">

        <a
            href="produtos.php"
            class="voltar"
            title="Voltar"
        >
            ←
        </a>


        <div>

            <h1>
                Editar produto
            </h1>

            <p>
                Altere as informações e a composição do produto.
            </p>

        </div>

    </div>


    <?php if ($erro !== ""): ?>

        <div class="erro">

            <?= htmlspecialchars(
                $erro
            ) ?>

        </div>

    <?php endif; ?>


    <form
        method="POST"
        enctype="multipart/form-data"
    >


        <!-- =================================================
             INFORMAÇÕES
        ================================================== -->


        <div class="box">


            <div class="box-titulo">

                <h2>
                    Informações do produto
                </h2>

                <p>
                    Essas informações aparecem no catálogo.
                </p>

            </div>


            <div class="grid">


                <!-- FOTO -->

                <div
                    class="campo campo-completo"
                >

                    <label>
                        Foto do produto
                    </label>


                    <div class="foto-area">


                        <div
                            class="preview"
                            id="preview"
                        >

                            <?php if ($urlFoto): ?>

                                <img
                                    src="<?= htmlspecialchars($urlFoto) ?>"
                                    alt="Foto do produto"
                                >

                            <?php else: ?>

                                🍰

                            <?php endif; ?>

                        </div>


                        <div class="foto-controles">

                            <p>

                                Escolha uma nova foto para substituir
                                a atual.

                                <br>

                                JPG, JPEG, PNG ou WEBP.

                                <br>

                                Tamanho máximo: 5 MB.

                            </p>


                            <input
                                type="file"
                                name="foto"
                                id="foto"
                                class="foto-input"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            >


                            <?php if ($fotoAtual): ?>

                                <label
                                    class="foto-excluir"
                                >

                                    <input
                                        type="checkbox"
                                        name="excluir_foto"
                                        value="1"
                                    >

                                    Remover foto atual

                                </label>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>


                <!-- NOME -->

                <div
                    class="campo campo-completo"
                >

                    <label for="nome">
                        Nome do produto *
                    </label>

                    <input
                        type="text"
                        name="nome"
                        id="nome"
                        maxlength="150"
                        required
                        value="<?= htmlspecialchars($nome) ?>"
                    >

                </div>


                <!-- CATEGORIA -->

                <div class="campo">

                    <label for="categoria">
                        Categoria
                    </label>


                    <select
                        name="categoria"
                        id="categoria"
                    >

                        <option value="">
                            Selecione
                        </option>


                        <?php foreach (
                            $categorias
                            as $cat
                        ): ?>

                            <option
                                value="<?= htmlspecialchars($cat) ?>"
                                <?= $categoria === $cat
                                    ? "selected"
                                    : "" ?>
                            >

                                <?= htmlspecialchars(
                                    $cat
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- RENDIMENTO -->

                <div class="campo">

                    <label for="rendimento">
                        Rendimento
                    </label>

                    <input
                        type="text"
                        name="rendimento"
                        id="rendimento"
                        maxlength="100"
                        value="<?= htmlspecialchars($rendimento) ?>"
                        placeholder="Ex.: 15 pessoas"
                    >

                </div>


                <!-- PREÇO -->

                <div class="campo">

                    <label for="preco_venda">
                        Preço de venda *
                    </label>

                    <input
                        type="text"
                        name="preco_venda"
                        id="preco_venda"
                        required
                        value="<?= htmlspecialchars($precoVenda) ?>"
                    >

                </div>


                <!-- DESCRIÇÃO -->

                <div
                    class="campo campo-completo"
                >

                    <label for="descricao">
                        Descrição
                    </label>

                    <textarea
                        name="descricao"
                        id="descricao"
                    ><?= htmlspecialchars($descricao) ?></textarea>

                </div>

            </div>

        </div>


        <!-- =================================================
             COMPOSIÇÃO
        ================================================== -->


        <div class="box">


            <div class="box-titulo">

                <h2>
                    Composição do produto
                </h2>

                <p>
                    Ajuste os ingredientes e receitas utilizados.
                </p>

            </div>


            <div class="composicao-grid">


                <!-- INGREDIENTES -->


                <div class="composicao-bloco">

                    <h3>
                        📦 Ingredientes
                    </h3>

                    <p>
                        Informe a quantidade usada no produto.
                    </p>


                    <?php if (
                        empty($insumos)
                    ): ?>

                        <div class="sem-itens">

                            Você ainda não possui ingredientes
                            cadastrados no estoque.

                        </div>

                    <?php else: ?>


                        <?php foreach (
                            $insumos
                            as $insumo
                        ): ?>


                            <?php

                            $quantidadeAtual =
                                $ingredientesAtuais[
                                    (int)
                                    $insumo["id"]
                                ] ?? "";

                            ?>


                            <div
                                class="item-composicao"
                            >


                                <div
                                    class="item-nome"
                                >

                                    <strong>

                                        <?= htmlspecialchars(
                                            $insumo["nome"]
                                        ) ?>

                                    </strong>


                                    <span>

                                        Unidade:

                                        <?= htmlspecialchars(
                                            $insumo["unidade"]
                                        ) ?>


                                        <?php if (
                                            $insumo["estoque"]
                                            !== null
                                        ): ?>

                                            • Estoque:

                                            <?= number_format(
                                                (float)
                                                $insumo["estoque"],
                                                3,
                                                ",",
                                                "."
                                            ) ?>

                                        <?php endif; ?>

                                    </span>

                                </div>


                                <div
                                    class="quantidade"
                                >

                                    <input
                                        type="text"
                                        name="ingredientes[<?= (int)$insumo["id"] ?>]"
                                        value="<?= $quantidadeAtual !== ""
                                            ? htmlspecialchars(
                                                rtrim(
                                                    rtrim(
                                                        number_format(
                                                            (float)
                                                            $quantidadeAtual,
                                                            3,
                                                            ".",
                                                            ""
                                                        ),
                                                        "0"
                                                    ),
                                                    "."
                                                )
                                            )
                                            : "" ?>"
                                        placeholder="Qtd."
                                        inputmode="decimal"
                                    >

                                </div>

                            </div>


                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>


                <!-- RECEITAS -->


                <div class="composicao-bloco">

                    <h3>
                        🧁 Receitas
                    </h3>

                    <p>
                        Massas, recheios, coberturas e preparações.
                    </p>


                    <?php if (
                        empty($receitas)
                    ): ?>

                        <div class="sem-itens">

                            Você ainda não possui receitas cadastradas.

                        </div>

                    <?php else: ?>


                        <?php foreach (

                            $nomesTiposReceita

                            as $tipo => $nomeTipo

                        ): ?>


                            <?php

                            if (
                                empty(
                                    $receitasPorTipo[
                                        $tipo
                                    ]
                                )
                            ) {

                                continue;
                            }

                            ?>


                            <div
                                class="tipo-receita"
                            >

                                <?= htmlspecialchars(
                                    $nomeTipo
                                ) ?>

                            </div>


                            <?php foreach (

                                $receitasPorTipo[
                                    $tipo
                                ]

                                as $receita

                            ): ?>


                                <?php

                                $quantidadeAtual =
                                    $receitasAtuais[
                                        (int)
                                        $receita["id"]
                                    ] ?? "";

                                ?>


                                <div
                                    class="item-composicao"
                                >


                                    <div
                                        class="item-nome"
                                    >

                                        <strong>

                                            <?= htmlspecialchars(
                                                $receita["nome"]
                                            ) ?>

                                        </strong>


                                        <span>


                                            <?php if (

                                                $receita[
                                                    "rendimento"
                                                ] !== null &&

                                                (float)
                                                $receita[
                                                    "rendimento"
                                                ] > 0

                                            ): ?>

                                                Rendimento:

                                                <?= number_format(
                                                    (float)
                                                    $receita[
                                                        "rendimento"
                                                    ],
                                                    3,
                                                    ",",
                                                    "."
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    $receita[
                                                        "unidade_rendimento"
                                                    ] ?? ""
                                                ) ?>

                                            <?php else: ?>

                                                <?= htmlspecialchars(
                                                    $nomeTipo
                                                ) ?>

                                            <?php endif; ?>


                                        </span>

                                    </div>


                                    <div
                                        class="quantidade"
                                    >

                                        <input
                                            type="text"
                                            name="receitas[<?= (int)$receita["id"] ?>]"
                                            value="<?= $quantidadeAtual !== ""
                                                ? htmlspecialchars(
                                                    rtrim(
                                                        rtrim(
                                                            number_format(
                                                                (float)
                                                                $quantidadeAtual,
                                                                3,
                                                                ".",
                                                                ""
                                                            ),
                                                            "0"
                                                        ),
                                                        "."
                                                    )
                                                )
                                                : "" ?>"
                                            placeholder="Qtd."
                                            inputmode="decimal"
                                        >

                                    </div>

                                </div>


                            <?php endforeach; ?>


                        <?php endforeach; ?>


                    <?php endif; ?>

                </div>

            </div>

        </div>


        <!-- =================================================
             BOTÕES
        ================================================== -->


        <div class="acoes-final">


            <a
                href="produtos.php"
                class="botao botao-cancelar"
            >

                Cancelar

            </a>


            <button
                type="submit"
                class="botao botao-salvar"
            >

                ✓ Salvar alterações

            </button>

        </div>


    </form>

</main>

</div>


<script>

const campoFoto =
    document.getElementById(
        "foto"
    );


const preview =
    document.getElementById(
        "preview"
    );


if (campoFoto) {

    campoFoto.addEventListener(

        "change",

        function () {

            const arquivo =
                this.files[0];


            if (!arquivo) {

                return;
            }


            const leitor =
                new FileReader();


            leitor.onload =
                function (evento) {

                    preview.innerHTML =

                        '<img src="' +

                        evento.target.result +

                        '" alt="Prévia do produto">';

                };


            leitor.readAsDataURL(
                arquivo
            );

        }

    );
}

</script>


</body>

</html>