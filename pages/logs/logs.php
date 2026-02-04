<?php
session_start();
require_once "../../config/db.php";
require_once "../../auth.php";
include "../../includes/header.php";


if (!can_edit("admin")) {
    echo "<div class='alert alert-danger m-3'>❌ Solo el administrador puede ver los logs.</div>";
    exit;
}

mysqli_set_charset($conn, "utf8");

// ========================
// FUNCIÓN PARA DETECTAR CAMBIOS
// ========================
function detectarCambios($antes, $despues, $prefix = "")
{
    $cambios = [];

    foreach ($antes as $key => $valorAntes) {
        $ruta = $prefix . $key;

        if (!array_key_exists($key, $despues)) {
            if (is_array($valorAntes)) {
                $valorAntes = json_encode($valorAntes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $cambios[] = "$ruta eliminado (valor antes: '$valorAntes')";
            continue;
        }

        $valorDespues = $despues[$key];

        // Si ambos son arrays → recursivo
        if (is_array($valorAntes) && is_array($valorDespues)) {
            $subCambios = detectarCambios($valorAntes, $valorDespues, $ruta . ".");
            $cambios = array_merge($cambios, $subCambios);
            continue;
        }

        // Normalizar arrays a JSON
        if (is_array($valorAntes)) {
            $valorAntes = json_encode($valorAntes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($valorDespues)) {
            $valorDespues = json_encode($valorDespues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($valorAntes !== $valorDespues) {
            $cambios[] = "$ruta cambió de '$valorAntes' a '$valorDespues'";
        }
    }

    // Claves nuevas en $despues
    foreach ($despues as $key => $valorDespues) {
        if (!array_key_exists($key, $antes)) {
            $ruta = $prefix . $key;
            if (is_array($valorDespues)) {
                $valorDespues = json_encode($valorDespues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $cambios[] = "$ruta agregado con valor '$valorDespues'";
        }
    }

    return $cambios;
}

// ========================
// FILTROS
// ========================
$f_usuario = $_GET["usuario"] ?? "";
$f_accion  = $_GET["accion"]  ?? "";
$f_fecha   = $_GET["fecha"]   ?? "";
$f_nombre  = $_GET["nombre"]  ?? "";  // filtro por nombre de proyecto

// WHERE dinámico
$where = "1=1";

if ($f_usuario !== "") {
    $usuario = $conn->real_escape_string($f_usuario);
    $where .= " AND usuario LIKE '%$usuario%'";
}

if ($f_accion !== "") {
    $accion = $conn->real_escape_string($f_accion);
    $where .= " AND accion LIKE '%$accion%'";
}

if ($f_fecha !== "") {
    $fecha = $conn->real_escape_string($f_fecha);
    $where .= " AND DATE(fecha) = '$fecha'";
}

if ($f_nombre !== "") {
    $nombre = $conn->real_escape_string($f_nombre);
    $where .= " AND detalle LIKE '%$nombre%'";
}

$logs = $conn->query("
    SELECT *
    FROM logs_acciones
    WHERE $where
    ORDER BY fecha DESC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Logs del sistema</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body { background:#f5f7fa; }
.card { border-radius:10px; }
.log-json-area {
    height: 220px;
    font-size: 12px;
    font-family: monospace;
    white-space: pre;
}
</style>
</head>

<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold text-primary">
      <i class="bi bi-file-earmark-text"></i> Logs del Sistema
    </h3>

    <a href="../../index.php" class="btn btn-secondary">
      <i class="bi bi-arrow-left"></i> Volver
    </a>
  </div>

  <!-- Filtros -->
  <div class="card mb-4">
    <div class="card-header fw-semibold">Filtros</div>
    <div class="card-body">
      <form method="GET" class="row g-3">

        <div class="col-md-3">
          <label class="form-label">Usuario</label>
          <input type="text" name="usuario" value="<?= htmlspecialchars($f_usuario) ?>"
                 class="form-control form-control-sm" placeholder="Buscar usuario...">
        </div>

        <div class="col-md-3">
          <label class="form-label">Acción</label>
          <input type="text" name="accion" value="<?= htmlspecialchars($f_accion) ?>"
                 class="form-control form-control-sm" placeholder="PROYECTO_...">
        </div>

        <div class="col-md-3">
          <label class="form-label">Fecha</label>
          <input type="date" name="fecha" value="<?= htmlspecialchars($f_fecha) ?>"
                 class="form-control form-control-sm">
        </div>

        <div class="col-md-3">
          <label class="form-label">Nombre proyecto</label>
          <input type="text" name="nombre" value="<?= htmlspecialchars($f_nombre) ?>"
                 class="form-control form-control-sm" placeholder="Ej: RUTA 21444">
        </div>

        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary me-2">
            <i class="bi bi-search"></i> Filtrar
          </button>
          <a href="logs.php" class="btn btn-secondary">
            <i class="bi bi-x-circle"></i> Limpiar
          </a>
        </div>

      </form>
    </div>
  </div>

  <!-- Resultados -->
  <div class="card">
    <div class="card-header fw-semibold">Resultados</div>
    <div class="table-responsive">
      <table class="table table-striped align-middle text-center mb-0">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Fecha</th>
            <th>Usuario</th>
            <th>Rol</th>
            <th>Acción</th>
            <th>Detalle</th>
            <th>Equipo / IP</th>
          </tr>
        </thead>
        <tbody>
        <?php while ($row = $logs->fetch_assoc()): ?>

          <?php
          $detalle_raw   = $row["detalle"] ?? "";
          $antes_texto   = "";
          $despues_texto = "";
          $cambios_texto = "";

          $json = json_decode($detalle_raw, true);

          if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {

              // Limpiar HTML de TODOS los strings
              array_walk_recursive($json, function (&$v) {
                  if (is_string($v)) {
                      $v = html_entity_decode($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                      $v = strip_tags($v);
                      $v = preg_replace('/\s+/', ' ', $v);
                      $v = trim($v);
                  }
              });

              $antes   = $json["antes"]   ?? [];
              $despues = $json["despues"] ?? [];

              $antes_texto   = json_encode($antes,   JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
              $despues_texto = json_encode($despues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

              $listaCambios = detectarCambios($antes, $despues);
              if (!empty($listaCambios)) {
                  $cambios_texto = implode("\n", $listaCambios);
              } else {
                  $cambios_texto = "No se detectaron cambios.";
              }
          } else {
              $antes_texto   = $detalle_raw;
              $despues_texto = "";
              $cambios_texto = "Detalle no es JSON válido.";
          }
          ?>

          <tr>
            <td><?= $row["id"] ?></td>
            <td><?= date("d/m/Y H:i:s", strtotime($row["fecha"])) ?></td>
            <td><?= htmlspecialchars($row["usuario"]) ?></td>
            <td><?= htmlspecialchars($row["rol"]) ?></td>
            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($row["accion"]) ?></span></td>

            <td>
              <div class="row g-2 text-start">

                <div class="col-4">
                  <label class="fw-bold text-secondary small">Antes</label>
                  <textarea class="form-control form-control-sm log-json-area" readonly><?= $antes_texto ?></textarea>
                </div>

                <div class="col-4">
                  <label class="fw-bold text-secondary small">Después</label>
                  <textarea class="form-control form-control-sm log-json-area" readonly><?= $despues_texto ?></textarea>
                </div>

                <div class="col-4">
                  <label class="fw-bold text-secondary small text-danger">Cambios detectados</label>
                  <textarea class="form-control form-control-sm log-json-area"
                            style="color:#b40000; font-weight:bold;"
                            readonly><?= $cambios_texto ?></textarea>
                </div>

              </div>
            </td>

            <td><?= htmlspecialchars($row["ip"]) ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

</body>
</html>
