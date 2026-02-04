<?php
session_start();
require_once "config/db.php";
require_once "auth.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // 🔐 Validar CSRF
    $csrf = $_POST['_csrf'] ?? '';
    if (!csrf_check($csrf)) {
        $error = "La sesión ha expirado, vuelva a intentarlo.";
    } else {

        // Sanitizar entrada
        $usuario  = trim((string)($_POST["usuario"] ?? ""));
        $password = trim((string)($_POST["password"] ?? ""));

        if (!$conn) {
            die("Error de conexión con la Base de Datos.");
        }

        // ✅ Login unificado: soporta bcrypt, hashes viejos y migración automática
        $res = auth_try_login($conn, $usuario, $password);

        if (!empty($res["ok"])) {
            // Set sesión centralizada
            auth_set_session($res["user"] ?? []);

            // Normalizar rol
            $rolBD     = trim((string)($_SESSION["rol"] ?? "vista"));
            $rolNormal = strtolower($rolBD);

            // 🔑 Lista de roles válidos (incluye los tuyos)
            $rolesValidos = [
                "admin",
                "ingeniero",
                "inspector",
                "vista",
                "encargado",
                "adminvehicular",
                "solicitante",
                "financiero",
                "dashboard"
            ];

            if (!in_array($rolNormal, $rolesValidos, true)) {
                $rolNormal = "vista";
            }
            $_SESSION["rol"] = $rolNormal;

            // Asegurar que tengás id en sesión para tu módulo vehicular
            if (!isset($_SESSION["id"]) && isset($res["user"]["id"])) {
                $_SESSION["id"] = (int)$res["user"]["id"];
            }

            // Log de éxito
            if (function_exists('log_accion')) {
                log_accion($conn, "LOGIN", "Inicio de sesión exitoso");
            }

            // 🚦 Redirección por rol (tus flujos)
            switch ($rolNormal) {
                case "adminvehicular":
                    header("Location: /gestion_vial_ui/gestionVehicular/index.php");
                    break;

                case "solicitante":
                    // si en el proyecto final la ruta es distinta, solo ajustás este path
                    header("Location: /gestion_vial_ui/gestionVehicular/solicitudes.php");
                    break;

                case "financiero":
                    header("Location: /gestion_vial_ui/gestionVehicular/reportes.php");
                    break;

                case "dashboard":
                    header("Location: /gestion_vial_ui/gestionVehicular/dashboard_stats.php");
                    break;

                default:
                    // comportamiento original del sistema de Justin
                    header("Location: index.php");
                    break;
            }

            exit;
        }

        // ❌ Login fallido
        // Usamos el mensaje que devuelva auth_try_login (puede ser "Usuario no encontrado", "Contraseña incorrecta", etc.)
        // y si viene vacío, caemos en uno genérico.
        $error = $res["error"] ?? "";
        if ($error === "") {
            $error = "Usuario o contraseña incorrectos.";
        }

        // Registro de intento fallido al estilo Justin (pero con tu mensaje más descriptivo)
        if (function_exists('registrar_intento_fallido')) {
            registrar_intento_fallido($conn, $usuario, $error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inicio de Sesión - Gestión Vial</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
<div class="card shadow p-4" style="width: 350px;">
  <h4 class="text-center fw-bold mb-3">Gestión Vial</h4>

  <form method="POST">
    <?php if ($error): ?>
      <div class="alert alert-danger py-2 text-center"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- 🔐 Token CSRF -->
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="mb-3">
      <label class="form-label">Usuario</label>
      <input type="text" name="usuario" class="form-control" required autofocus>
    </div>

    <div class="mb-3">
      <label class="form-label">Contraseña</label>
      <input type="password" name="password" class="form-control" required>
    </div>

    <button class="btn btn-primary w-100">Iniciar sesión</button>
  </form>
</div>
</body>
</html>
