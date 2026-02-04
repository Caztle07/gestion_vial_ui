<?php
require_once "../../auth.php";
require_login();

if ($_SESSION["rol"] !== "adminvehicular") {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

require_once "../../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: vehiculos.php");
    exit;
}

$id = $_POST["id"] ?? null;

$placa = $_POST["placa"] ?? "";
$tipo = $_POST["tipo"] ?? "";
$marca = $_POST["marca"] ?? "";
$modelo = $_POST["modelo"] ?? "";
$anio = $_POST["anio"] ?? null;
$km_actual = $_POST["km_actual"] ?? null;
$combustible = $_POST["combustible"] ?? null;
$estado = $_POST["estado"] ?? "disponible";
$dekra = $_POST["dekra_vencimiento"] ?? null;
$marchamo = $_POST["marchamo_vencimiento"] ?? null;
$seguro = $_POST["seguro_vencimiento"] ?? null;
$obs = $_POST["observaciones"] ?? "";

// Validación mínima
if (empty($placa) || empty($tipo) || empty($marca) || empty($modelo)) {
    die("Error: faltan campos obligatorios.");
}

if ($id) {
    // ============================
    // UPDATE
    // ============================
    $sql = "UPDATE vehiculos SET
        placa = ?, tipo = ?, marca = ?, modelo = ?, anio = ?, km_actual = ?, combustible = ?, estado = ?,
        dekra_vencimiento = ?, marchamo_vencimiento = ?, seguro_vencimiento = ?, observaciones = ?, fecha_modificado = NOW()
        WHERE id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssssisssssssi",
        $placa, $tipo, $marca, $modelo, $anio, $km_actual, $combustible, $estado,
        $dekra, $marchamo, $seguro, $obs, $id
    );
    $stmt->execute();
    $stmt->close();

} else {
    // ============================
    // INSERT
    // ============================
    $sql = "INSERT INTO vehiculos (
        placa, tipo, marca, modelo, anio, km_actual, combustible, estado,
        dekra_vencimiento, marchamo_vencimiento, seguro_vencimiento, observaciones,
        fecha_creado, fecha_modificado
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
    "ssssisssssss",  // 12 parámetros, uno para cada columna
    $placa, $tipo, $marca, $modelo, $anio, $km_actual, $combustible, $estado,
    $dekra, $marchamo, $seguro, $obs  // Agregamos un `s` para cada campo de tipo fecha
);
    $stmt->execute();
    $stmt->close();
}

// Volver al listado
header("Location: vehiculos.php");
exit;
?>
