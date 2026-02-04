<?php
require_once "../auth.php";
require_login();

if ($_SESSION["rol"] !== "adminvehicular") {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

include "../includes/header.php";
?>

<div class="container py-4">
  <h2 class="text-primary">Gestión Vehicular</h2>
  <p class="text-muted mb-4">Panel general del sistema vehicular municipal</p>

  <div class="list-group">
    <a href="vehiculos/vehiculos.php" class="list-group-item list-group-item-action">🚘 Vehículos</a>
    <a href="solicitudes/solicitudes.php" class="list-group-item list-group-item-action">📄 Solicitudes</a>
    <a href="usos/usos.php" class="list-group-item list-group-item-action">🔁 Entregas y Devoluciones</a>
    <a href="mantenimientos/mantenimientos.php" class="list-group-item list-group-item-action">🛠 Mantenimientos</a>
    <a href="config/parametros.php" class="list-group-item list-group-item-action">⚙ Configuración</a>
  </div>
</div>

<?php include "../includes/footer.php"; ?>
