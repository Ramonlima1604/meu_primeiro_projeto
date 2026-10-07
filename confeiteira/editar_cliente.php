<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");


/*
|--------------------------------------------------------------------------
| BUSCAR ID DA CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([
    $_SESSION["usuario_id"]
]);

$confeiteira = $stmt->fetch();


if (!$confeiteira) {

    die("Perfil da confeiteira não encontrado.");

}


$confeiteiraId = $confeiteira["id"];


/*
|--------------------------------------------------------------------------
| VALIDAR ID DO CLIENTE
|--------------------------------------------------------------------------
*/

if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {

    die("Cliente inválido.");

}

$clienteId = (int) $_GET["id"];


/*
|--------------------------------------------------------------------------
| BUSCAR CLIENTE
|--------------------------------------------------------------------------
|
| Importante:
| O cliente precisa pertencer à confeiteira logada.
|
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM clientes
    WHERE id = ?
    AND confeiteira_id = ?
    LIMIT 1
");

$stmt->execute([
    $clienteId,
    $confeiteiraId
]);

$cliente = $stmt->fetch();


if (!$cliente) {

    die("Cliente não encontrado.");

}


/*
|--------------------------------------------------------------------------
| VARIÁVEIS
|--------------------------------------------------------------------------
*/

$erro = "";

$nome = $cliente["nome"];
$telefone = $cliente["telefone"] ?? "";
$email = $cliente["email"] ?? "";
$endereco = $cliente["endereco"] ?? "";
$numero = $cliente["numero"] ?? "";
$bairro = $cliente["bairro"] ?? "";
$cidade = $cliente["cidade"] ?? "";
$observacao = $cliente["observacao"] ?? "";
$ativo = (int) $cliente["ativo"];


