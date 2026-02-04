<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Rol y nombre desde la sesión
$rol    = strtolower(trim($_SESSION["rol"] ?? "Invitado"));
$nombre = $_SESSION["nombre"] ?? "Usuario";

// Mostrar nombre amigable
$nombreRol = [
    "admin"      => "Administrador",
    "ingeniero"  => "Ingeniero",
    "inspector"  => "Inspector",
    "vista"      => "Vista",
    "visor"      => "Visor",
    "invitado"   => "Invitado"
][$rol] ?? ucfirst($rol);

// Helper para usar can_edit solo si existe
function hv_can_edit(string $permiso): bool {
    return function_exists('can_edit') && can_edit($permiso);
}

// Solo admin y visor (incluyo vista por si tu visor realmente se llama "vista")
$puedeVerFototeca = ($rol === "admin" || $rol === "visor" || $rol === "vista");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestión Vial</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    .navbar { background: #1976D2; padding: 0.8rem 1.2rem; }
    .navbar-brand { font-weight: bold; color: #fff !important; display: flex; align-items: center; gap: 6px; }
    .navbar-nav .nav-link { color: #E3F2FD !important; font-weight: 500; padding: 8px 15px; display: flex; align-items: center; gap: 6px; }
    .navbar-nav .nav-link:hover { background-color: rgba(255,255,255,0.18); border-radius: 6px; color: #fff !important; }
    .rol-badge { background-color: rgba(255,255,255,0.2); color: #fff; padding: 6px 14px; border-radius: 25px; font-size: 0.9rem; font-weight: 500; margin-left: 15px; display: flex; align-items: center; gap: 5px; }
  </style>
</head>

<body>
<nav class="navbar navbar-expand-lg navbar-dark shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand" href="/gestion_vial_ui/index.php">
      <i class="bi bi-geo-alt-fill"></i> Gestión Vial
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse justify-content-end" id="menu">
      <ul class="navbar-nav mb-2 mb-lg-0">

        <!-- Operación (según permisos) -->
        <?php if (hv_can_edit("proyectos") || hv_can_edit("proyectos_ver")): ?>
          <li class="nav-item">
            <a class="nav-link" href="/gestion_vial_ui/pages/proyectos/proyectos.php">
              <i class="bi bi-diagram-3"></i> Proyectos
            </a>
          </li>
        <?php endif; ?>

        <?php if ($rol === "admin" || hv_can_edit("cronicas") || hv_can_edit("cronicas_crear")): ?>
          <li class="nav-item">
            <a class="nav-link" href="/gestion_vial_ui/pages/cronicas/cronicas.php">
              <i class="bi bi-journal-text"></i> Crónicas
            </a>
          </li>
        <?php endif; ?>

        <?php if ($_SESSION["rol"] === "adminvehicular" || $_SESSION["rol"] === "financiero" || $_SESSION["rol"] === "dashboard") : ?>
<li class="nav-item dropdown">
  <a class="nav-link dropdown-toggle" href="#" id="vehicularMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
    🚗 Gestión Vehicular
  </a>
  <ul class="dropdown-menu" aria-labelledby="vehicularMenu">
    <?php if ($_SESSION["rol"] === "adminvehicular") : ?>
      <li><a class="dropdown-item" href="/gestion_vial_ui/gestionVehicular/vehiculos/vehiculos.php">Vehículos</a></li>
      <li><a class="dropdown-item" href="/gestion_vial_ui/gestionVehicular/solicitudes/solicitudes.php">Solicitudes</a></li>
      <li><a class="dropdown-item" href="/gestion_vial_ui/gestionVehicular/usos/usos.php">Entregas/Devoluciones</a></li>
      <li><a class="dropdown-item" href="/gestion_vial_ui/gestionVehicular/mantenimientos/mantenimientos.php">Mantenimientos</a></li>
      <li><a class="dropdown-item" href="/gestion_vial_ui/gestionVehicular/config/parametros.php">Configuración</a></li>
    <?php endif; ?>

    <?php if ($_SESSION["rol"] === "solicitante") : ?>
      <li><a class="dropdown-item" href="/gestion_vial_ui/gestionVehicular/solicitudes/solicitud_form.php">Nueva solicitud</a></li>
      <li><a class="dropdown-item" href="/gestion_vial_ui/gestionVehicular/solicitudes/solicitudes.php">Mis solicitudes</a></li>
    <?php endif; ?>

    <?php if ($_SESSION["rol"] === "financiero" || $_SESSION["rol"] === "dashboard") : ?>
      <li><a class="dropdown-item" href="/gestion_vial_ui/gestionVehicular/reportes/reportes.php">Reportes</a></li>
    <?php endif; ?>
  </ul>
</li>
<?php endif; ?>

        <?php if ($puedeVerFototeca): ?>
          <li class="nav-item">
            <a class="nav-link" href="/gestion_vial_ui/pages/fototeca/fototeca.php">
              <i class="bi bi-images"></i> Fototeca
            </a>
          </li>
        <?php endif; ?>

        <?php if (hv_can_edit("inventario") || hv_can_edit("caminos")): ?>
          <li class="nav-item">
            <a class="nav-link" href="/gestion_vial_ui/pages/caminos/caminos.php">
              <i class="bi bi-map"></i> Inventario
            </a>
          </li>
        <?php endif; ?>

        <?php if (hv_can_edit("historico") || hv_can_edit("historico_ver")): ?>
          <li class="nav-item">
            <a class="nav-link" href="/gestion_vial_ui/pages/historico/historico.php">
              <i class="bi bi-clock-history"></i> Histórico
            </a>
          </li>
        <?php endif; ?>

         <!-- Panel para rol "vista" y "visor" -->
        <?php if ($rol === "vista" || $rol === "visor"): ?>
          <li class="nav-item">
            <a class="nav-link" href="/gestion_vial_ui/pages/visor/panel_visor.php">
              <i class="bi bi-eye"></i> Panel visor
            </a>
          </li>
        <?php endif; ?>

        <!-- ADMIN / SEGURIDAD / GESTIÓN -->
        <?php if ($rol === "admin" || hv_can_edit("logs") || hv_can_edit("inspectores")): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="adminMenu" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-gear-fill"></i> Administración
            </a>
            <ul class="dropdown-menu dropdown-menu-end">

              <?php if (hv_can_edit("logs")): ?>
                <li><a class="dropdown-item" href="/gestion_vial_ui/pages/logs/logs.php"><i class="bi bi-file-earmark-text me-2"></i> Logs</a></li>
              <?php endif; ?>

              <?php if (hv_can_edit("inspectores")): ?>
                <li><a class="dropdown-item" href="/gestion_vial_ui/pages/inspectores/inspectores.php"><i class="bi bi-person-badge me-2"></i> Inspectores</a></li>
              <?php endif; ?>

              <?php if ($rol === "admin"): ?>
                <li><a class="dropdown-item" href="/gestion_vial_ui/pages/modalidades/modalidades.php"><i class="bi bi-sliders me-2"></i> Modalidades</a></li>
                <li><a class="dropdown-item" href="/gestion_vial_ui/pages/encargados/encargados.php"><i class="bi bi-people me-2"></i> Encargados</a></li>
                <li><a class="dropdown-item" href="/gestion_vial_ui/pages/papelera/papelera.php"><i class="bi bi-trash3 me-2"></i> Papelera</a></li>
                <li><a class="dropdown-item" href="/gestion_vial_ui/pages/usuarios/usuarios.php"><i class="bi bi-person-gear me-2"></i> Usuarios</a></li>
                <li><a class="dropdown-item" href="/gestion_vial_ui/pages/seguridad/seguridad_logs.php"><i class="bi bi-shield-lock me-2"></i> Seguridad</a></li>
              <?php endif; ?>

            </ul>
          </li>
        <?php endif; ?>

        <?php if ($rol !== "invitado"): ?>
          <li class="nav-item">
            <a class="nav-link text-warning fw-bold" href="/gestion_vial_ui/logout.php">
              <i class="bi bi-box-arrow-right"></i> Salir
            </a>
          </li>
        <?php endif; ?>

      </ul>

      <div class="rol-badge">
        <i class="bi bi-person-circle"></i> <?= htmlspecialchars($nombreRol, ENT_QUOTES, 'UTF-8') ?>
      </div>
    </div>
  </div>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
