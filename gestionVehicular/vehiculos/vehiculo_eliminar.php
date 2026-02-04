<?php
require_once "../../auth.php";
require_login();

if ($_SESSION["rol"] !== "adminvehicular") {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

require_once "../../config/db.php";

$id = $_GET["id"] ?? null;

if ($id) {
    $stmt = $conn->prepare("UPDATE vehiculos SET activo = 0, fecha_modificado = NOW() WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

header("Location: vehiculos.php");
exit;
