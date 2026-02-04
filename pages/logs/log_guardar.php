<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function registrar_log($conn, $accion, $detalle="") {

    $usuario = $_SESSION["nombre"] ?? "Desconocido";
    $rol     = $_SESSION["rol"] ?? "sin_rol";

    // -------------------------------
    // 🔥 1. Intentar hostname enviado por el navegador
    // -------------------------------
    if (!empty($_SESSION["hostname_real"])) {
        $ip = $_SESSION["hostname_real"];
    } else {
        // -------------------------------
        // 🔥 2. Intentar resolver la IP a nombre
        // -------------------------------
        $ip = gethostbyaddr($_SERVER["REMOTE_ADDR"] ?? "0.0.0.0");

        // Si no devuelve nombre, usar la IP como fallback
        if (!$ip || $ip === $_SERVER["REMOTE_ADDR"]) {
            $ip = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";
        }
    }

    $sql = "INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip, fecha)
            VALUES (?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $usuario, $rol, $accion, $detalle, $ip);
    $stmt->execute();
}
?>