/*
|--------------------------------------------------------------------------
| ATUALIZAR CLIENTE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    $nome = trim(
        $_POST["nome"] ?? ""
    );

    $telefone = trim(
        $_POST["telefone"] ?? ""
    );

    $email = trim(
        $_POST["email"] ?? ""
    );

    $endereco = trim(
        $_POST["endereco"] ?? ""
    );

    $numero = trim(
        $_POST["numero"] ?? ""
    );

    $bairro = trim(
        $_POST["bairro"] ?? ""
    );

    $cidade = trim(
        $_POST["cidade"] ?? ""
    );

    $observacao = trim(
        $_POST["observacao"] ?? ""
    );

    $ativo = isset($_POST["ativo"])
        ? 1
        : 0;


    /*
    |--------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------
    */

    if ($nome === "") {

        $erro = "Informe o nome do cliente.";

    }


    if (
        $erro === "" &&
        $email !== "" &&
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $erro = "Informe um e-mail válido.";

    }


    /*
    |--------------------------------------------------------------
    | ATUALIZAR
    |--------------------------------------------------------------
    */

    if ($erro === "") {

        try {

            $stmt = $pdo->prepare("
                UPDATE clientes

                SET
                    nome = ?,
                    telefone = ?,
                    email = ?,
                    endereco = ?,
                    numero = ?,
                    bairro = ?,
                    cidade = ?,
                    observacao = ?,
                    ativo = ?

                WHERE id = ?
                AND confeiteira_id = ?
            ");


            $stmt->execute([

                $nome,

                $telefone !== ""
                    ? $telefone
                    : null,

                $email !== ""
                    ? $email
                    : null,

                $endereco !== ""
                    ? $endereco
                    : null,

                $numero !== ""
                    ? $numero
                    : null,

                $bairro !== ""
                    ? $bairro
                    : null,

                $cidade !== ""
                    ? $cidade
                    : null,

                $observacao !== ""
                    ? $observacao
                    : null,

                $ativo,

                $clienteId,

                $confeiteiraId

            ]);


            /*
            |------------------------------------------------------
            | VOLTAR PARA LISTA
            |------------------------------------------------------
            */

            header(
                "Location: clientes.php"
            );

            exit;


        } catch (PDOException $e) {

            $erro =
                "Não foi possível atualizar o cliente.";

        }

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
        Editar Cliente
    </title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

</head>

<body>

<div class="admin-container">


    <!-- TOPO -->

    <header class="topo">

        <div>

            <h1>
                <?= htmlspecialchars(
                    $confeiteira["nome_confeitaria"]
                ) ?>
            </h1>

            <p>
                Editar cliente
            </p>

        </div>


        <a href="clientes.php">
            Voltar
        </a>

    </header>


    <!-- CONTEÚDO -->

    <main>

        <h2>
            ✏️ Editar Cliente
        </h2>


        <?php if ($erro !== ""): ?>

            <div
                class="card"
                style="
                    margin-bottom:20px;
                    border-left:5px solid #dc3545;
                "
            >

                <strong>
                    <?= htmlspecialchars($erro) ?>
                </strong>

            </div>

        <?php endif; ?>


        <div class="card">


            <form
                method="POST"
                action=""
            >


                <div
                    style="
                        display:grid;
                        grid-template-columns:
                            repeat(auto-fit,minmax(250px,1fr));
                        gap:15px;
                    "
                >


                    <!-- NOME -->

                    <div>

                        <label>
                            Nome *
                        </label>

                        <input
                            type="text"
                            name="nome"
                            value="<?= htmlspecialchars($nome) ?>"
                            required
                        >

                    </div>


                    <!-- TELEFONE -->

                    <div>

                        <label>
                            Telefone
                        </label>

                        <input
                            type="text"
                            name="telefone"
                            value="<?= htmlspecialchars($telefone) ?>"
                            placeholder="(00) 00000-0000"
                        >

                    </div>


                    <!-- EMAIL -->

                    <div>

                        <label>
                            E-mail
                        </label>

                        <input
                            type="email"
                            name="email"
                            value="<?= htmlspecialchars($email) ?>"
                            placeholder="cliente@email.com"
                        >

                    </div>


                    <!-- ENDEREÇO -->

                    <div>

                        <label>
                            Endereço
                        </label>

                        <input
                            type="text"
                            name="endereco"
                            value="<?= htmlspecialchars($endereco) ?>"
                        >

                    </div>


                    <!-- NÚMERO -->

                    <div>

                        <label>
                            Número
                        </label>

                        <input
                            type="text"
                            name="numero"
                            value="<?= htmlspecialchars($numero) ?>"
                        >

                    </div>


                    <!-- BAIRRO -->

                    <div>

                        <label>
                            Bairro
                        </label>

                        <input
                            type="text"
                            name="bairro"
                            value="<?= htmlspecialchars($bairro) ?>"
                        >

                    </div>


                    <!-- CIDADE -->

                    <div>

                        <label>
                            Cidade
                        </label>

                        <input
                            type="text"
                            name="cidade"
                            value="<?= htmlspecialchars($cidade) ?>"
                        >

                    </div>


                </div>


                <br>


                <!-- OBSERVAÇÃO -->

                <div>

                    <label>
                        Observação
                    </label>

                    <textarea
                        name="observacao"
                        rows="5"
                        placeholder="Informações adicionais sobre o cliente..."
                    ><?= htmlspecialchars($observacao) ?></textarea>

                </div>


                <br>


                <!-- STATUS -->

                <label
                    style="
                        display:flex;
                        align-items:center;
                        gap:8px;
                    "
                >

                    <input
                        type="checkbox"
                        name="ativo"
                        value="1"
                        <?= $ativo === 1
                            ? "checked"
                            : ""
                        ?>
                    >

                    Cliente ativo

                </label>


                <br>


                <!-- BOTÕES -->

                <div
                    style="
                        display:flex;
                        gap:10px;
                        flex-wrap:wrap;
                    "
                >

                    <button
                        type="submit"
                        class="btn"
                    >
                        💾 Salvar Alterações
                    </button>


                    <a
                        href="clientes.php"
                        class="btn"
                    >
                        ↩️ Cancelar
                    </a>

                </div>


            </form>


        </div>


    </main>

</div>

</body>

</html>