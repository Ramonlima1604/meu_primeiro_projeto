<?php

require_once __DIR__ . "/../config/banco.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$erros = [];

$nome = "";
$email = "";
$telefone = "";
$cidade = "";
$estado = "";

$senha = "";
$confirmar_senha = "";

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_cadastro_cliente"])) {
    $_SESSION["csrf_cadastro_cliente"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_cadastro_cliente"];

/*
|--------------------------------------------------------------------------
| FUNÇÕES
|--------------------------------------------------------------------------
*/

function limparCliente($valor)
{
    return trim((string) $valor);
}

function normalizarNomeCliente($valor)
{
    $valor = trim((string) $valor);
    return preg_replace('/\s+/', ' ', $valor);
}

function somenteNumerosCliente($valor)
{
    return preg_replace('/\D/', '', (string) $valor);
}

function formatarTelefoneCliente($telefone)
{
    $telefone = somenteNumerosCliente($telefone);

    if (strlen($telefone) === 11) {
        return "(" .
            substr($telefone, 0, 2) .
            ") " .
            substr($telefone, 2, 5) .
            "-" .
            substr($telefone, 7, 4);
    }

    if (strlen($telefone) === 10) {
        return "(" .
            substr($telefone, 0, 2) .
            ") " .
            substr($telefone, 2, 4) .
            "-" .
            substr($telefone, 6, 4);
    }

    return $telefone;
}

/*
|--------------------------------------------------------------------------
| PROCESSAR CADASTRO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrf_form = $_POST["csrf_token"] ?? "";

    if (
        empty($csrf_form) ||
        !hash_equals(
            $_SESSION["csrf_cadastro_cliente"],
            $csrf_form
        )
    ) {
        $erros[] =
            "Sessão expirada. Atualize a página e tente novamente.";
    }

    $nome = normalizarNomeCliente($_POST["nome"] ?? "");
    $email = strtolower(limparCliente($_POST["email"] ?? ""));
    $telefone = somenteNumerosCliente($_POST["telefone"] ?? "");
    $cidade = limparCliente($_POST["cidade"] ?? "");
    $estado = strtoupper(limparCliente($_POST["estado"] ?? ""));
    $senha = $_POST["senha"] ?? "";
    $confirmar_senha = $_POST["confirmar_senha"] ?? "";

    /*
    |--------------------------------------------------------------------------
    | VALIDAR NOME
    |--------------------------------------------------------------------------
    */

    if ($nome === "") {
        $erros[] = "Informe seu nome.";
    } elseif (mb_strlen($nome) < 2) {
        $erros[] = "Seu nome deve possuir pelo menos 2 caracteres.";
    } elseif (mb_strlen($nome) > 150) {
        $erros[] = "Seu nome não pode ultrapassar 150 caracteres.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAR E-MAIL
    |--------------------------------------------------------------------------
    */

    if ($email === "") {
        $erros[] = "Informe seu e-mail.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = "Informe um e-mail válido.";
    } elseif (mb_strlen($email) > 150) {
        $erros[] = "O e-mail não pode ultrapassar 150 caracteres.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAR TELEFONE
    |--------------------------------------------------------------------------
    */

    if ($telefone === "") {
        $erros[] = "Informe seu telefone ou WhatsApp.";
    } elseif (
        strlen($telefone) !== 10 &&
        strlen($telefone) !== 11
    ) {
        $erros[] = "Informe um telefone válido com DDD.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAR CIDADE
    |--------------------------------------------------------------------------
    */

    if ($cidade === "") {
        $erros[] = "Informe sua cidade.";
    } elseif (mb_strlen($cidade) > 100) {
        $erros[] = "A cidade não pode ultrapassar 100 caracteres.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAR ESTADO
    |--------------------------------------------------------------------------
    */

    $estadosValidos = [
        "AC", "AL", "AP", "AM", "BA", "CE", "DF",
        "ES", "GO", "MA", "MT", "MS", "MG", "PA",
        "PB", "PR", "PE", "PI", "RJ", "RN", "RS",
        "RO", "RR", "SC", "SP", "SE", "TO"
    ];

    if (!in_array($estado, $estadosValidos, true)) {
        $erros[] = "Selecione um estado válido.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAR SENHA FORTE
    |--------------------------------------------------------------------------
    */

    if ($senha === "") {

        $erros[] = "Informe uma senha.";

    } else {

        if (strlen($senha) < 8) {
            $erros[] = "A senha deve possuir pelo menos 8 caracteres.";
        }

        if (!preg_match('/[A-Z]/', $senha)) {
            $erros[] =
                "A senha deve possuir pelo menos uma letra maiúscula.";
        }

        if (!preg_match('/[a-z]/', $senha)) {
            $erros[] =
                "A senha deve possuir pelo menos uma letra minúscula.";
        }

        if (!preg_match('/[0-9]/', $senha)) {
            $erros[] =
                "A senha deve possuir pelo menos um número.";
        }

        if (!preg_match('/[^A-Za-z0-9]/', $senha)) {
            $erros[] =
                "A senha deve possuir pelo menos um símbolo, como !, @, # ou $.";
        }

        if (strlen($senha) > 255) {
            $erros[] = "A senha é muito longa.";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIRMAR SENHA
    |--------------------------------------------------------------------------
    */

    if ($confirmar_senha === "") {
        $erros[] = "Confirme sua senha.";
    } elseif ($senha !== $confirmar_senha) {
        $erros[] = "A confirmação da senha não confere.";
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR E-MAIL
    |--------------------------------------------------------------------------
    */

    if (empty($erros)) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $erros[] =
                "Este e-mail já está cadastrado. Faça login ou utilize outro e-mail.";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CRIAR CONTA
    |--------------------------------------------------------------------------
    */

    if (empty($erros)) {

        try {

            $pdo->beginTransaction();

            $senhaHash = password_hash(
                $senha,
                PASSWORD_DEFAULT
            );

            if ($senhaHash === false) {
                throw new Exception(
                    "Não foi possível proteger a senha."
                );
            }

            $stmtUsuario = $pdo->prepare("
                INSERT INTO usuarios
                (
                    nome,
                    email,
                    senha,
                    tipo,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    'cliente',
                    1
                )
            ");

            $stmtUsuario->execute([
                $nome,
                $email,
                $senhaHash
            ]);

            $usuarioId = (int) $pdo->lastInsertId();

            if ($usuarioId <= 0) {
                throw new Exception(
                    "Não foi possível identificar o usuário criado."
                );
            }

            $stmtCliente = $pdo->prepare("
                INSERT INTO clientes
                (
                    confeiteira_id,
                    nome,
                    telefone,
                    email,
                    cidade,
                    estado,
                    ativo
                )
                VALUES
                (
                    NULL,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    1
                )
            ");

            $stmtCliente->execute([
                $nome,
                $telefone !== "" ? $telefone : null,
                $email,
                $cidade,
                $estado
            ]);

            $clienteId = (int) $pdo->lastInsertId();

            if ($clienteId <= 0) {
                throw new Exception(
                    "Não foi possível identificar o cliente criado."
                );
            }

            $pdo->commit();

            $_SESSION["novo_cliente_id"] = $clienteId;
            $_SESSION["novo_usuario_id"] = $usuarioId;

            $sucesso =
                "Cadastro realizado com sucesso! Você já pode fazer seu login.";

            $nome = "";
            $email = "";
            $telefone = "";
            $cidade = "";
            $estado = "";

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                "Erro no cadastro do cliente: " .
                $e->getMessage()
            );

            $erros[] =
                "Não foi possível realizar o cadastro. Tente novamente.";
        }
    }
}

$listaEstados = [
    "AC" => "Acre",
    "AL" => "Alagoas",
    "AP" => "Amapá",
    "AM" => "Amazonas",
    "BA" => "Bahia",
    "CE" => "Ceará",
    "DF" => "Distrito Federal",
    "ES" => "Espírito Santo",
    "GO" => "Goiás",
    "MA" => "Maranhão",
    "MT" => "Mato Grosso",
    "MS" => "Mato Grosso do Sul",
    "MG" => "Minas Gerais",
    "PA" => "Pará",
    "PB" => "Paraíba",
    "PR" => "Paraná",
    "PE" => "Pernambuco",
    "PI" => "Piauí",
    "RJ" => "Rio de Janeiro",
    "RN" => "Rio Grande do Norte",
    "RS" => "Rio Grande do Sul",
    "RO" => "Rondônia",
    "RR" => "Roraima",
    "SC" => "Santa Catarina",
    "SP" => "São Paulo",
    "SE" => "Sergipe",
    "TO" => "Tocantins"
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

    <title>Cadastro de Cliente - Sistema de Bolos</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;

            background:
                linear-gradient(
                    135deg,
                    #fff7ed,
                    #fef3c7
                );

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 25px;
        }

        .container {
            width: 100%;
            max-width: 620px;
        }

        .card {
            background: #ffffff;
            border-radius: 18px;
            padding: 35px;

            box-shadow:
                0 12px 35px rgba(0, 0, 0, 0.10);
        }

        .logo {
            text-align: center;
            font-size: 48px;
            margin-bottom: 5px;
        }

        h1 {
            margin: 0;
            text-align: center;
            color: #333333;
            font-size: 28px;
        }

        .subtitulo {
            text-align: center;
            color: #777777;
            margin-top: 8px;
            margin-bottom: 28px;
            font-size: 14px;
            line-height: 1.5;
        }

        .mensagem {
            padding: 13px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.4;
        }

        .erro {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .erro ul {
            margin: 0;
            padding-left: 20px;
        }

        .sucesso {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .secao-cadastro {
            margin-top: 25px;
            margin-bottom: 20px;
        }

        .secao-cadastro h2 {
            font-size: 18px;
            margin: 0 0 5px;
            color: #333;
        }

        .secao-cadastro p {
            margin: 0 0 15px;
            font-size: 13px;
            color: #777;
        }

        .campo {
            margin-bottom: 15px;
        }

        .campo label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            color: #333;
        }

        .campo input,
        .campo select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            font-family: Arial, Helvetica, sans-serif;
            box-sizing: border-box;
            background: #fff;
        }

        .campo input:focus,
        .campo select:focus {
            outline: none;
            border-color: #d97700;
            box-shadow:
                0 0 0 2px
                rgba(217, 119, 0, 0.10);
        }

        .campo-ajuda {
            margin-top: 5px;
            font-size: 12px;
            color: #777;
        }

        .obrigatorio {
            color: #c00;
        }

        .senha-wrapper {
            position: relative;
            width: 100%;
        }

        .senha-wrapper input {
            padding-right: 55px !important;
        }

        .mostrar-senha {
            position: absolute !important;
            top: 0 !important;
            right: 5px !important;
            width: 42px !important;
            height: 100% !important;
            min-width: 42px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            border-radius: 7px !important;
            background: transparent !important;
            color: #555 !important;
            cursor: pointer;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            line-height: 1 !important;
            box-shadow: none !important;
        }

        .mostrar-senha:hover,
        .mostrar-senha:focus {
            background: #f3f4f6 !important;
            color: #222 !important;
            outline: none !important;
        }

        .icone-olho {
            width: 21px;
            height: 21px;
            display: block;
            pointer-events: none;
        }

        .senha-requisitos {
            margin-top: 10px;
            padding: 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
        }

        .senha-requisitos-titulo {
            margin-bottom: 8px;
            font-weight: bold;
            color: #444;
        }

        .requisito {
            margin: 5px 0;
            color: #777;
            transition: 0.2s;
        }

        .requisito.ok {
            color: #15803d;
            font-weight: bold;
        }

        .icone-requisito {
            display: inline-block;
            width: 20px;
            font-weight: bold;
        }

        .botao {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: 14px;
            background: #f59e0b;
            color: #ffffff;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
        }

        .botao:hover {
            background: #d97706;
        }

        .links {
            text-align: center;
            margin-top: 22px;
            font-size: 14px;
            color: #666666;
        }

        .links a {
            color: #d97706;
            text-decoration: none;
            font-weight: bold;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .separador {
            margin: 25px 0;
            border: 0;
            border-top: 1px solid #eeeeee;
        }

        @media (max-width: 600px) {

            body {
                padding: 15px;
            }

            .card {
                padding: 25px 20px;
            }

            h1 {
                font-size: 24px;
            }

            .campo input,
            .campo select {
                font-size: 16px;
            }

            .mostrar-senha {
                width: 44px !important;
                min-width: 44px !important;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <div class="card">

        <div class="logo">
            🎂
        </div>

        <h1>
            Criar minha conta
        </h1>

        <div class="subtitulo">
            Cadastre-se para fazer pedidos
            nas confeitarias disponíveis.
        </div>

        <?php if (!empty($erros)): ?>

            <div class="mensagem erro">

                <strong>
                    Não foi possível concluir o cadastro:
                </strong>

                <ul>

                    <?php foreach ($erros as $erro): ?>

                        <li>
                            <?= htmlspecialchars(
                                $erro,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>

        <?php if ($sucesso !== ""): ?>

            <div class="mensagem sucesso">
                <?= htmlspecialchars(
                    $sucesso,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </div>

        <?php endif; ?>


        <form
            method="POST"
            action=""
            autocomplete="on"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $csrf,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
            >


            <div class="secao-cadastro">

                <h2>
                    Seus dados
                </h2>

                <p>
                    Essas informações serão utilizadas para acessar sua conta.
                </p>

            </div>


            <div class="campo">

                <label for="nome">
                    Nome completo
                    <span class="obrigatorio">*</span>
                </label>

                <input
                    type="text"
                    id="nome"
                    name="nome"
                    maxlength="150"
                    required
                    autocomplete="name"
                    value="<?= htmlspecialchars(
                        $nome,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <div class="campo">

                <label for="email">
                    E-mail
                    <span class="obrigatorio">*</span>
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="150"
                    required
                    autocomplete="email"
                    value="<?= htmlspecialchars(
                        $email,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <div class="campo">

                <label for="telefone">
                    WhatsApp / Telefone
                    <span class="obrigatorio">*</span>
                </label>

                <input
                    type="text"
                    id="telefone"
                    name="telefone"
                    maxlength="15"
                    required
                    inputmode="numeric"
                    autocomplete="tel"
                    placeholder="(00) 00000-0000"
                    value="<?= htmlspecialchars(
                        formatarTelefoneCliente($telefone),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <div class="secao-cadastro">

                <h2>
                    Localização
                </h2>

                <p>
                    Informe sua cidade e estado para facilitar o atendimento e a localização dos pedidos.
                </p>

            </div>


            <div class="campo">

                <label for="cidade">
                    Cidade
                    <span class="obrigatorio">*</span>
                </label>

                <input
                    type="text"
                    id="cidade"
                    name="cidade"
                    maxlength="100"
                    required
                    autocomplete="address-level2"
                    value="<?= htmlspecialchars(
                        $cidade,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <div class="campo">

                <label for="estado">
                    Estado
                    <span class="obrigatorio">*</span>
                </label>

                <select
                    id="estado"
                    name="estado"
                    required
                    autocomplete="address-level1"
                >

                    <option value="">
                        Selecione o estado
                    </option>

                    <?php foreach ($listaEstados as $uf => $nomeEstado): ?>

                        <option
                            value="<?= $uf ?>"
                            <?= $estado === $uf ? "selected" : "" ?>
                        >
                            <?= $uf ?> - <?= $nomeEstado ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="secao-cadastro">

                <h2>
                    Segurança da conta
                </h2>

                <p>
                    Crie uma senha forte para proteger seu acesso.
                </p>

            </div>


            <div class="campo">

                <label for="senha">
                    Senha
                    <span class="obrigatorio">*</span>
                </label>

                <div class="senha-wrapper">

                    <input
                        type="password"
                        id="senha"
                        name="senha"
                        minlength="8"
                        maxlength="255"
                        required
                        autocomplete="new-password"
                        aria-describedby="senha-requisitos"
                    >

                    <button
                        type="button"
                        class="mostrar-senha"
                        data-target="senha"
                        aria-label="Mostrar senha"
                        title="Mostrar senha"
                    >

                        <svg
                            class="icone-olho"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >

                            <path
                                d="M3 3L21 21"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M10.58 10.58 A2 2 0 0 0 13.42 13.42"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M9.88 5.09 C11.18 4.7 12.57 4.5 14 4.5 C19 4.5 22 9 22 9 C21.39 9.91 20.39 11.15 19.05 12.29"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M6.61 6.61 C4.65 7.83 3 9 2 12 C2 12 5 19.5 12 19.5 C13.57 19.5 15 19.15 16.27 18.57"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                        </svg>

                    </button>

                </div>

                <div
                    class="senha-requisitos"
                    id="senha-requisitos"
                >

                    <div class="senha-requisitos-titulo">
                        Sua senha deve conter:
                    </div>

                    <div
                        class="requisito"
                        id="req-tamanho"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 8 caracteres
                    </div>

                    <div
                        class="requisito"
                        id="req-maiuscula"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 1 letra maiúscula
                    </div>

                    <div
                        class="requisito"
                        id="req-minuscula"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 1 letra minúscula
                    </div>

                    <div
                        class="requisito"
                        id="req-numero"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 1 número
                    </div>

                    <div
                        class="requisito"
                        id="req-simbolo"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 1 símbolo
                    </div>

                </div>

            </div>


            <div class="campo">

                <label for="confirmar_senha">
                    Confirmar senha
                    <span class="obrigatorio">*</span>
                </label>

                <div class="senha-wrapper">

                    <input
                        type="password"
                        id="confirmar_senha"
                        name="confirmar_senha"
                        minlength="8"
                        maxlength="255"
                        required
                        autocomplete="new-password"
                    >

                    <button
                        type="button"
                        class="mostrar-senha"
                        data-target="confirmar_senha"
                        aria-label="Mostrar senha"
                        title="Mostrar senha"
                    >

                        <svg
                            class="icone-olho"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >

                            <path
                                d="M3 3L21 21"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M10.58 10.58 A2 2 0 0 0 13.42 13.42"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M9.88 5.09 C11.18 4.7 12.57 4.5 14 4.5 C19 4.5 22 9 22 9 C21.39 9.91 20.39 11.15 19.05 12.29"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M6.61 6.61 C4.65 6.61 3 12 2 12 C2 12 5 19.5 12 19.5 C13.57 19.15 16.27 18.57"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                        </svg>

                    </button>

                </div>

                <div
                    class="campo-ajuda"
                    id="mensagem-confirmacao"
                >
                    Digite novamente a mesma senha.
                </div>

            </div>


            <button
                type="submit"
                class="botao"
            >
                Criar minha conta
            </button>

        </form>


        <hr class="separador">


        <div class="links">

            Já possui uma conta?

            <a href="login.php">
                Fazer login
            </a>

        </div>

    </div>

</div>


<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        /*
        |--------------------------------------------------------------------------
        | MOSTRAR / OCULTAR SENHA
        |--------------------------------------------------------------------------
        */

        document
            .querySelectorAll(".mostrar-senha")
            .forEach(function (botao) {

                botao.addEventListener(
                    "click",
                    function () {

                        const target =
                            botao.getAttribute("data-target");

                        const campo =
                            document.getElementById(target);

                        const svg =
                            botao.querySelector(".icone-olho");

                        if (!campo) {
                            return;
                        }

                        if (campo.type === "password") {

                            campo.type = "text";

                            svg.innerHTML = `
                                <path
                                    d="M2 12 C2 12 5 5 12 5 C19 5 22 12 22 12 C22 12 19 19 12 19 C5 19 2 12 2 12Z"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linejoin="round"
                                />
                                <circle
                                    cx="12"
                                    cy="12"
                                    r="3"
                                    stroke="currentColor"
                                    stroke-width="2"
                                />
                            `;

                            botao.setAttribute(
                                "aria-label",
                                "Ocultar senha"
                            );

                            botao.setAttribute(
                                "title",
                                "Ocultar senha"
                            );

                        } else {

                            campo.type = "password";

                            svg.innerHTML = `
                                <path
                                    d="M3 3L21 21"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M10.58 10.58 A2 2 0 0 0 13.42 13.42"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M9.88 5.09 C11.18 4.7 12.57 4.5 14 4.5 C19 4.5 22 9 22 9 C21.39 9.91 20.39 11.15 19.05 12.29"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M6.61 6.61 C4.65 6.61 3 12 2 12 C2 12 5 19.5 12 19.5 C13.57 19.15 16.27 18.57"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />
                            `;

                            botao.setAttribute(
                                "aria-label",
                                "Mostrar senha"
                            );

                            botao.setAttribute(
                                "title",
                                "Mostrar senha"
                            );
                        }
                    }
                );
            });


        /*
        |--------------------------------------------------------------------------
        | TELEFONE
        |--------------------------------------------------------------------------
        */

        const telefone =
            document.getElementById("telefone");

        telefone.addEventListener(
            "input",
            function () {

                let valor =
                    telefone.value.replace(/\D/g, "");

                valor = valor.substring(0, 11);

                if (valor.length === 11) {

                    telefone.value =
                        valor.replace(
                            /(\d{2})(\d{5})(\d{4})/,
                            "($1) $2-$3"
                        );

                } else if (valor.length === 10) {

                    telefone.value =
                        valor.replace(
                            /(\d{2})(\d{4})(\d{4})/,
                            "($1) $2-$3"
                        );

                } else {

                    telefone.value = valor;
                }
            }
        );


        /*
        |--------------------------------------------------------------------------
        | SENHA
        |--------------------------------------------------------------------------
        */

        const senha =
            document.getElementById("senha");

        const confirmarSenha =
            document.getElementById("confirmar_senha");

        const mensagemConfirmacao =
            document.getElementById("mensagem-confirmacao");

        const requisitos = [
            [
                document.getElementById("req-tamanho"),
                function (valor) {
                    return valor.length >= 8;
                }
            ],
            [
                document.getElementById("req-maiuscula"),
                function (valor) {
                    return /[A-Z]/.test(valor);
                }
            ],
            [
                document.getElementById("req-minuscula"),
                function (valor) {
                    return /[a-z]/.test(valor);
                }
            ],
            [
                document.getElementById("req-numero"),
                function (valor) {
                    return /[0-9]/.test(valor);
                }
            ],
            [
                document.getElementById("req-simbolo"),
                function (valor) {
                    return /[^A-Za-z0-9]/.test(valor);
                }
            ]
        ];

        function atualizarRequisito(elemento, valido) {

            const icone =
                elemento.querySelector(
                    ".icone-requisito"
                );

            if (valido) {

                elemento.classList.add("ok");
                icone.textContent = "✓";

            } else {

                elemento.classList.remove("ok");
                icone.textContent = "○";
            }
        }

        function atualizarRequisitos() {

            const valor = senha.value;

            requisitos.forEach(function (item) {

                atualizarRequisito(
                    item[0],
                    item[1](valor)
                );
            });

            atualizarConfirmacao();
        }

        function atualizarConfirmacao() {

            if (confirmarSenha.value === "") {

                mensagemConfirmacao.textContent =
                    "Digite novamente a mesma senha.";

                mensagemConfirmacao.style.color =
                    "#777";

                return;
            }

            if (senha.value === confirmarSenha.value) {

                mensagemConfirmacao.textContent =
                    "✓ As senhas coincidem.";

                mensagemConfirmacao.style.color =
                    "#15803d";

            } else {

                mensagemConfirmacao.textContent =
                    "As senhas não coincidem.";

                mensagemConfirmacao.style.color =
                    "#b91c1c";
            }
        }

        senha.addEventListener(
            "input",
            atualizarRequisitos
        );

        confirmarSenha.addEventListener(
            "input",
            atualizarConfirmacao
        );

    }
);

</script>

</body>
</html>